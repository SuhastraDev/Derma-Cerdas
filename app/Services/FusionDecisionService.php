<?php

namespace App\Services;

use App\Models\DatasetClassMapping;
use App\Models\Disease;
use App\Models\Setting;

class FusionDecisionService
{
    public function decide(
        Disease $disease,
        float $visualScore,
        float $textualCf,
        array $redFlagResult = [],
        ?float $visualWeight = null,
        ?float $textWeight = null,
        ?float $threshold = null,
        bool $hasValidatedVisual = true
    ): array {
        $weights = $this->weights($visualWeight, $textWeight);
        $threshold ??= $this->settingFloat('decision_threshold', 0.6);

        $visualScore = $this->clamp($visualScore);
        $textualCf = $this->clamp($textualCf);
        $fusionScore = $this->round(($weights['visual'] * $visualScore) + ($weights['text'] * $textualCf));
        $hasRedFlags = (bool) ($redFlagResult['has_red_flags'] ?? false);
        $datasetMapping = $this->datasetMappingFor($disease);

        $action = $this->resolveAction($disease, $fusionScore, $threshold, $hasRedFlags, $datasetMapping);

        return [
            'disease' => $disease,
            'visual_score' => $visualScore,
            'textual_cf' => $textualCf,
            'fusion_score' => $fusionScore,
            'fusion_score_percent' => $this->round($fusionScore * 100),
            'threshold' => $threshold,
            'weights' => $weights,
            'action' => $action,
            'can_recommend_medicine' => $action === 'recommend_otc',
            'explanation' => $this->explanation(
                $disease,
                $action,
                $visualScore,
                $textualCf,
                $fusionScore,
                $threshold,
                $hasRedFlags,
                $datasetMapping,
                $hasValidatedVisual
            ),
        ];
    }

    /**
     * @return array{visual: float, text: float}
     */
    private function weights(?float $visualWeight, ?float $textWeight): array
    {
        $visual = $visualWeight ?? $this->settingFloat('visual_weight', 0.4);
        $text = $textWeight ?? $this->settingFloat('text_weight', 0.6);
        $total = $visual + $text;

        if ($total <= 0.0) {
            return ['visual' => 0.4, 'text' => 0.6];
        }

        return [
            'visual' => $this->round($visual / $total),
            'text' => $this->round($text / $total),
        ];
    }

    private function resolveAction(
        Disease $disease,
        float $fusionScore,
        float $threshold,
        bool $hasRedFlags,
        ?DatasetClassMapping $datasetMapping
    ): string {
        if ($hasRedFlags) {
            return 'refer';
        }

        if ($datasetMapping && $datasetMapping->scope_category === 'rujuk') {
            return 'refer';
        }

        if ($disease->default_action === 'refer' || $disease->severity_scope === 'danger') {
            return 'refer';
        }

        if ($fusionScore < $threshold) {
            return 'insufficient_confidence';
        }

        if ($disease->default_action === 'educate_only') {
            return 'educate_only';
        }

        if ($datasetMapping && ! $datasetMapping->boleh_rekomendasi_obat) {
            return 'educate_only';
        }

        return 'recommend_otc';
    }

    private function explanation(
        Disease $disease,
        string $action,
        float $visualScore,
        float $textualCf,
        float $fusionScore,
        float $threshold,
        bool $hasRedFlags,
        ?DatasetClassMapping $datasetMapping,
        bool $hasValidatedVisual
    ): string {
        if ($hasValidatedVisual) {
            $base = sprintf(
                'Kemungkinan awal %s memiliki skor visual %.1f%%, skor gejala %.1f%%, dan skor gabungan %.1f%%.',
                $disease->name_indonesian ?: $disease->name,
                $visualScore * 100,
                $textualCf * 100,
                $fusionScore * 100
            );
        } else {
            $base = sprintf(
                'Kemungkinan awal %s dihitung dari skor gejala %.1f%% karena validasi visual belum tersedia.',
                $disease->name_indonesian ?: $disease->name,
                $textualCf * 100
            );
        }

        if ($hasRedFlags) {
            return $base.' Rekomendasi obat ditolak karena red flags terdeteksi.';
        }

        if ($datasetMapping && $datasetMapping->scope_category === 'rujuk') {
            return $base.' Class dataset ini masuk kategori rujuk, sehingga pengguna diarahkan ke tenaga kesehatan.';
        }

        if ($action === 'insufficient_confidence') {
            return $base.sprintf(
                ' Skor belum mencapai threshold %.1f%%, sehingga hasil belum cukup meyakinkan untuk rekomendasi obat.',
                $threshold * 100
            );
        }

        if ($action === 'educate_only') {
            return $base.' Sistem hanya memberi edukasi karena kondisi atau mapping belum aman untuk rekomendasi obat.';
        }

        return $base.' Skor melewati threshold dan tidak ada red flags, sehingga rekomendasi OBT dapat ditampilkan dengan peringatan penggunaan.';
    }

    private function datasetMappingFor(Disease $disease): ?DatasetClassMapping
    {
        if ($disease->relationLoaded('datasetMappings')) {
            return $disease->datasetMappings->first();
        }

        return $disease->datasetMappings()->first();
    }

    private function settingFloat(string $key, float $default): float
    {
        $setting = Setting::query()->where('key', $key)->first();

        if (! $setting) {
            return $default;
        }

        $value = $setting->value;

        if (is_array($value) && array_key_exists('value', $value)) {
            return (float) $value['value'];
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return $default;
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    private function round(float $value): float
    {
        return round($value, 4);
    }
}
