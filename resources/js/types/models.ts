import { type TestStatus } from '@/types';

export type DatabaseStateValue = 'included' | 'excluded' | 'pending';
export type StateSourceValue = 'manual' | 'rule' | 'policy';
export type NewDatabasePolicyValue = 'auto_include' | 'pending';
export type RuleTypeValue = 'include' | 'exclude';
export type DestinationTypeValue = 'local' | 'sftp' | 's3' | 'google_drive' | 'ftp' | 'onedrive';
export type RunStatusValue = 'queued' | 'running' | 'success' | 'partial' | 'failed';
export type RunTriggerValue = 'scheduled' | 'manual' | 'pre_restore';
export type BackupFileStatusValue = 'queued' | 'running' | 'success' | 'failed';
export type CopyStatusValue = 'pending' | 'uploaded' | 'verified' | 'failed' | 'deleted';
export type RestoreModeValue = 'replace' | 'new_copy';
export type RestoreStatusValue = 'queued' | 'safety_backup' | 'downloading' | 'verifying' | 'restoring' | 'post_check' | 'success' | 'failed';

export interface ConnectionRow {
    id: number;
    name: string;
    driver: string;
    host: string;
    port: number;
    username: string;
    password_set: boolean;
    socket: string | null;
    new_database_policy: NewDatabasePolicyValue;
    is_active: boolean;
    last_tested_at: string | null;
    last_test_status: TestStatus | null;
    last_test_message: string | null;
    last_discovered_at: string | null;
    databases_count: number;
    included_count: number;
    pending_count: number;
}

export interface DatabaseRow {
    id: number;
    name: string;
    connection_id: number;
    connection_name: string;
    state: DatabaseStateValue;
    state_source: StateSourceValue;
    size_bytes: number;
    table_count: number;
    first_seen_at: string | null;
    last_seen_at: string | null;
    missing_since: string | null;
    last_backup_at: string | null;
    last_backup_size: number | null;
}

export interface RuleRow {
    id: number;
    connection_id: number;
    type: RuleTypeValue;
    pattern: string;
    priority: number;
    is_active: boolean;
    match_count: number;
    decides_count: number;
    sample: string[];
}

export interface RuleGroup {
    connection: { id: number; name: string; policy: NewDatabasePolicyValue };
    unmatched: number;
    rules: RuleRow[];
}
