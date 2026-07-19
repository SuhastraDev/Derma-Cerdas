import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    ExternalLink,
    Eye,
    FileText,
    Filter,
    Image,
    Pencil,
    Plus,
    Search,
    Trash2,
    X,
} from 'lucide-react';
import { FormEvent, ReactNode, useEffect, useMemo, useRef, useState } from 'react';

type Field = {
    name: string;
    label: string;
    type?: 'text' | 'textarea' | 'select' | 'checkbox' | 'number' | 'file';
    options?: string;
    nullable?: boolean;
    help?: string;
    multiple?: boolean;
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
        dataset?: DatasetDetail | null;
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
type DatasetDetail = {
    id?: number;
    dataset_class_id?: number;
    dataset_class_name?: string;
    nama_indonesia?: string | null;
    scope_category?: string;
    boleh_rekomendasi_obat?: boolean;
    default_action?: string;
    disease_name?: string | null;
    risk_note?: string | null;
    sample_images?: Array<{
        class_name: string;
        file_name: string;
        url: string;
        thumb_url?: string;
    }>;
    image_count?: number;
};
type DatasetSampleImage = NonNullable<DatasetDetail['sample_images']>[number];
type KnowledgeDetail = {
    title?: string | null;
    dataset?: DatasetDetail | null;
    image_url?: string | null;
    medicine_image_url?: string | null;
    disease_name?: string | null;
    medicine_name?: string | null;
    description?: string | null;
    default_action?: string | null;
    severity_scope?: string | null;
    category?: string | null;
    dosage_form?: string | null;
    usage_instruction?: string | null;
    warnings?: string | null;
    source_note?: string | null;
    recommendation_note?: string | null;
    priority?: number;
    is_limited_otc?: boolean;
    is_active?: boolean;
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

type FormValue = string | number | boolean | null | File | File[];
type FormData = Record<string, FormValue>;

function labelize(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replaceAll('-', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

const humanLabels: Record<string, string> = {
    code: 'Kode',
    name: 'Nama',
    name_indonesian: 'Nama Indonesia',
    severity_scope: 'Tingkat Risiko',
    default_action: 'Arahan Default',
    is_active: 'Aktif',
    input_type: 'Jenis Jawaban',
    is_red_flag_candidate: 'Kandidat Red Flag',
    disease_name: 'Penyakit',
    symptom_name: 'Gejala',
    medicine_name: 'Obat',
    mb: 'MB',
    md: 'MD',
    expert_cf: 'CF Pakar',
    is_required: 'Wajib',
    category: 'Kategori',
    dosage_form: 'Sediaan',
    is_limited_otc: 'OBT',
    priority: 'Urutan',
    question: 'Pertanyaan',
    severity: 'Arahan',
    dataset_class_id: 'No. Class',
    dataset_class_name: 'Class SD-198',
    dataset_name: 'Dataset',
    image_url: 'Gambar',
    nama_indonesia: 'Nama Indonesia',
    scope_category: 'Kategori',
    session_code: 'Kode Riwayat',
    visitor_name: 'Nama User',
    user_name: 'Akun',
    status: 'Status',
    final_score: 'Skor',
    final_action: 'Arahan',
    created_at: 'Tanggal',
    key: 'Key',
    value: 'Value',
    group: 'Grup',
    description: 'Deskripsi',
};

const optionLabels: Record<string, string> = {
    recommend_otc: 'Tampilkan rekomendasi obat terbatas',
    educate_only: 'Edukasi saja',
    refer: 'Rujuk ke tenaga kesehatan',
    swamedikasi: 'Swamedikasi ringan',
    edukasi: 'Edukasi/perawatan umum',
    rujuk: 'Rujukan',
    exclude: 'Di luar scope sistem',
    mild: 'Ringan',
    moderate: 'Sedang',
    danger: 'Bahaya',
    excluded: 'Tidak dipakai',
    scale: 'Skala tingkat keluhan',
    boolean: 'Ya/Tidak',
    choice: 'Pilihan',
    duration: 'Durasi',
    completed: 'Selesai',
    insufficient_confidence: 'Skor belum cukup',
};

function humanLabel(value: string): string {
    return humanLabels[value] ?? labelize(value);
}

function optionLabel(value: string): string {
    return optionLabels[value] ?? labelize(value);
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
        data[field.name] =
            field.type === 'checkbox' ? false : field.type === 'file' ? [] : '';
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
                {optionLabel(text)}
            </span>
        );
    }

    if (['refer', 'rujuk', 'danger'].includes(normalized)) {
        return (
            <span className="inline-flex rounded-lg bg-[#f9e2e6] px-2 py-1 text-xs font-semibold text-[#b11d37]">
                {optionLabel(text)}
            </span>
        );
    }

    if (['exclude', 'excluded', 'insufficient_confidence'].includes(normalized)) {
        return (
            <span className="inline-flex rounded-lg bg-[#ebf2f5] px-2 py-1 text-xs font-semibold text-[#4d595e]">
                {optionLabel(text)}
            </span>
        );
    }

    if (['edukasi', 'moderate'].includes(normalized)) {
        return (
            <span className="inline-flex rounded-lg bg-[#feefe1] px-2 py-1 text-xs font-semibold text-[#d17824]">
                {optionLabel(text)}
            </span>
        );
    }

    return <span>{text}</span>;
}

function percent(value?: number): string {
    return `${Math.round((value ?? 0) * 100)}%`;
}

const pageSize = 25;

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
    const [isFormOpen, setIsFormOpen] = useState(false);
    const [selectedItem, setSelectedItem] = useState<Item | null>(null);
    const [detailImagesLoading, setDetailImagesLoading] = useState(false);
    const [datasetImages, setDatasetImages] = useState<File[]>([]);
    const [searchTerm, setSearchTerm] = useState('');
    const [scopeFilter, setScopeFilter] = useState('');
    const [actionFilter, setActionFilter] = useState('');
    const [medicineFilter, setMedicineFilter] = useState('');
    const [relationFilter, setRelationFilter] = useState('');
    const [currentPage, setCurrentPage] = useState(1);
    const { data, setData, post, put, processing, errors, reset } =
        useForm<FormData>(initialData);

    const filteredItems = useMemo(() => {
        const query = searchTerm.trim().toLowerCase();

        return items.filter((item) => {
            const matchesSearch =
                !query ||
                columns.some((column) =>
                    displayValue(item[column]).toLowerCase().includes(query),
                );

            if (resource !== 'dataset-mappings') {
                return matchesSearch;
            }

            const matchesDatasetFilters =
                (!scopeFilter || item.scope_category === scopeFilter) &&
                (!actionFilter || item.default_action === actionFilter) &&
                (!medicineFilter ||
                    String(Boolean(item.boleh_rekomendasi_obat)) ===
                        medicineFilter) &&
                (!relationFilter ||
                    (relationFilter === 'linked'
                        ? Boolean(item.disease_name)
                        : !item.disease_name));

            return matchesSearch && matchesDatasetFilters;
        });
    }, [
        actionFilter,
        columns,
        items,
        medicineFilter,
        relationFilter,
        resource,
        scopeFilter,
        searchTerm,
    ]);

    const totalPages = Math.max(1, Math.ceil(filteredItems.length / pageSize));
    const activePage = Math.min(currentPage, totalPages);
    const paginatedItems = useMemo(
        () =>
            filteredItems.slice(
                (activePage - 1) * pageSize,
                activePage * pageSize,
            ),
        [activePage, filteredItems],
    );
    const firstItemNumber =
        filteredItems.length === 0 ? 0 : (activePage - 1) * pageSize + 1;
    const lastItemNumber = Math.min(activePage * pageSize, filteredItems.length);
    const showDatasetFilters = resource === 'dataset-mappings';

    const updateSearch = (value: string) => {
        setSearchTerm(value);
        setCurrentPage(1);
    };

    const updateDatasetFilter = (
        setter: (value: string) => void,
        value: string,
    ) => {
        setter(value);
        setCurrentPage(1);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (editingId) {
            put(route('admin.resource.update', [resource, editingId]), {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => cancelEdit(),
            });
            return;
        }

        post(route('admin.resource.store', resource), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setDatasetImages([]);
                setIsFormOpen(false);
                reset();
            },
        });
    };

    const openCreate = () => {
        setEditingId(null);
        setData(initialData);
        setDatasetImages([]);
        reset();
        setIsFormOpen(true);
    };

    const edit = (item: Item) => {
        const nextData = emptyData(fields);

        fields.forEach((field) => {
            const value = item[field.name];
            if (field.type === 'file') {
                nextData[field.name] = [];
                return;
            }

            nextData[field.name] =
                typeof value === 'object' && value !== null
                    ? JSON.stringify(value)
                    : (value as FormValue) ?? (field.type === 'checkbox' ? false : '');
        });

        setEditingId(Number(item.id));
        setData(nextData);
        setDatasetImages([]);
        setIsFormOpen(true);
    };

    const cancelEdit = () => {
        setEditingId(null);
        setData(initialData);
        setDatasetImages([]);
        setIsFormOpen(false);
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
    const selectedDatasetDetail = selectedItem?.detail as DatasetDetail | undefined;
    const selectedKnowledgeDetail = selectedItem?.detail as KnowledgeDetail | undefined;
    const showDetailActions = ['consultations', 'dataset-mappings', 'diseases', 'medicines', 'recommendations'].includes(resource);
    const viewItem = async (item: Item) => {
        setSelectedItem(item);

        if (resource !== 'dataset-mappings') {
            return;
        }

        const detail = item.detail as DatasetDetail | undefined;

        if (!detail?.id) {
            return;
        }

        setDetailImagesLoading(true);

        try {
            const response = await fetch(
                route('admin.dataset-mappings.images', detail.id),
                {
                    headers: {
                        Accept: 'application/json',
                    },
                },
            );

            if (!response.ok) {
                throw new Error('Gagal memuat sample dataset.');
            }

            const payload = (await response.json()) as {
                images?: DatasetSampleImage[];
                image_count?: number;
            };

            setSelectedItem((current) => {
                if (!current || current.id !== item.id) {
                    return current;
                }

                const currentDetail = current.detail as DatasetDetail | undefined;

                return {
                    ...current,
                    detail: {
                        ...currentDetail,
                        sample_images: payload.images ?? [],
                        image_count:
                            payload.image_count ?? currentDetail?.image_count ?? 0,
                    },
                };
            });
        } catch {
            setSelectedItem((current) => {
                if (!current || current.id !== item.id) {
                    return current;
                }

                const currentDetail = current.detail as DatasetDetail | undefined;

                return {
                    ...current,
                    detail: {
                        ...currentDetail,
                        sample_images: [],
                    },
                };
            });
        } finally {
            setDetailImagesLoading(false);
        }
    };

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
                        <div className="flex w-full max-w-xl flex-col gap-3 sm:flex-row sm:items-end">
                            {!readOnly && (
                                <button
                                    type="button"
                                    onClick={openCreate}
                                    className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-[#b7d9ef] bg-[#eaf6ff] px-4 text-sm font-bold text-[#245da8] shadow-sm transition hover:border-[#3385f0] hover:bg-[#3385f0] hover:text-white focus:outline-none focus:ring-2 focus:ring-[#3385f0] focus:ring-offset-2"
                                >
                                    <span className="inline-flex h-7 w-7 items-center justify-center rounded-md bg-white/80 text-[#3385f0] shadow-sm">
                                        <Plus className="h-4 w-4" />
                                    </span>
                                    Tambah {title}
                                </button>
                            )}
                            <label className="w-full">
                                <span className="text-xs font-bold uppercase tracking-wide text-[#77878f]">
                                    Cari data
                                </span>
                                <div className="mt-2 flex min-h-11 items-center gap-2 rounded-lg border border-[#dbe6eb] bg-[#f7fafc] px-3 transition focus-within:border-[#3385f0] focus-within:bg-white">
                                    <Search className="h-4 w-4 text-[#77878f]" />
                                    <input
                                        value={searchTerm}
                                        onChange={(event) =>
                                            updateSearch(event.target.value)
                                        }
                                        placeholder={`Cari ${title.toLowerCase()}...`}
                                        className="w-full border-0 bg-transparent px-0 py-2 text-sm text-[#1b2124] placeholder:text-[#9caeb8] focus:ring-0"
                                    />
                                </div>
                            </label>
                        </div>
                    </div>
                </div>

                {showDatasetFilters && (
                    <div className="rounded-lg border border-[#dbe6eb] bg-white p-5 shadow-sm">
                        <div className="mb-4 flex items-start gap-3">
                            <span className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-[#eaf6ff] text-[#3385f0]">
                                <Filter className="h-5 w-5" />
                            </span>
                            <div>
                                <h2 className="text-base font-bold text-[#1b2124]">
                                    Filter dataset
                                </h2>
                                <p className="mt-1 text-sm leading-6 text-[#77878f]">
                                    Saring berdasarkan scope, arahan, rekomendasi obat, dan relasi penyakit agar review 130 class lebih cepat.
                                </p>
                            </div>
                        </div>

                        <div className="grid gap-3 md:grid-cols-4">
                            <label>
                                <span className="mb-1 block text-xs font-bold uppercase tracking-wide text-[#77878f]">
                                    Kategori penggunaan
                                </span>
                                <select
                                    value={scopeFilter}
                                    onChange={(event) =>
                                        updateDatasetFilter(
                                            setScopeFilter,
                                            event.target.value,
                                        )
                                    }
                                    className="min-h-11 w-full rounded-lg border-[#dbe6eb] bg-[#f7fafc] text-sm text-[#1b2124] shadow-sm focus:border-[#3385f0] focus:ring-[#3385f0]"
                                >
                                    <option value="">Semua kategori</option>
                                    {(options.scopeCategories ?? []).map((option) =>
                                        typeof option === 'string' ? (
                                            <option key={option} value={option}>
                                                {optionLabel(option)}
                                            </option>
                                        ) : null,
                                    )}
                                </select>
                            </label>

                            <label>
                                <span className="mb-1 block text-xs font-bold uppercase tracking-wide text-[#77878f]">
                                    Arahan default
                                </span>
                                <select
                                    value={actionFilter}
                                    onChange={(event) =>
                                        updateDatasetFilter(
                                            setActionFilter,
                                            event.target.value,
                                        )
                                    }
                                    className="min-h-11 w-full rounded-lg border-[#dbe6eb] bg-[#f7fafc] text-sm text-[#1b2124] shadow-sm focus:border-[#3385f0] focus:ring-[#3385f0]"
                                >
                                    <option value="">Semua arahan</option>
                                    {(options.actions ?? []).map((option) =>
                                        typeof option === 'string' ? (
                                            <option key={option} value={option}>
                                                {optionLabel(option)}
                                            </option>
                                        ) : null,
                                    )}
                                </select>
                            </label>

                            <label>
                                <span className="mb-1 block text-xs font-bold uppercase tracking-wide text-[#77878f]">
                                    Rekomendasi obat
                                </span>
                                <select
                                    value={medicineFilter}
                                    onChange={(event) =>
                                        updateDatasetFilter(
                                            setMedicineFilter,
                                            event.target.value,
                                        )
                                    }
                                    className="min-h-11 w-full rounded-lg border-[#dbe6eb] bg-[#f7fafc] text-sm text-[#1b2124] shadow-sm focus:border-[#3385f0] focus:ring-[#3385f0]"
                                >
                                    <option value="">Semua status</option>
                                    <option value="true">Boleh rekomendasi</option>
                                    <option value="false">Tidak rekomendasi</option>
                                </select>
                            </label>

                            <label>
                                <span className="mb-1 block text-xs font-bold uppercase tracking-wide text-[#77878f]">
                                    Relasi penyakit
                                </span>
                                <select
                                    value={relationFilter}
                                    onChange={(event) =>
                                        updateDatasetFilter(
                                            setRelationFilter,
                                            event.target.value,
                                        )
                                    }
                                    className="min-h-11 w-full rounded-lg border-[#dbe6eb] bg-[#f7fafc] text-sm text-[#1b2124] shadow-sm focus:border-[#3385f0] focus:ring-[#3385f0]"
                                >
                                    <option value="">Semua relasi</option>
                                    <option value="linked">Sudah terhubung</option>
                                    <option value="unlinked">Belum terhubung</option>
                                </select>
                            </label>
                        </div>
                    </div>
                )}

                    {!readOnly && isFormOpen && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#1b2124]/55 p-4 backdrop-blur-sm">
                        <form
                            onSubmit={submit}
                            className="max-h-[92vh] w-full max-w-5xl overflow-hidden rounded-lg border border-[#c9dce5] bg-white shadow-2xl"
                        >
                            <div className="flex flex-col gap-4 border-b border-[#dbe6eb] bg-[#f7fafc] p-5 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex items-start gap-3">
                                    <span className="mt-0.5 inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-[#eaf6ff] text-[#3385f0] ring-1 ring-[#b7d9ef]">
                                        {editingId ? (
                                            <Pencil className="h-5 w-5" />
                                        ) : (
                                            <Plus className="h-5 w-5" />
                                        )}
                                    </span>
                                    <div>
                                        <p className="text-xs font-bold uppercase tracking-wide text-[#3385f0]">
                                            {editingId ? 'Perbarui data' : 'Data baru'}
                                        </p>
                                        <h2 className="mt-1 text-xl font-bold text-[#1b2124]">
                                            {editingId ? `Edit ${title}` : `Tambah ${title}`}
                                        </h2>
                                        <p className="mt-1 max-w-2xl text-sm leading-6 text-[#77878f]">
                                            Isi field penting, pilih relasi dataset bila tersedia, lalu simpan agar data admin tetap mudah diaudit.
                                        </p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={cancelEdit}
                                    aria-label="Tutup form"
                                    title="Tutup form"
                                    className="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-[#dbe6eb] bg-white text-[#4d595e] transition hover:bg-[#ebf2f5] hover:text-[#1b2124]"
                                >
                                    <X className="h-5 w-5" />
                                </button>
                            </div>

                            <div className="max-h-[calc(92vh-205px)] overflow-y-auto bg-white p-5">
                            <div className="grid gap-4 md:grid-cols-2">
                                {fields.map((field) => (
                                    <label
                                        key={field.name}
                                        className={`rounded-lg border border-[#dbe6eb] bg-white p-4 shadow-sm transition focus-within:border-[#3385f0] focus-within:shadow-md ${
                                            field.type === 'textarea' ||
                                            (field.type === 'file' && field.multiple)
                                                ? 'md:col-span-2'
                                                : ''
                                        }`}
                                    >
                                        <span className="mb-1 block text-sm font-semibold text-[#4d595e]">
                                            {field.label}
                                        </span>
                                        {field.help && (
                                            <span className="mb-2 block text-xs leading-5 text-[#77878f]">
                                                {field.help}
                                            </span>
                                        )}

                                        {field.type === 'file' ? (
                                            <div className="rounded-lg border border-dashed border-[#b7d9ef] bg-[#f7fafc] p-4">
                                                <input
                                                    type="file"
                                                    accept="image/jpeg,image/png,image/webp"
                                                    multiple={field.multiple ?? false}
                                                    onChange={(event) => {
                                                        const files = Array.from(event.target.files ?? []);
                                                        setDatasetImages(files);
                                                        setData(field.name, field.multiple ? files : (files[0] ?? null));
                                                    }}
                                                    className="w-full text-sm text-[#4d595e] file:me-3 file:rounded-lg file:border-0 file:bg-[#3385f0] file:px-3 file:py-2 file:text-sm file:font-bold file:text-white hover:file:bg-[#2b71cc]"
                                                />
                                                {datasetImages.length > 0 && (
                                                    <div className="mt-3 grid gap-2">
                                                        {datasetImages.map((file) => (
                                                            <div key={`${file.name}-${file.size}`} className="rounded-md bg-white px-3 py-2 text-xs text-[#4d595e]">
                                                                {file.name} / {Math.round(file.size / 1024)} KB
                                                            </div>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                        ) : field.type === 'textarea' ? (
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
                                                className="min-h-11 w-full rounded-lg border-[#dbe6eb] bg-[#f7fafc] text-sm text-[#1b2124] shadow-sm transition focus:border-[#3385f0] focus:bg-white focus:ring-[#3385f0]"
                                            >
                                                <option value="">
                                                    {field.nullable ? 'Kosongkan' : 'Pilih'}
                                                </option>
                                                {(options[field.options ?? ''] ?? []).map(
                                                    (option) =>
                                                        typeof option === 'string' ? (
                                                            <option key={option} value={option}>
                                                                {optionLabel(option)}
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
                                                className="min-h-11 w-full rounded-lg border-[#dbe6eb] bg-[#f7fafc] text-sm text-[#1b2124] shadow-sm transition focus:border-[#3385f0] focus:bg-white focus:ring-[#3385f0]"
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
                            </div>

                            <div className="flex flex-col-reverse gap-3 border-t border-[#dbe6eb] bg-[#f7fafc] p-5 sm:flex-row sm:items-center sm:justify-end">
                                <button
                                    type="button"
                                    onClick={cancelEdit}
                                    className="inline-flex min-h-11 items-center justify-center rounded-lg border border-[#c3d3db] bg-white px-4 text-sm font-bold text-[#4d595e] transition hover:bg-[#ebf2f5] hover:text-[#1b2124]"
                                >
                                    Batal
                                </button>
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-[#3385f0] px-5 text-sm font-bold text-white shadow-sm transition hover:bg-[#2b71cc] disabled:opacity-60"
                                >
                                    <Plus className="h-4 w-4" />
                                    {editingId ? 'Simpan perubahan' : `Tambah ${title}`}
                                </button>
                            </div>
                        </form>
                        </div>
                    )}

                    <div className="overflow-hidden rounded-lg border border-[#dbe6eb] bg-white shadow-sm">
                        <div className="flex flex-col justify-between gap-3 border-b border-[#dbe6eb] p-5 sm:flex-row sm:items-center">
                            <div>
                                <h2 className="text-base font-bold text-[#1b2124]">
                                    Daftar {title.toLowerCase()}
                                </h2>
                                <p className="mt-1 text-sm text-[#77878f]">
                                    Menampilkan {firstItemNumber}-{lastItemNumber} dari {filteredItems.length} data tersaring.
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
                                                {humanLabel(column)}
                                            </th>
                                        ))}
                                        {(showDetailActions || !readOnly) && (
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
                                                colSpan={columns.length + (showDetailActions || !readOnly ? 1 : 0)}
                                                className="px-5 py-10 text-center text-[#77878f]"
                                            >
                                                Data tidak ditemukan. Coba kata kunci lain.
                                            </td>
                                        </tr>
                                    ) : (
                                        paginatedItems.map((item) => (
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
                                                            {column === 'image_url' && item[column] ? (
                                                                <img
                                                                    src={String(item[column])}
                                                                    alt="Gambar obat"
                                                                    className="h-14 w-14 rounded-md border border-[#dbe6eb] object-cover"
                                                                />
                                                            ) : (
                                                                valueBadge(item[column])
                                                            )}
                                                        </div>
                                                    </td>
                                                ))}
                                                {(showDetailActions || !readOnly) && (
                                                    <td className="whitespace-nowrap px-5 py-4 text-right">
                                                        {showDetailActions && (
                                                        <button
                                                            type="button"
                                                            onClick={() => void viewItem(item)}
                                                            aria-label={resource === 'dataset-mappings' ? 'Lihat dataset' : 'Lihat detail'}
                                                            title={resource === 'dataset-mappings' ? 'Lihat dataset' : 'Lihat detail'}
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-lg font-semibold text-[#3385f0] transition hover:bg-[#eaf3fd] hover:text-[#245da8]"
                                                        >
                                                            <Eye className="h-4 w-4" />
                                                        </button>
                                                        )}
                                                        {!readOnly && (
                                                        <>
                                                        <button
                                                            type="button"
                                                            onClick={() => edit(item)}
                                                            aria-label="Edit data"
                                                            title="Edit data"
                                                            className="inline-flex h-9 w-9 items-center justify-center rounded-lg font-semibold text-[#3385f0] transition hover:bg-[#eaf3fd] hover:text-[#245da8]"
                                                        >
                                                            <Pencil className="h-4 w-4" />
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => destroy(item)}
                                                            aria-label="Hapus data"
                                                            title="Hapus data"
                                                            className="ms-2 inline-flex h-9 w-9 items-center justify-center rounded-lg font-semibold text-red-600 transition hover:bg-red-50 hover:text-red-800"
                                                        >
                                                            <Trash2 className="h-4 w-4" />
                                                        </button>
                                                        </>
                                                        )}
                                                    </td>
                                                )}
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <div className="flex flex-col gap-3 border-t border-[#dbe6eb] bg-[#f7fafc] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-sm text-[#77878f]">
                                Halaman {activePage} dari {totalPages} / 25 data per halaman
                            </p>
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() =>
                                        setCurrentPage((page) =>
                                            Math.max(1, page - 1),
                                        )
                                    }
                                    disabled={activePage === 1}
                                    className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-[#dbe6eb] bg-white px-3 text-sm font-bold text-[#4d595e] transition hover:bg-[#ebf2f5] disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Sebelumnya
                                </button>
                                <button
                                    type="button"
                                    onClick={() =>
                                        setCurrentPage((page) =>
                                            Math.min(totalPages, page + 1),
                                        )
                                    }
                                    disabled={activePage === totalPages}
                                    className="inline-flex min-h-10 items-center gap-2 rounded-lg bg-[#3385f0] px-3 text-sm font-bold text-white transition hover:bg-[#2b71cc] disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    Berikutnya
                                    <ChevronRight className="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>
            </section>

            {selectedItem && resource === 'consultations' && selectedDetail && (
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

                                    <DatasetPanel
                                        dataset={selectedDetail.final_result?.dataset ?? null}
                                    />

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

            {selectedItem && resource === 'dataset-mappings' && selectedDatasetDetail && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#1b2124]/55 p-4 backdrop-blur-sm">
                    <section className="max-h-[92vh] w-full max-w-5xl overflow-hidden rounded-lg border border-[#dbe6eb] bg-white shadow-2xl">
                        <div className="flex items-start justify-between gap-4 border-b border-[#dbe6eb] bg-[#f7fafc] p-5">
                            <div>
                                <p className="text-xs font-bold uppercase tracking-wide text-[#3385f0]">
                                    Detail dataset SD-198
                                </p>
                                <h2 className="mt-1 text-xl font-bold text-[#1b2124]">
                                    {selectedDatasetDetail.dataset_class_name ?? '-'}
                                </h2>
                                <p className="mt-1 text-sm text-[#77878f]">
                                    Class #{selectedDatasetDetail.dataset_class_id ?? '-'} / {selectedDatasetDetail.nama_indonesia ?? 'Nama Indonesia belum diisi'}
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
                            <div className="grid gap-5 lg:grid-cols-[0.9fr_1.2fr]">
                                <div className="space-y-3">
                                    <DatasetFact label="Format class" value={`datasets/sd-198/images/${selectedDatasetDetail.dataset_class_name ?? '-'}`} />
                                    <DatasetFact label="Penyakit lokal" value={selectedDatasetDetail.disease_name ?? 'Belum dihubungkan'} />
                                    <DatasetFact label="Kategori penggunaan" value={optionLabel(selectedDatasetDetail.scope_category ?? '-')} />
                                    <DatasetFact label="Arahan default" value={optionLabel(selectedDatasetDetail.default_action ?? '-')} />
                                    <DatasetFact label="Total gambar" value={`${selectedDatasetDetail.image_count ?? 0} gambar`} />
                                    <DatasetFact
                                        label="Rekomendasi obat"
                                        value={selectedDatasetDetail.boleh_rekomendasi_obat ? 'Boleh jika skor aman dan tidak ada red flag' : 'Tidak ditampilkan untuk class ini'}
                                    />
                                    {selectedDatasetDetail.risk_note && (
                                        <div className="rounded-lg border border-[#fecc8f] bg-[#fff7ed] p-4">
                                            <p className="text-xs font-bold uppercase tracking-wide text-[#b35d16]">
                                                Catatan risiko
                                            </p>
                                            <p className="mt-2 text-sm leading-6 text-[#7c3f10]">
                                                {selectedDatasetDetail.risk_note}
                                            </p>
                                        </div>
                                    )}
                                </div>

                                <div className="rounded-lg border border-[#dbe6eb] bg-[#f7fafc] p-4">
                                    <div className="mb-3 flex items-center justify-between gap-3">
                                        <div>
                                            <p className="text-sm font-bold text-[#1b2124]">
                                                Contoh gambar dataset
                                            </p>
                                            <p className="mt-1 text-xs leading-5 text-[#77878f]">
                                                Menampilkan hingga 6 sample yang berhasil dibuka browser.
                                            </p>
                                        </div>
                                        <Image className="h-5 w-5 text-[#3385f0]" />
                                    </div>

                                    {detailImagesLoading ? (
                                        <EmptyNote text="Sedang memuat sample gambar yang valid..." />
                                    ) : (selectedDatasetDetail.sample_images ?? []).length > 0 ? (
                                        <DatasetSampleGrid images={selectedDatasetDetail.sample_images ?? []} />
                                    ) : (
                                        <EmptyNote text="Contoh gambar belum ditemukan. Pastikan nama class sama persis dengan folder di datasets/sd-198/images." />
                                    )}
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            )}

            {selectedItem && ['diseases', 'medicines', 'recommendations'].includes(resource) && selectedKnowledgeDetail && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-[#1b2124]/55 p-4 backdrop-blur-sm">
                    <section className="max-h-[92vh] w-full max-w-5xl overflow-hidden rounded-lg border border-[#dbe6eb] bg-white shadow-2xl">
                        <div className="flex items-start justify-between gap-4 border-b border-[#dbe6eb] bg-[#f7fafc] p-5">
                            <div>
                                <p className="text-xs font-bold uppercase tracking-wide text-[#3385f0]">
                                    Detail {title.toLowerCase()}
                                </p>
                                <h2 className="mt-1 text-xl font-bold text-[#1b2124]">
                                    {selectedKnowledgeDetail.title ?? selectedKnowledgeDetail.medicine_name ?? selectedKnowledgeDetail.disease_name ?? '-'}
                                </h2>
                                <p className="mt-1 text-sm text-[#77878f]">
                                    Terhubung ke dataset: {selectedKnowledgeDetail.dataset?.dataset_class_name ?? 'Belum dipilih'}
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
                            <div className="grid gap-5 lg:grid-cols-[0.85fr_1.25fr]">
                                <div className="space-y-3">
                                    {(selectedKnowledgeDetail.image_url || selectedKnowledgeDetail.medicine_image_url) && (
                                        <div className="rounded-lg border border-[#dbe6eb] bg-white p-3">
                                            <p className="mb-2 text-xs font-bold uppercase tracking-wide text-[#77878f]">
                                                Gambar obat
                                            </p>
                                            <img
                                                src={selectedKnowledgeDetail.image_url ?? selectedKnowledgeDetail.medicine_image_url ?? ''}
                                                alt="Gambar obat"
                                                className="h-56 w-full rounded-md object-cover"
                                            />
                                        </div>
                                    )}

                                    {resource === 'diseases' && (
                                        <DatasetFact label="Penyakit" value={selectedKnowledgeDetail.title ?? '-'} />
                                    )}
                                    {resource !== 'diseases' && (
                                        <DatasetFact label="Penyakit" value={selectedKnowledgeDetail.disease_name ?? '-'} />
                                    )}
                                    {resource !== 'diseases' && (
                                        <DatasetFact label="Obat" value={selectedKnowledgeDetail.medicine_name ?? selectedKnowledgeDetail.title ?? '-'} />
                                    )}
                                    {selectedKnowledgeDetail.category && <DatasetFact label="Kategori obat" value={selectedKnowledgeDetail.category} />}
                                    {selectedKnowledgeDetail.dosage_form && <DatasetFact label="Bentuk sediaan" value={selectedKnowledgeDetail.dosage_form} />}
                                    {selectedKnowledgeDetail.default_action && <DatasetFact label="Arahan default" value={optionLabel(selectedKnowledgeDetail.default_action)} />}
                                    {selectedKnowledgeDetail.severity_scope && <DatasetFact label="Tingkat risiko" value={optionLabel(selectedKnowledgeDetail.severity_scope)} />}
                                    {selectedKnowledgeDetail.priority !== undefined && <DatasetFact label="Urutan tampil" value={String(selectedKnowledgeDetail.priority)} />}
                                </div>

                                <div className="space-y-4">
                                    <DatasetPanel
                                        dataset={selectedKnowledgeDetail.dataset ?? null}
                                    />

                                    {selectedKnowledgeDetail.description && (
                                        <DetailSection title="Deskripsi">
                                            <p className="rounded-lg border border-[#dbe6eb] bg-white p-4 text-sm leading-6 text-[#4d595e]">
                                                {selectedKnowledgeDetail.description}
                                            </p>
                                        </DetailSection>
                                    )}
                                    {selectedKnowledgeDetail.usage_instruction && (
                                        <DetailSection title="Aturan pakai">
                                            <p className="rounded-lg border border-[#dbe6eb] bg-white p-4 text-sm leading-6 text-[#4d595e]">
                                                {selectedKnowledgeDetail.usage_instruction}
                                            </p>
                                        </DetailSection>
                                    )}
                                    {selectedKnowledgeDetail.recommendation_note && (
                                        <DetailSection title="Catatan rekomendasi">
                                            <p className="rounded-lg border border-[#dbe6eb] bg-white p-4 text-sm leading-6 text-[#4d595e]">
                                                {selectedKnowledgeDetail.recommendation_note}
                                            </p>
                                        </DetailSection>
                                    )}
                                    {selectedKnowledgeDetail.warnings && (
                                        <DetailSection title="Peringatan">
                                            <p className="rounded-lg border border-[#fecc8f] bg-[#fff7ed] p-4 text-sm leading-6 text-[#7c3f10]">
                                                {selectedKnowledgeDetail.warnings}
                                            </p>
                                        </DetailSection>
                                    )}
                                    {selectedKnowledgeDetail.source_note && (
                                        <DetailSection title="Sumber">
                                            <p className="rounded-lg border border-[#dbe6eb] bg-white p-4 text-sm leading-6 text-[#4d595e]">
                                                {selectedKnowledgeDetail.source_note}
                                            </p>
                                        </DetailSection>
                                    )}
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

function DatasetFact({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-lg border border-[#dbe6eb] bg-white p-4">
            <p className="text-xs font-bold uppercase tracking-wide text-[#77878f]">{label}</p>
            <p className="mt-2 break-words text-sm font-semibold leading-6 text-[#1b2124]">{value}</p>
        </div>
    );
}

function DatasetPanel({ dataset }: { dataset: DatasetDetail | null }) {
    return (
        <DetailSection title="Dataset terkait">
            {dataset ? (
                <div className="grid gap-4">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <DatasetFact label="Class SD-198" value={dataset.dataset_class_name ?? '-'} />
                        <DatasetFact label="Nomor class" value={String(dataset.dataset_class_id ?? '-')} />
                        <DatasetFact label="Nama Indonesia" value={dataset.nama_indonesia ?? '-'} />
                        <DatasetFact label="Kategori penggunaan" value={optionLabel(dataset.scope_category ?? '-')} />
                        <DatasetFact label="Total gambar" value={`${dataset.image_count ?? 0} gambar`} />
                    </div>
                    {(dataset.sample_images ?? []).length > 0 ? (
                        <div className="space-y-3">
                            <p className="text-sm font-semibold text-[#4d595e]">
                                Sample gambar dataset
                            </p>
                            <DatasetSampleGrid images={dataset.sample_images ?? []} />
                        </div>
                    ) : (
                        <EmptyNote text="Gambar dataset belum tersedia untuk class ini." />
                    )}
                </div>
            ) : (
                <EmptyNote text="Elemen ini belum dihubungkan ke dataset. Pilih dataset agar knowledge base tidak berdiri sendiri." />
            )}
        </DetailSection>
    );
}

function DatasetSampleGrid({ images, limit = 6 }: { images: DatasetSampleImage[]; limit?: number }) {
    const [slots, setSlots] = useState<number[]>([]);
    const nextIndexRef = useRef(0);

    useEffect(() => {
        const initialCount = Math.min(limit, images.length);
        setSlots(Array.from({ length: initialCount }, (_, index) => index));
        nextIndexRef.current = initialCount;
    }, [images, limit]);

    const replaceFailedImage = (slotPosition: number) => {
        setSlots((currentSlots) => {
            const nextIndex = nextIndexRef.current;

            if (nextIndex < images.length) {
                nextIndexRef.current = nextIndex + 1;

                return currentSlots.map((imageIndex, index) =>
                    index === slotPosition ? nextIndex : imageIndex,
                );
            }

            return currentSlots.filter((_, index) => index !== slotPosition);
        });
    };

    if (slots.length === 0) {
        return <EmptyNote text="Belum ada sample gambar yang bisa ditampilkan." />;
    }

    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {slots.map((imageIndex, slotPosition) => {
                const image = images[imageIndex];

                if (! image) {
                    return null;
                }

                return (
                    <DatasetSampleFigure
                        key={`${slotPosition}-${image.class_name}-${image.file_name}`}
                        image={image}
                        onFailed={() => replaceFailedImage(slotPosition)}
                    />
                );
            })}
        </div>
    );
}

function DatasetSampleFigure({ image, onFailed }: { image: DatasetSampleImage; onFailed: () => void }) {
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        setFailed(false);
    }, [image]);

    return (
        <figure className="rounded-lg border border-[#dbe6eb] bg-white p-2">
            <img
                src={image.thumb_url ?? image.url}
                alt={`Contoh dataset ${image.class_name}`}
                onError={() => {
                    if (! failed) {
                        setFailed(true);
                        onFailed();
                    }
                }}
                className="aspect-[4/3] w-full rounded-md object-cover"
                loading="lazy"
                decoding="async"
            />
            <figcaption className="mt-2 break-words text-xs leading-5 text-[#4d595e]">
                {image.file_name}
            </figcaption>
        </figure>
    );
}
