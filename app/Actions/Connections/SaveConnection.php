<?php

declare(strict_types=1);

namespace App\Actions\Connections;

use App\Enums\AuditAction;
use App\Models\ServerConnection;
use App\Services\Audit\AuditLogger;
use App\Services\Ssh\SshKeyGenerator;
use Illuminate\Support\Str;

class SaveConnection
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SshKeyGenerator $keys,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function handle(array $data, ?ServerConnection $connection = null): ServerConnection
    {
        $isNew = $connection === null;
        $connection ??= new ServerConnection;

        $password = $data['password'] ?? null;
        $forgetHostKey = (bool) ($data['ssh_forget_host_key'] ?? false);
        unset($data['password'], $data['ssh_forget_host_key']);

        $data['host'] = ($data['host'] ?? '') !== '' ? $data['host'] : 'localhost';
        $data['socket'] = ($data['socket'] ?? '') !== '' ? $data['socket'] : null;
        $data['ssh_enabled'] = (bool) ($data['ssh_enabled'] ?? false);
        $data['ssh_host'] = ($data['ssh_host'] ?? '') !== '' ? $data['ssh_host'] : null;
        $data['ssh_port'] = (int) ($data['ssh_port'] ?? 0) ?: 22;
        $data['ssh_user'] = ($data['ssh_user'] ?? '') !== '' ? $data['ssh_user'] : null;

        $connection->fill($data);

        $credentialsChanged = $connection->isDirty(['host', 'port', 'username', 'socket', 'ssh_enabled', 'ssh_host', 'ssh_port', 'ssh_user']);
        if ($password !== null && $password !== '') {
            $connection->password = $password;
            $credentialsChanged = true;
        }

        // A different SSH server must be trusted again by the next test.
        if ($forgetHostKey || $connection->isDirty(['ssh_host', 'ssh_port'])) {
            $connection->ssh_host_key = null;
        }

        if ($connection->ssh_enabled && ($connection->ssh_private_key ?? '') === '') {
            $pair = $this->keys->generate('backup-manager-'.Str::slug($connection->name));
            $connection->ssh_private_key = $pair['private'];
            $connection->ssh_public_key = $pair['public'];
        }

        $changed = array_keys($connection->getDirty());
        $connection->save();

        $meta = ['name' => $connection->name, 'changed' => array_values(array_diff($changed, ['password', 'ssh_private_key', 'updated_at', 'created_at']))];

        if ($isNew) {
            $this->audit->log(AuditAction::ConnectionCreated, $connection, $meta);
        } else {
            $this->audit->log(AuditAction::ConnectionUpdated, $connection, $meta);
            if ($credentialsChanged) {
                $this->audit->log(AuditAction::ConnectionCredentialsChanged, $connection, ['name' => $connection->name]);
            }
        }

        return $connection;
    }
}
