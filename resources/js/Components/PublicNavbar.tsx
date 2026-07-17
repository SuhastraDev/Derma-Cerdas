import { Link, usePage } from '@inertiajs/react';
import { Lock, Menu, ShieldCheck, X } from 'lucide-react';
import { useState } from 'react';

const navItems = [
    { label: 'Home', routeName: 'home', path: '/' },
    { label: 'Cara Kerja', routeName: 'how-it-works', path: '/cara-kerja' },
    { label: 'Konsultasi', routeName: 'consultation.start', path: '/konsultasi' },
    { label: 'Riwayat', routeName: 'consultation.history', path: '/riwayat' },
    { label: 'About', routeName: 'about', path: '/about' },
];

export default function PublicNavbar() {
    const [isOpen, setIsOpen] = useState(false);
    const { url } = usePage();

    const isActive = (path: string) => {
        const currentPath = url.split('?')[0];

        return path === '/' ? currentPath === '/' : currentPath.startsWith(path);
    };

    return (
        <header className="sticky inset-x-0 top-4 z-30 flex w-full px-2 text-sm">
            <div className="relative mx-auto w-full max-w-[85rem] rounded-[36px] border border-yellow-100/70 bg-yellow-50/85 px-3 py-3 shadow-sm backdrop-blur-md md:px-5">
                <div className="flex items-center justify-between gap-3">
                    <Link href={route('home')} className="flex min-w-0 items-center gap-3">
                        <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-neutral-900 text-orange-300 shadow-sm">
                            <ShieldCheck className="h-5 w-5" />
                        </span>
                        <span className="min-w-0">
                            <span className="block text-sm font-semibold text-slate-950">
                                DermaCerdas
                            </span>
                            <span className="block truncate text-xs text-slate-500">
                                Skrining awal kulit ringan
                            </span>
                        </span>
                    </Link>

                    <nav className="hidden items-center gap-1 rounded-full bg-white/45 p-1 text-sm font-semibold lg:flex">
                        {navItems.map((item) => (
                            <Link
                                key={item.routeName}
                                href={route(item.routeName)}
                                className={`inline-flex min-h-10 items-center rounded-full px-4 transition ${
                                    isActive(item.path)
                                        ? 'bg-neutral-950 text-orange-300 shadow-sm'
                                        : 'text-neutral-600 hover:bg-white hover:text-neutral-950'
                                }`}
                            >
                                {item.label}
                            </Link>
                        ))}
                    </nav>

                    <div className="flex items-center gap-2">
                        <Link
                            href={route('login')}
                            aria-label="Masuk admin"
                            title="Masuk admin"
                            className="inline-flex h-10 w-10 items-center justify-center rounded-full bg-neutral-900 text-orange-300 shadow-sm transition hover:bg-orange-500 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-400 focus-visible:ring-offset-2"
                        >
                            <Lock className="h-4 w-4" />
                        </Link>
                        <button
                            type="button"
                            onClick={() => setIsOpen((value) => !value)}
                            aria-label={isOpen ? 'Tutup menu' : 'Buka menu'}
                            className="inline-flex h-10 w-10 items-center justify-center rounded-full border border-neutral-200 bg-white text-neutral-800 shadow-sm transition hover:bg-neutral-100 lg:hidden"
                        >
                            {isOpen ? <X className="h-5 w-5" /> : <Menu className="h-5 w-5" />}
                        </button>
                    </div>
                </div>

                {isOpen && (
                    <nav className="mt-3 grid gap-1 rounded-[24px] border border-yellow-100 bg-white/80 p-2 text-sm font-semibold shadow-sm lg:hidden">
                        {navItems.map((item) => (
                            <Link
                                key={item.routeName}
                                href={route(item.routeName)}
                                onClick={() => setIsOpen(false)}
                                className={`rounded-2xl px-4 py-3 transition ${
                                    isActive(item.path)
                                        ? 'bg-neutral-950 text-orange-300'
                                        : 'text-neutral-700 hover:bg-yellow-50 hover:text-neutral-950'
                                }`}
                            >
                                {item.label}
                            </Link>
                        ))}
                    </nav>
                )}
            </div>
        </header>
    );
}
