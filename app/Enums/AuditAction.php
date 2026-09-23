<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditAction: string
{
    use HasOptions;

    case Login = 'auth.login';
    case LoginFailed = 'auth.login_failed';
    case Logout = 'auth.logout';
    case TwoFactorEnabled = 'auth.2fa_enabled';
    case TwoFactorDisabled = 'auth.2fa_disabled';

    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserDeleted = 'user.deleted';
    case UserTwoFactorReset = 'user.2fa_reset';

    case ConnectionCreated = 'connection.created';
    case ConnectionUpdated = 'connection.updated';
    case ConnectionDeleted = 'connection.deleted';
    case ConnectionCredentialsChanged = 'connection.credentials_changed';
    case ConnectionTested = 'connection.tested';

    case DiscoveryRequested = 'discovery.requested';
    case DatabaseStateChanged = 'database.state_changed';

    case RuleCreated = 'rule.created';
    case RuleUpdated = 'rule.updated';
    case RuleDeleted = 'rule.deleted';

    case DestinationCreated = 'destination.created';
    case DestinationUpdated = 'destination.updated';
    case DestinationDeleted = 'destination.deleted';
    case DestinationCredentialsChanged = 'destination.credentials_changed';
    case DestinationTested = 'destination.tested';

    case PlanCreated = 'plan.created';
    case PlanUpdated = 'plan.updated';
    case PlanDeleted = 'plan.deleted';

    case BackupRunStarted = 'backup.run_started';
    case BackupRunFinished = 'backup.run_finished';
    case BackupDownloaded = 'backup.downloaded';
    case BackupDeleted = 'backup.deleted';
    case RetentionApplied = 'backup.retention_applied';

    case RestoreRequested = 'restore.requested';
    case RestoreFinished = 'restore.finished';

    case NotificationChannelCreated = 'notification.created';
    case NotificationChannelUpdated = 'notification.updated';
    case NotificationChannelDeleted = 'notification.deleted';
    case NotificationTestSent = 'notification.test_sent';

    case SettingsChanged = 'settings.changed';
    case CatalogRebuildRequested = 'catalog.rebuild_requested';
    case CatalogRebuilt = 'catalog.rebuilt';
    case AccessDeniedByIp = 'security.ip_denied';
}
