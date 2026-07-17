import AdminLayout from '@/Layouts/AdminLayout';
import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    BarChart3,
    Database,
    HeartPulse,
    ShieldCheck,
    Stethoscope,
    TrendingUp,
    Users,
} from 'lucide-react';

type StatKey =
    | 'total_consultations'
    | 'today_consultations'
    | 'refer_consultations'
    | 'average_score'
    | 'active_diseases'
    | 'active_symptoms'
    | 'rules'
    | 'medicines'
    | 'red_flags'
    | 'dataset_mappings';

type DashboardProps = {
    stats: Record<StatKey, number>;
    charts: {
        trend: Array<{ label: string; date: string; count: number }>;
        actions: Array<{ label: string; key: string; total: number }>;
        top_diseases: Array<{ label: string; total: number }>;
    };
    latestConsultations: Array<{
        session_code: string;
        visitor_name: string;
        status: string;
        final_score: string | number | null;
        final_action: string | null;
        created_at: string;
    }>;
};

const actionColors: Record<string, string> = {
    recommend_otc: '#099f69',
    educate_only: '#f68d2a',
    refer: '#d02241',
    completed: '#3385f0',
    draft: '#77878f',
};

function formatNumber(value: number | string | null) {
    if (value === null || value === '') {
        return '-';
    }

    return Number(value).toLocaleString('id-ID');
}

function actionLabel(action: string | null) {
    if (!action) {
        return '-';
    }

    const labels: Record<string, string> = {
        recommend_otc: 'Rekomendasi obat',
        educate_only: 'Edukasi',
        refer: 'Rujuk',
        completed: 'Selesai',
        draft: 'Draft',
    };

    return labels[action] ?? action.replaceAll('_', ' ');
}

function LineChart({ data }: { data: DashboardProps['charts']['trend'] }) {
    const max = Math.max(...data.map((item) => item.count), 1);
    const width = 640;
    const height = 220;
    const padding = 28;
    const usableWidth = width - padding * 2;
    const usableHeight = height - padding * 2;
    const points = data.map((item, index) => {
        const x = padding + (index / Math.max(data.length - 1, 1)) * usableWidth;
        const y = padding + usableHeight - (item.count / max) * usableHeight;

        return { ...item, x, y };
    });
    const path = points
        .map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x} ${point.y}`)
        .join(' ');

    return (
        <div className="overflow-hidden rounded-lg border border-[#dbe6eb] bg-white p-5 shadow-sm">
            <div className="mb-4 flex items-center justify-between gap-4">
                <div>
                    <h2 className="text-base font-bold text-[#1b2124]">
                        Tren konsultasi
                    </h2>
                    <p className="mt-1 text-sm text-[#77878f]">
                        Jumlah konsultasi dalam 14 hari terakhir.
                    </p>
                </div>
                <TrendingUp className="h-5 w-5 text-[#3385f0]" />
            </div>

            <svg
                viewBox={`0 0 ${width} ${height}`}
                className="h-64 w-full"
                role="img"
                aria-label="Grafik tren konsultasi"
            >
                {[0, 1, 2, 3].map((line) => {
                    const y = padding + (usableHeight / 3) * line;
                    return (
                        <line
                            key={line}
                            x1={padding}
                            x2={width - padding}
                            y1={y}
                            y2={y}
                            stroke="#ebf2f5"
                            strokeWidth="1"
                        />
                    );
                })}
                <path
                    d={path}
                    fill="none"
                    stroke="#3385f0"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth="4"
                />
                {points.map((point) => (
                    <g key={point.date}>
                        <circle
                            cx={point.x}
                            cy={point.y}
                            r="5"
                            fill="#ffffff"
                            stroke="#3385f0"
                            strokeWidth="3"
                        />
                        <text
                            x={point.x}
                            y={height - 6}
                            textAnchor="middle"
                            className="fill-[#77878f] text-[10px] font-semibold"
                        >
                            {point.label}
                        </text>
                    </g>
                ))}
            </svg>
        </div>
    );
}

function DistributionChart({
    data,
}: {
    data: DashboardProps['charts']['actions'];
}) {
    const total = data.reduce((sum, item) => sum + item.total, 0);

    return (
        <div className="rounded-lg border border-[#dbe6eb] bg-white p-5 shadow-sm">
            <div className="mb-5 flex items-center justify-between gap-4">
                <div>
                    <h2 className="text-base font-bold text-[#1b2124]">
                        Distribusi keputusan
                    </h2>
                    <p className="mt-1 text-sm text-[#77878f]">
                        Aksi final dari riwayat konsultasi.
                    </p>
                </div>
                <BarChart3 className="h-5 w-5 text-[#3385f0]" />
            </div>

            <div className="space-y-4">
                {data.length === 0 && (
                    <p className="rounded-lg bg-[#f7fafc] p-4 text-sm text-[#77878f]">
                        Belum ada data konsultasi.
                    </p>
                )}
                {data.map((item) => {
                    const percent = total ? Math.round((item.total / total) * 100) : 0;
                    const color = actionColors[item.key] ?? '#3385f0';

                    return (
                        <div key={item.key}>
                            <div className="mb-2 flex items-center justify-between gap-3 text-sm">
                                <span className="font-semibold text-[#1b2124]">
                                    {item.label}
                                </span>
                                <span className="text-[#77878f]">
                                    {item.total} ({percent}%)
                                </span>
                            </div>
                            <div className="h-2 rounded-full bg-[#ebf2f5]">
                                <div
                                    className="h-2 rounded-full"
                                    style={{
                                        width: `${percent}%`,
                                        backgroundColor: color,
                                    }}
                                />
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function TopDiseaseChart({
    data,
}: {
    data: DashboardProps['charts']['top_diseases'];
}) {
    const max = Math.max(...data.map((item) => item.total), 1);

    return (
        <div className="rounded-lg border border-[#dbe6eb] bg-white p-5 shadow-sm">
            <div className="mb-5 flex items-center justify-between gap-4">
                <div>
                    <h2 className="text-base font-bold text-[#1b2124]">
                        Penyakit teratas
                    </h2>
                    <p className="mt-1 text-sm text-[#77878f]">
                        Berdasarkan hasil final konsultasi.
                    </p>
                </div>
                <Stethoscope className="h-5 w-5 text-[#3385f0]" />
            </div>

            <div className="space-y-3">
                {data.length === 0 && (
                    <p className="rounded-lg bg-[#f7fafc] p-4 text-sm text-[#77878f]">
                        Belum ada penyakit yang muncul di hasil final.
                    </p>
                )}
                {data.map((item) => (
                    <div
                        key={item.label}
                        className="grid grid-cols-[minmax(0,1fr)_48px] items-center gap-3"
                    >
                        <div>
                            <div className="mb-1 flex justify-between gap-3 text-sm">
                                <span className="truncate font-semibold text-[#1b2124]">
                                    {item.label}
                                </span>
                            </div>
                            <div className="h-2 rounded-full bg-[#ebf2f5]">
                                <div
                                    className="h-2 rounded-full bg-[#099f69]"
                                    style={{ width: `${(item.total / max) * 100}%` }}
                                />
                            </div>
                        </div>
                        <span className="rounded-lg bg-[#e6f5f0] px-2 py-1 text-center text-xs font-bold text-[#088759]">
                            {item.total}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function Dashboard({
    stats,
    charts,
    latestConsultations,
}: DashboardProps) {
    const statCards = [
        {
            label: 'Total konsultasi',
            value: stats.total_consultations,
            note: 'Semua riwayat user',
            icon: Users,
            tone: 'bg-[#eaf3fd] text-[#3385f0]',
        },
        {
            label: 'Konsultasi hari ini',
            value: stats.today_consultations,
            note: 'Aktivitas terbaru',
            icon: Activity,
            tone: 'bg-[#e6f5f0] text-[#099f69]',
        },
        {
            label: 'Perlu rujukan',
            value: stats.refer_consultations,
            note: 'Final action refer',
            icon: AlertTriangle,
            tone: 'bg-[#f9e2e6] text-[#b11d37]',
        },
        {
            label: 'Rata-rata skor',
            value: stats.average_score,
            note: 'Skor final konsultasi',
            icon: HeartPulse,
            tone: 'bg-[#feefe1] text-[#d17824]',
        },
    ];

    const dataReadiness = [
        { label: 'Penyakit aktif', value: stats.active_diseases },
        { label: 'Gejala aktif', value: stats.active_symptoms },
        { label: 'Rule CF', value: stats.rules },
        { label: 'Obat aktif', value: stats.medicines },
        { label: 'Red flags', value: stats.red_flags },
        { label: 'Mapping dataset', value: stats.dataset_mappings },
    ];

    return (
        <AdminLayout
            header={
                <div>
                    <p className="text-sm font-semibold text-[#3385f0]">
                        Admin DermaCerdas
                    </p>
                    <h1 className="mt-1 text-2xl font-bold text-[#1b2124]">
                        Ringkasan konsultasi dan audit data
                    </h1>
                    <p className="mt-1 max-w-3xl text-sm leading-6 text-[#77878f]">
                        Pantau aktivitas konsultasi user, distribusi keputusan,
                        penyakit yang sering muncul, dan kesiapan data sistem.
                    </p>
                </div>
            }
        >
            <Head title="Dashboard" />

            <div className="space-y-5">
                <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    {statCards.map((card) => {
                        const Icon = card.icon;

                        return (
                            <div
                                key={card.label}
                                className="rounded-lg border border-[#dbe6eb] bg-white p-5 shadow-sm"
                            >
                                <div className="flex items-start justify-between gap-4">
                                    <div>
                                        <p className="text-xs font-bold uppercase tracking-wide text-[#77878f]">
                                            {card.label}
                                        </p>
                                        <p className="mt-3 text-3xl font-semibold text-[#1b2124]">
                                            {formatNumber(card.value)}
                                        </p>
                                    </div>
                                    <span
                                        className={`flex h-11 w-11 items-center justify-center rounded-lg ${card.tone}`}
                                    >
                                        <Icon className="h-5 w-5" />
                                    </span>
                                </div>
                                <p className="mt-3 text-sm text-[#77878f]">{card.note}</p>
                            </div>
                        );
                    })}
                </section>

                <section className="grid gap-5 xl:grid-cols-[1.35fr_0.65fr]">
                    <LineChart data={charts.trend} />
                    <DistributionChart data={charts.actions} />
                </section>

                <section className="grid gap-5 xl:grid-cols-[0.85fr_1.15fr]">
                    <TopDiseaseChart data={charts.top_diseases} />

                    <div className="rounded-lg border border-[#dbe6eb] bg-white p-5 shadow-sm">
                        <div className="mb-5 flex items-center justify-between gap-4">
                            <div>
                                <h2 className="text-base font-bold text-[#1b2124]">
                                    Riwayat konsultasi terbaru
                                </h2>
                                <p className="mt-1 text-sm text-[#77878f]">
                                    Data terakhir dari user untuk audit admin.
                                </p>
                            </div>
                            <Link
                                href={route('admin.resource.index', 'consultations')}
                                className="rounded-lg bg-[#eaf3fd] px-3 py-2 text-sm font-bold text-[#245da8] transition hover:bg-[#c6ddfb]"
                            >
                                Lihat semua
                            </Link>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-[#ebf2f5] text-sm">
                                <thead className="bg-[#f7fafc]">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-[#4d595e]">
                                            User
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-[#4d595e]">
                                            Kode
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-[#4d595e]">
                                            Aksi
                                        </th>
                                        <th className="px-4 py-3 text-right text-xs font-bold uppercase tracking-wide text-[#4d595e]">
                                            Skor
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-[#ebf2f5]">
                                    {latestConsultations.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={4}
                                                className="px-4 py-8 text-center text-[#77878f]"
                                            >
                                                Belum ada konsultasi.
                                            </td>
                                        </tr>
                                    )}
                                    {latestConsultations.map((consultation) => (
                                        <tr
                                            key={consultation.session_code}
                                            className="transition hover:bg-[#f7fafc]"
                                        >
                                            <td className="px-4 py-3">
                                                <p className="font-semibold text-[#1b2124]">
                                                    {consultation.visitor_name}
                                                </p>
                                                <p className="text-xs text-[#77878f]">
                                                    {consultation.created_at}
                                                </p>
                                            </td>
                                            <td className="px-4 py-3 font-mono text-xs font-semibold text-[#4d595e]">
                                                {consultation.session_code}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className="rounded-lg bg-[#ebf2f5] px-2 py-1 text-xs font-bold text-[#4d595e]">
                                                    {actionLabel(consultation.final_action ?? consultation.status)}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-right font-semibold text-[#1b2124]">
                                                {formatNumber(consultation.final_score)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section className="rounded-lg border border-[#dbe6eb] bg-white p-5 shadow-sm">
                    <div className="mb-4 flex items-center justify-between gap-4">
                        <div>
                            <h2 className="text-base font-bold text-[#1b2124]">
                                Kesiapan data sistem
                            </h2>
                            <p className="mt-1 text-sm text-[#77878f]">
                                Ringkasan master data yang dipakai mesin keputusan.
                            </p>
                        </div>
                        <Database className="h-5 w-5 text-[#3385f0]" />
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {dataReadiness.map((item) => (
                            <div
                                key={item.label}
                                className="rounded-lg border border-[#dbe6eb] bg-[#f7fafc] p-4"
                            >
                                <p className="text-sm font-semibold text-[#4d595e]">
                                    {item.label}
                                </p>
                                <p className="mt-2 text-2xl font-semibold text-[#1b2124]">
                                    {formatNumber(item.value)}
                                </p>
                            </div>
                        ))}
                    </div>
                </section>
            </div>
        </AdminLayout>
    );
}
