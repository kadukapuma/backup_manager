<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Enums\DestinationType;
use App\Models\Destination;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Builds per-process rclone configuration as RCLONE_CONFIG_<REMOTE>_<OPTION>
 * environment variables, so no shared config file holds credentials.
 */
class RcloneEnvBuilder
{
    public const REMOTE = 'BMDEST';

    /**
     * @return array<string, string>
     */
    public function build(Destination $destination): array
    {
        $c = $destination->config;
        $p = 'RCLONE_CONFIG_'.self::REMOTE.'_';

        $env = [
            // In-memory config only: never read or write ~/.config/rclone/rclone.conf.
            'RCLONE_CONFIG' => '/notfound',
            $p.'TYPE' => match ($destination->type) {
                DestinationType::Local => 'local',
                DestinationType::Sftp => 'sftp',
                DestinationType::S3 => 's3',
                default => throw new RuntimeException("Destination type {$destination->type->value} is not supported yet."),
            },
        ];

        if ($destination->type === DestinationType::Sftp) {
            $env[$p.'HOST'] = (string) ($c['host'] ?? '');
            $env[$p.'PORT'] = (string) ($c['port'] ?? 22);
            $env[$p.'USER'] = (string) ($c['user'] ?? '');
            if (! empty($c['password'])) {
                $env[$p.'PASS'] = $this->obscure((string) $c['password']);
            }
            if (! empty($c['private_key'])) {
                $env[$p.'KEY_PEM'] = str_replace(["\r\n", "\n"], '\n', trim((string) $c['private_key']));
                if (! empty($c['private_key_passphrase'])) {
                    $env[$p.'KEY_FILE_PASS'] = $this->obscure((string) $c['private_key_passphrase']);
                }
            }
            if (! empty($c['known_hosts_file'])) {
                $env[$p.'KNOWN_HOSTS_FILE'] = (string) $c['known_hosts_file'];
            }
        }

        if ($destination->type === DestinationType::S3) {
            $env[$p.'PROVIDER'] = (string) ($c['provider'] ?? 'AWS');
            $env[$p.'ENV_AUTH'] = 'false';
            $env[$p.'ACCESS_KEY_ID'] = (string) ($c['access_key_id'] ?? '');
            $env[$p.'SECRET_ACCESS_KEY'] = (string) ($c['secret_access_key'] ?? '');
            $env[$p.'NO_CHECK_BUCKET'] = 'true';
            if (! empty($c['region'])) {
                $env[$p.'REGION'] = (string) $c['region'];
            }
            if (! empty($c['endpoint'])) {
                $env[$p.'ENDPOINT'] = (string) $c['endpoint'];
            }
            if (! empty($c['storage_class'])) {
                $env[$p.'STORAGE_CLASS'] = (string) $c['storage_class'];
            }
        }

        return $env;
    }

    /**
     * Secret values in the env, for redacting command output.
     *
     * @return list<string>
     */
    public function secrets(Destination $destination): array
    {
        $values = [];
        foreach ($destination->type->secretKeys() as $key) {
            if (! empty($destination->config[$key])) {
                $values[] = (string) $destination->config[$key];
            }
        }

        return $values;
    }

    /**
     * rclone requires passwords in its own obscured form. The plain value is
     * passed on stdin, never as an argument.
     */
    protected function obscure(string $secret): string
    {
        $result = Process::timeout(15)
            ->input($secret)
            ->env(['RCLONE_CONFIG' => '/notfound'])
            ->run([(string) config('backup-manager.binaries.rclone'), 'obscure', '-']);

        if (! $result->successful()) {
            throw new RuntimeException('rclone obscure failed: '.trim($result->errorOutput()));
        }

        return trim($result->output());
    }
}
