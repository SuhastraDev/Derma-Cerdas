import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm } from '@inertiajs/react';
import {
    ExternalLink,
    Eye,
    FileText,
    Pencil,
    Plus,
    Search,
    Trash2,
    X,
} from 'lucide-react';
import { FormEvent, ReactNode, useMemo, useState } from 'react';

type Field = {
    name: string;
    label: string;
    type?: 'text' | 'textarea' | 'select' | 'checkbox' | 'number';
    options?: string;
    nullable?: boolean;
};

type OptionItem = {
    id: number;
    name: string;
};

type Options = Record<string, Array<OptionItem> | string[]>;
type ItemValue = string | number | boolean | null | object | undefined;
type Item = Record<string, ItemValue>;
type Recommendation = {
    medicine_name?: string;
    category?: string;
    dosage_form?: string;
    usage_instruction?: string;
    warnings?: string;
    recommendation_note?: string;
};
type ConsultationDetail = {
    complaint_text?: string | null;
    complaint_summary?: string[];
    final_result?: {
        disease_name?: string;
        textual_cf?: number;
        visual_score?: number;
        fusion_score?: number;
        action?: string;
        explanation?: string;
        recommendations?: Recommendation[];
    } | null;
    symptoms?: Array<{ name?: string; question?: string; user_cf?: number }>;
    red_flags?: Array<{ question?: string; severity?: string }>;
    visual_results?: Array<{
        disease_name?: string;
        visual_score?: number;
        visual_reason?: string;
        provider?: string;
    }>;
};

type ResourceIndexProps = {
    resource: string;
    title: string;
    description: string;
    columns: string[];
    fields: Field[];
    items: Item[];
    options: Options;
    readOnly: boolean;
};

type FormValue = string | number | boolean | null;
type FormData = Record<string, FormValue>;

function labelize(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replaceAll('-', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function displayValue(value: ItemValue): string {
    if (typeof value === 'boolean') {
        return value ? 'Ya' : 'Tidak';
    }

    if (value === null || value === undefined || value === '') {
        return '-';
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}

function emptyData(fields: Field[]): FormData {
    return fields.reduce<FormData>((data, field) => {
        data[field.name] = field.type === 'checkbox' ? false : '';
        return data;
    }, {});
}

function valueBadge(value: ItemValue) {
    const text = displayValue(value);
    const normalized = text.toLowerCase();

    if (typeof value === 'boolean') {
        return (
            <span
                className={`inline-flex rounded-lg px-2 py-1 text-xs font-semibold ${
                    value
                        ? 'bg-[#e6f5f0] text-[#088759]'
                        : 'bg-[#ebf2f5] text-[#4d595e]'
                }`}
            >
                {text}
            </span>
        );
    }

    if (
        [
            'recommend_otc',
            'swamedikasi',
            'completed',
            'mild',
            'educate_only',
        ].includes(normalized)
    ) {
        return (
            <span className="inline-flex rounded-lg bg-[#e6f5f0] px-2 py-1 text-xs font-semibold text-[#088759]">
                {labelize(text)}
            </span>
        );
    }

    if (['refer', 'rujuk', 'danger'].includes(normalized)) {
        return (
            <span className="inline-flex rounded-lg bg-[#f9e2e6] px-2 py-1 text-xs font-semibold text-[#b11d37]">
                {labelize(text)}
            </span>
        );
    }

    if (['exclude', 'excluded', 'insufficient_confidence'].includes(normalized)) {
        return (
            <span className="inline-flex rounded-lg bg-[#ebf2f5] px-2 py-1 text-xs font-semibold text-[#4d595e]">
                {labelize(text)}
            </span>
        );
    }

    if (['edukasi', 'moderate'].includes(normalized)) {
        return (
            <span className="inline-flex rounded-lg bg-[#feefe1] px-2 py-1 text-xs font-semibold text-[#d17824]">
                {labelize(text)}
            </span>
        );
    }

    return <span>{text}</span>;
}

function percent(value?: number): string {
    return `${Math.round((value ?? 0) * 100)}%`;
}

export default function ResourceIndex({
    resource,
    title,
    description,
    columns,
    fields,
    items,
    options,
    readOnly,
}: ResourceIndexProps) {
    const initialData = useMemo(() => emptyData(fields), [fields]);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [selectedItem, setSelectedItem] = useState<Item | null>(null);
    const [searchTerm, setSearchTerm] = useState('');
    const { data, setData, post, put, processing, errors, reset } =
        useForm<FormData>(initialData);

    const filteredItems = useMemo(() => {
        const query = searchTerm.trim().toLowerCase();

        if (!query) {
            return items;
        }

        return items.filter((item) =>
            columns.some((column) =>
                displayValue(item[column]).toLowerCase().includes(query),
            ),
        );
    }, [columns, items, searchTerm]);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (editingId) {
            put(route('admin.resource.update', [resource, editingId]), {
                preserveScroll: true,
                onSuccess: () => cancelEdit(),
            });
            return;
        }

        post(route('admin.resource.store', resource), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    const edit = (item: Item) => {
        const nextData = emptyData(fields);

        fields.forEach((field) => {
            const value = item[field.name];
            nextData[field.name] =
                typeof value === 'object' && value !== null
                    ? JSON.stringify(value)
                    : (value as FormValue) ?? (field.type === 'checkbox' ? false : '');
        });

        setEditingId(Number(item.id));
        setData(nextData);
    };

    const cancelEdit = () => {
        setEditingId(null);
        setData(initialData);
        reset();
    };

    const destroy = (item: Item) => {
        if (!window.confirm(`Hapus data ${title.toLowerCase()} ini?`)) {
            return;
        }

        router.delete(route('admin.resource.destroy', [resource, item.id]), {
            preserveScroll: true,
        });
    };

    const selectedDetail = selectedItem?.detail as ConsultationDetail | undefined;
    const showReadOnlyActions = readOnly && resource === 'consultations';

    return (
        <AdminLayout
            header={
                <div>
                    <p className="text-sm font-semibold text-[#3385f0]">
                        Admin / {title}
                    </p>
                    <h1 className="mt-1 text-2xl font-bold text-[#1b2124]">
                        {title}
                    </h1>
                    <p className="mt-1 max-w-3xl text-sm leading-6 text-[#77878f]">
                        {description}
                    </p>
                </div>
            }
        >
            <Head title={title} />

            <section className="space-y-5">
                <div className="rounded-lg border border-[#dbe6eb] bg-white p-5 shadow-sm">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <span className="text-xs font-bold uppercase tracking-wide text-[#77878f]">
                                Total data
                            </span>
                            <div className="mt-2 flex items-end gap-3">
                                <p className="text-3xl font-semibold text-[#1b2124]">
                                    {items.length}
                                </p>
                                <p className="pb-1 text-sm text-[#77878f]">
                                    item tersimpan
                                </p>
                            </div>
                        </div>
                        <label className="w-full max-w-xl">
                            <span className="text-xs font-bold uppercase tracking-wide text-[#77878f]">
                                Cari data
                            </span>
                            <div className="mt-2 flex min-h-11 items-center gap-2 rounded-lg border border-[#dbe6eb] bg-[#f7fafc] px-3 transition focus-within:border-[#3385f0] focus-within:bg-white">
                                <Search className="h-4 w-4 text-[#77878f]" />
                                <input
                                    value={searchTerm}
                                    onChange={(event) =>
                                        setSearchTerm(event.target.value)
                                    }
                                    placeholder={`Cari ${title.toLowerCase()}...`}
                                    className="w-full border-0 bg-transparent px-0 py-2 text-sm text-[#1b2124] placeholder:text-[#9caeb8] focus:ring-0"
                                />
                            </div>
                        </label>
                    </div>
                </div>

                    {!readOnly && (
                        <form
                            onSubmit={submit}
                            className="rounded-lg border border-[#dbe6eb] bg-white p-5 shadow-sm"
                        >
                            <div className="mb-4 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                                <div>
                                    <h2 className="text-base font-bold text-[#1b2124]">
                                        {editingId ? 'Edit data' : 'Tambah data'}
                                    </h2>
                                    <p className="mt-1 text-sm text-[#77878f]">
                                        Isi data dengan bahasa yang jelas agar mudah
                                        diaudit.
                                    </p>
                                </div>
                                {editingId && (
                                    <button
                                        type="button"
                                        onClick={cancelEdit}
                                        className="rounded-lg px-3 py-2 text-sm font-semibold text-[#4d595e] transition hover:bg-[#ebf2f5] hover:text-[#1b2124]"
                                    >
                                        Batal edit
                                    </button>
                                )}
                            </div>

                            <div className="grid gap-4 md:grid-cols-2">
                                {fields.map((field) => (
                                    <label
                                        key={field.name}
                                        className={
                                            field.type === 'textarea'
                                                ? 'md:col-span-2'
                                                : ''
                                        }
                                    >
                                        <span className="mb-1 block text-sm font-semibold text-[#4d595e]">
                                            {field.label}
                                        </span>

                                        {field.type === 'textarea' ? (
                                            <textarea
                                                value={String(data[field.name] ?? '')}
                                                onChange={(event) =>
                                                    setData(field.name, event.target.value)
                                                }
                                                rows={3}
                                                className="w-full rounded-lg border-[#dbe6eb] bg-[#f7fafc] text-sm text-[#1b2124] shadow-sm transition focus:border-[#3385f0] focus:bg-white focus:ring-[#3385f0]"
                                            />
                                        ) : field.type === 'select' ? (
                                            <select
                                                value={String(data[field.name] ?? '')}
                                                onChange={(event) =>
                                                    setData(field.name, event.target.value)
                                                }
                                                className="w-full rounded-lg border-[#dbe6eb] bg-[#f7fafc] text-sm text-[#1b2124] shadow-sm transition focus:border-[#3385f0] focus:bg-white focus:ring-[#3385f0]"
                                            >
                                                <option value="">
                                                    {field.nullable ? 'Kosongkan' : 'Pilih'}
                                                </option>
                                                {(options[field.options ?? ''] ?? []).map(
                                                    (option) =>
                                                        typeof option === 'string' ? (
                                                            <option key={option} value={option}>
                                                                {labelize(option)}
                                                            </option>
                                                        ) : (
                                                            <option
                                                                key={option.id}
                                                                value={option.id}
                                                            >
                                                                {option.name}
                                                            </option>
                                                        ),
                                                )}
                                            </select>
                                        ) : field.type === 'checkbox' ? (
                                            <div className="flex min-h-10 items-center rounded-lg border border-[#dbe6eb] bg-[#f7fafc] px-3">
                                                <input
                                                    type="checkbox"
                                                    checked={Boolean(data[field.name])}
                                                    onChange={(event) =>
                                                        setData(
                                                            field.name,
                                                            event.target.checked,
                                                        )
                                                    }
                                                    className="rounded border-[#c3d3db] text-[#3385f0] shadow-sm focus:ring-[#3385f0]"
                                                />
                                                <span className="ms-2 text-sm text-[#4d595e]">
                                                    Aktifkan
                                                </span>
                                            </div>
                                        ) : (
                                            <input
                                                type={field.type === 'number' ? 'number' : 'text'}
                                                step={
                                                    field.name.includes('cf') ||
                                                    ['mb', 'md'].includes(field.name)
                                                        ? '0.01'
                                                        : '1'
                                                }
                                                value={String(data[field.name] ?? '')}
                                                onChange={(event) =>
                                                    setData(field.name, event.target.value)
                                                }
                                                className="w-full rounded-lg border-[#dbe6eb] bg-[#f7fafc] text-sm text-[#1b2124] shadow-sm transition focus:border-[#3385f0] focus:bg-white focus:ring-[#3385f0]"
                                            />
                                        )}

                                        {errors[field.name] && (
                                            <span className="mt-1 block text-sm text-red-600">
                                                {errors[field.name]}
                                            </span>
                                        )}
                                    </label>
                                ))}
                            </div>

                            <button
                                type="submit"
                                disabled={processing}
                                className="mt-5 inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-[#3385f0] px-4 text-sm font-bold text-white shadow-sm transition hover:bg-[#2b71cc] disabled:opacity-60"
                            >
                                <Plus className="h-4 w-4" />
                                {editingId ? 'Simpan perubahan' : 'Tambah data'}
                            </button>
                        </form>
                    )}

                    <div className="overflow-hidden rounded-lg border border-[#dbe6eb] bg-white shadow-sm">
                        <div className="flex flex-col justify-between gap-3 border-b border-[#dbe6eb] p-5 sm:flex-row sm:items-center">
                            <div>
                                <h2 className="text-base font-bold text-[#1b2124]">
                                    Daftar {title.toLowerCase()}
                                </h2>
                                <p className="mt-1 text-sm text-[#77878f]">
                                    Menampilkan {filteredItems.length} dari {items.length} data.
                                </p>
                            </div>
                            {readOnly && (
                                <span className="inline-flex rounded-lg bg-[#ebf2f5] px-3 py-1 text-xs font-semibold text-[#4d595e]">
                                    Read-only audit
                                </span>
                            )}
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-[#dbe6eb] text-sm">
                                <thead className="bg-[#f7fafc]">
                                    <tr>
                                        {columns.map((column) => (
                                            <th
                                                key={column}
                                                className="whitespace-nowrap px-5 py-4 text-left text-xs font-bold uppercase tracking-wide text-[#4d595e]"
                                            >
                                                {labelize(column)}
                                            </th>
                                        ))}
                                        {(showReadOnlyActions || !readOnly) && (
                                            <th className="whitespace-nowrap px-5 py-4 text-right text-xs font-bold uppercase tracking-wide text-[#4d595e]">
                                                Aksi
                                            </th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-[#ebf2f5]">
                                    {filteredItems.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={columns.length + (showReadOnlyActions || !readOnly ? 1 : 0)}
                                                className="px-5 py-10 text-center text-[#77878f]"
                                            >
                                                Data tidak ditemukan. Coba kata kunci lain.
                                            </td>
                                        </tr>
                                    ) : (
                                        filteredItems.map((item) => (
                                            <tr
                                                key={String(item.id)}
                                                className="transition hover:bg-[#f7fafc]"
                                            >
                                                {columns.map((column) => (
                                                    <td
                                                        key={column}
                                                        className="max-w-[320px] px-5 py-4 text-[#4d595e]"
                                                    >
                                                        <div className="line-clamp-2">
                                                            {valueBadge(item[column])}
                                                        </div>
                                                    </td>
                                                ))}
                                                {showReadOnlyActions && (
                                                    <td className="whitespace-nowrap px-5 py-4 text-right">
                                                        <button
                                                            type="button"
                                                            onClick={() => setSelectedItem(item)}
                                                            className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 font-semibold text-[#3385f0] transition hover:bg-[#eaf3fd] hover:text-[#245da8]"
                                                        >
                                                            <Eye className="h-4 w-4" />
                                                            Detail
                                                        </button>
                                                    </td>
                                                )}
                                                {!readOnly && (
                                                    <td className="whitespace-nowrap px-5 py-4 text-right">
                                                        <button
                                                            type="button"
                                                            onClick={() => edit(item)}
                                                            className="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 font-semibold text-[#3385f0] transition hover:bg-[#eaf3fd] hover:text-[#245da8]"
                                                        >
                                                            <Pencil className="h-4 w-4" />
                                                            Edit
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => destroy(item)}
                                                            className="ms-2 inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 font-semibold text-red-600 transition hover:bg-red-50 hover:text-red-800"
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                            Hapus
                                                        </button>
                                                    </td>
                                                )}
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
            </section>

            {selectedItem && selectedDetail && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#1b2124]/55 p-4 backdrop-blur-sm">
                    <section className="max-h-[92vh] w-full max-w-5xl overflow-hidden rounded-lg border border-[#dbe6eb] bg-white shadow-2xl">
                        <div className="flex items-start justify-between gap-4 border-b border-[#dbe6eb] bg-[#f7fafc] p-5">
                            <div>
                                <p className="text-xs font-bold uppercase tracking-wide text-[#3385f0]">
                                    Detail konsultasi
                                </p>
                                <h2 className="mt-1 text-xl font-bold text-[#1b2124]">
                                    {displayValue(selectedItem.visitor_name)}
                                </h2>
                                <p className="mt-1 text-sm text-[#77878f]">
                                    {displayValue(selectedItem.session_code)}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setSelectedItem(null)}
                                className="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-[#dbe6eb] bg-white text-[#4d595e] transition hover:bg-[#ebf2f5]"
                            >
                                <X className="h-5 w-5" />
                            </button>
                        </div>

                        <div className="max-h-[calc(92vh-88px)] overflow-y-auto p-5">
                            <div className="grid gap-5 lg:grid-cols-[0.9fr_1.4fr]">
                                <div className="space-y-4">
                                    <div className="rounded-lg border border-[#dbe6eb] bg-white p-3">
                                        <p className="mb-2 text-xs font-bold uppercase tracking-wide text-[#77878f]">
                                            Foto user
                                        </p>
                                        {selectedItem.uploaded_image_url ? (
                                            <img
                                                src={String(selectedItem.uploaded_image_url)}
                                                alt="Foto konsultasi user"
                                                className="h-72 w-full rounded-md object-cover"
                                            />
                                        ) : (
                                            <div className="flex h-72 items-center justify-center rounded-md bg-[#ebf2f5] text-sm text-[#77878f]">
                                                Foto tidak tersedia
                                            </div>
                                        )}
                                    </div>

                                    <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-1">
                                        <ScoreCard label="Visual" value={percent(selectedDetail.final_result?.visual_score)} />
                                        <ScoreCard label="Gejala CF" value={percent(selectedDetail.final_result?.textual_cf)} />
                                        <ScoreCard label="Fusion" value={percent(selectedDetail.final_result?.fusion_score)} strong />
                                    </div>
                                </div>

                                <div className="space-y-4">
                                    <div className="rounded-lg border border-[#dbe6eb] bg-[#f7fafc] p-5">
                                        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                                            <div>
                                                <p className="text-xs font-bold uppercase tracking-wide text-[#77878f]">
                                                    Hasil final
                                                </p>
                                                <h3 className="mt-1 text-2xl font-bold text-[#1b2124]">
                                                    {selectedDetail.final_result?.disease_name ?? '-'}
                                                </h3>
                                            </div>
                                            {valueBadge(selectedDetail.final_result?.action)}
                                        </div>
                                        <p className="mt-3 text-sm leading-6 text-[#4d595e]">
                                            {selectedDetail.final_result?.explanation ?? 'Belum ada penjelasan hasil.'}
                                        </p>
                                        <div className="mt-4 flex flex-wrap gap-2">
                                            <a
                                                href={String(selectedItem.result_url)}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex min-h-10 items-center gap-2 rounded-lg bg-[#3385f0] px-4 text-sm font-bold text-white transition hover:bg-[#2b71cc]"
                                            >
                                                <ExternalLink className="h-4 w-4" />
                                                Buka hasil user
                                            </a>
                                            <a
                                                href={String(selectedItem.export_url)}
                                                target="_blank"
                                                rel="noreferrer"
                                                className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-[#dbe6eb] bg-white px-4 text-sm font-bold text-[#4d595e] transition hover:bg-[#ebf2f5]"
                                            >
                                                <FileText className="h-4 w-4" />
                                                Export PDF
                                            </a>
                                        </div>
                                    </div>

                                    <DetailSection title="Rekomendasi obat">
                                        {(selectedDetail.final_result?.recommendations ?? []).length > 0 ? (
                                            <div className="grid gap-3">
                                                {selectedDetail.final_result?.recommendations?.map((item, index) => (
                                                    <div key={`${item.medicine_name}-${index}`} className="rounded-lg border border-[#dbe6eb] bg-white p-4">
                                                        <p className="font-bold text-[#1b2124]">{item.medicine_name}</p>
                                                        <p className="mt-1 text-sm text-[#77878f]">
                                                            {[item.category, item.dosage_form].filter(Boolean).join(' / ') || '-'}
                                                        </p>
                                                        <p className="mt-2 text-sm leading-6 text-[#4d595e]">
                                                            {item.usage_instruction || item.recommendation_note || '-'}
                                                        </p>
                                                        {item.warnings && (
                                                            <p className="mt-2 rounded-lg bg-[#feefe1] px-3 py-2 text-sm leading-6 text-[#9a4f16]">
                                                                {item.warnings}
                                                            </p>
                                                        )}
                                                    </div>
                                                ))}
                                            </div>
                                        ) : (
                                            <EmptyNote text="Tidak ada rekomendasi obat pada konsultasi ini. Biasanya karena skor belum cukup, ada red flag, atau mapping penyakit hanya edukasi/rujukan." />
                                        )}
                                    </DetailSection>

                                    <DetailSection title="Keluhan user">
                                        <p className="rounded-lg border border-[#dbe6eb] bg-white p-4 text-sm leading-6 text-[#4d595e]">
                                            {selectedDetail.complaint_text || 'Keluhan tidak tersedia.'}
                                        </p>
                                        {(selectedDetail.complaint_summary ?? []).length > 0 && (
                                            <div className="mt-3 grid gap-2">
                                                {selectedDetail.complaint_summary?.map((item) => (
                                                    <div key={item} className="rounded-lg border border-[#bfe5d8] bg-[#e6f5f0] p-3 text-sm leading-6 text-[#0b6545]">
                                                        {item}
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </DetailSection>

                                    <DetailSection title="Gejala dipilih">
                                        {(selectedDetail.symptoms ?? []).length > 0 ? (
                                            <div className="grid gap-2">
                                                {selectedDetail.symptoms?.map((item, index) => (
                                                    <div key={`${item.name}-${index}`} className="rounded-lg border border-[#dbe6eb] bg-white p-3">
                                                        <div className="flex justify-between gap-3">
                                                            <p className="font-semibold text-[#1b2124]">{item.name}</p>
                                                            <span className="text-sm font-bold text-[#088759]">{percent(item.user_cf)}</span>
                                                        </div>
                                                        <p className="mt-1 text-sm leading-6 text-[#77878f]">{item.question}</p>
                                                    </div>
                                                ))}
                                            </div>
                                        ) : (
                                            <EmptyNote text="Tidak ada gejala bernilai positif." />
                                        )}
                                    </DetailSection>

                                    <DetailSection title="Red flag">
                                        {(selectedDetail.red_flags ?? []).length > 0 ? (
                                            <div className="grid gap-2">
                                                {selectedDetail.red_flags?.map((item, index) => (
                                                    <div key={`${item.question}-${index}`} className="rounded-lg border border-[#f5c3cc] bg-[#fff5f6] p-3 text-sm text-[#8c182b]">
                                                        <strong>{item.severity}</strong>: {item.question}
                                                    </div>
                                                ))}
                                            </div>
                                        ) : (
                                            <EmptyNote text="Tidak ada red flag terdeteksi." />
                                        )}
                                    </DetailSection>

                                    <DetailSection title="Kandidat visual AI">
                                        {(selectedDetail.visual_results ?? []).length > 0 ? (
                                            <div className="grid gap-2">
                                                {selectedDetail.visual_results?.map((item, index) => (
                                                    <div key={`${item.disease_name}-${index}`} className="rounded-lg border border-[#dbe6eb] bg-white p-3">
                                                        <div className="flex justify-between gap-3">
                                                            <p className="font-semibold text-[#1b2124]">{item.disease_name}</p>
                                                            <span className="text-sm font-bold text-[#3385f0]">{percent(item.visual_score)}</span>
                                                        </div>
                                                        <p className="mt-1 text-xs font-semibold uppercase tracking-wide text-[#77878f]">
                                                            {item.provider}
                                                        </p>
                                                        <p className="mt-2 text-sm leading-6 text-[#4d595e]">{item.visual_reason}</p>
                                                    </div>
                                                ))}
                                            </div>
                                        ) : (
                                            <EmptyNote text="Belum ada kandidat visual tersimpan." />
                                        )}
                                    </DetailSection>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            )}
        </AdminLayout>
    );
}

function ScoreCard({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className={`rounded-lg border p-4 ${strong ? 'border-[#bfe5d8] bg-[#e6f5f0]' : 'border-[#dbe6eb] bg-[#f7fafc]'}`}>
            <p className="text-xs font-bold uppercase tracking-wide text-[#77878f]">{label}</p>
            <p className={`mt-1 text-2xl font-bold ${strong ? 'text-[#088759]' : 'text-[#1b2124]'}`}>{value}</p>
        </div>
    );
}

function DetailSection({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-lg border border-[#dbe6eb] bg-[#f7fafc] p-4">
            <h3 className="mb-3 text-sm font-bold uppercase tracking-wide text-[#4d595e]">{title}</h3>
            {children}
        </section>
    );
}

function EmptyNote({ text }: { text: string }) {
    return (
        <p className="rounded-lg border border-dashed border-[#c3d3db] bg-white px-4 py-3 text-sm leading-6 text-[#77878f]">
            {text}
        </p>
    );
}
