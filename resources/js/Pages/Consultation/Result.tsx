import PublicLayout from '@/Layouts/PublicLayout';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    BookOpen,
    CheckCircle2,
    ChevronDown,
    ClipboardCheck,
    Download,
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

/**
 * Empat tingkat urgensi yang benar-benar perlu dibedakan sekilas mata:
 * aman-bersyarat, hati-hati, belum jelas, atau segera periksa. Setiap
 * action dari FusionDecisionService dipetakan ke salah satu dari ini -
 * warnanya konsisten dipakai di seluruh hero, bukan cuma border tipis.
 */
type Severity = 'safe' | 'caution' | 'unclear' | 'urgent';

const SEVERITY_STYLES: Record<
    Severity,
    { hero: string; chip: string; bar: string; ring: string }
> = {
    safe: {
        hero: 'bg-teal-950 text-teal-50',
        chip: 'bg-teal-50 text-teal-900 ring-1 ring-inset ring-teal-600/20',
        bar: 'bg-teal-400',
        ring: 'ring-teal-400/30',
    },
    caution: {
        hero: 'bg-amber-950 text-amber-50',
        chip: 'bg-amber-50 text-amber-900 ring-1 ring-inset ring-amber-600/20',
        bar: 'bg-amber-400',
        ring: 'ring-amber-400/30',
    },
    unclear: {
        hero: 'bg-slate-900 text-slate-50',
        chip: 'bg-slate-100 text-slate-800 ring-1 ring-inset ring-slate-500/20',
        bar: 'bg-slate-400',
        ring: 'ring-slate-400/30',
    },
    urgent: {
        hero: 'bg-rose-950 text-rose-50',
        chip: 'bg-rose-50 text-rose-900 ring-1 ring-inset ring-rose-600/20',
        bar: 'bg-rose-400',
        ring: 'ring-rose-400/30',
    },
};

function actionCopy(action: string | null): {
    title: string;
    eyebrow: string;
    body: string;
    severity: Severity;
    Icon: typeof CheckCircle2;
} {
    if (action === 'recommend_otc') {
        return {
            title: 'Swamedikasi terbatas dapat dipertimbangkan',
            eyebrow: 'Hasil awal aman bersyarat',
            body: 'Hasil visual dan gejala sama-sama mengarah ke penyakit yang sama dengan keyakinan tinggi. Ikuti aturan pakai umum, baca peringatan, dan hentikan bila keluhan memburuk.',
            severity: 'safe',
            Icon: CheckCircle2,
        };
    }

    if (action === 'recommend_otc_observe') {
        return {
            title: 'Swamedikasi dapat dicoba dengan observasi',
            eyebrow: 'Keyakinan sedang',
            body: 'Keyakinan hasil masih pada tingkat sedang. Rekomendasi obat tetap ditampilkan, tetapi amati perkembangan keluhan dan konsultasi bila tidak membaik.',
            severity: 'caution',
            Icon: ClipboardCheck,
        };
    }

    if (action === 'recommend_otc_unsupported') {
        return {
            title: 'Swamedikasi dapat dipertimbangkan (tanpa validasi visual)',
            eyebrow: 'Keyakinan gejala tinggi, visual tidak tersedia',
            body: 'Citra tidak dapat dianalisis atau kelas visual berada di luar ruang lingkup sistem, sehingga keputusan hanya disandarkan pada gejala. Perlakukan hasil ini dengan lebih hati-hati.',
            severity: 'caution',
            Icon: ClipboardCheck,
        };
    }

    if (action === 'recommend_otc_mismatch') {
        return {
            title: 'Swamedikasi dapat dipertimbangkan (hasil visual berbeda)',
            eyebrow: 'Ketidaksesuaian modalitas',
            body: 'Hasil analisis citra menunjukkan kemungkinan penyakit lain dibanding hasil gejala. Keputusan disandarkan pada gejala karena keyakinannya tinggi, tetapi konsultasi dianjurkan bila ragu.',
            severity: 'caution',
            Icon: AlertTriangle,
        };
    }

    if (action === 'educate_only') {
        return {
            title: 'Utamakan edukasi dan perawatan umum',
            eyebrow: 'Obat belum menjadi fokus',
            body: 'Sistem memberi arahan perawatan umum karena rekomendasi obat belum cukup aman untuk ditampilkan.',
            severity: 'caution',
            Icon: ClipboardCheck,
        };
    }

    if (action === 'insufficient_confidence') {
        return {
            title: 'Hasil belum cukup meyakinkan',
            eyebrow: 'Perlu data lebih baik',
            body: 'Skor gabungan belum mencapai threshold. Perbaiki foto, lengkapi gejala, atau konsultasi langsung bila keluhan mengganggu.',
            severity: 'unclear',
            Icon: AlertTriangle,
        };
    }

    return {
        title: 'Disarankan konsultasi ke tenaga kesehatan',
        eyebrow: 'Safety layer aktif',
        body: 'Ada faktor risiko atau kondisi yang tidak aman untuk rekomendasi obat mandiri. Pemeriksaan langsung adalah pilihan yang lebih aman.',
        severity: 'urgent',
        Icon: ShieldAlert,
    };
}

/** Pil kecil untuk baris statistik di kaki hero - bukan kartu besar bersaing perhatian. */
function StatPill({
    label,
    value,
    barClass,
}: {
    label: string;
    value: number;
    barClass: string;
}) {
    return (
        <div className="min-w-[7.5rem] flex-1">
            <div className="flex items-baseline justify-between gap-2">
                <span className="text-[11px] font-semibold uppercase tracking-wide text-white/60">
                    {label}
                </span>
                <span className="font-mono text-sm font-semibold text-white">
                    {percent(value)}
                </span>
            </div>
            <div className="mt-1.5 h-1.5 rounded-full bg-white/15">
                <div
                    className={`h-1.5 rounded-full ${barClass}`}
                    style={{ width: `${Math.round(value * 100)}%` }}
                />
            </div>
        </div>
    );
}

/** Judul kecil yang membagi halaman jadi dua wilayah: verdict lalu bukti. */
function SectionEyebrow({ children }: { children: React.ReactNode }) {
    return (
        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-orange-700">
            {children}
        </p>
    );
}

function QuietCard({
    title,
    eyebrow,
    children,
    className = '',
}: {
    title: React.ReactNode;
    eyebrow?: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <section className={`rounded-xl border border-slate-200 bg-white p-5 ${className}`}>
            {eyebrow && <SectionEyebrow>{eyebrow}</SectionEyebrow>}
            <h3 className={`text-lg font-semibold text-slate-900 ${eyebrow ? 'mt-1' : ''}`}>
                {title}
            </h3>
            <div className="mt-3">{children}</div>
        </section>
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
    const style = SEVERITY_STYLES[action.severity];
    const diseaseName = finalResult?.label_suppressed
        ? 'Belum dapat dipastikan'
        : (finalResult?.disease_name_indonesian ??
              finalResult?.disease_name ??
              'Belum tersedia');
    const hasValidatedVisual = consultation.visual_validation?.status === 'valid';
    const isDegraded = consultation.visual_validation?.status === 'degraded';
    const activeRedFlags = redFlags.length > 0;

    return (
        <PublicLayout>
            <Head title={`Hasil ${consultation.session_code}`} />

            <div className="mx-auto max-w-5xl px-4 py-6 lg:px-8">
                {/* Identitas - ringkas, tidak bersaing dengan verdict di bawahnya */}
                <div className="mb-4 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                    <div>
                        <div className="inline-flex items-center gap-1.5 text-xs font-semibold text-orange-700">
                            <Sparkles className="h-3.5 w-3.5" />
                            DermaCerdas
                        </div>
                        <p className="mt-1 text-sm text-slate-500">
                            {consultation.visitor_name ?? '-'} ·{' '}
                            <span className="font-mono font-medium text-slate-700">
                                {consultation.session_code}
                            </span>
                            {consultation.created_at ? ` · ${consultation.created_at}` : ''}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <a
                            href={route('consultation.export', consultation.session_code)}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex h-9 items-center justify-center gap-1.5 rounded-lg border border-slate-300 px-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                        >
                            <Download className="h-3.5 w-3.5" />
                            PDF
                        </a>
                        <Link
                            href={route('consultation.start')}
                            className="inline-flex h-9 items-center justify-center gap-1.5 rounded-lg border border-slate-300 px-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                        >
                            <RotateCcw className="h-3.5 w-3.5" />
                            Ulangi
                        </Link>
                        <Link
                            href="/"
                            className="inline-flex h-9 items-center justify-center gap-1.5 rounded-lg bg-orange-500 px-3 text-sm font-semibold text-white transition hover:bg-orange-600"
                        >
                            <ArrowLeft className="h-3.5 w-3.5" />
                            Awal
                        </Link>
                    </div>
                </div>

                {/* ============ VERDICT - satu-satunya hal yang WAJIB dibaca ============ */}
                <section className={`rounded-2xl p-6 shadow-lg shadow-slate-900/5 sm:p-8 ${style.hero}`}>
                    <div className="flex flex-wrap items-center gap-2">
                        <span className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ${style.chip}`}>
                            <ActionIcon className="h-3.5 w-3.5" />
                            {action.eyebrow}
                        </span>
                        {finalResult?.fusion_rule_code && (
                            <span className="rounded-full bg-white/10 px-3 py-1 font-mono text-xs font-medium text-white/70">
                                Aturan {finalResult.fusion_rule_code}
                            </span>
                        )}
                        {activeRedFlags && (
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white/90">
                                <ShieldAlert className="h-3.5 w-3.5" />
                                Tanda bahaya terdeteksi
                            </span>
                        )}
                        {isDegraded && (
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white/90">
                                <AlertTriangle className="h-3.5 w-3.5" />
                                Visual belum tervalidasi
                            </span>
                        )}
                    </div>

                    <p className="mt-5 text-sm font-medium uppercase tracking-wide text-white/50">
                        Kemungkinan utama
                    </p>
                    <h1
                        className="mt-1 text-3xl font-semibold leading-tight sm:text-4xl"
                        style={{ fontFamily: "'Fraunces', 'Plus Jakarta Sans', serif" }}
                    >
                        {diseaseName}
                    </h1>

                    <p className="mt-4 text-lg font-semibold sm:text-xl">{action.title}</p>
                    <p className="mt-2 max-w-2xl text-sm leading-6 text-white/80">{action.body}</p>

                    {finalResult && (
                        <div className="mt-6 flex flex-wrap gap-x-8 gap-y-4 border-t border-white/10 pt-5">
                            <StatPill label="Gejala CF" value={finalResult.textual_cf} barClass={style.bar} />
                            <StatPill label="Visual" value={finalResult.visual_score} barClass={style.bar} />
                            <StatPill label="Gabungan" value={finalResult.fusion_score} barClass={style.bar} />
                        </div>
                    )}
                </section>

                {/* Peringatan visual degraded - konteks penting, tapi bukan verdict itu sendiri */}
                {isDegraded && (
                    <section className="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <div className="flex items-start gap-3">
                            <AlertTriangle className="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-700" />
                            <div className="text-sm leading-6 text-amber-950">
                                <p className="font-semibold">Foto belum berhasil dianalisis AI secara valid</p>
                                <p className="mt-1">
                                    {consultation.visual_validation?.outside_scope
                                        ? 'Sistem AI menilai foto ini kemungkinan bukan salah satu dari 16 kondisi yang dikenali sistem, sehingga hasil di atas murni berdasarkan jawaban gejala tanpa dukungan bukti visual yang tervalidasi.'
                                        : 'Analisis foto gagal menghasilkan kandidat yang tervalidasi, sehingga hasil di atas murni berdasarkan jawaban gejala tanpa dukungan bukti visual yang tervalidasi.'}
                                    {' '}
                                    Kandidat pada bagian &ldquo;Kandidat visual&rdquo; di bawah hanya perkiraan kemiripan warna/pola foto, bukan analisis AI yang diverifikasi.
                                </p>
                                {consultation.visual_validation?.observed_description && (
                                    <p className="mt-2">
                                        <span className="font-semibold">Ciri yang terlihat pada foto: </span>
                                        {consultation.visual_validation.observed_description}
                                    </p>
                                )}
                            </div>
                        </div>
                    </section>
                )}

                {/* Rekomendasi obat - langkah berikutnya, tetap dekat verdict kalau ada */}
                {finalResult?.recommendations && finalResult.recommendations.length > 0 && (
                    <section className="mt-3 rounded-xl border border-teal-200 bg-teal-50/60 p-5">
                        <div className="flex items-center gap-2">
                            <CheckCircle2 className="h-4 w-4 text-teal-700" />
                            <h2 className="text-base font-semibold text-slate-900">
                                Rekomendasi terbatas &mdash; ikuti aturan pakai dan batas durasi
                            </h2>
                        </div>
                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            {finalResult.recommendations.map((recommendation) => (
                                <article
                                    key={recommendation.medicine_name}
                                    className="overflow-hidden rounded-lg border border-teal-100 bg-white"
                                >
                                    {recommendation.image_url && (
                                        <img
                                            src={recommendation.image_url}
                                            alt={recommendation.medicine_name}
                                            className="h-36 w-full border-b border-teal-100 bg-white object-contain p-3"
                                            loading="lazy"
                                        />
                                    )}
                                    <div className="p-4">
                                        <p className="font-semibold text-slate-900">
                                            {recommendation.medicine_name}
                                        </p>
                                        <p className="mt-1 text-sm text-slate-500">
                                            {recommendation.category}
                                            {recommendation.dosage_form ? ` / ${recommendation.dosage_form}` : ''}
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

                {/* ============ BUKTI PENDUKUNG - wilayah tenang, boleh discan sekilas ============ */}
                <div className="mt-10 flex items-center gap-3">
                    <SectionEyebrow>Bukti pendukung</SectionEyebrow>
                    <div className="h-px flex-1 bg-slate-200" />
                </div>
                <p className="mt-1 text-sm text-slate-500">
                    Detail yang mendasari hasil di atas. Tidak wajib dibaca untuk mengetahui langkah selanjutnya.
                </p>

                <div className="mt-4 grid gap-4 lg:grid-cols-2">
                    <QuietCard eyebrow="Foto yang dikirim" title="Input pengguna">
                        {consultation.uploaded_image_url ? (
                            <img
                                src={consultation.uploaded_image_url}
                                alt="Foto area kulit yang dikirim pengguna"
                                className="h-64 w-full rounded-lg border border-slate-100 object-cover"
                            />
                        ) : (
                            <div className="flex h-64 items-center justify-center rounded-lg border border-dashed border-slate-200 text-sm text-slate-500">
                                Foto tidak tersedia.
                            </div>
                        )}
                    </QuietCard>

                    <QuietCard
                        eyebrow="Data pembanding"
                        title={`Contoh dataset SD-198 · ${comparisonImages[0]?.class_name ?? diseaseName}`}
                    >
                        {comparisonImages.length > 0 ? (
                            <div className="grid grid-cols-3 gap-2">
                                {comparisonImages.map((image, index) => (
                                    <figure key={`${image.class_name}-${image.file_name}`}>
                                        <img
                                            src={image.url}
                                            alt={`Contoh dataset ${image.class_name} ${index + 1}`}
                                            className="aspect-[4/3] w-full rounded-md border border-slate-100 object-cover"
                                        />
                                    </figure>
                                ))}
                            </div>
                        ) : finalResult?.secondary_visual_note ? (
                            <div className="flex h-64 flex-col justify-center rounded-lg border border-dashed border-slate-200 p-4 text-sm leading-6 text-slate-700">
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Foto juga menyerupai {finalResult.secondary_visual_note.disease_name_indonesian}{' '}
                                    ({percent(finalResult.secondary_visual_note.visual_score)})
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
                            <div className="flex h-64 flex-col justify-center rounded-lg border border-dashed border-slate-200 p-4 text-sm leading-6 text-slate-700">
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Ciri yang terlihat AI di foto
                                </p>
                                <p className="mt-2 line-clamp-6">
                                    {consultation.visual_validation.observed_description}
                                </p>
                                <p className="mt-2 text-xs text-slate-500">
                                    Deskripsi visual mentah, bukan nama penyakit &mdash; foto ini kemungkinan di luar 16 kondisi yang dikenali sistem sehingga tidak ada contoh dataset lokal untuk dibandingkan.
                                </p>
                            </div>
                        ) : (
                            <div className="flex h-64 items-center justify-center rounded-lg border border-dashed border-slate-200 px-4 text-center text-sm leading-6 text-slate-500">
                                Contoh dataset untuk hasil ini belum ditemukan di folder lokal `datasets/sd-198`.
                            </div>
                        )}
                    </QuietCard>
                </div>

                <div className="mt-4 grid gap-4 lg:grid-cols-2">
                    <QuietCard eyebrow="Keluhan pengguna" title="Cerita keluhan">
                        <p className="rounded-lg bg-slate-50 p-4 text-sm leading-6 text-slate-700">
                            {consultation.complaint_text || 'Keluhan tidak tersedia.'}
                        </p>
                    </QuietCard>

                    <QuietCard eyebrow="Evidence dari teks" title="Kata kunci yang terbaca sistem">
                        {(consultation.complaint_features?.summary ?? []).length > 0 ? (
                            <ul className="space-y-1.5">
                                {consultation.complaint_features?.summary?.map((item) => (
                                    <li
                                        key={item}
                                        className="rounded-lg border border-teal-100 bg-teal-50/60 px-3 py-2 text-sm leading-5 text-teal-950"
                                    >
                                        {item}
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="rounded-lg bg-slate-50 p-4 text-sm leading-6 text-slate-600">
                                Sistem tidak menemukan kata kunci spesifik dari keluhan bebas.
                            </p>
                        )}
                    </QuietCard>
                </div>

                {/* Rincian teknis - dilipat secara default, murni untuk yang ingin menelusuri "kenapa" */}
                <details className="group mt-4 rounded-xl border border-slate-200 bg-white open:pb-5">
                    <summary className="flex cursor-pointer list-none items-center justify-between gap-3 p-5 [&::-webkit-details-marker]:hidden">
                        <div>
                            <SectionEyebrow>Rincian teknis</SectionEyebrow>
                            <h3 className="mt-1 text-lg font-semibold text-slate-900">
                                Alasan sistem, gejala, dan tanda bahaya
                            </h3>
                        </div>
                        <ChevronDown className="h-5 w-5 flex-shrink-0 text-slate-400 transition group-open:rotate-180" />
                    </summary>

                    <div className="space-y-4 px-5">
                        {finalResult && (
                            <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
                                <p className="text-sm font-semibold text-slate-900">Alasan sistem</p>
                                <p className="mt-2 text-sm leading-6 text-slate-700">
                                    {finalResult.explanation}
                                </p>
                                {!hasValidatedVisual && (
                                    <p className="mt-2 text-xs text-slate-500">
                                        Skor visual belum dipakai dalam perhitungan karena validasi visual belum aktif/tervalidasi.
                                    </p>
                                )}
                            </div>
                        )}

                        <div>
                            <p className="mb-2 text-sm font-semibold text-slate-900">
                                Pemeriksaan red flags
                            </p>
                            {redFlags.length > 0 ? (
                                <div className="space-y-2">
                                    {redFlags.map((redFlag) => (
                                        <div
                                            key={redFlag.code ?? redFlag.question ?? ''}
                                            className="rounded-lg border border-rose-200 bg-rose-50 p-3"
                                        >
                                            <p className="text-sm font-semibold text-rose-950">
                                                {redFlag.question}
                                            </p>
                                            <p className="mt-1 text-sm leading-5 text-rose-800">
                                                {redFlag.action_message}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="rounded-lg border border-teal-100 bg-teal-50/60 p-3 text-sm leading-6 text-teal-950">
                                    Tidak ada red flags yang dipilih pada konsultasi ini.
                                </p>
                            )}
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <p className="mb-2 text-sm font-semibold text-slate-900">
                                    Gejala terpilih
                                </p>
                                <div className="space-y-1.5">
                                    {symptoms.length === 0 ? (
                                        <p className="rounded-lg bg-slate-50 p-3 text-sm text-slate-600">
                                            Tidak ada gejala yang dipilih.
                                        </p>
                                    ) : (
                                        symptoms.map((symptom) => (
                                            <div
                                                key={`${symptom.name}-${symptom.user_cf}`}
                                                className="flex items-center justify-between gap-4 rounded-lg bg-slate-50 px-3 py-2"
                                            >
                                                <span className="text-sm text-slate-700">{symptom.name}</span>
                                                <span className="font-mono text-xs font-semibold text-slate-900">
                                                    {percent(symptom.user_cf)}
                                                </span>
                                            </div>
                                        ))
                                    )}
                                </div>
                            </div>

                            <div>
                                <p className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-900">
                                    Kandidat visual
                                    {consultation.visual_validation?.provider_status === 'dataset_fallback' && (
                                        <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800">
                                            <AlertTriangle className="h-3 w-3" />
                                            Perkiraan kasar
                                        </span>
                                    )}
                                </p>
                                {finalResult?.label_suppressed && visualResults.length > 0 && (
                                    <p className="mb-2 rounded-lg border border-sky-200 bg-sky-50 p-2.5 text-xs leading-5 text-sky-950">
                                        Skor di bawah murni info tambahan, belum cukup meyakinkan untuk dipastikan sebagai diagnosis.
                                    </p>
                                )}
                                <div className="space-y-1.5">
                                    {visualResults.length > 0 ? (
                                        visualResults.map((visual) => (
                                            <div
                                                key={`${visual.disease_name_indonesian}-${visual.visual_score}`}
                                                className="rounded-lg bg-slate-50 px-3 py-2"
                                            >
                                                <div className="flex items-center justify-between gap-4">
                                                    <span className="text-sm font-medium text-slate-800">
                                                        {visual.disease_name_indonesian}
                                                    </span>
                                                    <span className="font-mono text-xs font-semibold text-slate-900">
                                                        {percent(visual.visual_score)}
                                                    </span>
                                                </div>
                                                <p className="mt-1 text-xs leading-5 text-slate-500">
                                                    {visual.visual_reason}
                                                </p>
                                            </div>
                                        ))
                                    ) : (
                                        <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-950">
                                            <p className="font-semibold">Validasi visual belum menghasilkan kandidat.</p>
                                            {consultation.visual_validation?.warnings?.length ? (
                                                <ul className="mt-2 list-disc space-y-1 pl-5 text-xs">
                                                    {consultation.visual_validation.warnings.map((warning) => (
                                                        <li key={warning}>{warning}</li>
                                                    ))}
                                                </ul>
                                            ) : null}
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>
                </details>

                {finalResult?.education &&
                    (finalResult.education.description ||
                        finalResult.education.medical_treatment_note ||
                        finalResult.education.source_note) && (
                    <section className="mt-4 rounded-xl border border-slate-200 bg-white p-5">
                        <div className="flex items-center gap-2">
                            <BookOpen className="h-4 w-4 text-slate-500" />
                            <h3 className="text-base font-semibold text-slate-900">
                                Info edukasi &mdash; bukan rekomendasi pengobatan
                            </h3>
                        </div>
                        {finalResult.education.description && (
                            <p className="mt-3 text-sm leading-6 text-slate-700">
                                {finalResult.education.description}
                            </p>
                        )}
                        {finalResult.education.medical_treatment_note && (
                            <div className="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Biasanya ditangani dokter dengan
                                </p>
                                <p className="mt-2 text-sm leading-6 text-slate-700">
                                    {finalResult.education.medical_treatment_note}
                                </p>
                            </div>
                        )}
                        {finalResult.education.source_note && (
                            <div className="mt-3 rounded-lg border border-slate-200 bg-slate-50 p-4 text-xs leading-5 text-slate-500">
                                <span className="font-semibold uppercase tracking-wide">Sumber</span>
                                <SourceLinkList sourceNote={finalResult.education.source_note} />
                            </div>
                        )}
                        <p className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950">
                            {finalResult.education.is_outside_validated_scope
                                ? 'Ini adalah informasi umum, bukan diagnosis pasti atau rekomendasi obat. Sistem belum memiliki basis pengetahuan gejala tervalidasi untuk kondisi ini — tetap konsultasikan ke dokter atau tenaga kesehatan untuk kepastian.'
                                : 'Ini adalah informasi umum, bukan resep atau rekomendasi beli obat sendiri. Kondisi ini butuh penilaian atau penanganan langsung oleh dokter, jadi sistem tidak menampilkan obat bebas untuk kondisi ini.'}
                        </p>
                    </section>
                )}

                {finalResult?.secondary_visual_note && (
                    <section className="mt-4 rounded-xl border border-sky-200 bg-sky-50/60 p-5">
                        <div className="flex items-center gap-2">
                            <BookOpen className="h-4 w-4 text-sky-600" />
                            <h3 className="text-base font-semibold text-slate-900">
                                Catatan visual tambahan &mdash; foto juga menyerupai{' '}
                                {finalResult.secondary_visual_note.disease_name_indonesian}{' '}
                                ({percent(finalResult.secondary_visual_note.visual_score)})
                            </h3>
                        </div>
                        {finalResult.secondary_visual_note.description && (
                            <p className="mt-3 text-sm leading-6 text-slate-700">
                                {finalResult.secondary_visual_note.description}
                            </p>
                        )}
                        {finalResult.secondary_visual_note.source_note && (
                            <div className="mt-3 rounded-lg border border-sky-200 bg-white p-4 text-xs leading-5 text-slate-500">
                                <span className="font-semibold uppercase tracking-wide">Sumber</span>
                                <SourceLinkList sourceNote={finalResult.secondary_visual_note.source_note} />
                            </div>
                        )}
                        <p className="mt-3 rounded-lg border border-sky-200 bg-white p-4 text-sm leading-6 text-sky-950">
                            Hasil dan rekomendasi di atas tetap disandarkan pada gejala yang kamu jawab, bukan pada catatan ini. Ini murni informasi tambahan karena foto juga menyerupai kondisi lain di luar 16 penyakit yang dikenali sistem — bukan diagnosis, dan sebaiknya dikonfirmasi ke tenaga kesehatan bila ragu.
                        </p>
                    </section>
                )}
            </div>
        </PublicLayout>
    );
}
