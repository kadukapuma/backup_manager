import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useCan } from '@/hooks/use-can';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { Bell, CalendarClock, Database, Filter, HardDrive, History, LayoutGrid, RotateCcw, ScrollText, Server, Settings2, Users } from 'lucide-react';
import AppLogo from './app-logo';

const backupNavItems: NavItem[] = [
    { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid, permission: 'panel.view' },
    { title: 'Databases', url: '/databases', icon: Database, permission: 'panel.view' },
    { title: 'Runs', url: '/runs', icon: History, permission: 'panel.view' },
    { title: 'Restore', url: '/restores', icon: RotateCcw, permission: 'panel.view' },
];

const configNavItems: NavItem[] = [
    { title: 'Connections', url: '/connections', icon: Server, permission: 'panel.view' },
    { title: 'Selection rules', url: '/rules', icon: Filter, permission: 'panel.view' },
    { title: 'Destinations', url: '/destinations', icon: HardDrive, permission: 'panel.view' },
    { title: 'Backup plans', url: '/plans', icon: CalendarClock, permission: 'panel.view' },
    { title: 'Notifications', url: '/notifications', icon: Bell, permission: 'panel.view' },
];

const adminNavItems: NavItem[] = [
    { title: 'Users & roles', url: '/users', icon: Users, permission: 'users.manage' },
    { title: 'Audit log', url: '/audit-log', icon: ScrollText, permission: 'audit.view' },
    { title: 'System settings', url: '/system', icon: Settings2, permission: 'panel.view' },
];

export function AppSidebar() {
    const can = useCan();
    const visible = (items: NavItem[]) => items.filter((item) => !item.permission || can(item.permission));

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain title="Backups" items={visible(backupNavItems)} />
                <NavMain title="Configuration" items={visible(configNavItems)} />
                <NavMain title="Administration" items={visible(adminNavItems)} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
