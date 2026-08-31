<?php

namespace Tests\Unit;

use App\Models\Disease;
use App\Services\FusionDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FusionDecisionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_f01_matching_candidates_with_high_cf_recommends_otc(): void
    {
        $disease = $this->createDisease('TINEA_PEDIS');

        $result = (new FusionDecisionService)->decide(
            textualDisease: $disease,
            textualCf: 0.70,
            visualDisease: $disease,
            visualScore: 0.75,
            visualAvailable: true,
            redFlagResult: ['has_red_flags' => false],
        );

        $this->assertSame('F01', $result['fusion_rule_code']);
        $this->assertSame('recommend_otc', $result['action']);
        $this->assertTrue($result['can_recommend_medicine']);
    }

    public function test_f02_matching_candidates_with_medium_cf_recommends_with_observation(): void
    {
        $disease = $this->createDisease('TINEA_CRURIS');

        $result = (new FusionDecisionService)->decide(
            textualDisease: $disease,
            textualCf: 0.50,
            visualDisease: $disease,
            visualScore: 0.60,
            visualAvailable: true,
            redFlagResult: ['has_red_flags' => false],
        );

        $this->assertSame('F02', $result['fusion_rule_code']);
        $this->assertSame('recommend_otc_observe', $result['action']);
        $this->assertTrue($result['can_recommend_medicine']);
    }

    public function test_f03_matching_candidates_with_low_cf_is_insufficient_confidence(): void
    {
        $disease = $this->createDisease('TINEA_VERSICOLOR');

        $result = (new FusionDecisionService)->decide(
            textualDisease: $disease,
            textualCf: 0.30,
            visualDisease: $disease,
            visualScore: 0.40,
            visualAvailable: true,
            redFlagResult: ['has_red_flags' => false],
        );

        $this->assertSame('F03', $result['fusion_rule_code']);
        $this->assertSame('insufficient_confidence', $result['action']);
        $this->assertFalse($result['can_recommend_medicine']);
    }

    public function test_f04_mismatched_candidates_with_high_cf_uses_textual_result(): void
    {
        $textualDisease = $this->createDisease('TINEA_CORPORIS');
        $visualDisease = $this->createDisease('ALLERGIC_CONTACT_DERMATITIS');

        $result = (new FusionDecisionService)->decide(
            textualDisease: $textualDisease,
            textualCf: 0.80,
            visualDisease: $visualDisease,
            visualScore: 0.70,
            visualAvailable: true,
            redFlagResult: ['has_red_flags' => false],
        );

        $this->assertSame('F04', $result['fusion_rule_code']);
        $this->assertSame('recommend_otc_mismatch', $result['action']);
        $this->assertTrue($result['can_recommend_medicine']);
        $this->assertSame($textualDisease->id, $result['disease']->id);
    }

    public function test_f05_mismatched_candidates_with_low_cf_triggers_safety_net_refer(): void
    {
        $textualDisease = $this->createDisease('TINEA_CORPORIS');
        $visualDisease = $this->createDisease('ALLERGIC_CONTACT_DERMATITIS');

        $result = (new FusionDecisionService)->decide(
            textualDisease: $textualDisease,
            textualCf: 0.30,
            visualDisease: $visualDisease,
            visualScore: 0.70,
            visualAvailable: true,
            redFlagResult: ['has_red_flags' => false],
        );

        $this->assertSame('F05', $result['fusion_rule_code']);
        $this->assertSame('refer', $result['action']);
        $this->assertFalse($result['can_recommend_medicine']);
    }

    public function test_f06_visual_unavailable_falls_back_to_text_only(): void
    {
        $disease = $this->createDisease('TINEA_PEDIS');

        $result = (new FusionDecisionService)->decide(
            textualDisease: $disease,
            textualCf: 0.70,
            visualDisease: null,
            visualScore: 0.0,
            visualAvailable: false,
            redFlagResult: ['has_red_flags' => false],
        );

        $this->assertSame('F06', $result['fusion_rule_code']);
        $this->assertSame('recommend_otc_unsupported', $result['action']);
        $this->assertTrue($result['can_recommend_medicine']);
    }

    /**
     * 2026-09-01: gate visualUnreliable/textualUnreliable dinonaktifkan atas
     * permintaan eksplisit (lihat catatan di FusionDecisionService::decide()) -
     * selama provider visual sering degraded, gate ini menahan nama penyakit &
     * rekomendasi obat untuk kasus CF teks tinggi yang sebenarnya benar, bukan
     * cuma kasus bukti tipis yang ditargetkannya semula. F06 sekarang murni
     * berbasis CF teks lagi, seperti sebelum gate ini ditambahkan - risiko
     * regresi lama (foto di luar 16 penyakit, gejala kebetulan cocok penyakit
     * lain) diterima secara sadar.
     */
    public function test_f06_visual_outside_scope_no_longer_blocks_medicine_recommendation(): void
    {
        $disease = $this->createDisease('TINEA_CORPORIS');

        $result = (new FusionDecisionService)->decide(
            textualDisease: $disease,
            textualCf: 0.999,
            visualDisease: null,
            visualScore: 0.0,
            visualAvailable: false,
            redFlagResult: ['has_red_flags' => false],
            visualUnreliable: true,
        );

        $this->assertSame('F06', $result['fusion_rule_code']);
        $this->assertSame('recommend_otc_unsupported', $result['action']);
        $this->assertTrue($result['can_recommend_medicine']);
        $this->assertFalse($result['label_suppressed']);
    }

    /** Tanpa visualUnreliable, perilaku F06 lama (berbasis CF semata) tidak berubah. */
    public function test_f06_visual_unavailable_without_outside_scope_keeps_cf_based_behavior(): void
    {
        $disease = $this->createDisease('TINEA_CORPORIS');

        $result = (new FusionDecisionService)->decide(
            textualDisease: $disease,
            textualCf: 0.999,
            visualDisease: null,
            visualScore: 0.0,
            visualAvailable: false,
            redFlagResult: ['has_red_flags' => false],
            visualUnreliable: false,
        );

        $this->assertSame('recommend_otc_unsupported', $result['action']);
        $this->assertTrue($result['can_recommend_medicine']);
        $this->assertFalse($result['label_suppressed']);
    }

    /**
     * 2026-09-01: label_suppressed dinonaktifkan (lihat catatan di
     * FusionDecisionService::decide()) - textualUnreliable tidak lagi
     * menyembunyikan nama penyakit, termasuk untuk golongan refer.
     */
    public function test_f06_textual_unreliable_no_longer_suppresses_label(): void
    {
        $bcc = $this->createDisease('BASAL_CELL_CARCINOMA', 'refer');

        $result = (new FusionDecisionService)->decide(
            textualDisease: $bcc,
            textualCf: 0.80,
            visualDisease: null,
            visualScore: 0.0,
            visualAvailable: false,
            redFlagResult: ['has_red_flags' => false],
            textualUnreliable: true,
        );

        $this->assertSame('refer', $result['action']);
        $this->assertFalse($result['can_recommend_medicine']);
        $this->assertFalse($result['label_suppressed']);
        $this->assertStringContainsString('basal cell carcinoma', mb_strtolower($result['explanation']));
    }

    /** Tanpa textualUnreliable, penyakit refer tetap tampil apa adanya (perilaku lama). */
    public function test_f06_textual_reliable_refer_disease_keeps_showing_name(): void
    {
        $bcc = $this->createDisease('BASAL_CELL_CARCINOMA', 'refer');

        $result = (new FusionDecisionService)->decide(
            textualDisease: $bcc,
            textualCf: 0.80,
            visualDisease: null,
            visualScore: 0.0,
            visualAvailable: false,
            redFlagResult: ['has_red_flags' => false],
            textualUnreliable: false,
        );

        $this->assertSame('refer', $result['action']);
        $this->assertFalse($result['label_suppressed']);
        $this->assertStringContainsString('basal cell carcinoma', $result['explanation']);
    }

    /**
     * F13: pengguna sendiri menjawab "Tidak yakin/tidak ada yang cocok" untuk
     * mayoritas gejala deskriptif - sinyal ini menang atas CF setinggi apa
     * pun dan atas visual yang tersedia sekalipun, karena meragukan validitas
     * Pt itu sendiri (lihat ConsultationWorkflowService::
     * symptomsIndicateOutOfScope()).
     */
    public function test_f13_symptoms_indicate_out_of_scope_suppresses_label_regardless_of_cf_and_visual(): void
    {
        $disease = $this->createDisease('TINEA_CORPORIS');

        $result = (new FusionDecisionService)->decide(
            textualDisease: $disease,
            textualCf: 0.95,
            visualDisease: $disease,
            visualScore: 0.90,
            visualAvailable: true,
            redFlagResult: ['has_red_flags' => false],
            symptomsIndicateOutOfScope: true,
        );

        $this->assertSame('F13', $result['fusion_rule_code']);
        $this->assertSame('refer', $result['action']);
        $this->assertFalse($result['can_recommend_medicine']);
        $this->assertTrue($result['label_suppressed']);
        $this->assertStringNotContainsString('tinea corporis', mb_strtolower($result['explanation']));
        $this->assertStringContainsString('tidak cocok', mb_strtolower($result['explanation']));
    }

    /**
     * F07 (tanda bahaya) tetap menang untuk rule_code/action - rujukan
     * darurat tidak boleh disamarkan jadi F13. Tapi label tetap disembunyikan
     * kalau symptomsIndicateOutOfScope juga true, karena keduanya independen.
     */
    public function test_f07_red_flags_win_rule_code_but_label_still_suppressed_with_f13_signal(): void
    {
        $disease = $this->createDisease('TINEA_CORPORIS');

        $result = (new FusionDecisionService)->decide(
            textualDisease: $disease,
            textualCf: 0.95,
            visualDisease: null,
            visualScore: 0.0,
            visualAvailable: false,
            redFlagResult: ['has_red_flags' => true],
            symptomsIndicateOutOfScope: true,
        );

        $this->assertSame('F07', $result['fusion_rule_code']);
        $this->assertSame('refer', $result['action']);
        $this->assertTrue($result['label_suppressed']);
        $this->assertStringNotContainsString('tinea corporis', mb_strtolower($result['explanation']));
    }

    public function test_f07_red_flags_force_refer_even_when_matching_and_cf_is_high(): void
    {
        $disease = $this->createDisease('TINEA_PEDIS');

        $result = (new FusionDecisionService)->decide(
            textualDisease: $disease,
            textualCf: 0.95,
            visualDisease: $disease,
            visualScore: 0.95,
            visualAvailable: true,
            redFlagResult: ['has_red_flags' => true],
        );

        $this->assertSame('F07', $result['fusion_rule_code']);
        $this->assertSame('refer', $result['action']);
        $this->assertFalse($result['can_recommend_medicine']);
    }

    public function test_f08_visual_only_finding_never_recommends_medicine(): void
    {
        $refer = $this->createDisease('SKIN_CANCER_REFER', 'refer');
        $educate = $this->createDisease('BENIGN_LESION', 'educate_only');

        $referResult = (new FusionDecisionService)->decideVisualOnly($refer, 0.8);
        $educateResult = (new FusionDecisionService)->decideVisualOnly($educate, 0.7);

        $this->assertSame('F08', $referResult['fusion_rule_code']);
        $this->assertSame('refer', $referResult['action']);
        $this->assertFalse($referResult['can_recommend_medicine']);

        $this->assertSame('F08', $educateResult['fusion_rule_code']);
        $this->assertSame('educate_only', $educateResult['action']);
        $this->assertFalse($educateResult['can_recommend_medicine']);
    }

    public function test_non_self_care_scope_overrides_otc_fusion_action(): void
    {
        $psoriasis = $this->createDisease('PSORIASIS_PAPULOSQUAMOUS', 'educate_only');

        $result = (new FusionDecisionService)->decide(
            textualDisease: $psoriasis,
            textualCf: 0.90,
            visualDisease: $psoriasis,
            visualScore: 0.90,
            visualAvailable: true,
            redFlagResult: ['has_red_flags' => false],
        );

        $this->assertSame('F01', $result['fusion_rule_code']);
        $this->assertSame('educate_only', $result['action']);
        $this->assertFalse($result['can_recommend_medicine']);
        $this->assertStringContainsString('scope', strtolower($result['explanation']));
    }

    public function test_context_aligned_visual_hint_is_education_only(): void
    {
        $psoriasis = $this->createDisease('PSORIASIS_PAPULOSQUAMOUS', 'educate_only');

        $result = (new FusionDecisionService)->decideContextAlignedVisual($psoriasis, 0.78);

        $this->assertSame('F09', $result['fusion_rule_code']);
        $this->assertSame('educate_only', $result['action']);
        $this->assertFalse($result['can_recommend_medicine']);
        $this->assertStringContainsString('konteks', strtolower($result['explanation']));
    }

    public function test_context_symptom_aligned_hint_is_education_only(): void
    {
        $psoriasis = $this->createDisease('PSORIASIS_PAPULOSQUAMOUS', 'educate_only');

        $result = (new FusionDecisionService)->decideContextSymptomAligned($psoriasis, 0.62);

        $this->assertSame('F10', $result['fusion_rule_code']);
        $this->assertSame('educate_only', $result['action']);
        $this->assertFalse($result['can_recommend_medicine']);
        $this->assertStringContainsString('gejala cukup selaras', strtolower($result['explanation']));
    }

    /**
     * Regresi dari data produksi: sesi DC-20260817-144600-BZ0GW tercatat
     * fusion_rule_code F07 (tanda bahaya) tetapi action-nya educate_only,
     * karena enforceDiseaseScope() menimpa 'refer' mengikuti default_action
     * penyakit. Rujukan tanda bahaya tidak boleh bisa diturunkan.
     */
    public function test_f07_red_flag_referral_is_not_downgraded_by_educate_only_disease(): void
    {
        $disease = $this->createDisease('DRY_SKIN_ECZEMA', 'educate_only');

        $result = (new FusionDecisionService)->decide(
            textualDisease: $disease,
            textualCf: 0.85,
            visualDisease: null,
            visualScore: 0.0,
            visualAvailable: false,
            redFlagResult: ['has_red_flags' => true],
        );

        $this->assertSame('F07', $result['fusion_rule_code']);
        $this->assertSame('refer', $result['action']);
        $this->assertFalse($result['can_recommend_medicine']);
    }

    /** Safety-net F05 juga tidak boleh diturunkan menjadi educate_only. */
    public function test_f05_safety_net_referral_is_not_downgraded(): void
    {
        $textual = $this->createDisease('DRY_SKIN_ECZEMA', 'educate_only');
        $visual = $this->createDisease('TINEA_CORPORIS');

        $result = (new FusionDecisionService)->decide(
            textualDisease: $textual,
            textualCf: 0.45,
            visualDisease: $visual,
            visualScore: 0.70,
            visualAvailable: true,
            redFlagResult: ['has_red_flags' => false],
        );

        $this->assertSame('F05', $result['fusion_rule_code']);
        $this->assertSame('refer', $result['action']);
    }

    /**
     * F11: reproduces the exact production mismatch (DC-20260819-020543-ZCPWW).
     * Tinea Corporis (kurap) scored 0.92 on the photo and 0.76 on symptoms -
     * both comfortably above the "high" CF threshold - but Candidiasis, which
     * scored 0.9977 on symptoms purely from evidence stacking, never appeared
     * in the visual candidates at all. The disease confirmed by BOTH
     * modalities should win over the one confirmed by only one.
     */
    public function test_f11_dual_confirmed_candidate_wins_over_single_modality_high_scorer(): void
    {
        $tineaCorporis = $this->createDisease('TINEA_CORPORIS');
        $candidiasis = $this->createDisease('CANDIDIASIS');

        // matched_symptoms mencerminkan bukti sungguhan sesi produksi itu (7
        // gejala) supaya lolos gerbang keyakinan (textualCandidateIsReliable) -
        // tanpa ini, fixture ringkas tanpa matched_symptoms akan ditolak
        // gerbang keluasan/kelompok deskriptif yang sekarang juga berlaku
        // untuk F11, bukan cuma F06.
        $matchedSymptoms = [
            ['symptom_code' => 'P1_BADAN'],
            ['symptom_code' => 'P2_CINCIN'],
            ['symptom_code' => 'P3_HALUS'],
            ['symptom_code' => 'P4_GATAL'],
            ['symptom_code' => 'P5_MINGGU'],
            ['symptom_code' => 'P7_KERINGAT'],
            ['symptom_code' => 'P8_TENGAHBERSIH'],
        ];
        $textualRankings = [
            ['disease' => $candidiasis, 'textual_cf' => 0.9977],
            ['disease' => $tineaCorporis, 'textual_cf' => 0.76, 'matched_symptoms' => $matchedSymptoms],
        ];
        $visualCandidates = [
            ['disease' => $tineaCorporis, 'visual_score' => 0.92],
        ];

        $service = new FusionDecisionService;
        $dual = $service->findDualConfirmedCandidate($textualRankings, $visualCandidates);

        $this->assertNotNull($dual);
        $this->assertTrue($tineaCorporis->is($dual['disease']));

        $result = $service->decideDualConfirmed($dual['disease'], $dual['textual_cf'], $dual['visual_score']);

        $this->assertSame('F11', $result['fusion_rule_code']);
        $this->assertSame('recommend_otc', $result['action']);
        $this->assertTrue($result['can_recommend_medicine']);
        $this->assertTrue($tineaCorporis->is($result['disease']));
    }

    /** No candidate clears the high bar on both sides at once -> no dual match, falls back to F01-F07. */
    public function test_f11_returns_null_when_no_candidate_is_confirmed_by_both_modalities(): void
    {
        $strongTextualOnly = $this->createDisease('CANDIDIASIS');
        $strongVisualOnly = $this->createDisease('TINEA_CORPORIS');

        $textualRankings = [
            ['disease' => $strongTextualOnly, 'textual_cf' => 0.95],
        ];
        $visualCandidates = [
            ['disease' => $strongVisualOnly, 'visual_score' => 0.90],
        ];

        $dual = (new FusionDecisionService)->findDualConfirmedCandidate($textualRankings, $visualCandidates);

        $this->assertNull($dual);
    }

    /**
     * F11 tetap wajib substansi bukti teks (keluasan + kelompok deskriptif) -
     * bukan jalan pintas untuk kandidat yang cuma didukung 1 gejala generik
     * hanya karena visual kebetulan yakin ke penyakit yang sama. Ini tepat
     * skenario yang membuat bisul disebut "Karsinoma sel basal" andai fotonya
     * kebetulan mirip BCC juga: CF teks tinggi dari 1 gejala + visual tinggi
     * TIDAK cukup lagi untuk F11.
     */
    public function test_f11_rejects_candidate_with_thin_textual_evidence_despite_high_visual_score(): void
    {
        $disease = $this->createDisease('BASAL_CELL_CARCINOMA');

        $textualRankings = [
            ['disease' => $disease, 'textual_cf' => 0.80, 'matched_symptoms' => [
                ['symptom_code' => 'P2_BENJOLAN'],
            ]],
        ];
        $visualCandidates = [
            ['disease' => $disease, 'visual_score' => 0.85],
        ];

        $dual = (new FusionDecisionService)->findDualConfirmedCandidate($textualRankings, $visualCandidates);

        $this->assertNull($dual);
    }

    /** A dual-confirmed refer/educate_only disease must still never unlock OTC medicine. */
    public function test_f11_dual_confirmed_candidate_respects_disease_scope(): void
    {
        $psoriasis = $this->createDisease('PSORIASIS', 'educate_only');

        $result = (new FusionDecisionService)->decideDualConfirmed($psoriasis, 0.85, 0.90);

        $this->assertSame('F11', $result['fusion_rule_code']);
        $this->assertSame('educate_only', $result['action']);
        $this->assertFalse($result['can_recommend_medicine']);
    }

    private function createDisease(string $code, string $defaultAction = 'recommend_otc'): Disease
    {
        return Disease::query()->firstOrCreate(
            ['code' => $code],
            [
                'name' => str_replace('_', ' ', $code),
                'slug' => strtolower(str_replace('_', '-', $code)),
                'name_indonesian' => str_replace('_', ' ', strtolower($code)),
                'severity_scope' => 'mild',
                'default_action' => $defaultAction,
                'is_active' => true,
            ],
        );
    }
}
