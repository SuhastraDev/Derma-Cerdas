import PublicLayout from '@/Layouts/PublicLayout';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    BookOpen,
    CheckCircle2,
    ClipboardCheck,
    Download,
    HeartPulse,
    RotateCcw,
    ShieldAlert,
    Sparkles,
} from 'lucide-react';

type Consultation = {
    session_code: string;
    visitor_name: string | null;
    complaint_text: string | null;
    complaint_features: {
        summary?: string[];
    } | null;
    image_path: string | null;
    uploaded_image_url: string | null;
    status: string;
    final_score: string | number | null;
    final_action: string | null;
    visual_validation: {
        provider: string;
        provider_status?: string;
        status: string;
        is_valid_skin_image: boolean | null;
        outside_scope?: boolean;
        observed_description?: string;
        warnings: string[];
    } | null;
    created_at: string | null;
};

type FinalResult = {
    disease_name: string | null;
    disease_name_indonesian: string | null;
    textual_cf: number;
    visual_score: number;
    fusion_score: number;
    action: string;
    fusion_rule_code: string | null;
    explanation: string | null;
    recommendations: Array<{
        medicine_name: string;
        category: string;
        dosage_form: string | null;
        image_url: string | null;
        image_credit: string | null;
        usage_instruction: string | null;
        warnings: string | null;
        recommendation_note: string | null;
    }>;
    education: {
        description: string | null;
        medical_treatment_note: string | null;
        source_note: string | null;
        is_outside_validated_scope: boolean;
    } | null;
    secondary_visual_note: {
        disease_name_indonesian: string;
        description: string | null;
        source_note: string | null;
        visual_score: number;
    } | null;
    label_suppressed: boolean;
} | null;

type RedFlag = {
    code: string | null;
    question: string | null;
    action_message: string | null;
    severity: string | null;
};

type Symptom = {
    name: string | null;
    question: string | null;
    user_cf: number;
};

type VisualResult = {
    disease_name_indonesian: string | null;
    visual_score: number;
    visual_reason: string | null;
    provider: string;
};

type ComparisonImage = {
    class_name: string;
    file_name: string;
    url: string;
};

type ResultProps = {
    consultation: Consultation;
    finalResult: FinalResult;
    redFlags: RedFlag[];
    symptoms: Symptom[];
    visualResults: VisualResult[];
    comparisonImages: ComparisonImage[];
};

function percent(value: number): string {
    return `${Math.round(value * 100)}%`;
}

type SourceLink = { label: string; url: string | null };

// source_note disimpan sebagai teks "Label: url; Label2: url2" (lihat
// DatasetDiseaseMapper::GROUPS). Dipecah di sini supaya tetap tampil sebagai
// link yang bisa diklik, bukan teks URL mentah yang tidak bisa ditekan.
function parseSourceLinks(sourceNote: string | null): SourceLink[] {
    if (!sourceNote) {
        return [];
    }

    return sourceNote
        .split(';')
        .map((entry) => entry.trim())
        .filter(Boolean)
        .map((entry) => {
            const separatorIndex = entry.indexOf('https://');
            const httpIndex = separatorIndex === -1 ? entry.indexOf('http://') : separatorIndex;

            if (httpIndex === -1) {
                return { label: entry, url: null };
            }

            const label = entry.slice(0, httpIndex).replace(/:\s*$/, '').trim();
            const url = entry.slice(httpIndex).trim();

            return { label: label || url, url };
        });
}

function SourceLinkList({ sourceNote }: { sourceNote: string | null }) {
    const links = parseSourceLinks(sourceNote);

    if (links.length === 0) {
        return null;
    }

    return (
        <ul className="mt-2 space-y-1">
            {links.map((link) => (
                <li key={link.label}>
                    {link.url ? (
                        <a
                            href={link.url}
                            target="_blank"
                            rel="noreferrer"
                            className="text-orange-700 underline decoration-orange-300 underline-offset-2 hover:text-orange-800"
                        >
                            {link.label}
                        </a>
                    ) : (
                        link.label
                    )}
                </li>
            ))}
        </ul>
    );
}

function actionCopy(action: string | null): {
    title: string;
    eyebrow: string;
    tone: string;
    iconTone: string;
    body: string;
    Icon: typeof CheckCircle2;
} {
    if (action === 'recommend_otc') {
        return {
            title: 'Swamedikasi terbatas dapat dipertimbangkan',
            eyebrow: 'Hasil awal aman bersyarat',
            tone: 'border-emerald-200 bg-emerald-50 text-emerald-950',
            iconTone: 'bg-emerald-700 text-white',
            body: 'Hasil visual dan gejala sama-sama mengarah ke penyakit yang sama dengan keyakinan tinggi. Ikuti aturan pakai umum, baca peringatan, dan hentikan bila keluhan memburuk.',
            Icon: CheckCircle2,
        };
    }

    if (action === 'recommend_otc_observe') {
        return {
            title: 'Swamedikasi dapat dicoba dengan observasi',
            eyebrow: 'Keyakinan sedang',
            tone: 'border-amber-200 bg-amber-50 text-amber-950',
            iconTone: 'bg-amber-600 text-white',
            body: 'Keyakinan hasil masih pada tingkat sedang. Rekomendasi obat tetap ditampilkan, tetapi amati perkembangan keluhan dan konsultasi bila tidak membaik.',
            Icon: ClipboardCheck,
        };
    }

    if (action === 'recommend_otc_unsupported') {
        return {
            title: 'Swamedikasi dapat dipertimbangkan (tanpa validasi visual)',
            eyebrow: 'Keyakinan gejala tinggi, visual tidak tersedia',
            tone: 'border-amber-200 bg-amber-50 text-amber-950',
            iconTone: 'bg-amber-600 text-white',
            body: 'Citra tidak dapat dianalisis atau kelas visual berada di luar ruang lingkup sistem, sehingga keputusan hanya disandarkan pada gejala. Perlakukan hasil ini dengan lebih hati-hati.',
            Icon: ClipboardCheck,
        };
    }

    if (action === 'recommend_otc_mismatch') {
        return {
            title: 'Swamedikasi dapat dipertimbangkan (hasil visual berbeda)',
            eyebrow: 'Ketidaksesuaian modalitas',
            tone: 'border-amber-200 bg-amber-50 text-amber-950',
            iconTone: 'bg-amber-600 text-white',
            body: 'Hasil analisis citra menunjukkan kemungkinan penyakit lain dibanding hasil gejala. Keputusan disandarkan pada gejala karena keyakinannya tinggi, tetapi konsultasi dianjurkan bila ragu.',
            Icon: AlertTriangle,
        };
    }

    if (action === 'educate_only') {
        return {
            title: 'Utamakan edukasi dan perawatan umum',
            eyebrow: 'Obat belum menjadi fokus',
            tone: 'border-amber-200 bg-amber-50 text-amber-950',
            iconTone: 'bg-amber-600 text-white',
            body: 'Sistem memberi arahan perawatan umum karena rekomendasi obat belum cukup aman untuk ditampilkan.',
            Icon: ClipboardCheck,
        };
    }

    if (action === 'insufficient_confidence') {
        return {
            title: 'Hasil belum cukup meyakinkan',
            eyebrow: 'Perlu data lebih baik',
            tone: 'border-slate-200 bg-slate-50 text-slate-950',
            iconTone: 'bg-slate-700 text-white',
            body: 'Skor gabungan belum mencapai threshold. Perbaiki foto, lengkapi gejala, atau konsultasi langsung bila keluhan mengganggu.',
            Icon: AlertTriangle,
        };
    }

    return {
        title: 'Disarankan konsultasi ke tenaga kesehatan',
        eyebrow: 'Safety layer aktif',
        tone: 'border-red-200 bg-red-50 text-red-950',
        iconTone: 'bg-red-700 text-white',
        body: 'Ada faktor risiko atau kondisi yang tidak aman untuk rekomendasi obat mandiri. Pemeriksaan langsung adalah pilihan yang lebih aman.',
        Icon: ShieldAlert,
    };
}

function ScoreCard({
    label,
    value,
    detail,
}: {
    label: string;
    value: number;
    detail: string;
}) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                {label}
            </span>
            <div className="mt-3 flex items-end justify-between gap-4">
                <p className="text-3xl font-semibold text-slate-950">
                    {percent(value)}
                </p>
                <div className="h-2 flex-1 rounded-full bg-slate-100">
                    <div
                        className="h-2 rounded-full bg-emerald-700"
                        style={{ width: `${Math.round(value * 100)}%` }}
                    />
                </div>
            </div>
            <p className="mt-3 text-sm leading-5 text-slate-600">{detail}</p>
        </div>
    );
}

export default function Result({
    consultation,
    finalResult,
    redFlags,
    symptoms,
    visualResults,
    comparisonImages,
}: ResultProps) {
    const action = actionCopy(finalResult?.action ?? consultation.final_action);
    const ActionIcon = action.Icon;
    const diseaseName = finalResult?.label_suppressed
        ? 'Belum dapat dipastikan'
        : (finalResult?.disease_name_indonesian ??
              finalResult?.disease_name ??
              'Belum tersedia');
    const hasValidatedVisual = consultation.visual_validation?.status === 'valid';

    return (
        <PublicLayout>
            <Head title={`Hasil ${consultation.session_code}`} />

            <div className="mx-auto max-w-7xl px-4 py-6 lg:px-8">
                <div className="mb-5 flex flex-col justify-between gap-4 rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm md:flex-row md:items-center">
                    <div>
                        <div className="inline-flex items-center gap-2 rounded-full bg-yellow-50 px-3 py-1 text-xs font-semibold text-orange-700">
                            <Sparkles className="h-4 w-4" />
                            DermaCerdas
                        </div>
                        <h1 className="mt-3 text-3xl font-semibold text-slate-950">
                            Hasil konsultasi awal
                        </h1>
                        <p className="mt-2 text-sm text-slate-600">
                            Nama {consultation.visitor_name ?? '-'} / Kode unik{' '}
                            <span className="font-bold text-orange-700">
                                {consultation.session_code}
                            </span>
                            {consultation.created_at ? ` / ${consultation.created_at}` : ''}
                        </p>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                            Simpan kode unik ini untuk membuka riwayat konsultasi di menu Riwayat.
                        </p>
                    </div>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <a
                            href={route('consultation.export', consultation.session_code)}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-neutral-300 px-4 text-sm font-semibold text-neutral-700 transition hover:bg-neutral-100"
                        >
                            <Download className="h-4 w-4" />
                            Ekspor PDF
                        </a>
                        <Link
                            href={route('consultation.start')}
                            className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-neutral-300 px-4 text-sm font-semibold text-neutral-700 transition hover:bg-neutral-100"
                        >
                            <RotateCcw className="h-4 w-4" />
                            Konsultasi ulang
                        </Link>
                        <Link
                            href="/"
                            className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-orange-400 px-4 text-sm font-bold text-neutral-50 transition hover:bg-orange-500"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Ke awal
                        </Link>
                    </div>
                </div>

                <section className={`rounded-lg border p-5 shadow-sm ${action.tone}`}>
                    <div className="flex flex-col gap-4 md:flex-row md:items-center">
                        <div className={`rounded-lg p-3 ${action.iconTone}`}>
                            <ActionIcon className="h-7 w-7" />
                        </div>
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wide opacity-75">
                                {action.eyebrow}
                                {finalResult?.fusion_rule_code
                                    ? ` · Aturan fusion ${finalResult.fusion_rule_code}`
                                    : ''}
                            </p>
                            <h2 className="mt-1 text-2xl font-semibold">
                                {action.title}
                            </h2>
                            <p className="mt-2 max-w-4xl text-sm leading-6">
                                {action.body}
                            </p>
                        </div>
                    </div>
                </section>

                {consultation.visual_validation?.status === 'degraded' && (
                    <section className="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-950">
                        <div className="flex items-start gap-3">
                            <AlertTriangle className="mt-0.5 h-5 w-5 flex-shrink-0" />
                            <div>
                                <p className="text-sm font-semibold">
                                    Foto belum berhasil dianalisis AI secara valid
                                </p>
                                <p className="mt-1 text-sm leading-6">
                                    {consultation.visual_validation.outside_scope
                                        ? 'Sistem AI menilai foto ini kemungkinan bukan salah satu dari 16 kondisi yang dikenali sistem, sehingga hasil di bawah murni berdasarkan jawaban gejala tanpa dukungan bukti visual yang tervalidasi.'
                                        : 'Analisis foto gagal menghasilkan kandidat yang tervalidasi, sehingga hasil di bawah murni berdasarkan jawaban gejala tanpa dukungan bukti visual yang tervalidasi.'}
                                    {' '}Kandidat pada bagian &ldquo;Kandidat visual&rdquo; di bawah hanya perkiraan kemiripan warna/pola foto, bukan analisis AI yang diverifikasi.
                                </p>
                                {consultation.visual_validation.observed_description && (
                                    <p className="mt-2 text-sm leading-6">
                                        <span className="font-semibold">Ciri yang terlihat pada foto: </span>
                                        {consultation.visual_validation.observed_description}
                                    </p>
                                )}
                            </div>
                        </div>
                    </section>
                )}

                <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                        <div>
                            <p className="text-sm font-semibold text-orange-600">
                                Bukti visual
                            </p>
                            <h2 className="mt-1 text-xl font-semibold text-slate-950">
                                Foto pengguna dan contoh dataset serupa
                            </h2>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                                Bagian ini membantu membedakan foto yang kamu kirim dengan contoh gambar dari dataset SD-198 pada class hasil utama. Contoh dataset bersifat pembanding visual, bukan diagnosis pasti.
                            </p>
                        </div>
                        <span className="w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                            Class: {comparisonImages[0]?.class_name ?? diseaseName}
                        </span>
                    </div>

                    <div className="mt-5 grid gap-5 lg:grid-cols-[0.95fr_1.25fr]">
                        <div className="rounded-2xl border border-orange-200 bg-orange-50/60 p-4">
                            <div className="flex items-center justify-between gap-3">
                                <p className="text-sm font-semibold text-orange-900">
                                    Foto yang dikirim
                                </p>
                                <span className="rounded-full bg-white px-3 py-1 text-xs font-semibold text-orange-700 shadow-sm">
                                    Input user
                                </span>
                            </div>
                            {consultation.uploaded_image_url ? (
                                <img
                                    src={consultation.uploaded_image_url}
                                    alt="Foto area kulit yang dikirim pengguna"
                                    className="mt-3 h-80 w-full rounded-xl border border-orange-100 bg-white object-cover"
                                />
                            ) : (
                                <div className="mt-3 flex h-80 items-center justify-center rounded-xl border border-dashed border-orange-200 bg-white text-sm text-slate-500">
                                    Foto tidak tersedia.
                                </div>
                            )}
                        </div>

                        <div className="rounded-2xl border border-emerald-200 bg-emerald-50/60 p-4">
                            <div className="flex items-center justify-between gap-3">
                                <p className="text-sm font-semibold text-emerald-950">
                                    Contoh dari dataset SD-198
                                </p>
                                <span className="rounded-full bg-white px-3 py-1 text-xs font-semibold text-emerald-700 shadow-sm">
                                    Data pembanding
                                </span>
                            </div>
                            {comparisonImages.length > 0 ? (
                                <div className="mt-3 grid gap-3 sm:grid-cols-3">
                                    {comparisonImages.map((image, index) => (
                                        <figure key={`${image.class_name}-${image.file_name}`} className="rounded-xl border border-emerald-100 bg-white p-2">
                                            <img
                                                src={image.url}
                                                alt={`Contoh dataset ${image.class_name} ${index + 1}`}
                                                className="aspect-[4/3] w-full rounded-lg object-cover"
                                            />
                                            <figcaption className="mt-2 break-words text-xs leading-5 text-slate-600">
                                                {image.class_name}
                                                <br />
                                                <span className="font-mono text-[11px] text-slate-400">
                                                    {image.file_name}
                                                </span>
                                            </figcaption>
                                        </figure>
                                    ))}
                                </div>
                            ) : finalResult?.secondary_visual_note ? (
                                <div className="mt-3 flex h-80 flex-col justify-center rounded-xl border border-dashed border-emerald-200 bg-white p-4 text-sm leading-6 text-slate-700">
                                    <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        Foto juga menyerupai {finalResult.secondary_visual_note.disease_name_indonesian}
                                        {' '}({percent(finalResult.secondary_visual_note.visual_score)})
                                    </p>
                                    <p className="mt-2 line-clamp-5">
                                        {finalResult.secondary_visual_note.description ??
                                            'Kondisi ini di luar 16 penyakit yang dikenali sistem, sehingga tidak punya contoh dataset lokal untuk dibandingkan.'}
                                    </p>
                                    {finalResult.secondary_visual_note.source_note && (
                                        <div className="mt-2 text-xs">
                                            <SourceLinkList sourceNote={finalResult.secondary_visual_note.source_note} />
                                        </div>
                                    )}
                                </div>
                            ) : consultation.visual_validation?.observed_description ? (
                                <div className="mt-3 flex h-80 flex-col justify-center rounded-xl border border-dashed border-emerald-200 bg-white p-4 text-sm leading-6 text-slate-700">
                                    <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        Ciri yang terlihat AI di foto
                                    </p>
                                    <p className="mt-2 line-clamp-6">
                                        {consultation.visual_validation.observed_description}
                                    </p>
                                    <p className="mt-2 text-xs text-slate-500">
                                        Deskripsi visual mentah, bukan nama penyakit — foto ini kemungkinan di luar 16 kondisi yang dikenali sistem sehingga tidak ada contoh dataset lokal untuk dibandingkan.
                                    </p>
                                </div>
                            ) : (
                                <div className="mt-3 flex h-80 items-center justify-center rounded-xl border border-dashed border-emerald-200 bg-white px-4 text-center text-sm leading-6 text-slate-500">
                                    Contoh dataset untuk hasil ini belum ditemukan di folder lokal `datasets/sd-198`.
                                </div>
                            )}
                        </div>
                    </div>
                </section>

                <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div className="flex flex-col gap-5 lg:grid lg:grid-cols-[1fr_1fr]">
                        <div>
                            <p className="text-sm font-semibold text-orange-600">
                                Keluhan user
                            </p>
                            <h2 className="mt-1 text-xl font-semibold text-slate-950">
                                Cerita keluhan ikut dipakai sebagai evidence
                            </h2>
                            <p className="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-700">
                                {consultation.complaint_text || 'Keluhan tidak tersedia.'}
                            </p>
                        </div>
                        <div>
                            <p className="text-sm font-semibold text-orange-600">
                                Evidence dari teks
                            </p>
                            <h2 className="mt-1 text-xl font-semibold text-slate-950">
                                Kata kunci yang terbaca sistem
                            </h2>
                            {(consultation.complaint_features?.summary ?? []).length > 0 ? (
                                <ul className="mt-3 space-y-2">
                                    {consultation.complaint_features?.summary?.map((item) => (
                                        <li key={item} className="rounded-lg border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm leading-6 text-emerald-950">
                                            {item}
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-600">
                                    Sistem tidak menemukan kata kunci spesifik dari keluhan bebas.
                                </p>
                            )}
                        </div>
                    </div>
                </section>

                <div className="mt-6 grid gap-5 lg:grid-cols-[1.25fr_0.75fr]">
                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <p className="text-sm font-semibold text-orange-600">
                                    Kemungkinan utama
                                </p>
                                <h2 className="mt-1 text-2xl font-semibold text-slate-950">
                                    {diseaseName}
                                </h2>
                            </div>
                            <div className="rounded-xl bg-yellow-50 p-3 text-orange-700">
                                <HeartPulse className="h-6 w-6" />
                            </div>
                        </div>

                        {finalResult ? (
                            <>
                                <div className="mt-5 grid gap-3 md:grid-cols-3">
                                    <ScoreCard
                                        label="Visual"
                                        value={finalResult.visual_score}
                                        detail={
                                            hasValidatedVisual
                                                ? 'Kandidat dari pembacaan foto.'
                                                : 'Belum dipakai karena validasi visual belum aktif.'
                                        }
                                    />
                                    <ScoreCard
                                        label="Gejala CF"
                                        value={finalResult.textual_cf}
                                        detail="Keyakinan dari gejala pengguna."
                                    />
                                    <ScoreCard
                                        label="Fusion"
                                        value={finalResult.fusion_score}
                                        detail={
                                            hasValidatedVisual
                                                ? 'Gabungan visual dan gejala.'
                                                : 'Dihitung dari gejala karena visual belum tervalidasi.'
                                        }
                                    />
                                </div>

                                <div className="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4">
                                    <p className="text-sm font-semibold text-slate-900">
                                        Alasan sistem
                                    </p>
                                    <p className="mt-2 text-sm leading-6 text-slate-700">
                                        {finalResult.explanation}
                                    </p>
                                </div>
                            </>
                        ) : (
                            <p className="mt-4 text-sm text-slate-600">
                                Hasil belum tersedia.
                            </p>
                        )}
                    </section>

                    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="text-lg font-semibold text-slate-950">
                            Pemeriksaan red flags
                        </h2>
                        {redFlags.length > 0 ? (
                            <div className="mt-4 space-y-3">
                                {redFlags.map((redFlag) => (
                                    <div
                                        key={redFlag.code ?? redFlag.question ?? ''}
                                        className="rounded-lg border border-red-200 bg-red-50 p-4"
                                    >
                                        <p className="text-sm font-semibold text-red-950">
                                            {redFlag.question}
                                        </p>
                                        <p className="mt-2 text-sm leading-5 text-red-800">
                                            {redFlag.action_message}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <p className="mt-4 rounded-lg border border-emerald-100 bg-emerald-50 p-4 text-sm leading-6 text-emerald-950">
                                Tidak ada red flags yang dipilih pada konsultasi ini.
                            </p>
                        )}
                    </section>
                </div>

                {finalResult?.recommendations && finalResult.recommendations.length > 0 && (
                    <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <p className="text-sm font-semibold text-orange-600">
                                    Rekomendasi terbatas
                                </p>
                                <h2 className="mt-1 text-xl font-semibold text-slate-950">
                                    Ikuti aturan pakai dan batas durasi
                                </h2>
                            </div>
                            <CheckCircle2 className="h-6 w-6 text-emerald-700" />
                        </div>
                        <div className="mt-4 grid gap-4 md:grid-cols-2">
                            {finalResult.recommendations.map((recommendation) => (
                                <article
                                    key={recommendation.medicine_name}
                                    className="overflow-hidden rounded-lg border border-emerald-100 bg-emerald-50/60"
                                >
                                    {recommendation.image_url && (
                                        <img
                                            src={recommendation.image_url}
                                            alt={recommendation.medicine_name}
                                            className="h-40 w-full border-b border-emerald-100 bg-white object-contain p-3"
                                            loading="lazy"
                                        />
                                    )}
                                    <div className="p-4">
                                        <p className="font-semibold text-slate-950">
                                            {recommendation.medicine_name}
                                        </p>
                                        <p className="mt-1 text-sm text-slate-600">
                                            {recommendation.category}
                                            {recommendation.dosage_form
                                                ? ` / ${recommendation.dosage_form}`
                                                : ''}
                                        </p>
                                        <p className="mt-3 text-sm leading-6 text-slate-700">
                                            {recommendation.usage_instruction}
                                        </p>
                                        {recommendation.warnings && (
                                            <p className="mt-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm leading-5 text-amber-950">
                                                {recommendation.warnings}
                                            </p>
                                        )}
                                        {recommendation.image_credit && (
                                            <p className="mt-3 text-xs leading-4 text-slate-400">
                                                {recommendation.image_credit}
                                            </p>
                                        )}
                                    </div>
                                </article>
                            ))}
                        </div>
                    </section>
                )}

                {finalResult?.education &&
                    (finalResult.education.description ||
                        finalResult.education.medical_treatment_note ||
                        finalResult.education.source_note) && (
                    <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <p className="text-sm font-semibold text-orange-600">
                                    Info edukasi
                                </p>
                                <h2 className="mt-1 text-xl font-semibold text-slate-950">
                                    Bukan rekomendasi pengobatan
                                </h2>
                            </div>
                            <BookOpen className="h-6 w-6 text-slate-500" />
                        </div>
                        {finalResult.education.description && (
                            <p className="mt-4 text-sm leading-6 text-slate-700">
                                {finalResult.education.description}
                            </p>
                        )}
                        {finalResult.education.medical_treatment_note && (
                            <div className="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Biasanya ditangani dokter dengan
                                </p>
                                <p className="mt-2 text-sm leading-6 text-slate-700">
                                    {finalResult.education.medical_treatment_note}
                                </p>
                            </div>
                        )}
                        {finalResult.education.source_note && (
                            <div className="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4 text-xs leading-5 text-slate-500">
                                <span className="font-semibold uppercase tracking-wide">
                                    Sumber
                                </span>
                                <SourceLinkList sourceNote={finalResult.education.source_note} />
                            </div>
                        )}
                        <p className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                            {finalResult.education.is_outside_validated_scope
                                ? 'Ini adalah informasi umum, bukan diagnosis pasti atau rekomendasi obat. Sistem belum memiliki basis pengetahuan gejala tervalidasi untuk kondisi ini — tetap konsultasikan ke dokter atau tenaga kesehatan untuk kepastian.'
                                : 'Ini adalah informasi umum, bukan resep atau rekomendasi beli obat sendiri. Kondisi ini butuh penilaian atau penanganan langsung oleh dokter, jadi sistem tidak menampilkan obat bebas untuk kondisi ini.'}
                        </p>
                    </section>
                )}

                {finalResult?.secondary_visual_note && (
                    <section className="mt-6 rounded-2xl border border-sky-200 bg-sky-50/60 p-5 shadow-sm">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <p className="text-sm font-semibold text-sky-700">
                                    Catatan visual tambahan
                                </p>
                                <h2 className="mt-1 text-xl font-semibold text-slate-950">
                                    Foto juga menyerupai {finalResult.secondary_visual_note.disease_name_indonesian}
                                    {' '}({percent(finalResult.secondary_visual_note.visual_score)})
                                </h2>
                            </div>
                            <BookOpen className="h-6 w-6 text-sky-600" />
                        </div>
                        {finalResult.secondary_visual_note.description && (
                            <p className="mt-4 text-sm leading-6 text-slate-700">
                                {finalResult.secondary_visual_note.description}
                            </p>
                        )}
                        {finalResult.secondary_visual_note.source_note && (
                            <div className="mt-4 rounded-lg border border-sky-200 bg-white p-4 text-xs leading-5 text-slate-500">
                                <span className="font-semibold uppercase tracking-wide">
                                    Sumber
                                </span>
                                <SourceLinkList sourceNote={finalResult.secondary_visual_note.source_note} />
                            </div>
                        )}
                        <p className="mt-4 rounded-lg border border-sky-200 bg-white p-4 text-sm leading-6 text-sky-950">
                            Hasil dan rekomendasi di atas tetap disandarkan pada gejala yang kamu jawab, bukan pada catatan ini. Ini murni informasi tambahan karena foto juga menyerupai kondisi lain di luar 16 penyakit yang dikenali sistem — bukan diagnosis, dan sebaiknya dikonfirmasi ke tenaga kesehatan bila ragu.
                        </p>
                    </section>
                )}

                <div className="mt-6 grid gap-5 lg:grid-cols-2">
                    <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="text-lg font-semibold text-slate-950">
                            Gejala terpilih
                        </h2>
                        <div className="mt-4 space-y-3">
                            {symptoms.length === 0 ? (
                                <p className="rounded-lg bg-slate-50 p-4 text-sm text-slate-600">
                                    Tidak ada gejala yang dipilih.
                                </p>
                            ) : (
                                symptoms.map((symptom) => (
                                    <div
                                        key={`${symptom.name}-${symptom.user_cf}`}
                                        className="flex items-center justify-between gap-4 rounded-lg border border-slate-200 bg-slate-50 p-3"
                                    >
                                        <span className="text-sm font-medium text-slate-800">
                                            {symptom.name}
                                        </span>
                                        <span className="rounded-full bg-white px-3 py-1 text-sm font-semibold text-slate-950 shadow-sm">
                                            {percent(symptom.user_cf)}
                                        </span>
                                    </div>
                                ))
                            )}
                        </div>
                    </section>

                    <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <h2 className="text-lg font-semibold text-slate-950">
                            Kandidat visual
                        </h2>
                        {consultation.visual_validation?.provider_status === 'dataset_fallback' && (
                            <p className="mt-2 inline-flex w-fit items-center gap-1.5 rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">
                                <AlertTriangle className="h-3.5 w-3.5" />
                                Perkiraan kemiripan visual kasar — bukan analisis AI tervalidasi
                            </p>
                        )}
                        {finalResult?.label_suppressed && visualResults.length > 0 && (
                            <p className="mt-3 rounded-lg border border-sky-200 bg-sky-50 p-3 text-xs leading-5 text-sky-950">
                                Skor di bawah ini murni informasi tambahan dari analisis visual — belum cukup meyakinkan untuk dipastikan sebagai diagnosis, sejalan dengan &ldquo;Kemungkinan utama&rdquo; di atas yang juga belum dapat dipastikan.
                            </p>
                        )}
                        <div className="mt-4 space-y-3">
                            {visualResults.length > 0 ? (
                                visualResults.map((visual) => (
                                    <div
                                        key={`${visual.disease_name_indonesian}-${visual.visual_score}`}
                                        className="rounded-lg border border-slate-200 bg-slate-50 p-3"
                                    >
                                        <div className="flex items-center justify-between gap-4">
                                            <span className="text-sm font-semibold text-slate-900">
                                                {visual.disease_name_indonesian}
                                            </span>
                                            <span className="rounded-full bg-white px-3 py-1 text-sm font-semibold text-slate-950 shadow-sm">
                                                {percent(visual.visual_score)}
                                            </span>
                                        </div>
                                        <p className="mt-2 text-sm leading-5 text-slate-600">
                                            {visual.visual_reason}
                                        </p>
                                    </div>
                                ))
                            ) : (
                                <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                                    <p className="font-semibold">
                                        Validasi visual belum menghasilkan kandidat.
                                    </p>
                                    <p className="mt-1">
                                        Sistem tidak menampilkan kandidat foto mock. Aktifkan
                                        AI service untuk memeriksa apakah gambar benar-benar
                                        foto area kulit.
                                    </p>
                                    {consultation.visual_validation?.warnings?.length ? (
                                        <ul className="mt-3 list-disc space-y-1 pl-5">
                                            {consultation.visual_validation.warnings.map(
                                                (warning) => (
                                                    <li key={warning}>{warning}</li>
                                                ),
                                            )}
                                        </ul>
                                    ) : null}
                                </div>
                            )}
                        </div>
                    </section>
                </div>
            </div>
        </PublicLayout>
    );
}
