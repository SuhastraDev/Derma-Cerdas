<?php

namespace Tests\Unit;

use App\Models\DatasetClassMapping;
use App\Models\Disease;
use App\Models\Setting;
use App\Services\FusionDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FusionDecisionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_recommends_otc_when_score_passes_threshold_and_no_red_flags(): void
    {
        $disease = $this->createDisease('TINEA_PEDIS', 'recommend_otc');

        DatasetClassMapping::query()->create([
            'dataset_class_id' => 1,
            'dataset_class_name' => 'Tinea_Pedis',
            'nama_indonesia' => 'Kutu air',
            'scope_category' => 'swamedikasi',
            'boleh_rekomendasi_obat' => true,
            'default_action' => 'recommend_otc',
            'disease_id' => $disease->id,
        ]);

        $result = (new FusionDecisionService())->decide(
            disease: $disease,
            visualScore: 0.75,
            textualCf: 0.70,
            redFlagResult: ['has_red_flags' => false],
            visualWeight: 0.4,
            textWeight: 0.6,
            threshold: 0.6
        );

        $this->assertSame('recommend_otc', $result['action']);
        $this->assertTrue($result['can_recommend_medicine']);
        $this->assertEqualsWithDelta(0.72, $result['fusion_score'], 0.0001);
        $this->assertStringContainsString('Skor melewati threshold', $result['explanation']);
    }

    public function test_red_flags_force_refer_even_when_score_is_high(): void
    {
        $disease = $this->createDisease('URTICARIA', 'recommend_otc');

        $result = (new FusionDecisionService())->decide(
            disease: $disease,
            visualScore: 0.95,
            textualCf: 0.95,
            redFlagResult: ['has_red_flags' => true],
            threshold: 0.6
        );

        $this->assertSame('refer', $result['action']);
        $this->assertFalse($result['can_recommend_medicine']);
        $this->assertStringContainsString('red flags terdeteksi', $result['explanation']);
    }

    public function test_score_under_threshold_is_insufficient_confidence(): void
    {
        $disease = $this->createDisease('ECZEMA', 'recommend_otc');

        $result = (new FusionDecisionService())->decide(
            disease: $disease,
            visualScore: 0.40,
            textualCf: 0.50,
            redFlagResult: ['has_red_flags' => false],
            visualWeight: 0.4,
            textWeight: 0.6,
            threshold: 0.6
        );

        $this->assertSame('insufficient_confidence', $result['action']);
        $this->assertFalse($result['can_recommend_medicine']);
        $this->assertEqualsWithDelta(0.46, $result['fusion_score'], 0.0001);
        $this->assertStringContainsString('belum mencapai threshold 60.0%', $result['explanation']);
    }

    public function test_dataset_rujuk_scope_forces_refer(): void
    {
        $disease = $this->createDisease('SERIOUS_CONDITION', 'recommend_otc');

        DatasetClassMapping::query()->create([
            'dataset_class_id' => 198,
            'dataset_class_name' => 'Malignant_Melanoma',
            'scope_category' => 'rujuk',
            'boleh_rekomendasi_obat' => false,
            'default_action' => 'refer',
            'disease_id' => $disease->id,
        ]);

        $result = (new FusionDecisionService())->decide(
            disease: $disease,
            visualScore: 0.90,
            textualCf: 0.90,
            redFlagResult: ['has_red_flags' => false],
            threshold: 0.6
        );

        $this->assertSame('refer', $result['action']);
        $this->assertStringContainsString('kategori rujuk', $result['explanation']);
    }

    public function test_it_uses_decision_settings_when_weights_are_not_passed(): void
    {
        Setting::query()->create([
            'key' => 'visual_weight',
            'value' => ['value' => 0.30],
            'group' => 'decision',
        ]);

        Setting::query()->create([
            'key' => 'text_weight',
            'value' => ['value' => 0.70],
            'group' => 'decision',
        ]);

        Setting::query()->create([
            'key' => 'decision_threshold',
            'value' => ['value' => 0.65],
            'group' => 'decision',
        ]);

        $result = (new FusionDecisionService())->decide(
            disease: $this->createDisease('ALLERGIC_CONTACT_DERMATITIS', 'recommend_otc'),
            visualScore: 0.60,
            textualCf: 0.80,
            redFlagResult: ['has_red_flags' => false]
        );

        $this->assertSame(['visual' => 0.3, 'text' => 0.7], $result['weights']);
        $this->assertSame(0.65, $result['threshold']);
        $this->assertEqualsWithDelta(0.74, $result['fusion_score'], 0.0001);
    }

    private function createDisease(string $code, string $defaultAction): Disease
    {
        return Disease::query()->create([
            'code' => $code,
            'name' => str_replace('_', ' ', $code),
            'slug' => strtolower(str_replace('_', '-', $code)),
            'name_indonesian' => str_replace('_', ' ', strtolower($code)),
            'severity_scope' => 'mild',
            'default_action' => $defaultAction,
            'is_active' => true,
        ]);
    }
}
