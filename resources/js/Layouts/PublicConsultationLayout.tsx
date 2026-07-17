import { Link } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';

type PublicConsultationLayoutProps = PropsWithChildren<{
    title?: string;
}>;

export default function PublicConsultationLayout({
    title = 'DermaCerdas',
    children,
}: PublicConsultationLayoutProps) {
    return (
        <div className="min-h-screen bg-slate-50 text-slate-950">
            <header className="border-b border-slate-200 bg-white">
                <div className="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
                    <Link href="/" className="text-lg font-semibold text-emerald-700">
                        DermaCerdas
                    </Link>
                    <span className="text-sm text-slate-500">{title}</span>
                </div>
            </header>

            <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                {children}
            </main>
        </div>
    );
}
