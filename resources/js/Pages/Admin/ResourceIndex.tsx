import AdminLayout from '@/Layouts/AdminLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useMemo, useState } from 'react';

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

type Item = Record<string, string | number | boolean | null | object>;

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

const resourceLinks = [
    ['diseases', 'Penyakit'],
    ['symptoms', 'Gejala'],
    ['rules', 'Rule CF'],
    ['medicines', 'Obat'],
    ['recommendations', 'Rekomendasi'],
    ['red-flags', 'Red Flags'],
    ['dataset-mappings', 'Dataset'],
    ['settings', 'Pengaturan'],
    ['consultations', 'Konsultasi'],
];

function labelize(value: string): string {
    return value
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function displayValue(value: Item[string]): string {
    if (typeof value === 'boolean') {
        return value ? 'Ya' : 'Tidak';
    }

    if (value === null || value === undefined) {
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
    const { data, setData, post, put, processing, errors, reset } =
        useForm<FormData>(initialData);

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

    return (
        <AdminLayout
            header={
                <div>
                    <h1 className="text-2xl font-semibold text-gray-900">
                        {title}
                    </h1>
                    <p className="mt-1 text-sm text-gray-600">{description}</p>
                </div>
            }
        >
            <Head title={title} />

            <div className="grid gap-6 lg:grid-cols-[220px_1fr]">
                <aside className="rounded-lg border border-gray-200 bg-white p-3">
                    <nav className="space-y-1">
                        {resourceLinks.map(([slug, label]) => (
                            <a
                                key={slug}
                                href={route('admin.resource.index', slug)}
                                className={[
                                    'block rounded-md px-3 py-2 text-sm font-medium',
                                    slug === resource
                                        ? 'bg-emerald-50 text-emerald-700'
                                        : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900',
                                ].join(' ')}
                            >
                                {label}
                            </a>
                        ))}
                    </nav>
                </aside>

                <section className="space-y-6">
                    {!readOnly && (
                        <form
                            onSubmit={submit}
                            className="rounded-lg border border-gray-200 bg-white p-5 shadow-sm"
                        >
                            <div className="mb-4 flex items-center justify-between gap-4">
                                <h2 className="text-base font-semibold text-gray-900">
                                    {editingId ? 'Edit Data' : 'Tambah Data'}
                                </h2>
                                {editingId && (
                                    <button
                                        type="button"
                                        onClick={cancelEdit}
                                        className="text-sm font-medium text-gray-500 hover:text-gray-900"
                                    >
                                        Batal
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
                                        <span className="mb-1 block text-sm font-medium text-gray-700">
                                            {field.label}
                                        </span>

                                        {field.type === 'textarea' ? (
                                            <textarea
                                                value={String(data[field.name] ?? '')}
                                                onChange={(event) =>
                                                    setData(field.name, event.target.value)
                                                }
                                                rows={3}
                                                className="w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
                                            />
                                        ) : field.type === 'select' ? (
                                            <select
                                                value={String(data[field.name] ?? '')}
                                                onChange={(event) =>
                                                    setData(field.name, event.target.value)
                                                }
                                                className="w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
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
                                            <input
                                                type="checkbox"
                                                checked={Boolean(data[field.name])}
                                                onChange={(event) =>
                                                    setData(field.name, event.target.checked)
                                                }
                                                className="rounded border-gray-300 text-emerald-600 shadow-sm focus:ring-emerald-500"
                                            />
                                        ) : (
                                            <input
                                                type={field.type === 'number' ? 'number' : 'text'}
                                                step={field.name.includes('cf') || ['mb', 'md'].includes(field.name) ? '0.01' : '1'}
                                                value={String(data[field.name] ?? '')}
                                                onChange={(event) =>
                                                    setData(field.name, event.target.value)
                                                }
                                                className="w-full rounded-md border-gray-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500"
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
                                className="mt-5 rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 disabled:opacity-60"
                            >
                                {editingId ? 'Simpan Perubahan' : 'Tambah'}
                            </button>
                        </form>
                    )}

                    <div className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200 text-sm">
                                <thead className="bg-gray-50">
                                    <tr>
                                        {columns.map((column) => (
                                            <th
                                                key={column}
                                                className="px-4 py-3 text-left font-semibold text-gray-700"
                                            >
                                                {labelize(column)}
                                            </th>
                                        ))}
                                        {!readOnly && (
                                            <th className="px-4 py-3 text-right font-semibold text-gray-700">
                                                Aksi
                                            </th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {items.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={columns.length + (readOnly ? 0 : 1)}
                                                className="px-4 py-6 text-center text-gray-500"
                                            >
                                                Belum ada data.
                                            </td>
                                        </tr>
                                    ) : (
                                        items.map((item) => (
                                            <tr key={String(item.id)}>
                                                {columns.map((column) => (
                                                    <td
                                                        key={column}
                                                        className="max-w-[280px] truncate px-4 py-3 text-gray-700"
                                                    >
                                                        {displayValue(item[column])}
                                                    </td>
                                                ))}
                                                {!readOnly && (
                                                    <td className="whitespace-nowrap px-4 py-3 text-right">
                                                        <button
                                                            type="button"
                                                            onClick={() => edit(item)}
                                                            className="font-medium text-emerald-700 hover:text-emerald-900"
                                                        >
                                                            Edit
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => destroy(item)}
                                                            className="ms-3 font-medium text-red-600 hover:text-red-800"
                                                        >
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
            </div>
        </AdminLayout>
    );
}
