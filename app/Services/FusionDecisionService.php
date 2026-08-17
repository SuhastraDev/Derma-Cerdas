<?php

namespace App\Services;

use App\Models\Disease;

/**
 * Rule-Based Decision-Level Fusion (Tabel 3.13, Subbab 3.2.3.5 naskah skripsi).
 *
 * Menggabungkan kandidat penyakit hasil analisis visual (Pv) dengan kandidat
 * penyakit berkeyakinan tertinggi hasil Forward Chaining + Certainty Factor
 * (Pt, CFt) melalui aturan F01-F07, bukan rata-rata berbobot (weighted fusion).
 */
class FusionDecisionService
{
    private const HIGH_CF = 0.60;

    private const MEDIUM_CF = 0.40;

    /**
     * @param  Disease  $textualDisease  Pt: kandidat teks berkeyakinan tertinggi.
     * @param  float  $textualCf  CFt: nilai Certainty Factor kandidat teks tersebut.
     * @param  Disease|null  $visualDisease  Pv: kandidat visual teratas, null jika tidak ada (F06).
     * @param  float  $visualScore  Skor kandidat visual teratas (0 jika Pv null).
     * @param  bool  $visualAvailable  False jika citra tidak dapat dianalisis atau kelas visual di luar ruang lingkup (F06).
     * @param  array{has_red_flags?: bool}  $redFlagResult
     */
    public function decide(
        Disease $textualDisease,
        float $textualCf,
        ?Disease $visualDisease,
        float $visualScore,
        bool $visualAvailable,
        array $redFlagResult = []
    ): array {
        $textualCf = $this->clamp($textualCf);
        $visualScore = $this->clamp($visualScore);
        $hasRedFlags = (bool) ($redFlagResult['has_red_flags'] ?? false);

        [$ruleCode, $action] = $this->resolveRule(
            $textualDisease,
            $textualCf,
            $visualDisease,
            $visualAvailable,
            $hasRedFlags
        );
        $action = $this->enforceDiseaseScope($textualDisease, $action);

        return [
            'disease' => $textualDisease,
            'visual_score' => $visualScore,
            'textual_cf' => $textualCf,
            'fusion_score' => $textualCf,
            'fusion_score_percent' => $this->round($textualCf * 100),
            'fusion_rule_code' => $ruleCode,
            'action' => $action,
            'can_recommend_medicine' => in_array($action, [
                'recommend_otc',
                'recommend_otc_observe',
                'recommend_otc_mismatch',
                'recommend_otc_unsupported',
            ], true),
            'explanation' => $this->explanation(
                $textualDisease,
                $visualDisease,
                $ruleCode,
                $action,
                $textualCf,
                $visualAvailable,
                $hasRedFlags
            ),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolveRule(
        Disease $textualDisease,
        float $textualCf,
        ?Disease $visualDisease,
        bool $visualAvailable,
        bool $hasRedFlags
    ): array {
        // F07: tanda bahaya menggantikan seluruh aturan lainnya.
        if ($hasRedFlags) {
            return ['F07', 'refer'];
        }

        // F06: citra tidak dapat dianalisis atau kelas visual di luar ruang lingkup.
        if (! $visualAvailable || ! $visualDisease) {
            return $this->textOnlyRule($textualCf);
        }

        $matches = $visualDisease->is($textualDisease);

        if ($matches) {
            if ($textualCf >= self::HIGH_CF) {
                return ['F01', 'recommend_otc'];
            }

            if ($textualCf >= self::MEDIUM_CF) {
                return ['F02', 'recommend_otc_observe'];
            }

            return ['F03', 'insufficient_confidence'];
        }

        if ($textualCf >= self::HIGH_CF) {
            return ['F04', 'recommend_otc_mismatch'];
        }

        return ['F05', 'refer'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function textOnlyRule(float $textualCf): array
    {
        if ($textualCf >= self::HIGH_CF) {
            return ['F06', 'recommend_otc_unsupported'];
        }

        if ($textualCf >= self::MEDIUM_CF) {
            return ['F06', 'recommend_otc_observe'];
        }

        return ['F06', 'insufficient_confidence'];
    }

    private function explanation(
        Disease $textualDisease,
        ?Disease $visualDisease,
        string $ruleCode,
        string $action,
        float $textualCf,
        bool $visualAvailable,
        bool $hasRedFlags
    ): string {
        $diseaseName = $textualDisease->name_indonesian ?: $textualDisease->name;
        $cfPercent = sprintf('%.1f%%', $textualCf * 100);

        if ($hasRedFlags) {
            return sprintf(
                'Aturan F07: terdeteksi tanda bahaya sehingga seluruh rekomendasi obat ditahan dan pengguna diarahkan ke tenaga kesehatan (kandidat gejala teratas: %s, CF %s).',
                $diseaseName,
                $cfPercent
            );
        }

        if ($textualDisease->default_action === 'refer' && $action === 'refer') {
            return sprintf(
                'Scope rujukan: kandidat %s berada di luar penanganan mandiri sehingga hasil skrining tidak disertai rekomendasi obat dan perlu diperiksa tenaga kesehatan (CF %s).',
                $diseaseName,
                $cfPercent
            );
        }

        if ($textualDisease->default_action === 'educate_only' && $action === 'educate_only') {
            return sprintf(
                'Scope edukasi: kandidat %s ditampilkan sebagai informasi awal berdasarkan data dan gejala, bukan diagnosis terkonfirmasi; sistem tidak memberikan rekomendasi obat (CF %s).',
                $diseaseName,
                $cfPercent
            );
        }

        if (! $visualAvailable || ! $visualDisease) {
            return match ($action) {
                'recommend_otc_unsupported' => sprintf(
                    'Aturan F06: citra tidak dapat dianalisis atau kelas visual di luar ruang lingkup, sehingga keputusan disandarkan pada gejala (%s, CF %s) dengan status keyakinan terbatas karena tidak didukung analisis visual.',
                    $diseaseName,
                    $cfPercent
                ),
                'recommend_otc_observe' => sprintf(
                    'Aturan F06: penilaian visual tidak tersedia. Gejala mengarah ke %s dengan keyakinan sedang (CF %s); rekomendasi obat disertai anjuran observasi dan konsultasi apabila tidak membaik.',
                    $diseaseName,
                    $cfPercent
                ),
                default => sprintf(
                    'Aturan F06: penilaian visual tidak tersedia dan keyakinan gejala terhadap %s masih rendah (CF %s), sehingga belum diberikan rekomendasi obat.',
                    $diseaseName,
                    $cfPercent
                ),
            };
        }

        $visualName = $visualDisease->name_indonesian ?: $visualDisease->name;

        return match ($ruleCode) {
            'F01' => sprintf(
                'Aturan F01: hasil visual dan gejala sama-sama mengarah ke %s dengan keyakinan tinggi (CF %s), sehingga rekomendasi Obat Bebas Terbatas ditampilkan.',
                $diseaseName,
                $cfPercent
            ),
            'F02' => sprintf(
                'Aturan F02: hasil visual dan gejala sama-sama mengarah ke %s dengan keyakinan sedang (CF %s); rekomendasi obat disertai anjuran observasi dan konsultasi apabila keluhan tidak membaik.',
                $diseaseName,
                $cfPercent
            ),
            'F03' => sprintf(
                'Aturan F03: hasil visual dan gejala sama-sama mengarah ke %s tetapi keyakinan masih rendah (CF %s), sehingga sistem meminta pengguna melengkapi gejala atau berkonsultasi.',
                $diseaseName,
                $cfPercent
            ),
            'F04' => sprintf(
                'Aturan F04: hasil visual mengarah ke %s sedangkan gejala mengarah ke %s dengan keyakinan tinggi (CF %s). Keputusan disandarkan pada hasil gejala disertai catatan ketidaksesuaian dan anjuran konsultasi.',
                $visualName,
                $diseaseName,
                $cfPercent
            ),
            default => sprintf(
                'Aturan F05: hasil visual (%s) dan gejala (%s, CF %s) tidak sesuai serta keyakinan gejala belum tinggi, sehingga mekanisme safety-net menahan rekomendasi obat dan mengarahkan pengguna berkonsultasi.',
                $visualName,
                $diseaseName,
                $cfPercent
            ),
        };
    }

    /**
     * F08: kandidat visual teratas cocok dengan penyakit yang belum punya basis
     * pengetahuan gejala/CF tervalidasi (di luar 5 penyakit naskah dan MVP awal).
     * Temuan visual tetap ditampilkan apa adanya sebagai informasi edukasi
     * dengan sumber rujukan, tapi TIDAK PERNAH menghasilkan rekomendasi obat -
     * hanya arahan edukasi atau rujuk sesuai default_action penyakit tersebut.
     */
    public function decideVisualOnly(Disease $disease, float $visualScore): array
    {
        $visualScore = $this->clamp($visualScore);
        $action = $disease->default_action === 'refer' ? 'refer' : 'educate_only';

        return [
            'disease' => $disease,
            'visual_score' => $visualScore,
            'textual_cf' => 0.0,
            'fusion_score' => $visualScore,
            'fusion_score_percent' => $this->round($visualScore * 100),
            'fusion_rule_code' => 'F08',
            'action' => $action,
            'can_recommend_medicine' => false,
            'explanation' => sprintf(
                'Aturan F08: analisis visual mengarah ke %s (skor %.1f%%), tetapi penyakit ini belum memiliki basis pengetahuan gejala/CF tervalidasi di sistem. Informasi ditampilkan sebagai edukasi awal berdasarkan temuan visual, bukan diagnosis, sehingga tidak ada rekomendasi obat. %s',
                $disease->name_indonesian ?: $disease->name,
                $visualScore * 100,
                $action === 'refer'
                    ? 'Kondisi ini disarankan untuk segera diperiksakan ke tenaga kesehatan.'
                    : 'Tetap disarankan konsultasi ke tenaga kesehatan untuk kepastian diagnosis.'
            ),
        ];
    }

    /**
     * F09: the user supplied a disease name as context and the visual model
     * returned the same mapped disease. This is stronger than text-only CF,
     * but it is still not a confirmed diagnosis and must never unlock OTC
     * medicine from the user's own label.
     */
    public function decideContextAlignedVisual(Disease $disease, float $visualScore): array
    {
        $visualScore = $this->clamp($visualScore);
        $action = $disease->default_action === 'refer' ? 'refer' : 'educate_only';
        $diseaseName = $disease->name_indonesian ?: $disease->name;

        return [
            'disease' => $disease,
            'visual_score' => $visualScore,
            'textual_cf' => 0.0,
            'fusion_score' => $visualScore,
            'fusion_score_percent' => $this->round($visualScore * 100),
            'fusion_rule_code' => 'F09',
            'action' => $action,
            'can_recommend_medicine' => false,
            'explanation' => sprintf(
                'Aturan F09: penyakit yang disebut pengguna sebagai konteks selaras dengan kandidat visual %s (skor %.1f%%). Hasil ini tetap bukan diagnosis terkonfirmasi dan tidak disertai rekomendasi obat.%s',
                $diseaseName,
                $visualScore * 100,
                $action === 'refer'
                    ? ' Pengguna diarahkan untuk diperiksa tenaga kesehatan.'
                    : ' Informasi digunakan untuk edukasi awal dan perlu dikonfirmasi tenaga kesehatan.'
            ),
        ];
    }

    private function enforceDiseaseScope(Disease $disease, string $action): string
    {
        return match ($disease->default_action) {
            'refer' => 'refer',
            'educate_only' => 'educate_only',
            default => $action,
        };
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
