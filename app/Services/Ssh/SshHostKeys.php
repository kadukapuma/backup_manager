<?php

declare(strict_types=1);

namespace App\Services\Ssh;

use App\Exceptions\BackupException;
use App\Models\ServerConnection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Pins the remote server's SSH host keys on the first test ("trust on first
 * use"). Tunnels then refuse to connect when the server presents another key.
 */
class SshHostKeys
{
    /**
     * Scans and stores the host keys if none are pinned yet.
     *
     * @return bool true when keys were pinned by this call
     */
    public function pinIfMissing(ServerConnection $connection): bool
    {
        if (! $connection->usesSsh() || ($connection->ssh_host_key ?? '') !== '') {
            return false;
        }

        $connection->forceFill(['ssh_host_key' => $this->scan($connection)])->save();

        return true;
    }

    /**
     * known_hosts lines as printed by ssh-keyscan.
     */
    public function scan(ServerConnection $connection): string
    {
        $result = Process::timeout(30)->run([
            (string) config('backup-manager.binaries.ssh_keyscan'),
            '-T', '10',
            '-p', (string) $connection->ssh_port,
            '-t', 'ed25519,ecdsa,rsa',
            '--', (string) $connection->ssh_host,
        ]);

        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $result->output())),
            static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#') && count(preg_split('/\s+/', $line) ?: []) >= 3,
        ));

        if ($lines === []) {
            throw new BackupException('Could not read the SSH host key of '.$connection->ssh_host.':'.$connection->ssh_port.': '
                .Str::limit(trim($result->errorOutput()) ?: 'no answer', 300));
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * "ssh-ed25519 SHA256:…" per pinned key, in the format `ssh-keygen -lf` prints.
     *
     * @return list<string>
     */
    public static function fingerprints(?string $knownHosts): array
    {
        $fingerprints = [];
        foreach (explode("\n", (string) $knownHosts) as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (count($parts) < 3) {
                continue;
            }
            $blob = base64_decode($parts[2], true);
            if ($blob === false) {
                continue;
            }
            $fingerprints[] = $parts[1].' SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
        }

        return $fingerprints;
    }
}
