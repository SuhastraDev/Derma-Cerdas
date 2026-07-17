import { PageProps } from '@/types';
import PublicLayout from '@/Layouts/PublicLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Database,
    HeartPulse,
    Mail,
    MessageSquare,
    Send,
    ShieldAlert,
} from 'lucide-react';
import { FormEvent } from 'react';

const aboutImage =
    'https://images.pexels.com/photos/7089401/pexels-photo-7089401.jpeg?auto=compress&cs=tinysrgb&w=1200';

type ContactForm = {
    name: string;
    email: string;
    subject: string;
    message: string;
};

export default function About() {
    const { flash } = usePage<PageProps>().props;
    const { data, setData, post, processing, errors, reset } = useForm<ContactForm>({
        name: '',
        email: '',
        subject: '',
        message: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('contact.store'), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <PublicLayout>
            <Head title="About" />

            <section className="mx-auto grid max-w-[85rem] gap-10 px-4 py-12 lg:grid-cols-[0.95fr_1.05fr] lg:items-center lg:px-8">
                <div>
                    <p className="text-sm font-semibold text-orange-600">About DermaCerdas</p>
                    <h1 className="mt-3 text-4xl font-semibold leading-tight text-neutral-950 sm:text-5xl">
                        Dibuat untuk membantu keputusan awal, bukan menggantikan tenaga medis.
                    </h1>
                    <p className="mt-5 text-base leading-8 text-neutral-600">
                        DermaCerdas adalah web app skrining awal keluhan kulit ringan. Sistem
                        menggabungkan dataset penyakit kulit, basis pengetahuan gejala, dan batas
                        keamanan agar pengguna mendapat arahan yang lebih bertanggung jawab.
                    </p>
                    <Link
                        href={route('consultation.start')}
                        className="mt-7 inline-flex min-h-12 items-center gap-2 rounded-full bg-orange-400 px-6 text-sm font-bold text-white transition hover:bg-orange-500"
                    >
                        Coba konsultasi
                        <ArrowRight className="h-4 w-4" />
                    </Link>
                </div>
                <div className="overflow-hidden rounded-[28px] border border-neutral-200 bg-white p-3 shadow-sm">
                    <img
                        src={aboutImage}
                        alt="Diskusi pasien dan spesialis kulit menggunakan tablet"
                        className="h-[400px] w-full rounded-[20px] object-cover"
                    />
                </div>
            </section>

            <section className="border-y border-neutral-200 bg-white">
                <div className="mx-auto grid max-w-[85rem] gap-4 px-4 py-16 md:grid-cols-3 lg:px-8">
                    {[
                        {
                            title: 'Fokus penyakit ringan',
                            body: 'MVP diarahkan ke keluhan kulit ringan yang relevan untuk edukasi dan swamedikasi terbatas.',
                            icon: HeartPulse,
                        },
                        {
                            title: 'Berbasis data dan aturan',
                            body: 'Dataset class mapping, gejala, red flags, dan rekomendasi obat dikelola melalui admin.',
                            icon: Database,
                        },
                        {
                            title: 'Safety gate lebih dulu',
                            body: 'Jika ada tanda bahaya, sistem mengarahkan pengguna ke tenaga kesehatan.',
                            icon: ShieldAlert,
                        },
                    ].map((item) => {
                        const Icon = item.icon;

                        return (
                            <article
                                key={item.title}
                                className="rounded-2xl border border-neutral-200 bg-neutral-50 p-5"
                            >
                                <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-neutral-950 text-orange-300">
                                    <Icon className="h-5 w-5" />
                                </span>
                                <h2 className="mt-5 text-lg font-semibold text-neutral-950">
                                    {item.title}
                                </h2>
                                <p className="mt-3 text-sm leading-7 text-neutral-600">
                                    {item.body}
                                </p>
                            </article>
                        );
                    })}
                </div>
            </section>

            <section className="mx-auto grid max-w-[85rem] gap-8 px-4 py-16 lg:grid-cols-[0.8fr_1.2fr] lg:px-8">
                <div>
                    <p className="text-sm font-semibold text-orange-600">Kontak</p>
                    <h2 className="mt-2 text-3xl font-semibold text-neutral-950">
                        Ada masukan, pertanyaan, atau kebutuhan kerja sama?
                    </h2>
                    <p className="mt-4 text-sm leading-7 text-neutral-600">
                        Kirim pesan melalui form ini. Untuk lokal, email akan mengikuti
                        konfigurasi mail Laravel. Jika mailer masih `log`, pesan tercatat
                        di log aplikasi.
                    </p>
                    <div className="mt-6 grid gap-3 text-sm text-neutral-700">
                        <a
                            href="mailto:hello@dermacerdas.local"
                            className="inline-flex items-center gap-3 rounded-2xl border border-neutral-200 bg-white p-4 transition hover:border-yellow-300"
                        >
                            <Mail className="h-5 w-5 text-orange-600" />
                            hello@dermacerdas.local
                        </a>
                        <div className="inline-flex items-center gap-3 rounded-2xl border border-neutral-200 bg-white p-4">
                            <MessageSquare className="h-5 w-5 text-orange-600" />
                            Respon ditujukan untuk pertanyaan project, validasi data, dan feedback UX.
                        </div>
                    </div>
                </div>

                <form
                    onSubmit={submit}
                    className="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm"
                >
                    {flash.success && (
                        <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-900">
                            {flash.success}
                        </div>
                    )}

                    <div className="grid gap-4 md:grid-cols-2">
                        <label className="block">
                            <span className="text-sm font-semibold text-neutral-800">Nama</span>
                            <input
                                type="text"
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                                className="mt-2 w-full rounded-xl border-neutral-300 text-sm focus:border-orange-400 focus:ring-orange-400"
                                placeholder="Nama lengkap"
                            />
                            {errors.name && (
                                <p className="mt-1 text-sm text-red-600">{errors.name}</p>
                            )}
                        </label>
                        <label className="block">
                            <span className="text-sm font-semibold text-neutral-800">Email</span>
                            <input
                                type="email"
                                value={data.email}
                                onChange={(event) => setData('email', event.target.value)}
                                className="mt-2 w-full rounded-xl border-neutral-300 text-sm focus:border-orange-400 focus:ring-orange-400"
                                placeholder="nama@email.com"
                            />
                            {errors.email && (
                                <p className="mt-1 text-sm text-red-600">{errors.email}</p>
                            )}
                        </label>
                    </div>

                    <label className="mt-4 block">
                        <span className="text-sm font-semibold text-neutral-800">Subjek</span>
                        <input
                            type="text"
                            value={data.subject}
                            onChange={(event) => setData('subject', event.target.value)}
                            className="mt-2 w-full rounded-xl border-neutral-300 text-sm focus:border-orange-400 focus:ring-orange-400"
                            placeholder="Contoh: Feedback tampilan konsultasi"
                        />
                        {errors.subject && (
                            <p className="mt-1 text-sm text-red-600">{errors.subject}</p>
                        )}
                    </label>

                    <label className="mt-4 block">
                        <span className="text-sm font-semibold text-neutral-800">Pesan</span>
                        <textarea
                            value={data.message}
                            onChange={(event) => setData('message', event.target.value)}
                            rows={6}
                            className="mt-2 w-full resize-none rounded-xl border-neutral-300 text-sm focus:border-orange-400 focus:ring-orange-400"
                            placeholder="Tulis pesan kamu di sini..."
                        />
                        {errors.message && (
                            <p className="mt-1 text-sm text-red-600">{errors.message}</p>
                        )}
                    </label>

                    <button
                        type="submit"
                        disabled={processing}
                        className="mt-5 inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-full bg-orange-400 px-6 text-sm font-bold text-white transition hover:bg-orange-500 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
                    >
                        {processing ? 'Mengirim...' : 'Kirim pesan'}
                        {!processing && <Send className="h-4 w-4" />}
                    </button>
                </form>
            </section>
        </PublicLayout>
    );
}
