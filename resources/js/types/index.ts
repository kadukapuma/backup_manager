import { LucideIcon } from 'lucide-react';

export type Permission =
    | 'panel.view'
    | 'databases.operate'
    | 'backups.run'
    | 'backups.restore'
    | 'backups.download'
    | 'backups.delete'
    | 'config.manage'
    | 'settings.manage'
    | 'users.manage'
    | 'audit.view';

export type RoleName = 'admin' | 'operator' | 'viewer';

export interface Auth {
    user: User;
    permissions: Permission[];
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    permission?: Permission;
}

export interface Flash {
    success: string | null;
    error: string | null;
    status: string | null;
}

export interface SharedData {
    name: string;
    auth: Auth;
    flash: Flash;
    errors: Record<string, string>;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    role: RoleName | null;
    two_factor_enabled: boolean;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface Option {
    value: string;
    label: string;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginationLink[];
}

export type TestStatus = 'ok' | 'failed';
