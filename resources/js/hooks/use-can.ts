import { type Permission, type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * Returns a checker for the current user's permissions. UI hints only; every
 * action is authorized again on the server.
 */
export function useCan(): (permission: Permission) => boolean {
    const { auth } = usePage<SharedData>().props;
    return (permission) => auth.permissions.includes(permission);
}
