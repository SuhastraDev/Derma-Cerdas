import PublicLayout from '@/Layouts/PublicLayout';
import { Head, Link } from '@inertiajs/react';
import {
    ArrowRight,
    BrainCircuit,
    Camera,
    LucideIcon,
    ShieldCheck,
    Sparkles,
} from 'lucide-react';

const heroImage =
    'https://images.pexels.com/photos/7446684/pexels-photo-7446684.jpeg?auto=compress&cs=tinysrgb&w=1400';
const skinCheckImage =
    'https://images.pexels.com/photos/7446672/pexels-photo-7446672.jpeg?auto=compress&cs=tinysrgb&w=900';

const knowledgeCards: Array<{ icon: LucideIcon; title: string; body: string }> = [
    {
        icon: Camera,
        title: 'Foto jelas',
        body: 'Area keluhan terlihat, tidak buram, tanpa filter.',
    },
    {
        icon: BrainCircuit,
        title: 'Gejala terukur',
        body: 'Pengguna memilih tingkat keyakinan setiap gejala.',
    },
    {
        icon: ShieldCheck,
        title: 'Batas aman',
        body: 'Red flags selalu mengutamakan rujukan.',
    },
];

export default function Home() {
    return (
        <PublicLayout>
            <Head title="Home" />

            <section className="mx-auto grid max-w-[85rem] items-center gap-10 px-4 py-10 lg:min-h-[calc(100vh-118px)] lg:grid-cols-[1fr_0.92fr] lg:px-8">
                <div>
                    <div className="inline-flex items-center gap-2 rounded-full border border-yellow-200 bg-yellow-50 px-3 py-1 text-xs font-semibold text-orange-700">
                        <Sparkles className="h-4 w-4" />
                        Pengetahuan awal sebelum panik
                    </div>
                    <h1 className="mt-6 max-w-4xl text-4xl font-semibold leading-tight text-neutral-950 sm:text-5xl lg:text-6xl">
                        Kenali keluhan kulit ringan dengan alur yang lebih tenang dan terarah.
                    </h1>
                    <p className="mt-5 max-w-2xl text-base leading-8 text-neutral-600">
                        DermaCerdas membantu pengguna memahami foto area keluhan, gejala yang
                        dirasakan, dan tanda bahaya sebelum mengambil keputusan. Fokusnya adalah
                        edukasi, skrining awal, dan rujukan aman jika ada risiko.
                    </p>
                    <div className="mt-7 flex flex-col gap-3 sm:flex-row">
                        <Link
                            href={route('consultation.start')}
                            className="inline-flex min-h-12 items-center justify-center gap-2 rounded-full bg-orange-400 px-6 text-sm font-bold text-white shadow-sm transition hover:bg-orange-500"
                        >
                            Mulai konsultasi
                            <ArrowRight className="h-4 w-4" />
                        </Link>
                        <Link
                            href={route('how-it-works')}
                            className="inline-flex min-h-12 items-center justify-center rounded-full border border-neutral-300 bg-white px-6 text-sm font-semibold text-neutral-800 transition hover:bg-neutral-50"
                        >
                            Lihat cara kerja
                        </Link>
                    </div>
                </div>

                <div className="overflow-hidden rounded-[30px] border border-neutral-200 bg-white p-3 shadow-sm">
                    <img
                        src={heroImage}
                        alt="Konsultasi kulit menggunakan tablet di klinik"
                        className="h-[360px] w-full rounded-[22px] object-cover sm:h-[480px]"
                    />
                </div>
            </section>

            <section className="border-y border-neutral-200 bg-white">
                <div className="mx-auto grid max-w-[85rem] gap-8 px-4 py-16 lg:grid-cols-[0.9fr_1.1fr] lg:px-8">
                    <div className="overflow-hidden rounded-2xl border border-neutral-200">
                        <img
                            src={skinCheckImage}
                            alt="Pemeriksaan kulit dengan perangkat digital"
                            className="h-full min-h-80 w-full object-cover"
                        />
                    </div>
                    <div className="grid content-center gap-5">
                        <p className="text-sm font-semibold text-orange-600">Pengetahuan dasar</p>
                        <h2 className="text-3xl font-semibold text-neutral-950">
                            Foto yang baik dan gejala yang jujur menentukan kualitas hasil.
                        </h2>
                        <div className="grid gap-3 md:grid-cols-3">
                            {knowledgeCards.map(({ icon: Icon, title, body }) => (
                                <article
                                    key={title}
                                    className="rounded-2xl border border-neutral-200 bg-neutral-50 p-4"
                                >
                                    <Icon className="h-5 w-5 text-orange-600" />
                                    <h3 className="mt-4 font-semibold text-neutral-950">{title}</h3>
                                    <p className="mt-2 text-sm leading-6 text-neutral-600">{body}</p>
                                </article>
                            ))}
                        </div>
                    </div>
                </div>
            </section>
        </PublicLayout>
    );
}
