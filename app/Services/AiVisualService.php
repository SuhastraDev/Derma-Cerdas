<?php

namespace App\Services;

use App\Models\Disease;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class AiVisualService
{
    /**
     * @param  array<int, array<string, mixed>>  $textualRankings
     * @return array{provider: string, is_valid_skin_image: bool|null, validation_status: string, candidates: array<int, array<string, mixed>>, warnings: array<int, string>, raw_response: array<string, mixed>}
     */
    public function analyze(string $imagePath, array $textualRankings): array
    {
        $baseUrl = trim((string) config('services.dermacerdas_ai.url'));

        if ($baseUrl === '') {
            return [
                'provider' => 'none',
                'is_valid_skin_image' => null,
                'validation_status' => 'not_configured',
                'candidates' => [],
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
                'candidate_classes' => $this->candidateClasses($textualRankings),
            ];

            $response = Http::timeout((int) config('services.dermacerdas_ai.timeout', 20))
                ->acceptJson()
                ->post(rtrim($baseUrl, '/').'/analyze-image', $payload);
        } catch (\Throwable $exception) {
            return [
                'provider' => 'dermacerdas_ai',
                'is_valid_skin_image' => null,
                'validation_status' => 'unavailable',
                'candidates' => [],
                'warnings' => ['AI visual tidak dapat dihubungi: '.$exception->getMessage()],
                'raw_response' => [],
            ];
        }

        if ($response->failed()) {
            return [
                'provider' => 'dermacerdas_ai',
                'is_valid_skin_image' => false,
                'validation_status' => 'invalid',
                'candidates' => [],
                'warnings' => [(string) ($response->json('detail') ?? 'Gambar tidak valid untuk dianalisis.')],
                'raw_response' => $response->json() ?? [],
            ];
        }

        $body = $response->json() ?? [];
        $isValidSkinImage = (bool) ($body['is_valid_skin_image'] ?? false);

        return [
            'provider' => (string) ($body['provider'] ?? 'dermacerdas_ai'),
            'is_valid_skin_image' => $isValidSkinImage,
            'validation_status' => $isValidSkinImage ? 'valid' : 'invalid',
            'candidates' => $isValidSkinImage ? $this->mapCandidates($body['candidates'] ?? []) : [],
            'warnings' => array_values($body['warnings'] ?? []),
            'raw_response' => $body['raw_response'] ?? $body,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $textualRankings
     * @return array<int, string>
     */
    private function candidateClasses(array $textualRankings): array
    {
        return collect($textualRankings)
            ->take(8)
            ->flatMap(function (array $ranking): array {
                /** @var Disease $disease */
                $disease = $ranking['disease'];

                return $disease->datasetMappings()->pluck('dataset_class_name')->all();
            })
            ->filter()
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
                $disease = Disease::query()
                    ->where('code', $candidate['local_disease_code'] ?? null)
                    ->orWhereHas('datasetMappings', function ($query) use ($candidate): void {
                        $query->where('dataset_class_name', $candidate['dataset_class_name'] ?? null);
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
}
