import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { type PropsWithChildren, type ReactNode } from 'react';

type AdminLayoutProps = PropsWithChildren<{
    header?: ReactNode;
}>;

export default function AdminLayout({ header, children }: AdminLayoutProps) {
    return (
        <AuthenticatedLayout header={header}>
            <main className="mx-auto max-w-[85rem] px-4 py-6 sm:px-6 lg:px-8">
                {children}
            </main>
        </AuthenticatedLayout>
    );
}
