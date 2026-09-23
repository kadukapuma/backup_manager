<?php

declare(strict_types=1);

namespace App\Actions\Destinations;

use App\Enums\AuditAction;
use App\Enums\DestinationType;
use App\Models\Destination;
use App\Services\Audit\AuditLogger;

class SaveDestination
{
    /** Non-secret config keys kept per type. */
    private const PUBLIC_KEYS = [
        'sftp' => ['host', 'port', 'user', 'known_hosts_file'],
        's3' => ['provider', 'region', 'endpoint', 'bucket', 'access_key_id', 'storage_class'],
        'local' => [],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Secrets left blank on update keep their stored value.
     *
     * @param  array<string, mixed>  $data  validated input
     */
    public function handle(array $data, ?Destination $destination = null): Destination
    {
        $isNew = $destination === null;
        $type = $isNew ? DestinationType::from((string) $data['type']) : $destination->type;
        $stored = $isNew ? [] : $destination->config;
        $input = (array) ($data['config'] ?? []);

        $config = [];
        foreach (self::PUBLIC_KEYS[$type->value] ?? [] as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== '') {
                $config[$key] = $key === 'port' ? (int) $input[$key] : (string) $input[$key];
            }
        }

        $secretsChanged = [];
        foreach ($type->secretKeys() as $key) {
            $value = $input[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $config[$key] = $value;
                $secretsChanged[] = $key;
            } elseif (! empty($stored[$key] ?? null)) {
                $config[$key] = $stored[$key];
            }
        }

        $destination ??= new Destination(['type' => $type]);
        $destination->fill([
            'name' => $data['name'],
            'base_path' => rtrim((string) ($data['base_path'] ?? ''), '/'),
            'is_active' => (bool) $data['is_active'],
            'config' => $config,
        ]);
        $destination->save();

        $meta = ['name' => $destination->name, 'type' => $type->value, 'config' => $destination->publicConfig()];
        $this->audit->log($isNew ? AuditAction::DestinationCreated : AuditAction::DestinationUpdated, $destination, $meta);
        if (! $isNew && $secretsChanged !== []) {
            $this->audit->log(AuditAction::DestinationCredentialsChanged, $destination, ['name' => $destination->name, 'fields' => $secretsChanged]);
        }

        return $destination;
    }
}
