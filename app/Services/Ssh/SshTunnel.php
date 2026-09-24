<?php

declare(strict_types=1);

namespace App\Services\Ssh;

use App\Exceptions\BackupException;
use App\Models\ServerConnection;
use App\Support\WorkDir;
use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs work against a database behind SSH. For connections without SSH the
 * callback gets the connection unchanged. Otherwise an `ssh -N -L` tunnel is
 * opened to a free local port, the callback gets a copy of the connection that
 * points at 127.0.0.1:<port>, and the tunnel is closed afterwards.
 *
 * The host key must have been pinned (by testing the connection) and is
 * checked strictly; the user's ssh config and agent are ignored.
 */
class SshTunnel
{
    private const ATTEMPTS = 3;

    /**
     * @template T
     *
     * @param  Closure(ServerConnection): T  $callback
     * @return T
     */
    public function with(ServerConnection $connection, Closure $callback): mixed
    {
        if (! $connection->usesSsh()) {
            return $callback($connection);
        }

        if (($connection->ssh_host_key ?? '') === '') {
            throw new BackupException('The SSH host key of '.$connection->ssh_host.' is not pinned yet. Press "Test" on the connection first.');
        }
        if (($connection->ssh_private_key ?? '') === '' || ($connection->ssh_user ?? '') === '') {
            throw new BackupException('The SSH user or key of this connection is missing. Edit and save the connection.');
        }

        $keyPath = WorkDir::tempPath('ssh-key');
        $knownHostsPath = WorkDir::tempPath('known-hosts');
        $process = null;

        try {
            WorkDir::writePrivate($keyPath, rtrim((string) $connection->ssh_private_key)."\n");
            WorkDir::writePrivate($knownHostsPath, (string) $connection->ssh_host_key);

            [$process, $port] = $this->open($connection, $keyPath, $knownHostsPath);

            $local = clone $connection;
            $local->host = '127.0.0.1';
            $local->port = $port;
            $local->socket = null;
            $local->ssh_enabled = false;

            return $callback($local);
        } finally {
            if ($process !== null) {
                $this->close($process);
            }
            WorkDir::delete($keyPath);
            WorkDir::delete($knownHostsPath);
        }
    }

    /**
     * @return list<string>
     */
    public function command(ServerConnection $connection, int $localPort, string $keyPath, string $knownHostsPath): array
    {
        $target = (str_contains($connection->host, ':') ? '['.$connection->host.']' : $connection->host).':'.$connection->port;

        return [
            (string) config('backup-manager.binaries.ssh'),
            '-N', '-T',
            '-F', '/dev/null',
            '-o', 'BatchMode=yes',
            '-o', 'ExitOnForwardFailure=yes',
            '-o', 'StrictHostKeyChecking=yes',
            '-o', 'UserKnownHostsFile='.$knownHostsPath,
            '-o', 'GlobalKnownHostsFile=/dev/null',
            '-o', 'IdentitiesOnly=yes',
            '-o', 'IdentityAgent=none',
            '-o', 'ConnectTimeout=10',
            '-o', 'ServerAliveInterval=30',
            '-o', 'ServerAliveCountMax=4',
            '-o', 'Compression=yes',
            '-i', $keyPath,
            '-L', '127.0.0.1:'.$localPort.':'.$target,
            '-p', (string) $connection->ssh_port,
            '-l', (string) $connection->ssh_user,
            '--', (string) $connection->ssh_host,
        ];
    }

    /**
     * @return array{0: InvokedProcess, 1: int}
     */
    private function open(ServerConnection $connection, string $keyPath, string $knownHostsPath): array
    {
        $error = '';

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $port = $this->freePort();
            $process = Process::forever()->start($this->command($connection, $port, $keyPath, $knownHostsPath));

            if ($this->waitUntilListening($process, $port)) {
                return [$process, $port];
            }

            $this->close($process);
            $error = trim($process->errorOutput());

            // Another process grabbed the port between probing and binding: try a new one.
            if (! str_contains($error, 'Address already in use') && ! str_contains($error, 'cannot listen')) {
                break;
            }
        }

        throw new BackupException('SSH tunnel to '.$connection->ssh_host.' failed: '.Str::limit($error !== '' ? $error : 'timed out', 500));
    }

    /**
     * ssh binds the local port only after authentication succeeded. The check
     * tries to bind the port itself instead of connecting to it, so no
     * half-open connection reaches the database server (MariaDB counts those
     * against max_connect_errors).
     */
    protected function waitUntilListening(InvokedProcess $process, int $port): bool
    {
        $deadline = microtime(true) + max(1, (int) config('backup-manager.ssh.connect_timeout', 15));

        while (microtime(true) < $deadline) {
            if (! $process->running()) {
                return false;
            }
            $probe = @stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $errstr);
            if ($probe === false) {
                return $process->running();
            }
            fclose($probe);
            usleep(200_000);
        }

        return false;
    }

    private function close(InvokedProcess $process): void
    {
        try {
            if ($process->running()) {
                $process->signal(15); // SIGTERM
            }
            $process->wait();
        } catch (Throwable) {
            // Already gone.
        }
    }

    protected function freePort(): int
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            throw new BackupException('Could not find a free local port for the SSH tunnel: '.$errstr);
        }
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
