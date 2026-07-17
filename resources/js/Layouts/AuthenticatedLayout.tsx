import Dropdown from '@/Components/Dropdown';
import { Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    BookOpen,
    ChevronDown,
    Database,
    HeartPulse,
    Home,
    LayoutDashboard,
    LogOut,
    Menu,
    Pill,
    Search,
    Settings,
    ShieldCheck,
    Stethoscope,
    UserRound,
    X,
} from 'lucide-react';
import { PropsWithChildren, ReactNode, useState } from 'react';

const dashboardLink = {
    href: 'dashboard',
    label: 'Dashboard',
    icon: LayoutDashboard,
    match: 'dashboard',
};

const adminMenuGroups = [
    {
        label: 'Data medis',
        items: [
            {
                href: 'admin.resource.index',
                params: 'diseases',
                label: 'Penyakit',
                icon: Stethoscope,
                match: 'admin.resource.index',
                resource: 'diseases',
            },
            {
                href: 'admin.resource.index',
                params: 'symptoms',
                label: 'Gejala',
                icon: HeartPulse,
                match: 'admin.resource.index',
                resource: 'symptoms',
            },
            {
                href: 'admin.resource.index',
                params: 'rules',
                label: 'Rule CF',
                icon: BookOpen,
                match: 'admin.resource.index',
                resource: 'rules',
            },
            {
                href: 'admin.resource.index',
                params: 'red-flags',
                label: 'Red flags',
                icon: AlertTriangle,
                match: 'admin.resource.index',
                resource: 'red-flags',
            },
        ],
    },
    {
        label: 'Terapi',
        items: [
            {
                href: 'admin.resource.index',
                params: 'medicines',
                label: 'Obat',
                icon: Pill,
                match: 'admin.resource.index',
                resource: 'medicines',
            },
            {
                href: 'admin.resource.index',
                params: 'recommendations',
                label: 'Rekomendasi',
                icon: ShieldCheck,
                match: 'admin.resource.index',
                resource: 'recommendations',
            },
        ],
    },
    {
        label: 'Sistem',
        items: [
            {
                href: 'admin.resource.index',
                params: 'dataset-mappings',
                label: 'Dataset',
                icon: Database,
                match: 'admin.resource.index',
                resource: 'dataset-mappings',
            },
            {
                href: 'admin.resource.index',
                params: 'settings',
                label: 'Pengaturan',
                icon: Settings,
                match: 'admin.resource.index',
                resource: 'settings',
            },
            {
                href: 'admin.resource.index',
                params: 'consultations',
                label: 'Riwayat',
                icon: ShieldCheck,
                match: 'admin.resource.index',
                resource: 'consultations',
            },
        ],
    },
];

export default function Authenticated({
    header,
    children,
}: PropsWithChildren<{ header?: ReactNode }>) {
    const user = usePage().props.auth.user;
    const routeParams = route().params as { resource?: string };
    const resource = routeParams.resource;

    const [showingNavigationDropdown, setShowingNavigationDropdown] =
        useState(false);
    const isMenuActive = (item: (typeof dashboardLink) & { resource?: string }) =>
        route().current(item.match) && (!item.resource || resource === item.resource);

    return (
        <div className="min-h-screen bg-[#f7fafc] text-[#1b2124]">
            <nav className="sticky inset-x-0 top-0 z-30 flex w-full border-b border-[#dbe6eb] bg-white/90 px-3 py-3 text-sm backdrop-blur-md">
                <div className="relative mx-auto w-full max-w-[85rem]">
                    <div className="flex items-center justify-between gap-4">
                        <Link
                            href={route('dashboard')}
                            className="flex min-w-0 items-center gap-3"
                        >
                            <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-[#eaf3fd] text-[#3385f0] ring-1 ring-[#c6ddfb]">
                                <ShieldCheck className="h-5 w-5" />
                            </span>
                            <span className="min-w-0">
                                <span className="block text-sm font-bold text-[#1b2124]">
                                    DermaCerdas
                                </span>
                                <span className="block truncate text-xs text-[#77878f]">
                                    Admin panel
                                </span>
                            </span>
                        </Link>

                        <div className="hidden min-w-0 flex-1 items-center justify-center gap-3 lg:flex">
                            <div className="hidden w-full max-w-[280px] items-center gap-2 rounded-lg border border-[#dbe6eb] bg-[#f7fafc] px-3 py-2 text-[#77878f] xl:flex">
                                <Search className="h-4 w-4" />
                                <span className="truncate text-xs font-medium">
                                    Cari modul admin
                                </span>
                            </div>

                            <nav className="flex max-w-full items-center gap-1 text-sm font-semibold">
                                {(() => {
                                    const Icon = dashboardLink.icon;
                                    const active = isMenuActive(dashboardLink);

                                    return (
                                        <Link
                                            href={route(dashboardLink.href)}
                                            className={`inline-flex min-h-10 shrink-0 items-center gap-2 rounded-lg px-3 transition ${
                                                active
                                                    ? 'bg-[#3385f0] text-white shadow-sm'
                                                    : 'text-[#4d595e] hover:bg-[#ebf2f5] hover:text-[#1b2124]'
                                            }`}
                                        >
                                            <Icon className="h-4 w-4" />
                                            {dashboardLink.label}
                                        </Link>
                                    );
                                })()}

                                {adminMenuGroups.map((group) => {
                                    const active = group.items.some((item) =>
                                        isMenuActive(item),
                                    );

                                    return (
                                        <div
                                            key={group.label}
                                            className="group relative"
                                        >
                                            <button
                                                type="button"
                                                className={`inline-flex min-h-10 shrink-0 items-center gap-2 rounded-lg px-3 transition ${
                                                    active
                                                        ? 'bg-[#3385f0] text-white shadow-sm'
                                                        : 'text-[#4d595e] hover:bg-[#ebf2f5] hover:text-[#1b2124]'
                                                }`}
                                            >
                                                {group.label}
                                                <ChevronDown className="h-4 w-4 transition group-hover:rotate-180" />
                                            </button>

                                            <div className="invisible absolute left-0 top-full z-50 pt-2 opacity-0 transition duration-150 group-hover:visible group-hover:opacity-100 group-focus-within:visible group-focus-within:opacity-100">
                                                <div className="w-72 rounded-lg bg-white p-2 shadow-lg ring-1 ring-[#dbe6eb]">
                                                    <div className="grid gap-1">
                                                        {group.items.map((item) => {
                                                            const Icon = item.icon;
                                                            const href = item.params
                                                                ? route(
                                                                      item.href,
                                                                      item.params,
                                                                  )
                                                                : route(item.href);
                                                            const itemActive =
                                                                isMenuActive(item);

                                                            return (
                                                                <Link
                                                                    key={`${group.label}-${item.label}`}
                                                                    href={href}
                                                                    className={`flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-semibold transition ${
                                                                        itemActive
                                                                            ? 'bg-[#eaf3fd] text-[#245da8]'
                                                                            : 'text-[#4d595e] hover:bg-[#f7fafc] hover:text-[#1b2124]'
                                                                    }`}
                                                                >
                                                                    <span
                                                                        className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${
                                                                            itemActive
                                                                                ? 'bg-white text-[#3385f0]'
                                                                                : 'bg-[#ebf2f5] text-[#77878f]'
                                                                        }`}
                                                                    >
                                                                        <Icon className="h-4 w-4" />
                                                                    </span>
                                                                    {item.label}
                                                                </Link>
                                                            );
                                                        })}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </nav>
                        </div>

                        <div className="hidden items-center gap-1 text-sm font-semibold xl:flex">
                            <Link
                                href={route('home')}
                                className="inline-flex min-h-10 items-center gap-2 rounded-lg px-3 text-[#4d595e] transition hover:bg-[#ebf2f5] hover:text-[#1b2124]"
                            >
                                <Home className="h-4 w-4" />
                                Lihat web
                            </Link>
                        </div>

                        <div className="flex items-center gap-2">
                            <div className="hidden sm:block">
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <span className="inline-flex rounded-lg">
                                            <button
                                                type="button"
                                                className="inline-flex h-10 items-center gap-2 rounded-lg bg-[#1b2124] px-3 text-sm font-semibold text-white shadow-sm transition hover:bg-[#3385f0] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#3385f0] focus-visible:ring-offset-2"
                                            >
                                                <UserRound className="h-4 w-4" />
                                                <span className="max-w-32 truncate">
                                                    {user.name}
                                                </span>
                                            </button>
                                        </span>
                                    </Dropdown.Trigger>

                                    <Dropdown.Content>
                                        <Dropdown.Link href={route('profile.edit')}>
                                            Profile
                                        </Dropdown.Link>
                                        <Dropdown.Link
                                            href={route('logout')}
                                            method="post"
                                            as="button"
                                        >
                                            Log Out
                                        </Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>
                            </div>

                            <button
                                type="button"
                                onClick={() =>
                                    setShowingNavigationDropdown(
                                        (previousState) => !previousState,
                                    )
                                }
                                aria-label={
                                    showingNavigationDropdown
                                        ? 'Tutup menu admin'
                                        : 'Buka menu admin'
                                }
                                className="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-[#dbe6eb] bg-white text-[#1b2124] shadow-sm transition hover:bg-[#ebf2f5] lg:hidden"
                            >
                                {showingNavigationDropdown ? (
                                    <X className="h-5 w-5" />
                                ) : (
                                    <Menu className="h-5 w-5" />
                                )}
                            </button>
                        </div>
                    </div>

                    {showingNavigationDropdown && (
                        <div className="mt-3 rounded-lg border border-[#dbe6eb] bg-white p-2 shadow-sm lg:hidden">
                            <div className="grid gap-1 text-sm font-semibold">
                                {(() => {
                                    const Icon = dashboardLink.icon;
                                    const active = isMenuActive(dashboardLink);

                                    return (
                                        <Link
                                            href={route(dashboardLink.href)}
                                            className={`flex items-center gap-3 rounded-lg px-4 py-3 transition ${
                                                active
                                                    ? 'bg-[#3385f0] text-white'
                                                    : 'text-[#4d595e] hover:bg-[#f7fafc] hover:text-[#1b2124]'
                                            }`}
                                        >
                                            <Icon className="h-4 w-4" />
                                            {dashboardLink.label}
                                        </Link>
                                    );
                                })()}

                                {adminMenuGroups.map((group) => (
                                    <div
                                        key={group.label}
                                        className="rounded-lg border border-[#dbe6eb] bg-[#f7fafc] p-2"
                                    >
                                        <p className="px-2 pb-1 text-xs font-bold uppercase tracking-wide text-[#77878f]">
                                            {group.label}
                                        </p>
                                        <div className="grid gap-1">
                                            {group.items.map((item) => {
                                                const Icon = item.icon;
                                                const href = item.params
                                                    ? route(item.href, item.params)
                                                    : route(item.href);
                                                const active = isMenuActive(item);

                                                return (
                                                    <Link
                                                        key={`${group.label}-${item.label}`}
                                                        href={href}
                                                        className={`flex items-center gap-3 rounded-lg px-3 py-2.5 transition ${
                                                            active
                                                                ? 'bg-[#eaf3fd] text-[#245da8]'
                                                                : 'text-[#4d595e] hover:bg-white hover:text-[#1b2124]'
                                                        }`}
                                                    >
                                                        <Icon className="h-4 w-4" />
                                                        {item.label}
                                                    </Link>
                                                );
                                            })}
                                        </div>
                                    </div>
                                ))}
                                <Link
                                    href={route('home')}
                                    className="flex items-center gap-3 rounded-lg px-4 py-3 text-[#4d595e] transition hover:bg-[#f7fafc] hover:text-[#1b2124]"
                                >
                                    <Home className="h-4 w-4" />
                                    Lihat web
                                </Link>
                                <Link
                                    href={route('profile.edit')}
                                    className="flex items-center gap-3 rounded-lg px-4 py-3 text-[#4d595e] transition hover:bg-[#f7fafc] hover:text-[#1b2124]"
                                >
                                    <UserRound className="h-4 w-4" />
                                    Profile
                                </Link>
                                <Link
                                    href={route('logout')}
                                    method="post"
                                    as="button"
                                    className="flex items-center gap-2 rounded-lg px-4 py-3 text-left text-red-700 transition hover:bg-red-50"
                                >
                                    <LogOut className="h-4 w-4" />
                                    Log Out
                                </Link>
                            </div>
                            <div className="mt-2 border-t border-[#dbe6eb] px-4 py-3">
                                <p className="truncate text-sm font-semibold text-[#1b2124]">
                                    {user.name}
                                </p>
                                <p className="truncate text-xs text-[#77878f]">
                                    {user.email}
                                </p>
                            </div>
                        </div>
                    )}
                </div>
            </nav>

            {header && (
                <header className="mx-auto mt-6 max-w-[85rem] px-4 sm:px-6 lg:px-8">
                    <div className="rounded-lg border border-[#dbe6eb] bg-white p-5 shadow-sm">
                        {header}
                    </div>
                </header>
            )}

            <main>{children}</main>
        </div>
    );
}
