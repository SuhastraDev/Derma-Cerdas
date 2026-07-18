<?php

namespace App\Services;

use Illuminate\Support\Str;

class ComplaintExtractionService
{
    /**
     * @return array{
     *     normalized_text: string,
     *     symptom_evidence: array<string, array{score: float, matched_terms: array<int, string>}>,
     *     red_flag_evidence: array<string, array{detected: bool, matched_terms: array<int, string>}>,
     *     summary: array<int, string>
     * }
     */
    public function extract(?string $complaintText): array
    {
        $normalizedText = $this->normalize($complaintText ?? '');

        if ($normalizedText === '') {
            return [
                'normalized_text' => '',
                'symptom_evidence' => [],
                'red_flag_evidence' => [],
                'summary' => [],
            ];
        }

        $symptomEvidence = $this->extractSymptomEvidence($normalizedText);
        $redFlagEvidence = $this->extractRedFlagEvidence($normalizedText);

        return [
            'normalized_text' => $normalizedText,
            'symptom_evidence' => $symptomEvidence,
            'red_flag_evidence' => $redFlagEvidence,
            'summary' => $this->summary($symptomEvidence, $redFlagEvidence),
        ];
    }

    /**
     * @return array<string, array{score: float, matched_terms: array<int, string>}>
     */
    private function extractSymptomEvidence(string $text): array
    {
        $dictionary = [
            'ITCHING' => [0.7, ['gatal', 'digaruk', 'garuk']],
            'RED_RASH' => [0.65, ['merah', 'kemerahan', 'ruam', 'bintik merah', 'bercak merah']],
            'DRY_SCALY_SKIN' => [0.7, ['kering', 'bersisik', 'sisik', 'kasar', 'mengelupas', 'pecah pecah']],
            'VESICLES_OOZING' => [0.55, ['berair', 'cairan', 'lepuh', 'melepuh', 'bintil', 'gelembung']],
            'CONTACT_TRIGGER' => [0.8, ['sabun', 'detergen', 'kosmetik', 'skincare', 'parfum', 'logam', 'tanaman', 'pemicu', 'alergi']],
            'WHEALS_COME_GO' => [0.85, ['biduran', 'bentol', 'hilang timbul', 'muncul hilang', 'berpindah']],
            'RING_SHAPED_EDGE' => [0.85, ['melingkar', 'lingkar', 'cincin', 'tepi merah', 'kurap']],
            'MOIST_FOLD_RASH' => [0.75, ['lipatan', 'selangkangan', 'paha', 'ketiak', 'lembap', 'lembab']],
            'FOOT_SCALING' => [0.85, ['kaki', 'sela jari', 'telapak', 'kutu air']],
            'WHITE_BROWN_PATCHES' => [0.75, ['putih', 'cokelat', 'coklat', 'panu', 'bercak putih', 'bercak cokelat']],
            'BURNING_STINGING' => [0.55, ['perih', 'panas', 'pedih', 'terbakar', 'menyengat']],
            'RECURRENT_OR_DAYS' => [0.55, ['hari', 'minggu', 'bulan', 'sejak', 'kambuh', 'berulang', 'lama']],
        ];

        $evidence = [];

        foreach ($dictionary as $code => [$score, $terms]) {
            $matchedTerms = $this->matchedTerms($text, $terms);

            if ($matchedTerms !== []) {
                $evidence[$code] = [
                    'score' => $score,
                    'matched_terms' => $matchedTerms,
                ];
            }
        }

        return $evidence;
    }

    /**
     * @return array<string, array{detected: bool, matched_terms: array<int, string>}>
     */
    private function extractRedFlagEvidence(string $text): array
    {
        $dictionary = [
            'FEVER_HIGH' => ['demam', 'panas tinggi', 'meriang'],
            'SEVERE_PAIN' => ['nyeri hebat', 'sangat nyeri', 'sakit sekali', 'nyeri sekali'],
            'FAST_SPREADING_SWELLING' => ['menyebar cepat', 'cepat menyebar', 'bengkak menyebar', 'membesar cepat'],
            'PUS_OR_WIDE_INFECTION' => ['nanah', 'bernanah', 'infeksi', 'busuk'],
            'OPEN_WOUND_LARGE' => ['luka terbuka', 'luka luas'],
            'BLACKENED_SKIN' => ['menghitam', 'hitam', 'jaringan mati'],
            'WIDESPREAD_RASH' => ['seluruh tubuh', 'hampir semua badan', 'ruam luas'],
            'BREATHING_OR_FACE_SWELLING' => ['sesak', 'susah napas', 'bibir bengkak', 'wajah bengkak', 'mata bengkak'],
        ];

        $evidence = [];

        foreach ($dictionary as $code => $terms) {
            $matchedTerms = $this->matchedTerms($text, $terms);

            if ($matchedTerms !== []) {
                $evidence[$code] = [
                    'detected' => true,
                    'matched_terms' => $matchedTerms,
                ];
            }
        }

        return $evidence;
    }

    /**
     * @param  array<string, array{score: float, matched_terms: array<int, string>}>  $symptomEvidence
     * @param  array<string, array{detected: bool, matched_terms: array<int, string>}>  $redFlagEvidence
     * @return array<int, string>
     */
    private function summary(array $symptomEvidence, array $redFlagEvidence): array
    {
        $summary = [];

        foreach ($symptomEvidence as $code => $evidence) {
            $summary[] = sprintf('%s terdeteksi dari kata: %s.', $code, implode(', ', $evidence['matched_terms']));
        }

        foreach ($redFlagEvidence as $code => $evidence) {
            $summary[] = sprintf('Red flag %s terdeteksi dari kata: %s.', $code, implode(', ', $evidence['matched_terms']));
        }

        return $summary;
    }

    /**
     * @param  array<int, string>  $terms
     * @return array<int, string>
     */
    private function matchedTerms(string $text, array $terms): array
    {
        return collect($terms)
            ->filter(fn (string $term): bool => Str::contains($text, $term) && ! $this->isNegated($text, $term))
            ->values()
            ->all();
    }

    private function isNegated(string $text, string $term): bool
    {
        $position = strpos($text, $term);

        if ($position !== false) {
            $beforeTerm = substr($text, max(0, $position - 24), $position);

            if (preg_match('/\b(tidak|tanpa|bukan|belum|gak|ga|nggak)\b/', $beforeTerm)) {
                return true;
            }
        }

        $patterns = [
            'tidak '.$term,
            'tanpa '.$term,
            'bukan '.$term,
            'belum '.$term,
            'gak '.$term,
            'ga '.$term,
            'nggak '.$term,
        ];

        return collect($patterns)->contains(fn (string $pattern): bool => Str::contains($text, $pattern));
    }

    private function normalize(string $text): string
    {
        $text = Str::lower(Str::ascii($text));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }
}
