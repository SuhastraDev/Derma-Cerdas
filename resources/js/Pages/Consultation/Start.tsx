import PublicLayout from '@/Layouts/PublicLayout';
import { Head, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Camera,
    CheckCircle2,
    FileImage,
    HeartPulse,
    ShieldCheck,
    Sparkles,
    UserRound,
    Video,
    X,
} from 'lucide-react';
import { ChangeEvent, FormEvent, useEffect, useMemo, useRef, useState } from 'react';

type Symptom = {
    id: number;
    code: string;
    name: string;
    question: string;
};

type RedFlag = {
    id: number;
    code: string;
    question: string;
    severity: string;
};

type StartProps = {
    symptoms: Symptom[];
    redFlags: RedFlag[];
};

type FormData = {
    visitor_name: string;
    consent: boolean;
    image: File | null;
    symptoms: Record<string, number>;
    red_flags: Record<string, boolean>;
};

const certaintyLabels: Record<number, string> = {
    0: 'Tidak ada',
    0.2: 'Ringan',
    0.4: 'Sedang',
    0.6: 'Jelas',
    0.8: 'Sangat jelas',
    1: 'Dominan',
};

const symptomChoices = [
    { value: 0, label: 'Tidak ada', helper: 'Tidak saya rasakan' },
    { value: 0.2, label: 'Ringan', helper: 'Ada sedikit' },
    { value: 0.4, label: 'Sedang', helper: 'Cukup terasa' },
    { value: 0.6, label: 'Jelas', helper: 'Saya yakin ada' },
    { value: 0.8, label: 'Sangat jelas', helper: 'Sangat terasa' },
    { value: 1, label: 'Dominan', helper: 'Keluhan utama' },
];

const steps = [
    { label: 'Identitas', helper: 'Nama pengguna', icon: UserRound },
    { label: 'Persetujuan', helper: 'Batas sistem', icon: ShieldCheck },
    { label: 'Foto', helper: 'Kamera/upload', icon: Camera },
    { label: 'Gejala', helper: 'Keluhan utama', icon: HeartPulse },
    { label: 'Risiko', helper: 'Tanda bahaya', icon: AlertTriangle },
    { label: 'Review', helper: 'Periksa & kirim', icon: CheckCircle2 },
];

function scoreLabel(value: number): string {
    return certaintyLabels[value] ?? `${Math.round(value * 100)}%`;
}

function symptomGuide(code: string): string {
    if (code.includes('DAYS') || code.includes('RECURRENT')) {
        return 'Pilih berdasarkan lamanya keluhan berlangsung atau sering kambuh.';
    }

    if (code.includes('TRIGGER')) {
        return 'Pilih jika keluhan muncul setelah kontak sabun, kosmetik, logam, tanaman, atau bahan tertentu.';
    }

    if (code.includes('FOOT')) {
        return 'Pilih sesuai kondisi di sela jari kaki, telapak, atau area kaki yang gatal/bersisik.';
    }

    if (code.includes('PATCHES')) {
        return 'Pilih jika bercak putih/cokelat tampak jelas pada kulit.';
    }

    return 'Pilih tingkat keyakinan sesuai yang benar-benar terlihat atau dirasakan.';
}

export default function Start({ symptoms, redFlags }: StartProps) {
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const [cameraError, setCameraError] = useState<string | null>(null);
    const [isCameraOpen, setIsCameraOpen] = useState(false);
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const [currentStep, setCurrentStep] = useState(0);

    const initialSymptoms = useMemo(
        () =>
            symptoms.reduce<Record<string, number>>((carry, symptom) => {
                carry[symptom.code] = 0;
                return carry;
            }, {}),
        [symptoms],
    );

    const initialRedFlags = useMemo(
        () =>
            redFlags.reduce<Record<string, boolean>>((carry, redFlag) => {
                carry[redFlag.code] = false;
                return carry;
            }, {}),
        [redFlags],
    );

    const { data, setData, post, processing, errors } = useForm<FormData>({
        visitor_name: '',
        consent: false,
        image: null,
        symptoms: initialSymptoms,
        red_flags: initialRedFlags,
    });

    const selectedSymptomCount = Object.values(data.symptoms).filter((value) => value > 0).length;
    const selectedRedFlagCount = Object.values(data.red_flags).filter(Boolean).length;
    const canContinue =
        (currentStep === 0 && data.visitor_name.trim().length >= 2) ||
        (currentStep === 1 && data.consent) ||
        (currentStep === 2 && data.image !== null) ||
        (currentStep === 3 && selectedSymptomCount > 0) ||
        currentStep >= 4;

    const completed = [
        data.visitor_name.trim().length >= 2,
        data.consent,
        data.image !== null,
        selectedSymptomCount > 0,
        true,
        currentStep === 5,
    ];

    const maxAccessibleStep = completed.findIndex((value) => !value);
    const unlockedUntil = maxAccessibleStep === -1 ? steps.length - 1 : maxAccessibleStep;

    useEffect(() => {
        return () => {
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }
            stopCamera();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const replaceImage = (file: File | null) => {
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
        }

        setData('image', file);
        setPreviewUrl(file ? URL.createObjectURL(file) : null);
    };

    const onImageChange = (event: ChangeEvent<HTMLInputElement>) => {
        replaceImage(event.target.files?.[0] ?? null);
    };

    const startCamera = async () => {
        setCameraError(null);

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' } },
                audio: false,
            });

            streamRef.current = stream;
            setIsCameraOpen(true);

            if (videoRef.current) {
                videoRef.current.srcObject = stream;
                await videoRef.current.play();
            }
        } catch {
            setCameraError('Kamera tidak bisa diakses. Izinkan akses kamera atau gunakan upload file.');
        }
    };

    const stopCamera = () => {
        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;
        setIsCameraOpen(false);
    };

    const capturePhoto = () => {
        const video = videoRef.current;

        if (!video) {
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth || 1280;
        canvas.height = video.videoHeight || 720;
        canvas.getContext('2d')?.drawImage(video, 0, 0, canvas.width, canvas.height);
        canvas.toBlob((blob) => {
            if (!blob) {
                return;
            }

            replaceImage(new File([blob], `foto-kulit-${Date.now()}.jpg`, { type: 'image/jpeg' }));
            stopCamera();
        }, 'image/jpeg', 0.9);
    };

    const goNext = () => {
        if (!canContinue) {
            return;
        }

        setCurrentStep((step) => Math.min(step + 1, steps.length - 1));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('consultation.store'), {
            forceFormData: true,
            onError: (formErrors) => {
                if (formErrors.visitor_name) setCurrentStep(0);
                else if (formErrors.consent) setCurrentStep(1);
                else if (formErrors.image) setCurrentStep(2);
                else if (formErrors.symptoms) setCurrentStep(3);
            },
        });
    };

    return (
        <PublicLayout>
            <Head title="Konsultasi Awal" />

            <section className="mx-auto max-w-7xl px-4 pt-10 lg:px-8">
                <div className="mb-6 overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm">
                    <div className="bg-neutral-950 p-6 text-white md:p-8">
                        <div className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-semibold">
                            <Sparkles className="h-4 w-4" />
                            Wizard aman
                        </div>
                        <h1 className="mt-5 max-w-2xl text-3xl font-semibold leading-tight md:text-4xl">
                            Akses bertahap, data lebih rapi
                        </h1>
                        <p className="mt-4 max-w-2xl text-sm leading-7 text-neutral-300">
                            Nama, foto, gejala, dan risiko harus lengkap sebelum sistem membuat
                            kode riwayat.
                        </p>
                    </div>
                </div>
            </section>

            <section className="mx-auto max-w-7xl px-4 pb-16 lg:px-8">
                <form onSubmit={submit} className="space-y-5">
                    <nav aria-label="Tahap konsultasi" className="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                        <ol className="grid gap-2 sm:grid-cols-3 xl:grid-cols-6">
                            {steps.map((step, index) => {
                                const Icon = step.icon;
                                const isLocked = index > unlockedUntil;

                                return (
                                    <li key={step.label}>
                                        <button
                                            type="button"
                                            disabled={isLocked || processing}
                                            onClick={() => !isLocked && setCurrentStep(index)}
                                            className={`flex min-h-20 w-full items-center gap-3 rounded-xl border px-3 text-left transition ${
                                                index === currentStep
                                                    ? 'border-neutral-900 bg-neutral-900 text-white shadow-sm'
                                                    : completed[index]
                                                      ? 'border-emerald-200 bg-emerald-50 text-emerald-950'
                                                      : isLocked
                                                        ? 'cursor-not-allowed border-slate-200 bg-slate-50 text-slate-400'
                                                        : 'border-slate-200 bg-slate-50 text-slate-500 hover:border-slate-300 hover:bg-white'
                                            }`}
                                        >
                                            <Icon className="h-5 w-5 shrink-0" />
                                            <span>
                                                <span className="block text-sm font-semibold">{step.label}</span>
                                                <span className="mt-0.5 block text-xs opacity-80">{step.helper}</span>
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ol>
                    </nav>

                    {currentStep === 0 && (
                        <section className="rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm">
                            <StepTitle icon={UserRound} title="Masukkan nama terlebih dahulu" body="Nama ditampilkan di riwayat admin dan export hasil agar data konsultasi mudah diaudit." />
                            <label className="mt-6 block">
                                <span className="text-sm font-semibold text-neutral-800">Nama lengkap</span>
                                <input
                                    value={data.visitor_name}
                                    onChange={(event) => setData('visitor_name', event.target.value)}
                                    className="mt-2 w-full rounded-xl border-neutral-300 text-sm focus:border-orange-400 focus:ring-orange-400"
                                    placeholder="Fadhilah Akfarizi"
                                />
                                {errors.visitor_name && <ErrorText>{errors.visitor_name}</ErrorText>}
                            </label>
                        </section>
                    )}

                    {currentStep === 1 && (
                        <section className="rounded-2xl border border-neutral-200 bg-white p-6 shadow-sm">
                            <StepTitle icon={ShieldCheck} title="Pahami batasan sistem" body="DermaCerdas membantu skrining awal. Hasilnya bukan diagnosis dokter dan tidak menggantikan pemeriksaan langsung." />
                            <div className="mt-5 grid gap-3 md:grid-cols-3">
                                {['Gunakan foto area kulit yang jelas.', 'Jawab gejala sesuai yang dirasakan.', 'Red flags akan diarahkan untuk rujukan.'].map((item) => (
                                    <div key={item} className="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-700">{item}</div>
                                ))}
                            </div>
                            <label className="mt-5 flex items-start gap-3 rounded-xl border border-yellow-200 bg-yellow-50 p-4 text-sm leading-6 text-neutral-900">
                                <input
                                    type="checkbox"
                                    checked={data.consent}
                                    onChange={(event) => setData('consent', event.target.checked)}
                                    className="mt-1 rounded border-yellow-300 text-orange-500 focus:ring-orange-400"
                                />
                                <span>Saya memahami batasan tersebut dan setuju melanjutkan konsultasi awal.</span>
                            </label>
                            {errors.consent && <ErrorText>{errors.consent}</ErrorText>}
                        </section>
                    )}

                    {currentStep === 2 && (
                        <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <StepTitle icon={FileImage} title="Ambil atau unggah foto area kulit" body="Gunakan kamera langsung atau upload JPG/PNG/WEBP. Foto harus terang, fokus, dan memperlihatkan area keluhan." />
                            <div className="mt-6 grid gap-5 lg:grid-cols-[1fr_1fr]">
                                <div className="rounded-2xl border border-yellow-200 bg-yellow-50/60 p-4">
                                    {isCameraOpen ? (
                                        <div className="space-y-3">
                                            <video ref={videoRef} className="h-72 w-full rounded-xl bg-neutral-950 object-cover" playsInline muted />
                                            <div className="flex gap-2">
                                                <button type="button" onClick={capturePhoto} className="inline-flex min-h-10 flex-1 items-center justify-center gap-2 rounded-lg bg-orange-400 px-4 text-sm font-bold text-white hover:bg-orange-500">
                                                    <Camera className="h-4 w-4" />
                                                    Ambil foto
                                                </button>
                                                <button type="button" onClick={stopCamera} className="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-neutral-300 bg-white text-neutral-700">
                                                    <X className="h-4 w-4" />
                                                </button>
                                            </div>
                                        </div>
                                    ) : (
                                        <button type="button" onClick={startCamera} className="flex min-h-72 w-full flex-col items-center justify-center rounded-xl border border-dashed border-yellow-300 bg-white/70 text-center transition hover:bg-white">
                                            <span className="rounded-full bg-yellow-50 p-4 text-orange-700"><Video className="h-8 w-8" /></span>
                                            <span className="mt-4 font-semibold text-neutral-950">Buka kamera</span>
                                            <span className="mt-1 text-sm text-neutral-600">Ambil foto langsung dari perangkat</span>
                                        </button>
                                    )}
                                    {cameraError && <ErrorText>{cameraError}</ErrorText>}
                                </div>

                                <div>
                                    <label className="flex min-h-72 cursor-pointer flex-col items-center justify-center rounded-2xl border border-dashed border-yellow-300 bg-yellow-50/70 px-4 text-center transition hover:bg-yellow-50">
                                        {previewUrl ? (
                                            <img src={previewUrl} alt="Preview area kulit" className="h-72 w-full rounded-md object-cover" />
                                        ) : (
                                            <span className="flex flex-col items-center">
                                                <span className="rounded-full bg-white p-4 text-orange-700 shadow-sm"><FileImage className="h-8 w-8" /></span>
                                                <span className="mt-4 block text-base font-semibold text-neutral-950">Upload foto kulit</span>
                                                <span className="mt-1 block text-sm text-orange-700">Klik area ini untuk memilih file</span>
                                            </span>
                                        )}
                                        <input type="file" accept="image/jpeg,image/png,image/webp" onChange={onImageChange} className="sr-only" />
                                    </label>
                                    {errors.image && <ErrorText>{errors.image}</ErrorText>}
                                </div>
                            </div>
                        </section>
                    )}

                    {currentStep === 3 && (
                        <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <StepTitle icon={HeartPulse} title="Ceritakan gejala yang dirasakan" body="Pilih satu jawaban untuk setiap gejala. Minimal satu gejala harus lebih dari “Tidak ada” sebelum lanjut." />
                            <div className="mt-5 grid gap-4">
                                {symptoms.map((symptom) => {
                                    const value = data.symptoms[symptom.code] ?? 0;

                                    return (
                                        <article key={symptom.code} className={`rounded-2xl border p-4 transition ${value > 0 ? 'border-yellow-200 bg-yellow-50/60' : 'border-slate-200 bg-white'}`}>
                                            <div className="flex flex-col justify-between gap-3 lg:flex-row lg:items-start">
                                                <div>
                                                    <span className="text-sm font-semibold text-slate-900">{symptom.name}</span>
                                                    <p className="mt-1 text-sm leading-5 text-slate-600">{symptom.question}</p>
                                                    <p className="mt-2 text-xs leading-5 text-slate-500">{symptomGuide(symptom.code)}</p>
                                                </div>
                                                <span className="w-fit shrink-0 rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-700 shadow-sm">
                                                    Pilihan: {scoreLabel(value)}
                                                </span>
                                            </div>
                                            <div className="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                                {symptomChoices.map((choice) => {
                                                    const active = value === choice.value;

                                                    return (
                                                        <button
                                                            key={`${symptom.code}-${choice.value}`}
                                                            type="button"
                                                            onClick={() =>
                                                                setData('symptoms', {
                                                                    ...data.symptoms,
                                                                    [symptom.code]: choice.value,
                                                                })
                                                            }
                                                            className={`min-h-16 rounded-xl border px-3 text-left transition ${
                                                                active
                                                                    ? 'border-neutral-900 bg-neutral-900 text-white shadow-sm'
                                                                    : 'border-slate-200 bg-white text-slate-700 hover:border-yellow-300 hover:bg-yellow-50'
                                                            }`}
                                                        >
                                                            <span className="block text-sm font-semibold">
                                                                {choice.label}
                                                            </span>
                                                            <span className={`mt-1 block text-xs ${active ? 'text-neutral-300' : 'text-slate-500'}`}>
                                                                {choice.helper}
                                                            </span>
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        </article>
                                    );
                                })}
                            </div>
                            {errors.symptoms && <ErrorText>{errors.symptoms}</ErrorText>}
                        </section>
                    )}

                    {currentStep === 4 && (
                        <section className="rounded-2xl border border-red-100 bg-white p-6 shadow-sm">
                            <StepTitle icon={AlertTriangle} title="Cek tanda bahaya" body="Jawab setiap pertanyaan dengan “Tidak” atau “Ya”. Jika ada jawaban “Ya”, sistem akan mengutamakan rujukan." />
                            <div className="mt-5 grid gap-3 md:grid-cols-2">
                                {redFlags.map((redFlag) => {
                                    const checked = Boolean(data.red_flags[redFlag.code]);

                                    return (
                                        <article key={redFlag.code} className={`rounded-xl border p-4 transition ${checked ? 'border-red-300 bg-red-50' : 'border-slate-200 bg-white'}`}>
                                            <div className="flex items-start justify-between gap-4">
                                                <div>
                                                <span className="block text-sm font-semibold text-slate-900">{redFlag.question}</span>
                                                <span className="mt-1 inline-flex rounded-full bg-white px-2 py-1 text-xs font-semibold uppercase tracking-wide text-red-700">{redFlag.severity}</span>
                                                </div>
                                            </div>
                                            <div className="mt-4 grid grid-cols-2 gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setData('red_flags', {
                                                            ...data.red_flags,
                                                            [redFlag.code]: false,
                                                        })
                                                    }
                                                    className={`min-h-11 rounded-lg border px-3 text-sm font-semibold transition ${
                                                        !checked
                                                            ? 'border-neutral-900 bg-neutral-900 text-white'
                                                            : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300'
                                                    }`}
                                                >
                                                    Tidak
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setData('red_flags', {
                                                            ...data.red_flags,
                                                            [redFlag.code]: true,
                                                        })
                                                    }
                                                    className={`min-h-11 rounded-lg border px-3 text-sm font-semibold transition ${
                                                        checked
                                                            ? 'border-red-700 bg-red-700 text-white'
                                                            : 'border-red-200 bg-white text-red-700 hover:bg-red-50'
                                                    }`}
                                                >
                                                    Ya
                                                </button>
                                            </div>
                                        </article>
                                    );
                                })}
                            </div>
                        </section>
                    )}

                    {currentStep === 5 && (
                        <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <StepTitle icon={CheckCircle2} title="Review dan kirim" body="Periksa data terakhir, lalu kirim konsultasi untuk membuat kode unik riwayat." />
                            <div className="mt-5 grid gap-3 md:grid-cols-4">
                                <Summary label="Nama" value={data.visitor_name || '-'} />
                                <Summary label="Foto" value={data.image?.name ?? 'Belum dipilih'} />
                                <Summary label="Gejala" value={`${selectedSymptomCount} dari ${symptoms.length}`} />
                                <Summary label="Red flags" value={String(selectedRedFlagCount)} danger={selectedRedFlagCount > 0} />
                            </div>
                            <div className="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm leading-6 text-emerald-950">
                                Semua tahap utama sudah lengkap. Setelah dikirim, sistem akan
                                membuat kode unik yang bisa dipakai untuk cek riwayat dan export PDF.
                            </div>
                        </section>
                    )}

                    <section className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
                        <button
                            type="button"
                            onClick={() => setCurrentStep((step) => Math.max(step - 1, 0))}
                            disabled={currentStep === 0 || processing}
                            className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-slate-300 px-5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Kembali
                        </button>
                        <span className="text-center text-sm text-slate-500">Tahap {currentStep + 1} dari {steps.length}</span>
                        {currentStep === steps.length - 1 ? (
                            <button
                                type="submit"
                                disabled={!canContinue || processing}
                                className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-orange-400 px-5 text-sm font-bold text-neutral-50 shadow-sm transition hover:bg-orange-500 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {processing ? 'Menganalisis...' : 'Analisis konsultasi'}
                                {!processing && <Sparkles className="h-4 w-4" />}
                            </button>
                        ) : (
                            <button
                                type="button"
                                onClick={goNext}
                                disabled={!canContinue || processing}
                                className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-orange-400 px-5 text-sm font-bold text-neutral-50 shadow-sm transition hover:bg-orange-500 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                Lanjut
                                <ArrowRight className="h-4 w-4" />
                            </button>
                        )}
                    </section>
                    {!canContinue && (
                        <p className="-mt-3 text-sm text-slate-500">Lengkapi tahap ini untuk melanjutkan.</p>
                    )}
                </form>
            </section>
        </PublicLayout>
    );
}

function StepTitle({
    icon: Icon,
    title,
    body,
}: {
    icon: typeof UserRound;
    title: string;
    body: string;
}) {
    return (
        <div className="flex items-start gap-4">
            <div className="rounded-xl bg-yellow-50 p-3 text-orange-700">
                <Icon className="h-6 w-6" />
            </div>
            <div>
                <h2 className="text-xl font-semibold text-slate-950">{title}</h2>
                <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">{body}</p>
            </div>
        </div>
    );
}

function Summary({ label, value, danger = false }: { label: string; value: string; danger?: boolean }) {
    return (
        <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <span className="text-xs uppercase tracking-wide text-slate-500">{label}</span>
            <p className={`mt-1 break-words text-sm font-semibold ${danger ? 'text-red-700' : 'text-slate-900'}`}>
                {value}
            </p>
        </div>
    );
}

function ErrorText({ children }: { children: string }) {
    return <p className="mt-2 text-sm text-red-600">{children}</p>;
}
