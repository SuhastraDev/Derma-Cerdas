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
