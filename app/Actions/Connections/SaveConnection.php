<?php

declare(strict_types=1);

namespace App\Actions\Connections;

use App\Enums\AuditAction;
use App\Models\ServerConnection;
use App\Services\Audit\AuditLogger;

class SaveConnection
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function handle(array $data, ?ServerConnection $connection = null): ServerConnection
    {
        $isNew = $connection === null;
        $connection ??= new ServerConnection;

        $password = $data['password'] ?? null;
        unset($data['password']);

        $data['host'] = ($data['host'] ?? '') !== '' ? $data['host'] : 'localhost';
        $data['socket'] = ($data['socket'] ?? '') !== '' ? $data['socket'] : null;

        $connection->fill($data);

        $credentialsChanged = $connection->isDirty(['host', 'port', 'username', 'socket']);
        if ($password !== null && $password !== '') {
            $connection->password = $password;
            $credentialsChanged = true;
        }

        $changed = array_keys($connection->getDirty());
        $connection->save();

        $meta = ['name' => $connection->name, 'changed' => array_values(array_diff($changed, ['password', 'updated_at', 'created_at']))];

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
