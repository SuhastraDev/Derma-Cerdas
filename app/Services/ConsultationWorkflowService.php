<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\ConsultationFinalResult;
use App\Models\ConsultationRedFlag;
use App\Models\ConsultationSymptom;
use App\Models\ConsultationVisualResult;
use App\Models\Disease;
use App\Models\RedFlag;
use App\Models\Symptom;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConsultationWorkflowService
{
    /** Ambang kandidat visual dianggap kuat sebelum boleh menggeser keputusan. */
    private const VISUAL_STRONG = 0.55;

    /**
     * Selisih minimal skor visual kandidat teratas terhadap kandidat visual
     * lain (penyakit berbeda) sebelum boleh menggeser keputusan lewat F08 -
     * sejalan dengan FusionDecisionService::MIN_CF_MARGIN_FOR_CONFIDENT_LABEL
     * di sisi teks, supaya kandidat visual yang cuma menang tipis (model tidak
     * benar-benar yakin, skornya menyebar rata ke beberapa kandidat) tidak
     * ikut memaksakan nama penyakit.
     */
    private const MIN_VISUAL_MARGIN_FOR_CONFIDENT_LABEL = 0.15;

    public function __construct(
        private readonly CertaintyFactorService $certaintyFactorService,
        private readonly RedFlagService $redFlagService,
        private readonly FusionDecisionService $fusionDecisionService,
        private readonly AiVisualService $aiVisualService,
        private readonly ComplaintExtractionService $complaintExtractionService,
    ) {}

    /**
     * @param  array<int|string, float|int|string>  $symptomInputs
     * @param  array<int|string, bool|int|string>  $redFlagInputs
     */
    public function process(string $visitorName, ?string $complaintText, UploadedFile $image, array $symptomInputs, array $redFlagInputs): Consultation
    {
        return DB::transaction(function () use ($visitorName, $complaintText, $image, $symptomInputs, $redFlagInputs): Consultation {
            $complaintFeatures = $this->complaintExtractionService->extract($complaintText);
            $imagePath = $image->store('consultations', 'public');
            $consultation = Consultation::query()->create([
                'user_id' => auth()->id(),
                'visitor_name' => $visitorName,
                'complaint_text' => $complaintText,
                'complaint_features' => $complaintFeatures,
                'session_code' => $this->sessionCode(),
                'image_path' => $imagePath,
                'status' => 'processing',
                'metadata' => [
                    'consent_accepted_at' => now()->toIso8601String(),
                    'ai_mode' => 'mock_local',
                ],
            ]);

            // Teks keluhan TIDAK PERNAH mengisi nilai gejala. Ia hanya menentukan
            // pertanyaan mana yang diajukan (AdaptiveQuestionService) dan disimpan
            // sebagai ringkasan pada metadata. Prinsip yang sama seperti saran AI:
            // boleh memilih pertanyaan, tidak boleh menjawabnya atas nama pengguna.
            $normalizedSymptoms = $this->normalizeSymptomInputs($symptomInputs);
            $normalizedRedFlags = $this->applyComplaintRedFlagEvidence(
                $this->normalizeRedFlagInputs($redFlagInputs),
                $complaintFeatures,
                $this->answeredRedFlagCodes($redFlagInputs)
            );

            $this->storeSymptoms($consultation, $normalizedSymptoms);
            $redFlagResult = $this->redFlagService->evaluate($normalizedRedFlags);
            $this->storeRedFlags($consultation, $redFlagResult);

            $textualRankings = $this->certaintyFactorService->rankDiseases($normalizedSymptoms);
            $visualAnalysis = $this->aiVisualService->analyze(
                $imagePath,
                $textualRankings,
                (string) $complaintText,
                $complaintFeatures['disease_hints'] ?? []
            );

            if ($visualAnalysis['is_valid_skin_image'] !== true) {
                Storage::disk('public')->delete($imagePath);

                throw ValidationException::withMessages([
                    'image' => $this->visualValidationMessage($visualAnalysis),
                ]);
            }

            $visualCandidates = $visualAnalysis['candidates'];
            $this->storeVisualResults($consultation, $visualCandidates);

            $finalResult = $this->storeFinalResult(
                $consultation,
                $textualRankings,
                $visualCandidates,
                $redFlagResult,
                $visualAnalysis['validation_status'] === 'valid',
                $complaintFeatures['disease_hints'] ?? [],
                $normalizedSymptoms,
                $this->visualIsUntrustworthy($visualAnalysis),
            );

            $consultation->update([
                'status' => 'completed',
                'final_score' => round(((float) $finalResult->fusion_score) * 100, 2),
                'final_action' => $finalResult->action,
                'metadata' => [
                    ...($consultation->metadata ?? []),
                    'red_flag_summary' => $redFlagResult,
                    'textual_top_count' => count($textualRankings),
                    'visual_candidate_count' => count($visualCandidates),
                    'complaint_summary' => $complaintFeatures['summary'] ?? [],
                    'visual_validation' => [
                        'provider' => $visualAnalysis['provider'],
                        'provider_status' => $visualAnalysis['provider_status'] ?? 'ok',
                        'status' => $visualAnalysis['validation_status'],
                        'is_valid_skin_image' => $visualAnalysis['is_valid_skin_image'],
                        'outside_scope' => $visualAnalysis['outside_scope'] ?? false,
                        'observed_description' => $visualAnalysis['observed_description'] ?? '',
                        'suggested_symptom_codes' => $visualAnalysis['suggested_symptom_codes'] ?? [],
                        'dataset_retrieval' => array_values(array_slice(
                            $visualAnalysis['raw_response']['dataset_retrieval'] ?? [],
                            0,
                            10
                        )),
                        'warnings' => $visualAnalysis['warnings'],
                    ],
                ],
            ]);

            return $consultation->refresh();
        });
    }

    /**
     * Visual dianggap tidak bisa dipercaya sebagai bukti pendukung salah satu
     * dari 16 penyakit - bukan sekadar ketiadaan bukti (provider belum
     * dikonfigurasi/timeout, yang tetap wajar diberi OTC dengan catatan
     * keterbatasan) - dalam dua kasus:
     *
     * 1. Model AI secara eksplisit menilai foto ini di luar 16 kelas
     *    (`outside_scope`).
     * 2. Provider gagal menghasilkan JSON valid sama sekali, sehingga sistem
     *    jatuh ke fallback indeks kemiripan visual (`validation_status`
     *    'degraded', recall@1 hanya ~3,5%). Regresi produksi 2026-08-30
     *    menunjukkan `outside_scope` sendirian tidak cukup: field itu hanya
     *    terisi saat model BERHASIL parsing dan menolak dengan sengaja -
     *    kegagalan parsing total membuatnya tetap `false` walau sistem
     *    sebenarnya sama sekali tidak tahu apa isi foto itu.
     *
     * @param  array{validation_status: string, outside_scope?: bool}  $visualAnalysis
     */
    private function visualIsUntrustworthy(array $visualAnalysis): bool
    {
        return ($visualAnalysis['validation_status'] ?? null) === 'degraded'
            || (bool) ($visualAnalysis['outside_scope'] ?? false);
    }

    /**
     * Sinyal "kemungkinan di luar 16 penyakit" dari jawaban pengguna sendiri,
     * bukan dari visual (yang bisa mati saat provider down). Kelompok
     * deskriptif P2-P5 (bentuk, permukaan, rasa, durasi) menggambarkan
     * KARAKTER lesi itu sendiri - lihat FusionDecisionService::
     * DESCRIPTIVE_SYMPTOM_GROUP_PREFIXES. Kalau pengguna dengan jujur memilih
     * "Tidak yakin / tidak ada yang cocok" untuk MAYORITAS kelompok itu yang
     * benar-benar ditanyakan padanya (bukan asumsi keempatnya selalu
     * ditanyakan - pertanyaan P3-P5 bersifat adaptif), itu pengakuan eksplisit
     * bahwa gejalanya tidak cocok pola manapun di 16 penyakit cakupan.
     *
     * Ambang: minimal 2 kelompok dijawab "tidak yakin" DAN itu lebih dari
     * separuh kelompok yang dijawab (1/1 atau 2/2 tidak cukup - baru 2/3 ke
     * atas). Satu jawaban "tidak yakin" satu-satunya (mis. cuma P2 yang
     * sempat ditanya sebelum CF sudah cukup jelas) tidak boleh memicu ini -
     * itu bisa saja cuma satu ciri yang memang tidak khas, bukan sinyal
     * seluruh profil gejala meleset.
     */
    private function symptomsIndicateOutOfScope(array $normalizedSymptoms): bool
    {
        $answered = 0;
        $tidakYakin = 0;

        foreach (FusionDecisionService::DESCRIPTIVE_SYMPTOM_GROUP_PREFIXES as $prefix) {
            $selectedCode = null;

            foreach ($normalizedSymptoms as $code => $value) {
                if ($value >= 1.0 && str_starts_with($code, $prefix.'_')) {
                    $selectedCode = $code;
                    break;
                }
            }

            if ($selectedCode === null) {
                continue;
            }

            $answered++;

            if (str_ends_with($selectedCode, '_TIDAKYAKIN')) {
                $tidakYakin++;
            }
        }

        return $tidakYakin >= 2 && $tidakYakin > ($answered / 2);
    }

    /**
     * @param  array{provider_status?: string, validation_status: string, warnings: array<int, string>}  $visualAnalysis
     */
    private function visualValidationMessage(array $visualAnalysis): string
    {
        if (($visualAnalysis['provider_status'] ?? null) === 'quota_exceeded') {
            return 'Kuota/limit analisis visual NVIDIA NIM sedang habis. Tunggu hingga kuota tersedia kembali atau gunakan API key dengan limit aktif.';
        }

        if ($visualAnalysis['validation_status'] === 'not_configured') {
            return 'Validasi visual AI belum aktif, sehingga foto belum bisa dipastikan sebagai area kulit. Aktifkan AI service sebelum menjalankan analisis foto.';
        }

        if ($visualAnalysis['validation_status'] === 'unavailable') {
            return 'Validasi visual AI sedang tidak dapat dihubungi. Coba lagi setelah service AI aktif.';
        }

        return 'Foto yang diunggah belum terdeteksi sebagai foto area kulit yang valid. Gunakan foto area keluhan kulit yang jelas, terang, dan tidak tertutup objek lain.';
    }

    public function loadResult(string $sessionCode): Consultation
    {
        return Consultation::query()
            ->where('session_code', $sessionCode)
            ->with([
                'symptoms.symptom',
                'visualResults.disease',
                'finalResults.disease.medicineRecommendations.medicine',
                'redFlags.redFlag',
            ])
            ->firstOrFail();
    }

    private function sessionCode(): string
    {
        return 'DC-'.now()->format('Ymd-His').'-'.Str::upper(Str::random(5));
    }

    /**
     * @param  array<int|string, float|int|string>  $inputs
     * @return array<string, float>
     */
    private function normalizeSymptomInputs(array $inputs): array
    {
        $symptoms = Symptom::query()->where('is_active', true)->pluck('code', 'id');
        $normalized = [];

        foreach ($symptoms as $id => $code) {
            $value = $inputs[$id] ?? $inputs[(string) $id] ?? $inputs[$code] ?? 0;
            $normalized[$code] = max(0.0, min(1.0, (float) $value));
        }

        return $normalized;
    }

    /**
     * @param  array<int|string, bool|int|string>  $inputs
     * @return array<string, bool>
     */
    private function normalizeRedFlagInputs(array $inputs): array
    {
        $redFlags = RedFlag::query()->where('is_active', true)->pluck('code', 'id');
        $normalized = [];

        foreach ($redFlags as $id => $code) {
            $value = $inputs[$id] ?? $inputs[(string) $id] ?? $inputs[$code] ?? false;
            $normalized[$code] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $normalized;
    }

    /**
     * Kode tanda bahaya yang benar-benar ditanyakan dan dijawab pengguna.
     *
     * Kuncinya keberadaan key, bukan nilainya: "Tidak" dan "tidak ditanyakan"
     * sama-sama bernilai false, tetapi hanya yang pertama merupakan pernyataan
     * pengguna yang harus dihormati.
     *
     * @param  array<int|string, bool|int|string>  $inputs
     * @return array<int, string>
     */
    private function answeredRedFlagCodes(array $inputs): array
    {
        return RedFlag::query()
            ->where('is_active', true)
            ->pluck('code', 'id')
            ->filter(fn (string $code, int $id): bool => array_key_exists($id, $inputs)
                || array_key_exists((string) $id, $inputs)
                || array_key_exists($code, $inputs))
            ->values()
            ->all();
    }

    /**
     * Tanda bahaya yang terdeteksi dari teks keluhan tetap dipakai sebagai
     * jaring pengaman, TETAPI hanya untuk pertanyaan yang tidak sempat
     * ditanyakan. Bila pengguna sudah menjawab suatu tanda bahaya, jawabannya
     * menang mutlak - sebelumnya operator || membuat jawaban "Tidak" tidak
     * pernah bisa membatalkan deteksi kata kunci, sehingga F07 menahan seluruh
     * hasil tanpa bisa dibantah.
     *
     * @param  array<string, bool>  $redFlags
     * @param  array<string, mixed>  $complaintFeatures
     * @param  array<int, string>  $answeredCodes
     * @return array<string, bool>
     */
    private function applyComplaintRedFlagEvidence(
        array $redFlags,
        array $complaintFeatures,
        array $answeredCodes
    ): array {
        foreach (($complaintFeatures['red_flag_evidence'] ?? []) as $code => $evidence) {
            if (! array_key_exists($code, $redFlags) || in_array($code, $answeredCodes, true)) {
                continue;
            }

            $redFlags[$code] = (bool) $redFlags[$code] || (bool) ($evidence['detected'] ?? false);
        }

        return $redFlags;
    }

    /**
     * @param  array<string, float>  $symptoms
     */
    private function storeSymptoms(Consultation $consultation, array $symptoms): void
    {
        Symptom::query()
            ->where('is_active', true)
            ->get()
            ->each(function (Symptom $symptom) use ($consultation, $symptoms): void {
                $userCf = $symptoms[$symptom->code] ?? 0.0;

                ConsultationSymptom::query()->create([
                    'consultation_id' => $consultation->id,
                    'symptom_id' => $symptom->id,
                    'user_cf' => $userCf,
                    'selected' => $userCf > 0,
                ]);
            });
    }

    /**
     * @param  array<string, mixed>  $redFlagResult
     */
    private function storeRedFlags(Consultation $consultation, array $redFlagResult): void
    {
        $detectedIds = collect($redFlagResult['detected'] ?? [])->pluck('id')->all();

        RedFlag::query()
            ->where('is_active', true)
            ->get()
            ->each(function (RedFlag $redFlag) use ($consultation, $detectedIds): void {
                ConsultationRedFlag::query()->create([
                    'consultation_id' => $consultation->id,
                    'red_flag_id' => $redFlag->id,
                    'detected' => in_array($redFlag->id, $detectedIds, true),
                ]);
            });
    }

    /**
     * @param  array<int, array<string, mixed>>  $visualCandidates
     */
    private function storeVisualResults(Consultation $consultation, array $visualCandidates): void
    {
        foreach ($visualCandidates as $candidate) {
            /** @var Disease $disease */
            $disease = $candidate['disease'];

            ConsultationVisualResult::query()->create([
                'consultation_id' => $consultation->id,
                'provider' => $candidate['provider'],
                'disease_id' => $disease->id,
                'visual_score' => $candidate['visual_score'],
                'visual_reason' => $candidate['visual_reason'],
                'raw_response' => $candidate['raw_response'],
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $textualRankings
     * @param  array<int, array<string, mixed>>  $visualCandidates
     * @param  array<string, mixed>  $redFlagResult
     * @param  array<int, array<string, mixed>>  $diseaseHints
     */
    private function storeFinalResult(
        Consultation $consultation,
        array $textualRankings,
        array $visualCandidates,
        array $redFlagResult,
        bool $hasValidatedVisual,
        array $diseaseHints = [],
        array $normalizedSymptoms = [],
        bool $visualUnreliable = false
    ): ConsultationFinalResult {
        if (! $textualRankings) {
            /** @var Disease $fallbackDisease */
            $fallbackDisease = Disease::query()->where('is_active', true)->firstOrFail();
            $textualRankings = [['disease' => $fallbackDisease, 'textual_cf' => 0.0]];
        }

        // Pt / CFt: kandidat teks berkeyakinan tertinggi hasil Forward Chaining + Certainty Factor.
        $topTextual = $textualRankings[0];
        /** @var Disease $textualDisease */
        $textualDisease = $topTextual['disease'];
        $textualCf = (float) ($topTextual['textual_cf'] ?? 0.0);
        $textualUnreliable = ! $this->fusionDecisionService->textualCandidateIsReliable($topTextual, $textualRankings);

        // Pv: kandidat visual teratas. Kosong berarti F06 (citra tak dapat dianalisis / di luar ruang lingkup).
        $topVisual = $visualCandidates[0] ?? null;
        $visualDisease = $topVisual ? $topVisual['disease'] : null;
        $visualScore = $topVisual ? (float) $topVisual['visual_score'] : 0.0;
        $hasRedFlags = (bool) ($redFlagResult['has_red_flags'] ?? false);

        // F08: kandidat visual mengarah ke penyakit tanpa basis gejala/CF tervalidasi
        // (di luar cakupan naskah/MVP) dengan keyakinan visual memadai, sedangkan hasil
        // gejala nyaris tidak berbukti apa pun. Tampilkan temuan visual itu langsung
        // (edukasi/rujuk) alih-alih dipaksakan ke Pt yang tidak relevan. Tanda bahaya
        // tetap didahulukan lewat decide() normal (F07 menggantikan semua aturan lain).
        // Kandidat yang berasal dari fallback indeks dataset ($hasValidatedVisual
        // false) tidak boleh menggeser keputusan lewat F08/F09 - sumbernya indeks
        // ber-recall 3,5%, bukan analisis model.
        // F11: didahulukan dari F08/F09 karena syaratnya lebih ketat - dikonfirmasi
        // silang lewat SELURUH daftar kandidat kedua modalitas, bukan cuma juara
        // #1 gejala atau kecocokan dengan hint dari teks keluhan.
        $dualConfirmed = ($hasRedFlags || ! $hasValidatedVisual)
            ? null
            : $this->fusionDecisionService->findDualConfirmedCandidate($textualRankings, $visualCandidates);

        if ($dualConfirmed !== null) {
            $decision = $this->fusionDecisionService->decideDualConfirmed(
                $dualConfirmed['disease'],
                $dualConfirmed['textual_cf'],
                $dualConfirmed['visual_score'],
            );
            $finalDisease = $dualConfirmed['disease'];
        } elseif (
            ! $hasRedFlags
            && $hasValidatedVisual
            && $visualDisease
            && $visualScore >= self::VISUAL_STRONG
            && $this->visualMatchesDiseaseHint($visualDisease, $diseaseHints)
            && in_array($visualDisease->default_action, ['educate_only', 'refer'], true)
        ) {
            $decision = $this->fusionDecisionService->decideContextAlignedVisual($visualDisease, $visualScore);
            $finalDisease = $visualDisease;
        } elseif (
            ! $hasRedFlags
            && $hasValidatedVisual
            && $this->visualFindingMustNotBeMasked($visualDisease, $visualScore, $textualDisease, $textualCf, $visualCandidates)
        ) {
            $decision = $this->fusionDecisionService->decideVisualOnly($visualDisease, $visualScore);
            $finalDisease = $visualDisease;
        } else {
            $decision = $this->fusionDecisionService->decide(
                textualDisease: $textualDisease,
                textualCf: $textualCf,
                visualDisease: $visualDisease,
                visualScore: $visualScore,
                visualAvailable: $hasValidatedVisual && $topVisual !== null,
                redFlagResult: $redFlagResult,
                // Bukti berlawanan/tidak meyakinkan (bukan sekadar "visual tidak
                // sempat dianalisis") - lihat visualIsUntrustworthy() di atas.
                // Hanya relevan saat visual memang tidak tervalidasi - relevansinya
                // dipastikan di dalam FusionDecisionService sendiri.
                visualUnreliable: $visualUnreliable,
                // CF gejala dibangun dari terlalu sedikit gejala berbeda untuk
                // menyebut nama penyakit spesifik dengan yakin - lihat konstanta
                // MIN_MATCHED_SYMPTOMS_FOR_CONFIDENT_LABEL di atas.
                textualUnreliable: $textualUnreliable,
                // Pengakuan eksplisit pengguna sendiri: mayoritas kelompok gejala
                // deskriptif (bentuk/permukaan/rasa/durasi) dijawab "Tidak yakin/
                // tidak ada yang cocok" - lihat symptomsIndicateOutOfScope().
                // Sengaja TIDAK bergantung pada visual sama sekali, supaya tetap
                // berfungsi saat provider visual tidak tersedia/degraded.
                symptomsIndicateOutOfScope: $this->symptomsIndicateOutOfScope($normalizedSymptoms),
            );
            $finalDisease = $textualDisease;
        }

        return ConsultationFinalResult::query()->create([
            'consultation_id' => $consultation->id,
            'disease_id' => $finalDisease->id,
            'textual_cf' => $decision['textual_cf'],
            'visual_score' => $decision['visual_score'],
            'fusion_score' => $decision['fusion_score'],
            'action' => $decision['action'],
            'fusion_rule_code' => $decision['fusion_rule_code'],
            'explanation' => $decision['explanation'],
            'recommendations_snapshot' => $this->recommendationsSnapshot($finalDisease, $decision['can_recommend_medicine']),
            'secondary_visual_note' => $this->secondaryVisualNote($finalDisease, $visualDisease, $visualScore, $hasValidatedVisual, $hasRedFlags),
            'label_suppressed' => $decision['label_suppressed'] ?? false,
        ]);
    }

    /**
     * Info edukasi murni tambahan untuk kandidat visual di luar 16 penyakit
     * cakupan (tanpa basis gejala/CF sendiri, lihat DatasetDiseaseMapper) yang
     * TIDAK menjadi disease_id hasil akhir - mis. F04, di mana CF gejala
     * mengalahkan kandidat visual. Sengaja tidak menyentuh $decision atau
     * $finalDisease sama sekali: tujuannya hanya menambah info yang sudah ada
     * di consultation_visual_results (nama + skor) dengan deskripsi dan
     * sumber rujukan, bukan mengubah aksi/rekomendasi obat yang sudah dihitung.
     *
     * @return array{disease_name_indonesian: string, description: string|null, source_note: string|null, visual_score: float}|null
     */
    private function secondaryVisualNote(
        Disease $finalDisease,
        ?Disease $visualDisease,
        float $visualScore,
        bool $hasValidatedVisual,
        bool $hasRedFlags
    ): ?array {
        if ($hasRedFlags || ! $hasValidatedVisual || ! $visualDisease) {
            return null;
        }

        if ($visualDisease->is($finalDisease) || $visualScore < self::VISUAL_STRONG) {
            return null;
        }

        // Penyakit di luar 16 cakupan tidak pernah punya basis gejala/CF
        // (lihat DatabaseSeeder::retireOutOfScopeDiseases). Kandidat visual
        // yang PUNYA basis gejala sendiri sudah cukup terwakili lewat
        // fusion_rule_code (F04/F05) dan panel "Kandidat visual" biasa.
        if ($visualDisease->symptomRules()->exists()) {
            return null;
        }

        return [
            'disease_name_indonesian' => $visualDisease->name_indonesian ?: $visualDisease->name,
            'description' => $visualDisease->description,
            'source_note' => $visualDisease->source_note,
            'visual_score' => $visualScore,
        ];
    }

    /**
     * F08: temuan visual yang kuat tidak boleh ditimpa diam-diam oleh kandidat
     * gejala (Pt) yang berbeda.
     *
     * Sebelumnya syaratnya CFt < 0,10, sehingga Pt sekecil apa pun di atas itu
     * sudah cukup membuang hasil visual. Akibatnya foto psoriasis, lesi
     * mencurigakan, atau infeksi bakteri tetap dilaporkan sebagai penyakit
     * swamedikasi. Syaratnya sekarang:
     *
     * - Pv kuat (>= VISUAL_STRONG) dan berbeda dari Pt;
     * - Pv belum punya basis pengetahuan gejala/CF tervalidasi, sehingga
     *   jalur Certainty Factor memang tidak pernah bisa membenarkannya; dan
     * - Pv bergolongan rujuk (kanker, autoimun, infeksi bakteri) sehingga
     *   keselamatan didahulukan berapa pun CFt, ATAU CFt sendiri belum
     *   mencapai batas "cukup yakin" (Tabel 3.10); dan
     * - Pv unggul dengan margin yang cukup dari kandidat visual terbaik
     *   BERIKUTNYA yang penyakitnya berbeda - model yang skornya menyebar
     *   rata ke beberapa kandidat (tidak benar-benar yakin) tidak boleh
     *   memaksakan satu nama penyakit, sejalan dengan gerbang margin di sisi
     *   teks (FusionDecisionService::textualCandidateIsReliable()).
     *
     * Hasilnya tidak pernah berupa rekomendasi obat, hanya edukasi atau rujukan.
     *
     * @param  array<int, array<string, mixed>>  $visualCandidates  Seluruh kandidat visual (bukan cuma Pv) untuk menghitung margin.
     */
    private function visualFindingMustNotBeMasked(
        ?Disease $visualDisease,
        float $visualScore,
        Disease $textualDisease,
        float $textualCf,
        array $visualCandidates = []
    ): bool {
        if (! $visualDisease || $visualScore < self::VISUAL_STRONG) {
            return false;
        }

        if ($visualDisease->is($textualDisease) || $visualDisease->symptomRules()->exists()) {
            return false;
        }

        if (! $this->visualMarginIsWideEnough($visualDisease, $visualScore, $visualCandidates)) {
            return false;
        }

        return $visualDisease->default_action === 'refer'
            || $textualCf < FusionDecisionService::HIGH_CF;
    }

    /**
     * @param  array<int, array<string, mixed>>  $visualCandidates
     */
    private function visualMarginIsWideEnough(Disease $visualDisease, float $visualScore, array $visualCandidates): bool
    {
        $runnerUp = 0.0;

        foreach ($visualCandidates as $candidate) {
            $candidateDisease = $candidate['disease'] ?? null;

            if (! $candidateDisease instanceof Disease || $candidateDisease->is($visualDisease)) {
                continue;
            }

            $runnerUp = max($runnerUp, (float) ($candidate['visual_score'] ?? 0.0));
        }

        return ($visualScore - $runnerUp) >= self::MIN_VISUAL_MARGIN_FOR_CONFIDENT_LABEL;
    }

    /**
     * @param  array<int, array<string, mixed>>  $diseaseHints
     */
    private function visualMatchesDiseaseHint(Disease $visualDisease, array $diseaseHints): bool
    {
        $hintClasses = collect($diseaseHints)
            ->map(fn ($hint): mixed => is_array($hint) ? ($hint['dataset_class_name'] ?? null) : null)
            ->filter(fn ($className): bool => is_string($className) && trim($className) !== '')
            ->values();

        if ($hintClasses->isEmpty()) {
            return false;
        }

        $visualClasses = $visualDisease->loadMissing('datasetMappings')
            ->datasetMappings
            ->pluck('dataset_class_name');

        return $visualClasses->intersect($hintClasses)->isNotEmpty();
    }





    /**
     * @return array<int, array<string, mixed>>
     */
    private function recommendationsSnapshot(Disease $disease, bool $canRecommendMedicine): array
    {
        if (! $canRecommendMedicine) {
            return [];
        }

        return $disease->medicineRecommendations()
            ->where('is_active', true)
            ->with('medicine')
            ->orderBy('priority')
            ->get()
            ->map(fn ($recommendation): array => [
                'medicine_name' => $recommendation->medicine->name,
                'category' => $recommendation->medicine->category,
                'dosage_form' => $recommendation->medicine->dosage_form,
                'image_url' => $recommendation->medicine->image_path ? asset($recommendation->medicine->image_path) : null,
                'image_credit' => $recommendation->medicine->image_credit,
                'usage_instruction' => $recommendation->medicine->usage_instruction,
                'warnings' => $recommendation->medicine->warnings,
                'recommendation_note' => $recommendation->recommendation_note,
            ])
            ->values()
            ->all();
    }
}
