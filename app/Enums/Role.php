<?php

declare(strict_types=1);

namespace App\Enums;

enum Role: string
{
    use HasOptions;

    case Admin = 'admin';
    case Operator = 'operator';
    case Viewer = 'viewer';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Operator => [
                Permission::ViewPanel,
                Permission::OperateDatabases,
                Permission::RunBackups,
                Permission::Restore,
            ],
            self::Viewer => [Permission::ViewPanel],
        };
    }
}
