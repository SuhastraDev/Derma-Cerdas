<?php

namespace App\Services;

use App\Models\Disease;
use App\Models\DatasetClassMapping;
use App\Models\Symptom;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AiVisualService
{
    /**
     * @param  array<int, array<string, mixed>>  $textualRankings
     * @return array{provider: string, provider_status: string, is_valid_skin_image: bool|null, validation_status: string, outside_scope: bool, observed_description: string, candidates: array<int, array<string, mixed>>, suggested_symptom_codes: array<int, string>, warnings: array<int, string>, raw_response: array<string, mixed>}
     */
    public function analyze(string $imagePath, array $textualRankings, string $complaintText = '', array $diseaseHints = []): array
    {
        $baseUrl = trim((string) config('services.dermacerdas_ai.url'));

        if ($baseUrl === '') {
            return [
                'provider' => 'none',
                'provider_status' => 'not_configured',
                'is_valid_skin_image' => null,
                'validation_status' => 'not_configured',
                'outside_scope' => false,
                'observed_description' => '',
                'candidates' => [],
                'suggested_symptom_codes' => [],
                'warnings' => [
                    'AI visual belum dikonfigurasi; sistem tidak membuat kandidat visual mock.',
                ],
                'raw_response' => [],
            ];
        }

        try {
            $payload = [
                'consultation_id' => pathinfo($imagePath, PATHINFO_FILENAME),
                'image_base64' => base64_encode(Storage::disk('public')->get($imagePath)),
                'complaint_text' => $complaintText,
                'candidate_classes' => $this->candidateClasses($textualRankings, $diseaseHints),
                // Daftar dipatok: model tidak boleh menambahkan tebakan indeks visual
                // (recall@1 3,5%) ke dalam ruang pilihannya.
                'strict_candidates' => true,
                'symptom_questions' => $this->symptomQuestions(),
            ];

            $response = Http::timeout((int) config('services.dermacerdas_ai.timeout', 20))
                ->acceptJson()
                ->post(rtrim($baseUrl, '/').'/analyze-image', $payload);
        } catch (\Throwable $exception) {
            return [
                'provider' => 'dermacerdas_ai',
                'provider_status' => 'unavailable',
                'is_valid_skin_image' => null,
                'validation_status' => 'unavailable',
                'outside_scope' => false,
                'observed_description' => '',
                'candidates' => [],
                'suggested_symptom_codes' => [],
                'warnings' => ['AI visual tidak dapat dihubungi: '.$exception->getMessage()],
                'raw_response' => [],
            ];
        }

        if ($response->failed()) {
            return [
                'provider' => 'dermacerdas_ai',
                'provider_status' => 'invalid_request',
                'is_valid_skin_image' => false,
                'validation_status' => 'invalid',
                'outside_scope' => false,
                'observed_description' => '',
                'candidates' => [],
                'suggested_symptom_codes' => [],
                'warnings' => [(string) ($response->json('detail') ?? 'Gambar tidak valid untuk dianalisis.')],
                'raw_response' => $response->json() ?? [],
            ];
        }

        $body = $response->json() ?? [];
        $providerStatus = (string) ($body['provider_status'] ?? 'ok');

        // 'dataset_fallback' berarti model visual gagal dan kandidat berasal dari
        // indeks visual dataset (recall@1 3,5%). Hasilnya tetap ditampilkan
        // sebagai informasi, tetapi ditandai 'degraded' agar tidak diperlakukan
        // sebagai analisis visual tervalidasi - keputusan jatuh ke aturan F06.
        $isDegraded = $providerStatus === 'dataset_fallback';

        if ($providerStatus !== 'ok' && ! $isDegraded) {
            Log::warning('AI visual provider unavailable.', [
                'provider' => (string) ($body['provider'] ?? 'dermacerdas_ai'),
                'provider_status' => $providerStatus,
                'warnings' => array_values($body['warnings'] ?? []),
                'raw_error' => $body['raw_response']['error']
                    ?? $body['raw_response']['skin_filter']['error']
                    ?? null,
                'primary_completion_text' => isset($body['raw_response']['text'])
                    ? mb_substr((string) $body['raw_response']['text'], 0, 800)
                    : null,
            ]);

            return [
                'provider' => (string) ($body['provider'] ?? 'dermacerdas_ai'),
                'provider_status' => $providerStatus,
                'is_valid_skin_image' => null,
                'validation_status' => 'unavailable',
                'outside_scope' => false,
                'observed_description' => '',
                'candidates' => [],
                'suggested_symptom_codes' => [],
                'warnings' => array_values($body['warnings'] ?? ['Layanan AI visual sedang tidak tersedia.']),
                'raw_response' => $body['raw_response'] ?? $body,
            ];
        }

        if ($isDegraded) {
            Log::warning('AI visual provider fell back to the dataset index.', [
                'provider' => (string) ($body['provider'] ?? 'dermacerdas_ai'),
                'warnings' => array_values($body['warnings'] ?? []),
                'raw_error' => $body['raw_response']['error'] ?? null,
            ]);
        }

        $visualCandidates = $this->mapCandidates($body['candidates'] ?? []);
        $isValidSkinImage = (bool) ($body['is_valid_skin_image'] ?? false) || $visualCandidates !== [];

        // Sinyal positif dari model bahwa foto ini kemungkinan bukan salah satu
        // dari 16 penyakit cakupan - bukan cuma "kandidat kosong" karena provider
        // gagal. Sebelumnya field ini dihitung oleh ai-service tapi tidak pernah
        // dibaca di sini, sehingga hilang total sebelum sempat dipakai memberi
        // peringatan ke pengguna saat F06 tetap merekomendasikan obat.
        $outsideScope = (bool) ($body['outside_scope'] ?? false);

        return [
            'provider' => (string) ($body['provider'] ?? 'dermacerdas_ai'),
            'provider_status' => $providerStatus,
            'is_valid_skin_image' => $isValidSkinImage,
            'validation_status' => match (true) {
                ! $isValidSkinImage => 'invalid',
                $isDegraded => 'degraded',
                default => 'valid',
            },
            'outside_scope' => $outsideScope,
            'observed_description' => (string) ($body['observed_description'] ?? ''),
            'candidates' => $visualCandidates,
            'suggested_symptom_codes' => $this->validSuggestedSymptomCodes($body['suggested_symptom_codes'] ?? []),
            'warnings' => array_values($body['warnings'] ?? []),
            'raw_response' => $body['raw_response'] ?? $body,
        ];
    }

    /**
     * Mode Foto (beta): analisis bebas tanpa candidate_classes 16-penyakit/
     * dataset SD-198 - lihat QuickScanController. Sengaja terpisah dari
     * analyze() (konsultasi utama): tidak pernah masuk CF/fusion/rekomendasi
     * obat, murni referensi edukasi mentah dengan disclaimer eksplisit di UI.
     *
     * @return array{provider: string, provider_status: string, is_valid_skin_image: bool, candidates: array<int, array{condition_name: string, confidence: float, observed_description: string}>, warnings: array<int, string>}
     */
    public function analyzeOpen(string $imagePath): array
    {
        $baseUrl = trim((string) config('services.dermacerdas_ai.url'));

        if ($baseUrl === '') {
            return [
                'provider' => 'none',
                'provider_status' => 'not_configured',
                'is_valid_skin_image' => false,
                'candidates' => [],
                'warnings' => ['AI visual belum dikonfigurasi.'],
            ];
        }

        try {
            $response = Http::timeout((int) config('services.dermacerdas_ai.timeout', 20))
                ->acceptJson()
                ->post(rtrim($baseUrl, '/').'/analyze-image-open', [
                    'image_base64' => base64_encode(Storage::disk('public')->get($imagePath)),
                ]);
        } catch (\Throwable $exception) {
            return [
                'provider' => 'dermacerdas_ai',
                'provider_status' => 'unavailable',
                'is_valid_skin_image' => false,
                'candidates' => [],
                'warnings' => ['AI visual tidak dapat dihubungi: '.$exception->getMessage()],
            ];
        }

        if ($response->failed()) {
            return [
                'provider' => 'dermacerdas_ai',
                'provider_status' => 'invalid_request',
                'is_valid_skin_image' => false,
                'candidates' => [],
                'warnings' => [(string) ($response->json('detail') ?? 'Gambar tidak valid untuk dianalisis.')],
            ];
        }

        $body = $response->json() ?? [];

        $candidates = collect($body['candidates'] ?? [])
            ->filter(fn ($item): bool => is_array($item) && ! empty($item['condition_name']))
            ->map(fn (array $item): array => [
                'condition_name' => (string) $item['condition_name'],
                'confidence' => max(0.0, min(1.0, (float) ($item['confidence'] ?? 0))),
                'observed_description' => (string) ($item['observed_description'] ?? ''),
            ])
            ->values()
            ->all();

        return [
            'provider' => (string) ($body['provider'] ?? 'dermacerdas_ai'),
            'provider_status' => (string) ($body['provider_status'] ?? 'ok'),
            'is_valid_skin_image' => (bool) ($body['is_valid_skin_image'] ?? false),
            'candidates' => $candidates,
            'warnings' => array_values($body['warnings'] ?? []),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $textualRankings
     * @return array<int, string>
     */
    private function candidateClasses(array $textualRankings, array $diseaseHints = []): array
    {
        $hintClasses = collect($diseaseHints)
            ->map(fn ($hint): mixed => is_array($hint) ? ($hint['dataset_class_name'] ?? null) : null)
            ->filter(fn ($className): bool => is_string($className) && trim($className) !== '')
            ->values();

        $textualClasses = collect($textualRankings)
            ->take(8)
            ->flatMap(function (array $ranking): array {
                /** @var Disease $disease */
                $disease = $ranking['disease'];

                return $disease->datasetMappings()->pluck('dataset_class_name')->all();
            })
            ->filter()
            ->values();

        // Hanya kelas dari penyakit AKTIF yang ditawarkan. Sebelumnya seluruh
        // mapping ikut dikirim (159 kelas), dan itu terukur menghancurkan akurasi:
        // 159 kelas menghasilkan 0/8 benar, sedangkan ruang kandidat yang sempit
        // pada model dan citra yang sama menghasilkan 9/10.
        $scopeClasses = DatasetClassMapping::query()
            ->whereHas('disease', fn ($query) => $query->where('is_active', true))
            ->orderBy('dataset_class_id')
            ->pluck('dataset_class_name');

        return $hintClasses
            ->concat($textualClasses)
            ->concat($scopeClasses)
            ->filter(fn ($className): bool => is_string($className) && trim($className) !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{code: string, name: string, question: string}>
     */
    private function symptomQuestions(): array
    {
        return Symptom::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['code', 'name', 'question'])
            ->map(fn (Symptom $symptom): array => [
                'code' => $symptom->code,
                'name' => $symptom->name,
                'question' => $symptom->question,
            ])
            ->values()
            ->all();
    }

    /**
     * AI suggestions can only reference the active local question bank.
     * Unknown codes are discarded before they reach adaptive selection.
     *
     * @return array<int, string>
     */
    private function validSuggestedSymptomCodes(mixed $codes): array
    {
        if (! is_array($codes)) {
            return [];
        }

        $validCodes = Symptom::query()
            ->where('is_active', true)
            ->pluck('code')
            ->all();

        return collect($codes)
            ->filter(fn ($code): bool => is_string($code) && in_array($code, $validCodes, true))
            ->unique()
            ->take(8)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawCandidates
     * @return array<int, array<string, mixed>>
     */
    private function mapCandidates(array $rawCandidates): array
    {
        return collect($rawCandidates)
            ->map(function (array $candidate): ?array {
                // Penyakit nonaktif (mis. penyakit MVP yang sudah dipensiunkan)
                // tidak boleh menjadi kandidat visual Pv.
                $disease = Disease::query()
                    ->where('is_active', true)
                    ->where(function ($query) use ($candidate): void {
                        $query
                            ->where('code', $candidate['local_disease_code'] ?? null)
                            ->orWhereHas('datasetMappings', function ($mappingQuery) use ($candidate): void {
                                $mappingQuery->where('dataset_class_name', $candidate['dataset_class_name'] ?? null);
                            });
                    })
                    ->first();

                if (! $disease) {
                    return null;
                }

                return [
                    'disease' => $disease,
                    'provider' => 'dermacerdas_ai',
                    'visual_score' => round(max(0.0, min(1.0, (float) ($candidate['visual_score'] ?? 0))), 4),
                    'visual_reason' => (string) ($candidate['reason'] ?? 'Kandidat visual dari AI service.'),
                    'raw_response' => $candidate,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Ask the AI service to point out which red flags the user already
     * described in their free-text story, so the wizard doesn't make them
     * re-answer what they already said. Never blocks or fails the request -
     * any failure just means nothing gets pre-filled and the user answers
     * every question manually, same as before this existed.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\RedFlag>  $redFlags
     * @return array<int, string>
     */
    public function assessRedFlags(string $complaintText, \Illuminate\Support\Collection $redFlags): array
    {
        $baseUrl = trim((string) config('services.dermacerdas_ai.url'));

        if ($baseUrl === '' || $redFlags->isEmpty()) {
            return [];
        }

        try {
            $response = Http::timeout((int) config('services.dermacerdas_ai.timeout', 45))
                ->acceptJson()
                ->post(rtrim($baseUrl, '/').'/assess-red-flags', [
                    'complaint_text' => $complaintText,
                    'red_flags' => $redFlags
                        ->map(fn ($redFlag): array => [
                            'code' => $redFlag->code,
                            'question' => $redFlag->question,
                        ])
                        ->values()
                        ->all(),
                ]);

            if ($response->failed()) {
                return [];
            }

            $body = $response->json() ?? [];

            if ((string) ($body['provider_status'] ?? 'ok') !== 'ok') {
                return [];
            }

            $validCodes = $redFlags->pluck('code')->all();

            return collect($body['detected_codes'] ?? [])
                ->filter(fn ($code): bool => is_string($code) && in_array($code, $validCodes, true))
                ->values()
                ->all();
        } catch (\Throwable $exception) {
            Log::warning('AI red flag assessment unavailable.', ['message' => $exception->getMessage()]);

            return [];
        }
    }
}
