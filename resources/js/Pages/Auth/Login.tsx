import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    Database,
    HeartPulse,
    LockKeyhole,
    Mail,
    ShieldCheck,
    Sparkles,
} from 'lucide-react';
import { FormEventHandler } from 'react';

export default function Login({
    status,
    canResetPassword,
}: {
    status?: string;
    canResetPassword: boolean;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false as boolean,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    const useDemoAccount = () => {
        setData({
            email: 'admin@dermacerdas.local',
            password: 'password',
            remember: true,
        });
    };

    return (
        <main className="min-h-screen bg-neutral-100 text-neutral-950">
            <Head title="Login Admin" />

            <div className="mx-auto grid min-h-screen max-w-7xl gap-6 px-4 py-5 lg:grid-cols-[0.95fr_1.05fr] lg:px-8">
                <section className="flex min-h-[420px] flex-col justify-between overflow-hidden rounded-2xl border border-neutral-800 bg-neutral-950 p-6 text-white shadow-sm">
                    <div>
                        <Link
                            href={route('consultation.start')}
                            className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-white/15 bg-white/10 px-3 text-sm font-semibold text-white transition hover:bg-white/15"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Kembali ke konsultasi
                        </Link>

                        <div className="mt-12 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-semibold">
                            <Sparkles className="h-4 w-4" />
                            DermaCerdas Admin
                        </div>
                        <h1 className="mt-5 max-w-xl text-4xl font-semibold leading-tight">
                            Ruang kerja untuk menjaga knowledge base tetap aman.
                        </h1>
                        <p className="mt-4 max-w-2xl text-sm leading-6 text-neutral-300">
                            Login admin digunakan untuk mengelola penyakit, gejala,
                            rule Certainty Factor, obat, red flags, mapping SD-198,
                            dan audit konsultasi.
                        </p>
                    </div>

                    <div className="mt-10 grid gap-3 sm:grid-cols-3">
                        {[
                            {
                                label: 'Rule CF',
                                text: 'Bobot gejala',
                                icon: HeartPulse,
                            },
                            {
                                label: 'Dataset',
                                text: 'Mapping SD-198',
                                icon: Database,
                            },
                            {
                                label: 'Safety',
                                text: 'Red flags aktif',
                                icon: ShieldCheck,
                            },
                        ].map((item) => {
                            const Icon = item.icon;

                            return (
                                <div
                                    key={item.label}
                                    className="rounded-lg border border-white/15 bg-white/10 p-4"
                                >
                                    <Icon className="h-5 w-5 text-orange-300" />
                                    <p className="mt-3 text-sm font-semibold">
                                        {item.label}
                                    </p>
                                    <p className="mt-1 text-xs text-neutral-400">
                                        {item.text}
                                    </p>
                                </div>
                            );
                        })}
                    </div>
                </section>

                <section className="flex items-center justify-center">
                    <div className="w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <p className="text-sm font-semibold text-orange-600">
                                    Login admin
                                </p>
                                <h2 className="mt-2 text-2xl font-semibold text-slate-950">
                                    Masuk ke panel DermaCerdas
                                </h2>
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                    Gunakan akun admin untuk mengubah knowledge base dan
                                    mengecek riwayat konsultasi.
                                </p>
                            </div>
                            <span className="rounded-xl bg-yellow-50 p-3 text-orange-700">
                                <LockKeyhole className="h-6 w-6" />
                            </span>
                        </div>

                        <div className="mt-5 rounded-xl border border-yellow-200 bg-yellow-50 p-4">
                            <p className="text-sm font-semibold text-neutral-950">
                                Akun demo lokal
                            </p>
                            <div className="mt-3 grid gap-2 text-sm text-neutral-800 sm:grid-cols-2">
                                <div>
                                    <span className="block text-xs uppercase tracking-wide text-orange-700">
                                        Email
                                    </span>
                                    <code>admin@dermacerdas.local</code>
                                </div>
                                <div>
                                    <span className="block text-xs uppercase tracking-wide text-orange-700">
                                        Password
                                    </span>
                                    <code>password</code>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={useDemoAccount}
                                className="mt-4 inline-flex min-h-9 items-center justify-center rounded-lg bg-orange-400 px-3 text-sm font-bold text-neutral-50 transition hover:bg-orange-500"
                            >
                                Isi akun demo
                            </button>
                        </div>

                        {status && (
                            <div className="mt-4 rounded-md bg-yellow-50 p-3 text-sm font-medium text-orange-700">
                                {status}
                            </div>
                        )}

                        <form onSubmit={submit} className="mt-6 space-y-4">
                            <div>
                                <label
                                    htmlFor="email"
                                    className="mb-1 block text-sm font-medium text-slate-700"
                                >
                                    Email
                                </label>
                                <div className="flex items-center gap-2 rounded-lg border border-slate-300 px-3 shadow-sm focus-within:border-orange-400 focus-within:ring-1 focus-within:ring-orange-400">
                                    <Mail className="h-4 w-4 text-slate-500" />
                                    <input
                                        id="email"
                                        type="email"
                                        name="email"
                                        value={data.email}
                                        className="w-full border-0 px-0 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:ring-0"
                                        autoComplete="username"
                                        autoFocus
                                        placeholder="admin@dermacerdas.local"
                                        onChange={(e) => setData('email', e.target.value)}
                                    />
                                </div>
                                <InputError message={errors.email} className="mt-2" />
                            </div>

                            <div>
                                <label
                                    htmlFor="password"
                                    className="mb-1 block text-sm font-medium text-slate-700"
                                >
                                    Password
                                </label>
                                <div className="flex items-center gap-2 rounded-lg border border-slate-300 px-3 shadow-sm focus-within:border-orange-400 focus-within:ring-1 focus-within:ring-orange-400">
                                    <LockKeyhole className="h-4 w-4 text-slate-500" />
                                    <input
                                        id="password"
                                        type="password"
                                        name="password"
                                        value={data.password}
                                        className="w-full border-0 px-0 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:ring-0"
                                        autoComplete="current-password"
                                        placeholder="Masukkan password"
                                        onChange={(e) =>
                                            setData('password', e.target.value)
                                        }
                                    />
                                </div>
                                <InputError message={errors.password} className="mt-2" />
                            </div>

                            <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                                <label className="flex items-center">
                                    <Checkbox
                                        name="remember"
                                        checked={data.remember}
                                        onChange={(e) =>
                                            setData(
                                                'remember',
                                                (e.target.checked || false) as false,
                                            )
                                        }
                                    />
                                    <span className="ms-2 text-sm text-slate-600">
                                        Ingat sesi admin
                                    </span>
                                </label>

                                {canResetPassword && (
                                    <Link
                                        href={route('password.request')}
                                    className="rounded-md text-sm font-medium text-orange-600 hover:text-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-400 focus:ring-offset-2"
                                    >
                                        Lupa password?
                                    </Link>
                                )}
                            </div>

                            <button
                                type="submit"
                                disabled={processing}
                                className="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-orange-400 px-5 text-sm font-bold text-neutral-50 shadow-sm transition hover:bg-orange-500 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {processing ? 'Memeriksa akun...' : 'Masuk admin'}
                            </button>
                        </form>
                    </div>
                </section>
            </div>
        </main>
    );
}
