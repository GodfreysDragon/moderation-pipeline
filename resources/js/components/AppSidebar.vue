<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    Images,
    LayoutGrid,
    ListChecks,
    SlidersHorizontal,
    Upload,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { edit as policy } from '@/routes/policy';
import { index as review } from '@/routes/review';
import { create as submit, index as submissions } from '@/routes/submissions';
import type { NavItem } from '@/types';

const page = usePage();

const mainNavItems = computed<NavItem[]>(() => {
    const staff = page.props.auth.can.moderate;

    return [
        ...(staff
            ? [{ title: 'Dashboard', href: dashboard(), icon: LayoutGrid }]
            : []),
        { title: 'Submit content', href: submit(), icon: Upload },
        {
            title: staff ? 'All submissions' : 'My submissions',
            href: submissions(),
            icon: Images,
        },
        ...(staff
            ? [
                  {
                      title: 'Review queue',
                      href: review(),
                      icon: ListChecks,
                      badge: page.props.queueCount,
                  },
                  { title: 'Policy', href: policy(), icon: SlidersHorizontal },
              ]
            : []),
    ];
});

const footerNavItems: NavItem[] = [
    {
        title: 'Laravel docs',
        href: 'https://laravel.com/docs',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
