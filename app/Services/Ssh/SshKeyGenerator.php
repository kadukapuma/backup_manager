<?php

declare(strict_types=1);

namespace App\Services\Ssh;

use App\Exceptions\BackupException;
use App\Support\WorkDir;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Creates the ed25519 key pair a connection uses for its SSH tunnel. The
 * private key is returned to be stored encrypted and never leaves the panel.
 */
class SshKeyGenerator
{
    /**
     * @return array{private: string, public: string}
     */
    public function generate(string $comment): array
    {
        $path = WorkDir::tempPath('ssh-keygen');
        $comment = preg_replace('/[^A-Za-z0-9_.@\-]/', '-', $comment) ?? 'backup-manager';

        try {
            $result = Process::timeout(30)->run([
                (string) config('backup-manager.binaries.ssh_keygen'),
                '-q', '-t', 'ed25519', '-N', '', '-C', $comment, '-f', $path,
            ]);

            if (! $result->successful() || ! is_file($path) || ! is_file($path.'.pub')) {
                throw new BackupException('Could not create an SSH key: '.Str::limit(trim($result->errorOutput()) ?: 'ssh-keygen failed', 300));
            }

            return [
                'private' => (string) file_get_contents($path),
                'public' => trim((string) file_get_contents($path.'.pub')),
            ];
        } finally {
            WorkDir::delete($path);
            WorkDir::delete($path.'.pub');
        }
    }
}
