import { Link } from '@inertiajs/react';
import { ExternalLink, Lock, Mail, MapPin, ShieldCheck } from 'lucide-react';

const footerLinks = [
    { label: 'Home', routeName: 'home' },
    { label: 'Cara Kerja', routeName: 'how-it-works' },
    { label: 'Konsultasi', routeName: 'consultation.start' },
    { label: 'Riwayat', routeName: 'consultation.history' },
    { label: 'About', routeName: 'about' },
];

export default function PublicFooter() {
    return (
        <footer className="border-t border-neutral-200 bg-neutral-950 text-white">
            <div className="mx-auto grid max-w-[85rem] gap-8 px-4 py-10 lg:grid-cols-[1fr_0.9fr_auto] lg:px-8">
                <div>
                    <div className="flex items-center gap-3">
                        <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-orange-400 text-neutral-950">
                            <ShieldCheck className="h-5 w-5" />
                        </span>
                        <span>
                            <span className="block font-semibold">DermaCerdas</span>
                            <span className="block text-sm text-neutral-400">
                                Skrining awal kulit ringan berbasis foto, gejala, dan safety gate.
                            </span>
                        </span>
                    </div>
                    <p className="mt-5 max-w-2xl text-sm leading-6 text-neutral-400">
                        Hasil sistem bersifat edukasi awal dan tidak menggantikan pemeriksaan dokter.
                        Segera cari bantuan medis bila ada tanda bahaya atau keluhan memburuk.
                    </p>
                </div>

                <div className="grid gap-3 text-sm text-neutral-300">
                    <a
                        href="mailto:hello@dermacerdas.local"
                        className="inline-flex items-center gap-3 transition hover:text-orange-300"
                    >
                        <Mail className="h-4 w-4 text-orange-300" />
                        hello@dermacerdas.local
                    </a>
                    <span className="inline-flex items-center gap-3">
                        <MapPin className="h-4 w-4 text-orange-300" />
                        Indonesia
                    </span>
                    <div className="flex flex-wrap gap-2 pt-1">
                        <a
                            href="https://www.linkedin.com/"
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/10 transition hover:border-orange-300 hover:text-orange-300"
                            aria-label="LinkedIn DermaCerdas"
                        >
                            <ExternalLink className="h-4 w-4" />
                        </a>
                        <a
                            href="https://github.com/SuhastraDev/Derma-Cerdas"
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/10 transition hover:border-orange-300 hover:text-orange-300"
                            aria-label="GitHub DermaCerdas"
                        >
                            <ExternalLink className="h-4 w-4" />
                        </a>
                    </div>
                </div>

                <div className="flex flex-wrap items-start gap-3 text-sm font-semibold lg:justify-end">
                    {footerLinks.map((item) => (
                        <Link
                            key={item.routeName}
                            href={route(item.routeName)}
                            className="rounded-full border border-white/10 px-4 py-2 text-neutral-300 transition hover:border-orange-300 hover:text-orange-300"
                        >
                            {item.label}
                        </Link>
                    ))}
                    <Link
                        href={route('login')}
                        aria-label="Masuk admin"
                        className="inline-flex h-10 w-10 items-center justify-center rounded-full border border-white/10 text-orange-300 transition hover:border-orange-300 hover:bg-orange-400 hover:text-neutral-950"
                    >
                        <Lock className="h-4 w-4" />
                    </Link>
                </div>
            </div>
        </footer>
    );
}
