import PublicFooter from '@/Components/PublicFooter';
import PublicNavbar from '@/Components/PublicNavbar';
import { PropsWithChildren } from 'react';

export default function PublicLayout({ children }: PropsWithChildren) {
    return (
        <main className="min-h-screen bg-neutral-100 text-neutral-950">
            <PublicNavbar />
            <div className="page-transition">{children}</div>
            <PublicFooter />
        </main>
    );
}
