import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { History, Images, KeyRound, Plug, UserRound, Wand2, Workflow } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    { title: 'Flows', url: '/workflows', icon: Workflow },
    { title: 'Runs', url: '/runs', icon: History },
    { title: 'Characters', url: '/characters', icon: UserRound },
    { title: 'Prompt writer', url: '/prompt-writer', icon: Wand2 },
    { title: 'Assets', url: '/assets', icon: Images },
    { title: 'Connectors', url: '/connectors', icon: Plug },
    { title: 'AI keys & models', url: '/keys', icon: KeyRound },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/workflows" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
