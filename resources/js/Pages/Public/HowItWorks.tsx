import PublicLayout from '@/Layouts/PublicLayout';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Camera, ClipboardList, ShieldCheck } from 'lucide-react';

const clinicImage =
    'https://images.pexels.com/photos/33637448/pexels-photo-33637448.jpeg?auto=compress&cs=tinysrgb&w=1400';

const steps = [
    {
        title: 'Unggah foto area keluhan',
        body: 'Sistem hanya menerima gambar yang sesuai format dan harus lolos validasi visual sebelum dianalisis.',
        icon: Camera,
    },
    {
        title: 'Isi gejala yang dirasakan',
        body: 'Gejala dihitung dengan Certainty Factor agar input pengguna tidak diperlakukan sebagai jawaban hitam-putih.',
        icon: ClipboardList,
    },
    {
        title: 'Periksa tanda bahaya',
        body: 'Demam tinggi, sesak, nyeri berat, nanah luas, atau lesi mencurigakan mengarahkan pengguna untuk rujukan.',
        icon: AlertTriangle,
    },
    {
        title: 'Dapatkan arahan awal',
        body: 'Hasil dapat berupa edukasi, kepercayaan belum cukup, rujukan, atau rekomendasi terbatas bila aman.',
        icon: ShieldCheck,
    },
];

export default function HowItWorks() {
    return (
        <PublicLayout>
            <Head title="Cara Kerja" />

            <section className="mx-auto max-w-[85rem] px-4 py-12 lg:px-8">
                <div className="grid gap-8 lg:grid-cols-[0.85fr_1.15fr] lg:items-end">
                    <div>
                        <p className="text-sm font-semibold text-orange-600">Cara Kerja</p>
                        <h1 className="mt-3 text-4xl font-semibold leading-tight text-neutral-950 sm:text-5xl">
                            Alur dibuat berlapis supaya hasil tidak asal keluar.
                        </h1>
                        <p className="mt-5 text-base leading-8 text-neutral-600">
                            DermaCerdas tidak hanya membaca satu sumber data. Foto, gejala, dan
                            red flags dipakai bersama untuk membedakan edukasi ringan dari kondisi
                            yang sebaiknya diperiksa langsung.
                        </p>
                        <Link
                            href={route('consultation.start')}
                            className="mt-7 inline-flex min-h-12 items-center gap-2 rounded-full bg-orange-400 px-6 text-sm font-bold text-white transition hover:bg-orange-500"
                        >
                            Buka konsultasi
                            <ArrowRight className="h-4 w-4" />
                        </Link>
                    </div>
                    <div className="overflow-hidden rounded-[28px] border border-neutral-200 bg-white p-3 shadow-sm">
                        <img
                            src={clinicImage}
                            alt="Konsultasi dermatologi di klinik modern"
                            className="h-[360px] w-full rounded-[20px] object-cover"
                        />
                    </div>
                </div>
            </section>

            <section className="border-y border-neutral-200 bg-white">
                <div className="mx-auto grid max-w-[85rem] gap-4 px-4 py-16 md:grid-cols-2 xl:grid-cols-4 lg:px-8">
                    {steps.map((step, index) => {
                        const Icon = step.icon;

                        return (
                            <article
                                key={step.title}
                                className="rounded-2xl border border-neutral-200 bg-neutral-50 p-5 transition hover:border-yellow-300 hover:bg-yellow-50/60"
                            >
                                <div className="flex items-center justify-between gap-4">
                                    <span className="text-sm font-bold text-orange-600">
                                        0{index + 1}
                                    </span>
                                    <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-neutral-950 text-orange-300">
                                        <Icon className="h-5 w-5" />
                                    </span>
                                </div>
                                <h2 className="mt-6 text-lg font-semibold text-neutral-950">
                                    {step.title}
                                </h2>
                                <p className="mt-3 text-sm leading-6 text-neutral-600">
                                    {step.body}
                                </p>
                            </article>
                        );
                    })}
                </div>
            </section>
        </PublicLayout>
    );
}
