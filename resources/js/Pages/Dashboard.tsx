import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const adminModules = [
    ['diseases', 'Penyakit', 'Master penyakit lokal untuk mesin keputusan.'],
    ['symptoms', 'Gejala', 'Pertanyaan anamnesis dan gejala CF.'],
    ['rules', 'Rule CF', 'Bobot MB/MD untuk tiap penyakit dan gejala.'],
    ['medicines', 'Obat', 'Master obat/edukasi yang aman untuk swamedikasi.'],
    ['recommendations', 'Rekomendasi', 'Mapping penyakit ke obat atau edukasi.'],
    ['red-flags', 'Red Flags', 'Kondisi bahaya yang memblokir rekomendasi obat.'],
    ['dataset-mappings', 'Dataset', 'Mapping class SD-198 ke scope sistem.'],
    ['consultations', 'Riwayat', 'Audit konsultasi dan hasil sistem.'],
];

export default function Dashboard() {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Dashboard
                </h2>
            }
        >
            <Head title="Dashboard" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="mb-6 rounded-lg border border-emerald-100 bg-white p-6 shadow-sm">
                        <p className="text-sm font-medium text-emerald-700">
                            Admin DermaCerdas
                        </p>
                        <h3 className="mt-2 text-2xl font-semibold text-gray-950">
                            Knowledge base dan audit konsultasi
                        </h3>
                        <p className="mt-2 max-w-3xl text-sm text-gray-600">
                            Kelola penyakit, gejala, rule Certainty Factor,
                            obat, red flags, mapping dataset SD-198, dan
                            riwayat konsultasi dari satu panel.
                        </p>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        {adminModules.map(([slug, title, description]) => (
                            <Link
                                key={slug}
                                href={route('admin.resource.index', slug)}
                                className="rounded-lg border border-gray-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-md"
                            >
                                <h4 className="font-semibold text-gray-950">
                                    {title}
                                </h4>
                                <p className="mt-2 text-sm leading-6 text-gray-600">
                                    {description}
                                </p>
                            </Link>
                        ))}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
