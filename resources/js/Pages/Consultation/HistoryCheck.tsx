import PublicLayout from '@/Layouts/PublicLayout';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { Search } from 'lucide-react';

type HistoryForm = {
    session_code: string;
};

export default function HistoryCheck() {
    const { data, setData, post, processing, errors } = useForm<HistoryForm>({
        session_code: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('consultation.history.check'));
    };

    return (
        <PublicLayout>
            <Head title="Cek Riwayat" />

            <section className="mx-auto grid max-w-[85rem] gap-8 px-4 py-14 lg:grid-cols-[0.85fr_1.15fr] lg:px-8">
                <div>
                    <p className="text-sm font-semibold text-orange-600">Riwayat</p>
                    <h1 className="mt-3 text-4xl font-semibold leading-tight text-neutral-950 sm:text-5xl">
                        Cek ulang hasil konsultasi dengan kode unik.
                    </h1>
                    <p className="mt-5 text-base leading-8 text-neutral-600">
                        Setelah konsultasi selesai, sistem memberi kode seperti
                        <strong> DC-20260718-120000-ABCDE</strong>. Simpan kode itu untuk membuka
                        hasil dan export PDF kapan saja.
                    </p>
                </div>

                <form
                    onSubmit={submit}
                    className="rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm"
                >
                    <label className="block">
                        <span className="text-sm font-semibold text-neutral-800">
                            Kode konsultasi
                        </span>
                        <input
                            value={data.session_code}
                            onChange={(event) => setData('session_code', event.target.value.toUpperCase())}
                            className="mt-2 w-full rounded-xl border-neutral-300 text-sm uppercase focus:border-orange-400 focus:ring-orange-400"
                            placeholder="DC-YYYYMMDD-HHMMSS-XXXXX"
                        />
                        {errors.session_code && (
                            <p className="mt-2 text-sm text-red-600">{errors.session_code}</p>
                        )}
                    </label>

                    <button
                        type="submit"
                        disabled={processing}
                        className="mt-5 inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-full bg-orange-400 px-6 text-sm font-bold text-white transition hover:bg-orange-500 disabled:opacity-60"
                    >
                        <Search className="h-4 w-4" />
                        {processing ? 'Mengecek...' : 'Cek riwayat'}
                    </button>
                </form>
            </section>
        </PublicLayout>
    );
}
