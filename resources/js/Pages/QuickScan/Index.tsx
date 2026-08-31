import PublicLayout from '@/Layouts/PublicLayout';
import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, FlaskConical, ImageUp, Sparkles } from 'lucide-react';
import { FormEvent, useRef, useState } from 'react';

type Candidate = {
    condition_name: string;
    confidence: number;
    observed_description: string;
};

type ScanResult = {
    provider: string;
    provider_status: string;
    is_valid_skin_image: boolean;
    candidates: Candidate[];
    warnings: string[];
} | null;

type PageProps = {
    result: ScanResult;
};

function percent(value: number): string {
    return `${Math.round(value * 100)}%`;
}

export default function QuickScanIndex({ result }: PageProps) {
    const { data, setData, post, processing, errors, reset } = useForm<{ image: File | null }>({
        image: null,
    });
    const [preview, setPreview] = useState<string | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    function onImageChange(event: React.ChangeEvent<HTMLInputElement>) {
        const file = event.target.files?.[0] ?? null;
        setData('image', file);
        setPreview(file ? URL.createObjectURL(file) : null);
    }

    function onSubmit(event: FormEvent) {
        event.preventDefault();
        post(route('quick-scan.store'), { forceFormData: true });
    }

    function onScanAgain() {
        reset();
        setPreview(null);
        if (inputRef.current) {
            inputRef.current.value = '';
        }
    }

    const isProviderIssue =
        result &&
        !['ok', 'mock_mode'].includes(result.provider_status) &&
        result.candidates.length === 0;

    return (
        <PublicLayout>
            <Head title="Mode Foto (Beta) - DermaCerdas" />

            <div className="mx-auto max-w-3xl px-4 py-6 lg:px-8">
                <div className="inline-flex items-center gap-1.5 text-xs font-semibold text-orange-700">
                    <FlaskConical className="h-3.5 w-3.5" />
                    Fitur beta &mdash; terpisah dari konsultasi utama
                </div>
                <h1 className="mt-2 text-2xl font-semibold text-slate-950 sm:text-3xl">
                    Mode Foto
                </h1>
                <p className="mt-2 max-w-xl text-sm leading-6 text-slate-600">
                    Upload foto saja, tanpa mengisi gejala. AI akan menyebutkan kemungkinan kondisi
                    kulit apa pun berdasarkan tampilan foto &mdash; tidak dibatasi 16 kondisi seperti
                    di alur Konsultasi biasa.
                </p>

                <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <div className="flex items-start gap-3">
                        <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-700" />
                        <p className="text-sm leading-6 text-amber-950">
                            <span className="font-semibold">Ini bukan diagnosis.</span> Hasil di sini
                            murni referensi mentah dari model AI, tanpa dukungan gejala, CF, atau
                            perbandingan dataset &mdash; jauh lebih mudah keliru dibanding hasil
                            Konsultasi biasa. Tidak ada rekomendasi obat sama sekali. Untuk hasil yang
                            lebih dipertanggungjawabkan, gunakan menu{' '}
                            <a href={route('consultation.start')} className="font-semibold underline">
                                Konsultasi
                            </a>
                            .
                        </p>
                    </div>
                </div>

                <form onSubmit={onSubmit} className="mt-5 rounded-2xl border border-slate-200 bg-white p-5">
                    <label
                        htmlFor="quick-scan-image"
                        className="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 p-8 text-center transition hover:border-orange-300 hover:bg-orange-50/40"
                    >
                        {preview ? (
                            <img
                                src={preview}
                                alt="Pratinjau foto yang dipilih"
                                className="h-48 w-48 rounded-lg object-cover"
                            />
                        ) : (
                            <>
                                <ImageUp className="h-8 w-8 text-slate-400" />
                                <span className="text-sm font-semibold text-slate-700">
                                    Pilih atau jatuhkan foto di sini
                                </span>
                                <span className="text-xs text-slate-500">JPG, PNG, atau WEBP, maks 5MB</span>
                            </>
                        )}
                        <input
                            ref={inputRef}
                            id="quick-scan-image"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            onChange={onImageChange}
                            className="sr-only"
                        />
                    </label>
                    {errors.image && <p className="mt-2 text-sm text-rose-600">{errors.image}</p>}

                    <button
                        type="submit"
                        disabled={!data.image || processing}
                        className="mt-4 inline-flex h-11 w-full items-center justify-center gap-2 rounded-lg bg-orange-500 px-4 text-sm font-semibold text-white transition hover:bg-orange-600 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <Sparkles className="h-4 w-4" />
                        {processing ? 'Menganalisis...' : 'Analisis foto'}
                    </button>
                </form>

                {result && (
                    <section className="mt-5 rounded-2xl border border-slate-200 bg-white p-5">
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="text-lg font-semibold text-slate-900">Hasil analisis bebas</h2>
                            <button
                                type="button"
                                onClick={onScanAgain}
                                className="text-sm font-semibold text-orange-700 hover:underline"
                            >
                                Scan foto lain
                            </button>
                        </div>

                        {isProviderIssue ? (
                            <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                                <p className="font-semibold">Analisis belum bisa dijalankan.</p>
                                {result.warnings.map((warning) => (
                                    <p key={warning} className="mt-1">
                                        {warning}
                                    </p>
                                ))}
                            </div>
                        ) : !result.is_valid_skin_image || result.candidates.length === 0 ? (
                            <div className="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-700">
                                <p className="font-semibold text-slate-900">
                                    Model tidak cukup yakin untuk menyebut kemungkinan apa pun.
                                </p>
                                <p className="mt-1">
                                    Coba foto yang lebih jelas, fokus, dan pencahayaan cukup pada area
                                    keluhan.
                                </p>
                                {result.warnings.map((warning) => (
                                    <p key={warning} className="mt-2 text-xs text-slate-500">
                                        {warning}
                                    </p>
                                ))}
                            </div>
                        ) : (
                            <div className="mt-4 space-y-3">
                                {result.candidates.map((candidate) => (
                                    <div
                                        key={candidate.condition_name}
                                        className="rounded-lg border border-slate-200 bg-slate-50 p-4"
                                    >
                                        <div className="flex items-center justify-between gap-3">
                                            <span className="font-semibold text-slate-900">
                                                {candidate.condition_name}
                                            </span>
                                            <span className="rounded-full bg-white px-2.5 py-1 font-mono text-xs font-semibold text-slate-700 shadow-sm">
                                                {percent(candidate.confidence)}
                                            </span>
                                        </div>
                                        {candidate.observed_description && (
                                            <p className="mt-2 text-sm leading-6 text-slate-600">
                                                {candidate.observed_description}
                                            </p>
                                        )}
                                    </div>
                                ))}
                                <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-950">
                                    Daftar ini murni tebakan AI dari tampilan foto, belum tentu benar,
                                    dan tidak menggantikan pemeriksaan tenaga kesehatan.
                                </p>
                            </div>
                        )}
                    </section>
                )}
            </div>
        </PublicLayout>
    );
}
