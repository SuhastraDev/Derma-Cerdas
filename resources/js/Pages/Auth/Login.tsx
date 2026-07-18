import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
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

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

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
        <main className="min-h-screen bg-[#eef4f2] text-slate-950">
            <Head title="Login Admin" />

            <div className="mx-auto grid min-h-screen max-w-7xl gap-5 px-4 py-5 lg:grid-cols-[0.95fr_1.05fr] lg:px-8">
                <section className="relative flex min-h-[460px] flex-col justify-between overflow-hidden rounded-lg bg-neutral-950 p-6 text-white shadow-sm md:p-8">
                    <div className="absolute inset-x-0 top-0 h-1 bg-orange-400" />

                    <div>
                        <Link
                            href={route('home')}
                            className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-white/15 bg-white/10 px-3 text-sm font-semibold text-white transition hover:bg-white/15"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Kembali ke website
                        </Link>

                        <div className="mt-14 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-semibold">
                            <Sparkles className="h-4 w-4 text-orange-300" />
                            DermaCerdas Admin
                        </div>

                        <h1 className="mt-5 max-w-xl text-4xl font-semibold leading-tight md:text-5xl">
                            Panel aman untuk menjaga keputusan sistem tetap terkendali.
                        </h1>
                        <p className="mt-5 max-w-2xl text-sm leading-7 text-neutral-300">
                            Gunakan akses admin untuk memelihara gejala, rule Certainty
                            Factor, red flag, rekomendasi obat, mapping SD-198, dan audit
                            riwayat konsultasi.
                        </p>
                    </div>

                    <div className="mt-10 grid gap-3 sm:grid-cols-3">
                        {[
                            ['Knowledge base', 'Penyakit, gejala, dan rule CF'],
                            ['Safety gate', 'Red flag dan batas swamedikasi'],
                            ['Audit hasil', 'Riwayat konsultasi user'],
                        ].map(([label, text]) => (
                            <div
                                key={label}
                                className="rounded-lg border border-white/15 bg-white/10 p-4"
                            >
                                <CheckCircle2 className="h-5 w-5 text-orange-300" />
                                <p className="mt-3 text-sm font-semibold">{label}</p>
                                <p className="mt-1 text-xs leading-5 text-neutral-400">
                                    {text}
                                </p>
                            </div>
                        ))}
                    </div>
                </section>

                <section className="flex items-center justify-center">
                    <div className="w-full max-w-xl rounded-lg border border-slate-200 bg-white p-6 shadow-sm md:p-8">
                        <div className="flex items-start gap-4">
                            <div className="rounded-lg bg-orange-50 p-3 text-orange-700">
                                <LockKeyhole className="h-6 w-6" />
                            </div>
                            <div>
                                <p className="text-sm font-semibold text-orange-600">
                                    Login admin
                                </p>
                                <h2 className="mt-1 text-2xl font-semibold text-slate-950">
                                    Masuk ke panel DermaCerdas
                                </h2>
                                <p className="mt-2 text-sm leading-6 text-slate-600">
                                    Akses ini khusus admin untuk mengubah data inti sistem.
                                </p>
                            </div>
                        </div>

                        <div className="mt-6 grid gap-3 rounded-lg border border-emerald-100 bg-emerald-50 p-4 text-sm text-emerald-950 sm:grid-cols-[auto_1fr]">
                            <ShieldCheck className="h-5 w-5" />
                            <p className="leading-6">
                                Pastikan perubahan knowledge base sudah tervalidasi sebelum
                                digunakan untuk testing user.
                            </p>
                        </div>

                        <div className="mt-5 rounded-lg border border-yellow-200 bg-yellow-50 p-4">
                            <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                                <div>
                                    <p className="text-sm font-semibold text-neutral-950">
                                        Akun demo lokal
                                    </p>
                                    <p className="mt-1 text-xs leading-5 text-neutral-600">
                                        Untuk testing internal sebelum akun produksi dibuat.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={useDemoAccount}
                                    className="inline-flex min-h-9 items-center justify-center rounded-lg bg-orange-400 px-3 text-sm font-bold text-neutral-50 transition hover:bg-orange-500"
                                >
                                    Isi demo
                                </button>
                            </div>
                            <div className="mt-3 grid gap-2 text-sm text-neutral-800 sm:grid-cols-2">
                                <code className="rounded-md bg-white px-3 py-2">
                                    admin@dermacerdas.local
                                </code>
                                <code className="rounded-md bg-white px-3 py-2">
                                    password
                                </code>
                            </div>
                        </div>

                        {status && (
                            <div className="mt-4 rounded-lg bg-yellow-50 p-3 text-sm font-medium text-orange-700">
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
                                <div className="flex min-h-11 items-center gap-2 rounded-lg border border-slate-300 px-3 shadow-sm focus-within:border-orange-400 focus-within:ring-1 focus-within:ring-orange-400">
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
                                        onChange={(event) =>
                                            setData('email', event.target.value)
                                        }
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
                                <div className="flex min-h-11 items-center gap-2 rounded-lg border border-slate-300 px-3 shadow-sm focus-within:border-orange-400 focus-within:ring-1 focus-within:ring-orange-400">
                                    <LockKeyhole className="h-4 w-4 text-slate-500" />
                                    <input
                                        id="password"
                                        type="password"
                                        name="password"
                                        value={data.password}
                                        className="w-full border-0 px-0 py-2.5 text-sm text-slate-900 placeholder:text-slate-400 focus:ring-0"
                                        autoComplete="current-password"
                                        placeholder="Masukkan password"
                                        onChange={(event) =>
                                            setData('password', event.target.value)
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
                                        onChange={(event) =>
                                            setData('remember', event.target.checked)
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
                                className="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-lg bg-neutral-950 px-5 text-sm font-bold text-orange-300 shadow-sm transition hover:bg-orange-500 hover:text-white disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <HeartPulse className="h-4 w-4" />
                                {processing ? 'Memeriksa akun...' : 'Masuk admin'}
                            </button>
                        </form>
                    </div>
                </section>
            </div>
        </main>
    );
}
