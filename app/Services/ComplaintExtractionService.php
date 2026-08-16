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

            // Gejala G01-G20 sesuai Tabel 3.4 naskah skripsi (lima penyakit ruang lingkup).
            'G01' => [0.6, ['merah', 'kemerahan']],
            'G02' => [0.6, ['gatal', 'digaruk', 'garuk']],
            'G03' => [0.6, ['bersisik', 'sisik', 'kasar', 'mengelupas', 'pecah pecah']],
            'G04' => [0.5, ['lepuh', 'melepuh', 'bintil berair', 'gelembung']],
            'G05' => [0.5, ['bengkak ringan', 'sedikit bengkak']],
            'G06' => [0.8, ['melingkar', 'lingkar', 'cincin', 'kurap']],
            'G07' => [0.7, ['tengah lebih bersih', 'tengah bersih', 'tengah normal']],
            'G08' => [0.7, ['batas jelas', 'tepi jelas', 'pinggir jelas', 'tepi merah']],
            'G09' => [0.7, ['sela jari', 'pecah-pecah kaki', 'sela jari kaki']],
            'G10' => [0.7, ['lipatan paha', 'selangkangan']],
            'G11' => [0.8, ['bercak putih', 'bercak cokelat', 'bercak coklat', 'panu']],
            'G12' => [0.6, ['gatal berkeringat', 'gatal saat keringat', 'gatal setelah keringat', 'bertambah setelah berkeringat']],
            'G13' => [0.7, ['kontak sabun', 'kontak kosmetik', 'kontak logam', 'kontak tanaman', 'setelah pakai', 'pemicu']],
            'G14' => [0.5, ['perih', 'terbakar', 'panas']],
            'G15' => [0.7, ['telapak kaki']],
            'G16' => [0.5, ['di badan', 'pada badan', 'di lengan', 'pada lengan']],
            'G17' => [0.4, ['nyeri ringan', 'sedikit nyeri']],
            'G18' => [0.5, ['kulit kering', 'kering']],
            'G19' => [0.6, ['melebar', 'meluas', 'bertambah luas']],
            'G20' => [0.5, ['area yang terkena', 'area yang kena', 'hanya di area terpapar']],
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
            'PUS_OR_WIDE_INFECTION' => ['nanah', 'bernanah', 'terinfeksi', 'infeksi berat', 'busuk'],
            'OPEN_WOUND_LARGE' => ['luka terbuka', 'luka luas'],
            'BLACKENED_SKIN' => ['kulit menghitam', 'kulit hitam', 'jaringan mati', 'jaringan menghitam'],
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
            $windowStart = max(0, $position - 24);
            $beforeTerm = substr($text, $windowStart, $position - $windowStart);

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
