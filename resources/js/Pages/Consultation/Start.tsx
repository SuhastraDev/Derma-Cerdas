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
    MessageSquareText,
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

type PrecheckResult = {
    selected_symptoms: Symptom[];
    complaint_summary: string[];
    visual: {
        status: string;
        is_valid_skin_image: boolean | null;
        warnings: string[];
        candidates: Array<{
            disease_name: string | null;
            visual_score: number;
            reason: string | null;
        }>;
    };
};

type FormData = {
    visitor_name: string;
    complaint_text: string;
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
    { label: 'Keluhan', helper: 'Cerita singkat', icon: MessageSquareText },
    { label: 'Foto', helper: 'Kamera/upload', icon: Camera },
    { label: 'Gejala', helper: 'Keluhan utama', icon: HeartPulse },
    { label: 'Risiko', helper: 'Tanda bahaya', icon: AlertTriangle },
    { label: 'Review', helper: 'Periksa & kirim', icon: CheckCircle2 },
];

const analysisStages = [
    'Mengunggah foto',
    'Validasi visual AI',
    'Menghitung gejala CF',
    'Fusion keputusan',
    'Menyimpan hasil',
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
    const [symptomQuestionIndex, setSymptomQuestionIndex] = useState(0);
    const [redFlagQuestionIndex, setRedFlagQuestionIndex] = useState(0);
    const [answeredSymptoms, setAnsweredSymptoms] = useState<Set<string>>(new Set());
    const [answeredRedFlags, setAnsweredRedFlags] = useState<Set<string>>(new Set());
    const [analysisStageIndex, setAnalysisStageIndex] = useState(0);
    const [failedAnalysisStage, setFailedAnalysisStage] = useState<number | null>(null);
    const [adaptiveSymptoms, setAdaptiveSymptoms] = useState<Symptom[]>(symptoms);
    const [precheckResult, setPrecheckResult] = useState<PrecheckResult | null>(null);
    const [precheckError, setPrecheckError] = useState<string | null>(null);
    const [prechecking, setPrechecking] = useState(false);

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
        complaint_text: '',
        consent: false,
        image: null,
        symptoms: initialSymptoms,
        red_flags: initialRedFlags,
    });

    const selectedSymptomCount = Object.values(data.symptoms).filter((value) => value > 0).length;
    const selectedRedFlagCount = Object.values(data.red_flags).filter(Boolean).length;
    const answeredSymptomCount = answeredSymptoms.size;
    const answeredRedFlagCount = answeredRedFlags.size;
    const currentSymptom = adaptiveSymptoms[symptomQuestionIndex];
    const currentRedFlag = redFlags[redFlagQuestionIndex];
    const canContinue =
        (currentStep === 0 && data.visitor_name.trim().length >= 2) ||
        (currentStep === 1 && data.consent) ||
        (currentStep === 2 && data.complaint_text.trim().length >= 12) ||
        (currentStep === 3 && data.image !== null) ||
        (currentStep === 4 && answeredSymptomCount === adaptiveSymptoms.length && selectedSymptomCount > 0) ||
        (currentStep === 5 && answeredRedFlagCount === redFlags.length) ||
        currentStep >= 6;

    const completed = [
        data.visitor_name.trim().length >= 2,
        data.consent,
        data.complaint_text.trim().length >= 12,
        data.image !== null,
        answeredSymptomCount === adaptiveSymptoms.length && selectedSymptomCount > 0,
        answeredRedFlagCount === redFlags.length,
        currentStep === 6,
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

    useEffect(() => {
        if (!processing) {
            return;
        }

        setFailedAnalysisStage(null);
        setAnalysisStageIndex(0);
        const timer = window.setInterval(() => {
            setAnalysisStageIndex((stage) => Math.min(stage + 1, analysisStages.length - 1));
        }, 900);

        return () => window.clearInterval(timer);
    }, [processing]);

    const replaceImage = (file: File | null) => {
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
        }

        setData('image', file);
        setPreviewUrl(file ? URL.createObjectURL(file) : null);
        setPrecheckResult(null);
        setPrecheckError(null);
        setAdaptiveSymptoms(symptoms);
        setAnsweredSymptoms(new Set());
        setSymptomQuestionIndex(0);
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

    const answerSymptom = (code: string, value: number) => {
        setData('symptoms', {
            ...data.symptoms,
            [code]: value,
        });
        setAnsweredSymptoms((answers) => new Set(answers).add(code));
    };

    const answerRedFlag = (code: string, value: boolean) => {
        setData('red_flags', {
            ...data.red_flags,
            [code]: value,
        });
        setAnsweredRedFlags((answers) => new Set(answers).add(code));
    };

    const goToNextSymptom = () => {
        setSymptomQuestionIndex((index) => Math.min(index + 1, adaptiveSymptoms.length - 1));
    };

    const goToNextRedFlag = () => {
        setRedFlagQuestionIndex((index) => Math.min(index + 1, redFlags.length - 1));
    };

    const goNext = () => {
        if (!canContinue) {
            return;
        }

        if (currentStep === 3) {
            runPrecheck();
            return;
        }

        setCurrentStep((step) => Math.min(step + 1, steps.length - 1));
    };

    const runPrecheck = async () => {
        if (!data.image || data.complaint_text.trim().length < 12) {
            return;
        }

        setPrechecking(true);
        setPrecheckError(null);

        const payload = new FormData();
        payload.append('complaint_text', data.complaint_text);
        payload.append('image', data.image);

        try {
            const response = await fetch(route('consultation.precheck'), {
                method: 'POST',
                body: payload,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
                },
            });

            const body = await response.json();

            if (!response.ok) {
                throw new Error(body.message ?? 'Precheck belum berhasil. Periksa foto dan keluhan.');
            }

            const selected = body.selected_symptoms?.length ? body.selected_symptoms : symptoms.slice(0, 8);
            setPrecheckResult(body);
            setAdaptiveSymptoms(selected);
            setAnsweredSymptoms(new Set());
            setSymptomQuestionIndex(0);
            setCurrentStep(4);
        } catch (error) {
            setPrecheckError(error instanceof Error ? error.message : 'Precheck belum berhasil. Coba ulangi.');
        } finally {
            setPrechecking(false);
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(route('consultation.store'), {
            forceFormData: true,
            onError: (formErrors) => {
                if (formErrors.visitor_name) {
                    setCurrentStep(0);
                    setFailedAnalysisStage(0);
                } else if (formErrors.consent) {
                    setCurrentStep(1);
                    setFailedAnalysisStage(0);
                } else if (formErrors.complaint_text) {
                    setCurrentStep(2);
                    setFailedAnalysisStage(2);
                } else if (formErrors.image) {
                    setCurrentStep(3);
                    setFailedAnalysisStage(1);
                } else if (formErrors.symptoms) {
                    setCurrentStep(4);
                    setFailedAnalysisStage(2);
                } else {
                    setFailedAnalysisStage(analysisStageIndex);
                }
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
                        <ol className="grid gap-2 sm:grid-cols-3 xl:grid-cols-7">
                            {steps.map((step, index) => {
                                const Icon = step.icon;
                                const isLocked = index > unlockedUntil;

                                return (
                                    <li key={step.label}>
                                        <button
                                            type="button"
                                            disabled={isLocked || processing || prechecking}
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
                            <StepTitle icon={MessageSquareText} title="Jelaskan keluhan dengan kata-kata sendiri" body="Ceritakan lokasi, rasa gatal/perih/nyeri, durasi, pemicu, dan perubahan yang terlihat. Teks ini ikut dipakai sebagai evidence tambahan saat analisis." />
                            <label className="mt-6 block">
                                <span className="text-sm font-semibold text-neutral-800">Keluhan yang dirasakan</span>
                                <textarea
                                    value={data.complaint_text}
                                    onChange={(event) => setData('complaint_text', event.target.value)}
                                    rows={6}
                                    maxLength={1500}
                                    className="mt-2 w-full rounded-xl border-neutral-300 text-sm leading-6 focus:border-orange-400 focus:ring-orange-400"
                                    placeholder="Contoh: Gatal sejak 1 minggu, ruam melingkar di paha, tepinya merah dan agak bersisik. Tidak demam dan tidak bernanah."
                                />
                            </label>
                            <div className="mt-4 grid gap-3 md:grid-cols-3">
                                {[
                                    'Lokasi dan bentuk keluhan',
                                    'Durasi dan rasa yang muncul',
                                    'Pemicu, nanah, demam, atau nyeri berat',
                                ].map((item) => (
                                    <div key={item} className="rounded-xl border border-yellow-200 bg-yellow-50 p-4 text-sm leading-6 text-orange-950">
                                        {item}
                                    </div>
                                ))}
                            </div>
                            <div className="mt-3 flex items-center justify-between text-xs text-slate-500">
                                <span>Minimal 12 karakter agar cerita cukup terbaca sistem.</span>
                                <span>{data.complaint_text.length}/1500</span>
                            </div>
                            {errors.complaint_text && <ErrorText>{errors.complaint_text}</ErrorText>}
                        </section>
                    )}

                    {currentStep === 3 && (
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
                            <div className="mt-5 rounded-xl border border-emerald-100 bg-emerald-50 p-4 text-sm leading-6 text-emerald-950">
                                Setelah foto dipilih, sistem akan melakukan precheck untuk menyusun pertanyaan gejala yang paling relevan dari keluhan dan kandidat visual.
                            </div>
                            {precheckError && <ErrorText>{precheckError}</ErrorText>}
                        </section>
                    )}

                    {currentStep === 4 && (
                        <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <StepTitle icon={HeartPulse} title="Ceritakan gejala yang dirasakan" body="Pertanyaan di tahap ini sudah dipilih adaptif dari keluhan dan hasil precheck foto, sehingga jumlahnya tidak selalu sama." />
                            {precheckResult && (
                                <div className="mt-5 grid gap-3 lg:grid-cols-[1fr_1fr]">
                                    <div className="rounded-xl border border-emerald-100 bg-emerald-50 p-4 text-sm leading-6 text-emerald-950">
                                        <span className="block font-semibold">Pertanyaan adaptif</span>
                                        Sistem memilih {adaptiveSymptoms.length} dari {symptoms.length} gejala aktif untuk ditanyakan.
                                    </div>
                                    <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-700">
                                        <span className="block font-semibold text-slate-950">Precheck visual</span>
                                        Status: {precheckResult.visual.status}
                                        {precheckResult.visual.candidates[0]?.disease_name
                                            ? ` / kandidat utama ${precheckResult.visual.candidates[0].disease_name}`
                                            : ''}
                                    </div>
                                </div>
                            )}
                            {currentSymptom && (
                                <article key={currentSymptom.code} className="consultation-card-enter mt-6 overflow-hidden rounded-2xl border border-yellow-200 bg-yellow-50/70 shadow-sm">
                                    <div className="border-b border-yellow-200 bg-white p-4">
                                        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                                            <div>
                                                <p className="text-xs font-semibold uppercase tracking-wide text-orange-700">
                                                    Pertanyaan gejala {symptomQuestionIndex + 1} dari {adaptiveSymptoms.length}
                                                </p>
                                                <h3 className="mt-1 text-xl font-semibold text-slate-950">
                                                    {currentSymptom.name}
                                                </h3>
                                            </div>
                                            <span className="w-fit rounded-full bg-yellow-100 px-3 py-1 text-xs font-semibold text-orange-800">
                                                Terjawab {answeredSymptomCount}/{adaptiveSymptoms.length}
                                            </span>
                                        </div>
                                        <div className="mt-4 h-2 rounded-full bg-slate-100">
                                            <div
                                                className="h-2 rounded-full bg-emerald-600 transition-all duration-300"
                                                style={{ width: `${Math.round((answeredSymptomCount / Math.max(adaptiveSymptoms.length, 1)) * 100)}%` }}
                                            />
                                        </div>
                                    </div>
                                    <div className="p-5">
                                        <p className="text-lg font-semibold leading-7 text-slate-950">
                                            {currentSymptom.question}
                                        </p>
                                        <p className="mt-2 text-sm leading-6 text-slate-600">
                                            {symptomGuide(currentSymptom.code)}
                                        </p>
                                        <div className="mt-5 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                            {symptomChoices.map((choice) => {
                                                const active =
                                                    answeredSymptoms.has(currentSymptom.code) &&
                                                    (data.symptoms[currentSymptom.code] ?? 0) === choice.value;

                                                return (
                                                    <button
                                                        key={`${currentSymptom.code}-${choice.value}`}
                                                        type="button"
                                                        onClick={() => answerSymptom(currentSymptom.code, choice.value)}
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
                                        <div className="mt-5 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                                            <button
                                                type="button"
                                                onClick={() => setSymptomQuestionIndex((index) => Math.max(index - 1, 0))}
                                                disabled={symptomQuestionIndex === 0}
                                                className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 text-sm font-semibold text-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                <ArrowLeft className="h-4 w-4" />
                                                Pertanyaan sebelumnya
                                            </button>
                                            <button
                                                type="button"
                                                onClick={goToNextSymptom}
                                                disabled={!answeredSymptoms.has(currentSymptom.code) || symptomQuestionIndex === adaptiveSymptoms.length - 1}
                                                className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-neutral-900 px-4 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                Pertanyaan berikutnya
                                                <ArrowRight className="h-4 w-4" />
                                            </button>
                                        </div>
                                    </div>
                                </article>
                            )}
                            <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-600">
                                Pilih “Tidak ada” jika gejala memang tidak dirasakan. Untuk melanjutkan tahap, semua pertanyaan adaptif perlu dijawab dan minimal satu gejala bernilai lebih dari “Tidak ada”.
                            </div>
                            {errors.symptoms && <ErrorText>{errors.symptoms}</ErrorText>}
                        </section>
                    )}

                    {currentStep === 5 && (
                        <section className="rounded-2xl border border-red-100 bg-white p-6 shadow-sm">
                            <StepTitle icon={AlertTriangle} title="Cek tanda bahaya" body="Jawab setiap pertanyaan dengan “Tidak” atau “Ya”. Jika ada jawaban “Ya”, sistem akan mengutamakan rujukan." />
                            {currentRedFlag && (
                                <article key={currentRedFlag.code} className="consultation-card-enter mt-6 overflow-hidden rounded-2xl border border-red-200 bg-red-50/60 shadow-sm">
                                    <div className="border-b border-red-200 bg-white p-4">
                                        <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                                            <div>
                                                <p className="text-xs font-semibold uppercase tracking-wide text-red-700">
                                                    Pertanyaan risiko {redFlagQuestionIndex + 1} dari {redFlags.length}
                                                </p>
                                                <h3 className="mt-1 text-xl font-semibold text-slate-950">
                                                    Tanda bahaya
                                                </h3>
                                            </div>
                                            <div className="flex flex-wrap gap-2">
                                                <span className="w-fit rounded-full bg-red-50 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-red-700">
                                                    {currentRedFlag.severity}
                                                </span>
                                                <span className="w-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                                                    Terjawab {answeredRedFlagCount}/{redFlags.length}
                                                </span>
                                            </div>
                                        </div>
                                        <div className="mt-4 h-2 rounded-full bg-slate-100">
                                            <div
                                                className="h-2 rounded-full bg-emerald-600 transition-all duration-300"
                                                style={{ width: `${Math.round((answeredRedFlagCount / Math.max(redFlags.length, 1)) * 100)}%` }}
                                            />
                                        </div>
                                    </div>
                                    <div className="p-5">
                                        <p className="text-lg font-semibold leading-7 text-slate-950">
                                            {currentRedFlag.question}
                                        </p>
                                        <p className="mt-2 text-sm leading-6 text-slate-600">
                                            Jawab sesuai kondisi saat ini. Jika ragu pada tanda bahaya, pilih “Ya” agar sistem memberi arahan yang lebih aman.
                                        </p>
                                        <div className="mt-5 grid gap-3 sm:grid-cols-2">
                                            <button
                                                type="button"
                                                onClick={() => answerRedFlag(currentRedFlag.code, false)}
                                                className={`min-h-20 rounded-xl border px-4 text-left transition ${
                                                    answeredRedFlags.has(currentRedFlag.code) && !data.red_flags[currentRedFlag.code]
                                                        ? 'border-neutral-900 bg-neutral-900 text-white'
                                                        : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300'
                                                }`}
                                            >
                                                <span className="block text-base font-semibold">Tidak</span>
                                                <span className="mt-1 block text-sm opacity-75">Tidak ada tanda ini.</span>
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => answerRedFlag(currentRedFlag.code, true)}
                                                className={`min-h-20 rounded-xl border px-4 text-left transition ${
                                                    data.red_flags[currentRedFlag.code]
                                                        ? 'border-red-700 bg-red-700 text-white'
                                                        : 'border-red-200 bg-white text-red-700 hover:bg-red-50'
                                                }`}
                                            >
                                                <span className="block text-base font-semibold">Ya</span>
                                                <span className="mt-1 block text-sm opacity-75">Ada tanda ini.</span>
                                            </button>
                                        </div>
                                        <div className="mt-5 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                                            <button
                                                type="button"
                                                onClick={() => setRedFlagQuestionIndex((index) => Math.max(index - 1, 0))}
                                                disabled={redFlagQuestionIndex === 0}
                                                className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 text-sm font-semibold text-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                <ArrowLeft className="h-4 w-4" />
                                                Pertanyaan sebelumnya
                                            </button>
                                            <button
                                                type="button"
                                                onClick={goToNextRedFlag}
                                                disabled={!answeredRedFlags.has(currentRedFlag.code) || redFlagQuestionIndex === redFlags.length - 1}
                                                className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-neutral-900 px-4 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
                                            >
                                                Pertanyaan berikutnya
                                                <ArrowRight className="h-4 w-4" />
                                            </button>
                                        </div>
                                    </div>
                                </article>
                            )}
                        </section>
                    )}

                    {currentStep === 6 && (
                        <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <StepTitle icon={CheckCircle2} title="Review dan kirim" body="Periksa data terakhir, lalu kirim konsultasi untuk membuat kode unik riwayat." />
                            <div className="mt-5 grid gap-3 md:grid-cols-5">
                                <Summary label="Nama" value={data.visitor_name || '-'} />
                                <Summary label="Keluhan" value={`${data.complaint_text.trim().length} karakter`} />
                                <Summary label="Foto" value={data.image?.name ?? 'Belum dipilih'} />
                                <Summary label="Gejala" value={`${selectedSymptomCount} dari ${adaptiveSymptoms.length} adaptif`} />
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
                            disabled={currentStep === 0 || processing || prechecking}
                            className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-slate-300 px-5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            Kembali
                        </button>
                        <span className="text-center text-sm text-slate-500">Tahap {currentStep + 1} dari {steps.length}</span>
                        {currentStep === steps.length - 1 ? (
                            <button
                                type="submit"
                                disabled={!canContinue || processing || prechecking}
                                className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-orange-400 px-5 text-sm font-bold text-neutral-50 shadow-sm transition hover:bg-orange-500 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {processing ? 'Menganalisis...' : 'Analisis konsultasi'}
                                {!processing && <Sparkles className="h-4 w-4" />}
                            </button>
                        ) : (
                            <button
                                type="button"
                                onClick={goNext}
                                disabled={!canContinue || processing || prechecking}
                                className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-orange-400 px-5 text-sm font-bold text-neutral-50 shadow-sm transition hover:bg-orange-500 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {prechecking ? 'Menyusun...' : currentStep === 3 ? 'Susun pertanyaan' : 'Lanjut'}
                                <ArrowRight className="h-4 w-4" />
                            </button>
                        )}
                    </section>
                    {!canContinue && (
                        <p className="-mt-3 text-sm text-slate-500">Lengkapi tahap ini untuk melanjutkan.</p>
                    )}
                </form>
            </section>

            {(processing || failedAnalysisStage !== null) && (
                <AnalysisTracker
                    activeIndex={analysisStageIndex}
                    failedIndex={failedAnalysisStage}
                    isProcessing={processing}
                />
            )}
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

function AnalysisTracker({
    activeIndex,
    failedIndex,
    isProcessing,
}: {
    activeIndex: number;
    failedIndex: number | null;
    isProcessing: boolean;
}) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-neutral-950/60 px-4 backdrop-blur-sm">
            <div className="w-full max-w-xl rounded-2xl bg-white p-6 shadow-2xl">
                <div className="flex items-start gap-4">
                    <div className={`rounded-xl p-3 text-white ${failedIndex !== null ? 'bg-red-700' : 'bg-neutral-950'}`}>
                        {failedIndex !== null ? <AlertTriangle className="h-6 w-6" /> : <Sparkles className="h-6 w-6" />}
                    </div>
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-orange-700">
                            Tracking analisis
                        </p>
                        <h2 className="mt-1 text-xl font-semibold text-slate-950">
                            {failedIndex !== null ? 'Analisis berhenti di satu tahap' : 'Analisis sedang berlangsung'}
                        </h2>
                        <p className="mt-2 text-sm leading-6 text-slate-600">
                            {failedIndex !== null
                                ? 'Periksa pesan error di tahap konsultasi yang terbuka, lalu kirim ulang setelah diperbaiki.'
                                : 'Sistem memvalidasi foto, menghitung gejala, lalu menggabungkan hasil visual dan tekstual.'}
                        </p>
                    </div>
                </div>

                <div className="mt-6 space-y-3">
                    {analysisStages.map((stage, index) => {
                        const failed = failedIndex === index;
                        const done = failedIndex === null && index < activeIndex;
                        const active = failedIndex === null && index === activeIndex;

                        return (
                            <div key={stage} className="flex items-center gap-3">
                                <div
                                    className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full border text-sm font-bold ${
                                        failed
                                            ? 'border-red-700 bg-red-700 text-white'
                                            : done
                                              ? 'border-emerald-600 bg-emerald-600 text-white'
                                              : active
                                                ? 'border-orange-400 bg-orange-400 text-white'
                                                : 'border-slate-200 bg-slate-100 text-slate-400'
                                    }`}
                                >
                                    {failed ? '!' : done ? '✓' : index + 1}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className={`text-sm font-semibold ${failed ? 'text-red-700' : active ? 'text-orange-700' : done ? 'text-emerald-700' : 'text-slate-500'}`}>
                                        {stage}
                                    </p>
                                    <div className="mt-1 h-1.5 rounded-full bg-slate-100">
                                        <div
                                            className={`h-1.5 rounded-full transition-all duration-300 ${
                                                failed ? 'bg-red-700' : done ? 'bg-emerald-600' : active ? 'bg-orange-400' : 'bg-slate-200'
                                            }`}
                                            style={{ width: done || active || failed ? '100%' : '0%' }}
                                        />
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>

                {isProcessing && (
                    <p className="mt-5 text-center text-xs font-semibold uppercase tracking-wide text-slate-400">
                        Jangan tutup halaman ini sampai hasil muncul.
                    </p>
                )}
            </div>
        </div>
    );
}
