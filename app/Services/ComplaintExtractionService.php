<?php

namespace App\Services;

use App\Models\DatasetClassMapping;
use Illuminate\Support\Str;

class ComplaintExtractionService
{
    /**
     * @return array{
     *     normalized_text: string,
     *     symptom_evidence: array<string, array{score: float, matched_terms: array<int, string>}>,
     *     red_flag_evidence: array<string, array{detected: bool, matched_terms: array<int, string>}>,
     *     disease_hints: array<int, array{dataset_class_name: string, disease_code: string|null, matched_terms: array<int, string>}>,
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
                'disease_hints' => [],
                'summary' => [],
            ];
        }

        $symptomEvidence = $this->extractSymptomEvidence($normalizedText);
        $redFlagEvidence = $this->extractRedFlagEvidence($normalizedText);
        $diseaseHints = $this->extractDiseaseHints($normalizedText);

        return [
            'normalized_text' => $normalizedText,
            'symptom_evidence' => $symptomEvidence,
            'red_flag_evidence' => $redFlagEvidence,
            'disease_hints' => $diseaseHints,
            'summary' => $this->summary($symptomEvidence, $redFlagEvidence, $diseaseHints),
        ];
    }

    /**
     * Extract explicit disease words as shortlist hints only. A user statement
     * such as "saya menduga psoriasis" must never become a diagnosis by itself.
     *
     * @return array<int, array{dataset_class_name: string, disease_code: string|null, matched_terms: array<int, string>}>
     */
    private function extractDiseaseHints(string $text): array
    {
        return DatasetClassMapping::query()
            ->with('disease:id,code,name,name_indonesian')
            ->get(['dataset_class_name', 'nama_indonesia', 'disease_id'])
            ->map(function (DatasetClassMapping $mapping) use ($text): ?array {
                $labels = collect([
                    $mapping->dataset_class_name,
                    $mapping->nama_indonesia,
                    $mapping->disease?->name,
                    $mapping->disease?->name_indonesian,
                ])
                    ->filter(fn ($label): bool => is_string($label) && trim($label) !== '')
                    ->map(fn (string $label): string => $this->normalizeLabel($label))
                    ->filter(fn (string $label): bool => mb_strlen($label) >= 4)
                    ->unique()
                    ->values();

                $matchedTerms = $labels
                    ->filter(fn (string $label): bool => Str::contains($text, $label))
                    ->values()
                    ->all();

                if ($matchedTerms === []) {
                    return null;
                }

                return [
                    'dataset_class_name' => (string) $mapping->dataset_class_name,
                    'disease_code' => $mapping->disease?->code,
                    'matched_terms' => $matchedTerms,
                ];
            })
            ->filter()
            ->unique('dataset_class_name')
            ->values()
            ->all();
    }

    /**
     * @return array<string, array{score: float, matched_terms: array<int, string>}>
     */
    private function extractSymptomEvidence(string $text): array
    {
        // Kode di sini HARUS sama dengan kode pilihan pada QuestionBank, karena
        // gunanya cuma satu: memberi tahu AdaptiveQuestionService pertanyaan mana
        // yang layak diajukan lebih dulu. Nilai skornya tidak pernah menjadi
        // jawaban - lihat ConsultationWorkflowService::process().
        $dictionary = [
            'P1_KUKU' => [0.8, ['kuku']],
            'P1_KAKI' => [0.8, ['sela jari', 'telapak kaki', 'kutu air']],
            'P1_LIPATAN' => [0.75, ['lipatan', 'selangkangan', 'ketiak']],
            'P1_SIKULUTUT' => [0.7, ['siku', 'lutut']],
            'P1_WAJAH' => [0.6, ['wajah', 'muka', 'pipi', 'dahi', 'leher']],
            'P1_BADAN' => [0.5, ['badan', 'punggung', 'lengan', 'perut', 'dada']],
            'P1_KONTAK' => [0.7, ['bekas jam', 'kena kosmetik', 'setelah pakai']],

            'P2_CINCIN' => [0.85, ['melingkar', 'lingkar', 'cincin', 'kurap']],
            'P2_PLAK' => [0.7, ['plak', 'menebal', 'tebal menonjol']],
            'P2_DATAR' => [0.7, ['panu', 'bercak putih', 'bercak cokelat', 'bercak coklat']],
            'P2_JERAWAT' => [0.8, ['jerawat', 'komedo', 'beruntusan']],
            'P2_BENJOLAN' => [0.7, ['benjolan', 'tonjolan', 'mengkilap']],
            'P2_BENTOL' => [0.85, ['biduran', 'bentol', 'hilang timbul', 'berpindah']],
            'P2_GELEMBUNG' => [0.75, ['lepuh', 'melepuh', 'gelembung', 'bergerombol']],
            'P2_KERAK' => [0.75, ['keropeng', 'krusta', 'berkerak', 'bernanah']],
            'P2_MERAHLUAS' => [0.5, ['kemerahan', 'ruam', 'bercak merah']],
            'P2_KUKUBERUBAH' => [0.8, ['kuku menebal', 'kuku rapuh', 'kuku berubah warna', 'kuku rusak']],
            'P2_TANDUK' => [0.8, ['tanduk', 'seperti tanduk', 'menjulang keras']],

            'P3_HALUS' => [0.6, ['bersisik', 'sisik halus']],
            'P3_TEBAL' => [0.7, ['sisik tebal', 'keperakan', 'mengelupas berlapis']],
            'P3_KERING' => [0.6, ['kulit kering', 'pecah pecah', 'pecah-pecah']],
            'P3_SISIKIKAN' => [0.85, ['sisik ikan', 'seperti sisik ikan', 'kotak kotak', 'kotak-kotak']],

            'P4_GATAL' => [0.6, ['gatal', 'digaruk', 'garuk']],
            'P4_NYERI' => [0.75, ['nyeri', 'menusuk', 'ngilu']],
            'P4_PERIH' => [0.6, ['perih', 'pedih', 'terbakar', 'menyengat']],

            'P5_JAM' => [0.7, ['hilang timbul', 'muncul hilang']],
            'P5_HARI' => [0.5, ['beberapa hari', 'menyebar cepat', 'cepat menyebar']],
            'P5_MINGGU' => [0.5, ['berminggu', 'beberapa minggu']],
            'P5_BULAN' => [0.6, ['berbulan', 'beberapa bulan', 'tidak sembuh']],
            'P5_TAHUN' => [0.6, ['bertahun', 'kambuh', 'berulang']],

            'P6_SATUSISI' => [0.75, ['satu sisi', 'sebelah kiri saja', 'sebelah kanan saja']],
            'P6_SIMETRIS' => [0.5, ['kedua sisi', 'dua sisi', 'simetris']],
            'P6_SETEMPAT' => [0.5, ['satu tempat saja', 'hanya di situ saja']],
            'P6_TERSEBAR' => [0.5, ['titik terpisah', 'titik titik terpisah', 'tersebar tidak beraturan']],

            'P7_KONTAK' => [0.7, ['sabun', 'detergen', 'kosmetik', 'skincare', 'parfum', 'logam', 'tanaman', 'alergi']],
            'P7_KERINGAT' => [0.7, ['berkeringat', 'keringat', 'lembap', 'lembab']],
            'P7_BEKASLUKA' => [0.8, ['bekas luka', 'bekas tindik', 'bekas operasi', 'bekas jerawat', 'keloid']],
            'P7_OBAT' => [0.7, ['minum obat', 'setelah obat', 'obat baru']],

            'P8_TENGAHBERSIH' => [0.8, ['tengah lebih bersih', 'tengah bersih', 'tengah normal']],
            'P8_SATELIT' => [0.8, ['bintik kecil di sekitar', 'bintik satelit']],
            'P8_KOMEDO' => [0.8, ['komedo']],
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
     * @param  array<int, array{dataset_class_name: string, disease_code: string|null, matched_terms: array<int, string>}>  $diseaseHints
     * @return array<int, string>
     */
    private function summary(array $symptomEvidence, array $redFlagEvidence, array $diseaseHints = []): array
    {
        $summary = [];

        foreach ($symptomEvidence as $code => $evidence) {
            $summary[] = sprintf('%s terdeteksi dari kata: %s.', $code, implode(', ', $evidence['matched_terms']));
        }

        foreach ($redFlagEvidence as $code => $evidence) {
            $summary[] = sprintf('Red flag %s terdeteksi dari kata: %s.', $code, implode(', ', $evidence['matched_terms']));
        }

        foreach ($diseaseHints as $hint) {
            $summary[] = sprintf(
                'Kandidat penyakit %s disebut pengguna sebagai konteks (%s), bukan diagnosis terkonfirmasi.',
                $hint['dataset_class_name'],
                implode(', ', $hint['matched_terms'])
            );
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
            ->filter(fn (string $term): bool => $this->containsWord($text, $term) && ! $this->isNegated($text, $term))
            ->values()
            ->all();
    }

    /**
     * Pencocokan pada batas kata, bukan substring mentah.
     *
     * Str::contains() menimbulkan salah deteksi yang terbukti pada data produksi:
     * "berkeringat" mengandung "kering" sehingga memicu kulit kering, dan
     * "kulit putih" memicu bercak putih/panu. Imbuhan Indonesia yang lazim
     * (ber-, me-, -nya, -an) tetap dikenali agar "bersisik" cocok dengan "sisik".
     */
    private function containsWord(string $text, string $term): bool
    {
        return preg_match($this->wordPattern($term), $text) === 1;
    }

    private function wordPattern(string $term): string
    {
        $escaped = preg_quote($term, '/');

        return '/(?<![a-z])(?:ber|be|me|meng|men|ter|di|ke)?'.$escaped.'(?:nya|an|kan)?(?![a-z])/';
    }

    private function isNegated(string $text, string $term): bool
    {
        // Diperiksa pada setiap kemunculan, bukan hanya yang pertama. Kalimat
        // "awalnya tidak gatal, sekarang gatal sekali" sebelumnya dianggap
        // tidak gatal karena kemunculan pertamanya dinegasikan.
        if (preg_match_all($this->wordPattern($term), $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return false;
        }

        $occurrences = $matches[0] ?? [];

        if ($occurrences === []) {
            return false;
        }

        foreach ($occurrences as [$matchedText, $position]) {
            $windowStart = max(0, $position - 24);
            $beforeTerm = substr($text, $windowStart, $position - $windowStart);

            if (! preg_match('/\b(tidak|tanpa|bukan|belum|gak|ga|nggak)\b[^.,;]*$/', $beforeTerm)) {
                return false;
            }
        }

        return true;
    }

    private function normalize(string $text): string
    {
        $text = Str::lower(Str::ascii($text));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    private function normalizeLabel(string $label): string
    {
        $label = Str::lower(Str::ascii($label));
        $label = preg_replace('/[_\-()\/]+/', ' ', $label) ?? $label;
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;

        return trim($label);
    }
}
