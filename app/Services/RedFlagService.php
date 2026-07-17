<?php

namespace App\Services;

use App\Models\RedFlag;
use Illuminate\Support\Collection;

class RedFlagService
{
    /**
     * @param  array<int|string, bool|int|string>  $answers
     * @return array{
     *     has_red_flags: bool,
     *     action: string,
     *     message: string,
     *     detected: array<int, array<string, mixed>>
     * }
     */
    public function evaluate(array $answers): array
    {
        $detected = $this->activeRedFlags()
            ->filter(fn (RedFlag $redFlag): bool => $this->answerIsPositive($answers, $redFlag))
            ->map(fn (RedFlag $redFlag): array => [
                'id' => $redFlag->id,
                'code' => $redFlag->code,
                'question' => $redFlag->question,
                'severity' => $redFlag->severity,
                'action_message' => $redFlag->action_message,
            ])
            ->values()
            ->all();

        return [
            'has_red_flags' => $detected !== [],
            'action' => $detected === [] ? 'continue' : 'refer',
            'message' => $detected === []
                ? 'Tidak ada red flags yang terdeteksi dari jawaban pengguna.'
                : 'Terdapat red flags sehingga sistem tidak menampilkan rekomendasi obat mandiri.',
            'detected' => $detected,
        ];
    }

    /**
     * @return Collection<int, RedFlag>
     */
    private function activeRedFlags(): Collection
    {
        return RedFlag::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<int|string, bool|int|string>  $answers
     */
    private function answerIsPositive(array $answers, RedFlag $redFlag): bool
    {
        $keys = [
            $redFlag->id,
            (string) $redFlag->id,
            $redFlag->code,
        ];

        foreach ($keys as $key) {
            if ($key !== null && array_key_exists($key, $answers)) {
                return filter_var($answers[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return false;
    }
}
