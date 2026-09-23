<?php

declare(strict_types=1);

namespace App\Enums;

enum Permission: string
{
    use HasOptions;

    case ViewPanel = 'panel.view';
    case OperateDatabases = 'databases.operate';
    case RunBackups = 'backups.run';
    case Restore = 'backups.restore';
    case Download = 'backups.download';
    case DeleteBackups = 'backups.delete';
    case ManageConfig = 'config.manage';
    case ManageSettings = 'settings.manage';
    case ManageUsers = 'users.manage';
    case ViewAudit = 'audit.view';
}
