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
    /** Batas "cukup yakin" pada skala Praditya dkk (2024), Tabel 3.10 naskah. */
    public const HIGH_CF = 0.60;

    /** Batas "sedikit yakin" pada skala yang sama. */
    public const MEDIUM_CF = 0.40;

    /**
     * @param  Disease  $textualDisease  Pt: kandidat teks berkeyakinan tertinggi.
     * @param  float  $textualCf  CFt: nilai Certainty Factor kandidat teks tersebut.
     * @param  Disease|null  $visualDisease  Pv: kandidat visual teratas, null jika tidak ada (F06).
     * @param  float  $visualScore  Skor kandidat visual teratas (0 jika Pv null).
     * @param  bool  $visualAvailable  False jika citra tidak dapat dianalisis atau kelas visual di luar ruang lingkup (F06).
     * @param  array{has_red_flags?: bool}  $redFlagResult
     * @param  bool  $visualUnreliable  True jika visual sempat dianalisis TAPI hasilnya tidak bisa dipercaya
     *                                    sebagai bukti pendukung salah satu dari 16 penyakit - baik karena
     *                                    model AI eksplisit menolak (outside_scope) maupun karena parsing
     *                                    gagal total sehingga sistem jatuh ke fallback indeks kemiripan
     *                                    visual (recall@1 ~3,5%). Ini bukti berlawanan/tidak meyakinkan,
     *                                    beda dari $visualAvailable false karena provider belum sempat
     *                                    dipanggil sama sekali (ketiadaan bukti murni). Hanya dipakai saat
     *                                    $visualAvailable false (F06) - lihat textOnlyRule().
     * @param  bool  $textualUnreliable  True jika kandidat teks berkeyakinan tertinggi dibangun dari bukti
     *                                    yang terlalu tipis (lihat ConsultationWorkflowService, keluasan
     *                                    minimal gejala) - mis. cuma 1 gejala generik yang kebetulan cocok,
     *                                    bukan beberapa gejala independen yang saling menguatkan. CF akhirnya
     *                                    bisa saja tinggi, tapi tidak cukup meyakinkan untuk menyebut nama
     *                                    penyakit spesifik. Hanya dipakai saat $visualAvailable false (F06).
     */
    public function decide(
        Disease $textualDisease,
        float $textualCf,
        ?Disease $visualDisease,
        float $visualScore,
        bool $visualAvailable,
        array $redFlagResult = [],
        bool $visualUnreliable = false,
        bool $textualUnreliable = false
    ): array {
        $textualCf = $this->clamp($textualCf);
        $visualScore = $this->clamp($visualScore);
        $hasRedFlags = (bool) ($redFlagResult['has_red_flags'] ?? false);

        [$ruleCode, $action] = $this->resolveRule(
            $textualDisease,
            $textualCf,
            $visualDisease,
            $visualAvailable,
            $hasRedFlags,
            $visualUnreliable,
            $textualUnreliable
        );
        $action = $this->enforceDiseaseScope($textualDisease, $action);

        $canRecommendMedicine = in_array($action, [
            'recommend_otc',
            'recommend_otc_observe',
            'recommend_otc_mismatch',
            'recommend_otc_unsupported',
        ], true);

        // Nama penyakit spesifik disembunyikan dari tampilan utama setiap kali
        // (a) visual TIDAK independen setuju dengan kandidat teks (tidak
        // tersedia, tidak bisa dipercaya, atau justru mengarah ke penyakit
        // lain), (b) buktinya sendiri lemah (visual tidak bisa dipercaya ATAU
        // teks dibangun dari terlalu sedikit gejala), DAN (c) hasil akhirnya
        // TIDAK memberi obat. Syarat (c) sengaja membatasi F04 (mismatch, CF
        // tinggi) tetap menampilkan nama seperti sebelumnya - F04 sudah punya
        // mekanisme transparansinya sendiri (catatan mismatch di explanation)
        // dan tetap memberi obat, jadi menyembunyikan namanya sambil tetap
        // menyerahkan obat untuk penyakit yang "tidak disebutkan" itu cuma
        // membingungkan. Berlaku penuh di F05/F06/F07 (semuanya sudah tidak
        // memberi obat) - termasuk saat tanda bahaya memaksa refer, karena
        // regresi produksi 2026-08-30 menunjukkan F07 melewati seluruh
        // pengecekan F04-F06 (resolveRule() mengembalikannya duluan), sehingga
        // nama penyakit (mis. "Karsinoma sel basal") tetap tampil dari CF yang
        // dibangun dari 2 gejala generik, padahal kandidat visual (Jerawat/
        // Impetigo) sama sekali berbeda. disease_id/action tetap tersimpan
        // apa adanya untuk audit - ini murni soal apa yang ditonjolkan ke
        // pengguna.
        $visualAgrees = $visualAvailable && $visualDisease && $visualDisease->is($textualDisease);
        $labelSuppressed = ! $visualAgrees && ! $canRecommendMedicine && ($visualUnreliable || $textualUnreliable);

        return [
            'disease' => $textualDisease,
            'visual_score' => $visualScore,
            'textual_cf' => $textualCf,
            'fusion_score' => $textualCf,
            'fusion_score_percent' => $this->round($textualCf * 100),
            'fusion_rule_code' => $ruleCode,
            'action' => $action,
            'label_suppressed' => $labelSuppressed,
            'can_recommend_medicine' => $canRecommendMedicine,
            'explanation' => $this->explanation(
                $textualDisease,
                $visualDisease,
                $ruleCode,
                $action,
                $textualCf,
                $visualAvailable,
                $hasRedFlags,
                $labelSuppressed
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
        bool $hasRedFlags,
        bool $visualUnreliable = false,
        bool $textualUnreliable = false
    ): array {
        // F07: tanda bahaya menggantikan seluruh aturan lainnya.
        if ($hasRedFlags) {
            return ['F07', 'refer'];
        }

        // F06: citra tidak dapat dianalisis atau kelas visual di luar ruang lingkup.
        if (! $visualAvailable || ! $visualDisease) {
            return $this->textOnlyRule($textualCf, $visualUnreliable || $textualUnreliable);
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
    private function textOnlyRule(float $textualCf, bool $visualUnreliable = false): array
    {
        // $visualUnreliable true berarti visual sempat dianalisis tapi hasilnya
        // tidak bisa dipercaya - baik model AI eksplisit menolak (outside_scope)
        // atau parsing gagal total (fallback indeks kemiripan visual, bukan
        // provider yang tidak sempat dipanggil). CF gejala setinggi apa pun
        // tidak boleh menimpa bukti berlawanan/tidak meyakinkan itu, karena
        // kombinasi gejala umum (gatal + bersisik) bisa kebetulan cocok dengan
        // salah satu dari 16 penyakit padahal foto menunjukkan kondisi yang
        // sama sekali berbeda dan tidak pernah benar-benar dinilai sistem.
        if ($visualUnreliable) {
            return ['F06', 'insufficient_confidence'];
        }

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
        bool $hasRedFlags,
        bool $labelSuppressed = false
    ): string {
        $diseaseName = $textualDisease->name_indonesian ?: $textualDisease->name;
        $cfPercent = sprintf('%.1f%%', $textualCf * 100);

        // Diperiksa PALING AWAL, sebelum cabang tanda bahaya maupun cabang
        // golongan penyakit di bawah - lihat decide() untuk kondisi lengkapnya.
        // Nama penyakit sengaja TIDAK disebut di sini: kalau sudah disembunyikan
        // dari "Kemungkinan utama" karena buktinya lemah, menyebutnya lewat
        // "Alasan sistem" cuma membocorkannya lewat pintu belakang. Ini berlaku
        // SAMA untuk F04/F05/F06 maupun F07 (tanda bahaya) - tanda bahaya
        // menentukan urgensi rujukannya, bukan seberapa yakin nama penyakitnya.
        if ($labelSuppressed) {
            if ($hasRedFlags) {
                return sprintf(
                    'Aturan F07: terdeteksi tanda bahaya sehingga seluruh rekomendasi obat ditahan dan pengguna diarahkan ke tenaga kesehatan. Kandidat penyakit teratas belum cukup meyakinkan untuk dipastikan namanya (CF %s), sehingga keputusan ini murni berdasarkan tanda bahaya yang terdeteksi, bukan satu diagnosis tertentu.',
                    $cfPercent
                );
            }

            if (! $visualAvailable || ! $visualDisease) {
                return sprintf(
                    'Aturan %s: analisis visual menilai foto ini kemungkinan bukan salah satu dari 16 penyakit yang dikenali sistem, atau jawaban gejala cuma dibangun dari sedikit gejala yang cocok (CF %s) - belum cukup spesifik untuk memastikan satu nama penyakit. Disarankan konsultasi langsung untuk kepastian, bukan berdasarkan satu nama penyakit tertentu.',
                    $ruleCode,
                    $cfPercent
                );
            }

            $visualName = $visualDisease->name_indonesian ?: $visualDisease->name;

            return sprintf(
                'Aturan %s: hasil visual justru mengarah ke %s, berbeda dari kandidat gejala teratas yang buktinya sendiri belum cukup kuat untuk dipastikan (CF %s hanya dari sedikit gejala yang cocok) - belum cukup meyakinkan untuk menyebut satu nama penyakit pasti. Disarankan konsultasi langsung untuk kepastian.',
                $ruleCode,
                $visualName,
                $cfPercent
            );
        }

        if ($hasRedFlags) {
            return sprintf(
                'Aturan F07: terdeteksi tanda bahaya sehingga seluruh rekomendasi obat ditahan dan pengguna diarahkan ke tenaga kesehatan (kandidat gejala teratas: %s, CF %s).',
                $diseaseName,
                $cfPercent
            );
        }

        // Catatan ketidaksesuaian antarmodalitas harus tetap terbaca meskipun aksi
        // ditentukan oleh golongan penyakit. Sebelumnya kedua cabang scope di bawah
        // keluar lebih dulu tanpa menyebut kandidat visual sama sekali, sehingga
        // temuan visual yang berbeda hilang dari penjelasan yang dilihat pengguna.
        $catatanVisual = ($visualAvailable && $visualDisease && ! $visualDisease->is($textualDisease))
            ? sprintf(
                ' Catatan: analisis foto justru mengarah ke %s, sehingga hasil ini belum sesuai antarmodalitas dan sebaiknya dikonfirmasi tenaga kesehatan.',
                $visualDisease->name_indonesian ?: $visualDisease->name
            )
            : '';

        if ($textualDisease->default_action === 'refer' && $action === 'refer') {
            return sprintf(
                'Scope rujukan: kandidat %s berada di luar penanganan mandiri sehingga hasil skrining tidak disertai rekomendasi obat dan perlu diperiksa tenaga kesehatan (CF %s).%s',
                $diseaseName,
                $cfPercent,
                $catatanVisual
            );
        }

        if ($textualDisease->default_action === 'educate_only' && $action === 'educate_only') {
            return sprintf(
                'Scope edukasi: kandidat %s ditampilkan sebagai informasi awal berdasarkan data dan gejala, bukan diagnosis terkonfirmasi; sistem tidak memberikan rekomendasi obat (CF %s).%s',
                $diseaseName,
                $cfPercent,
                $catatanVisual
            );
        }

        if (! $visualAvailable || ! $visualDisease) {
            // Cabang bukti tak bisa dipercaya (visual maupun teks) sudah ditangani
            // di awal fungsi ini sebelum cabang golongan penyakit - titik ini
            // hanya tercapai kalau visual/teks masih dianggap layak dipakai.
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

    /**
     * F11 (perluasan di luar Tabel 3.13): mencari kandidat yang didukung KUAT
     * oleh gejala DAN visual sekaligus, dengan menoleh ke seluruh daftar
     * kandidat kedua modalitas - bukan hanya juara #1 masing-masing sisi
     * seperti F01-F05.
     *
     * Tabel 3.13 hanya membandingkan identitas Pv vs Pt. Ini bisa membuat
     * penyakit yang sebenarnya didukung kuat oleh foto DAN gejala kalah start
     * dari penyakit lain yang CF gejalanya kebetulan lebih tinggi tapi nol
     * dukungan visual. Kasus produksi nyata: foto Tinea Corporis (kurap) skor
     * 0,92 dengan CF gejala 0,76 (di atas ambang tinggi) kalah dari
     * Kandidiasis yang CF gejalanya 0,9977 tapi tidak muncul sama sekali di
     * kandidat visual - rumus CF yang menumpuk banyak gejala sedang membuat
     * penyakit dengan gejala tumpang tindih terbanyak menang, bukan yang
     * paling didukung dua sumber bukti independen.
     *
     * @param  array<int, array<string, mixed>>  $textualRankings  CertaintyFactorService::rankDiseases(), terurut.
     * @param  array<int, array<string, mixed>>  $visualCandidates  Kandidat visual (disease + visual_score), terurut.
     * @return array{disease: Disease, textual_cf: float, visual_score: float}|null
     */
    public function findDualConfirmedCandidate(array $textualRankings, array $visualCandidates): ?array
    {
        $visualScores = [];

        foreach ($visualCandidates as $candidate) {
            $disease = $candidate['disease'] ?? null;

            if ($disease instanceof Disease) {
                $visualScores[$disease->id] = max(
                    $visualScores[$disease->id] ?? 0.0,
                    $this->clamp((float) ($candidate['visual_score'] ?? 0.0))
                );
            }
        }

        if ($visualScores === []) {
            return null;
        }

        $best = null;

        foreach ($textualRankings as $ranking) {
            $disease = $ranking['disease'] ?? null;
            $textualCf = $this->clamp((float) ($ranking['textual_cf'] ?? 0.0));

            if (! $disease instanceof Disease || $textualCf < self::HIGH_CF) {
                continue;
            }

            $visualScore = $visualScores[$disease->id] ?? 0.0;

            if ($visualScore < self::HIGH_CF) {
                continue;
            }

            $combined = $textualCf + $visualScore;

            if ($best === null || $combined > $best['combined']) {
                $best = [
                    'disease' => $disease,
                    'textual_cf' => $textualCf,
                    'visual_score' => $visualScore,
                    'combined' => $combined,
                ];
            }
        }

        return $best === null ? null : [
            'disease' => $best['disease'],
            'textual_cf' => $best['textual_cf'],
            'visual_score' => $best['visual_score'],
        ];
    }

    public function decideDualConfirmed(Disease $disease, float $textualCf, float $visualScore): array
    {
        $textualCf = $this->clamp($textualCf);
        $visualScore = $this->clamp($visualScore);
        $action = $this->enforceDiseaseScope($disease, 'recommend_otc');

        return [
            'disease' => $disease,
            'visual_score' => $visualScore,
            'textual_cf' => $textualCf,
            'fusion_score' => $textualCf,
            'fusion_score_percent' => $this->round($textualCf * 100),
            'fusion_rule_code' => 'F11',
            'action' => $action,
            'can_recommend_medicine' => in_array($action, [
                'recommend_otc',
                'recommend_otc_observe',
                'recommend_otc_mismatch',
                'recommend_otc_unsupported',
            ], true),
            'explanation' => sprintf(
                'Aturan F11 (perluasan di luar Tabel 3.13): %s didukung kuat oleh gejala (CF %s) DAN analisis visual (skor %s) sekaligus, dikonfirmasi silang dari seluruh daftar kandidat kedua modalitas - bukan hanya kandidat teratas masing-masing sisi.',
                $disease->name_indonesian ?: $disease->name,
                sprintf('%.1f%%', $textualCf * 100),
                sprintf('%.1f%%', $visualScore * 100)
            ),
        ];
    }

    /**
     * F10: the user's free-text context names a non-self-care disease and the
     * answered symptom profile supports that context, while visual evidence is
     * unavailable or too weak. This prevents broad symptoms such as "scaly" and
     * "itchy" from being forced into a generic OTC class.
     */
    public function decideContextSymptomAligned(Disease $disease, float $supportScore): array
    {
        $supportScore = $this->clamp($supportScore);
        $action = $disease->default_action === 'refer' ? 'refer' : 'educate_only';
        $diseaseName = $disease->name_indonesian ?: $disease->name;

        return [
            'disease' => $disease,
            'visual_score' => 0.0,
            'textual_cf' => $supportScore,
            'fusion_score' => $supportScore,
            'fusion_score_percent' => $this->round($supportScore * 100),
            'fusion_rule_code' => 'F10',
            'action' => $action,
            'can_recommend_medicine' => false,
            'explanation' => sprintf(
                'Aturan F10: pengguna menyebut %s sebagai konteks dan jawaban gejala cukup selaras (dukungan %.1f%%), sementara kandidat visual belum cukup kuat. Hasil ditampilkan sebagai edukasi awal, bukan diagnosis, dan tidak disertai rekomendasi obat.%s',
                $diseaseName,
                $supportScore * 100,
                $action === 'refer'
                    ? ' Pengguna diarahkan untuk diperiksa tenaga kesehatan.'
                    : ' Konsultasi tenaga kesehatan tetap diperlukan untuk kepastian.'
            ),
        ];
    }

    /**
     * Menyelaraskan aksi dengan golongan penyakit, TANPA pernah melemahkan rujukan.
     *
     * Sebelumnya penyakit bergolongan educate_only menimpa aksi 'refer' yang
     * dihasilkan F07 (tanda bahaya) dan F05 (safety-net) menjadi 'educate_only',
     * sehingga peringatan rujukan hilang diam-diam. Ini terjadi pada data
     * produksi: sesi dengan fusion_rule_code F07 tercatat beraksi educate_only.
     */
    private function enforceDiseaseScope(Disease $disease, string $action): string
    {
        if ($action === 'refer') {
            return 'refer';
        }

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
