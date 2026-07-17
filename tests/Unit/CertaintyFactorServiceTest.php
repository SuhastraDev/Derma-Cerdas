<?php

namespace Tests\Unit;

use App\Models\Disease;
use App\Models\DiseaseSymptomRule;
use App\Models\Symptom;
use App\Services\CertaintyFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CertaintyFactorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_combines_multiple_positive_certainty_factors(): void
    {
        $service = new CertaintyFactorService();

        $result = $service->combine([0.48, 0.42]);

        $this->assertEqualsWithDelta(0.6984, $result, 0.0001);
    }

    public function test_it_evaluates_textual_cf_for_a_disease_from_user_symptoms(): void
    {
        $disease = Disease::query()->create([
            'code' => 'TINEA_CORPORIS',
            'name' => 'Tinea Corporis',
            'slug' => 'tinea-corporis',
            'name_indonesian' => 'Kurap badan',
            'severity_scope' => 'mild',
            'default_action' => 'recommend_otc',
            'is_active' => true,
        ]);

        $ring = Symptom::query()->create([
            'code' => 'RING_SHAPED_EDGE',
            'name' => 'Lesi melingkar',
            'question' => 'Apakah ruam tampak melingkar?',
            'input_type' => 'scale',
            'is_active' => true,
        ]);

        $itch = Symptom::query()->create([
            'code' => 'ITCHING',
            'name' => 'Gatal',
            'question' => 'Apakah terasa gatal?',
            'input_type' => 'scale',
            'is_active' => true,
        ]);

        DiseaseSymptomRule::query()->create([
            'disease_id' => $disease->id,
            'symptom_id' => $ring->id,
            'mb' => 0.80,
            'md' => 0.05,
            'expert_cf' => 0.75,
            'is_required' => true,
        ]);

        DiseaseSymptomRule::query()->create([
            'disease_id' => $disease->id,
            'symptom_id' => $itch->id,
            'mb' => 0.60,
            'md' => 0.10,
            'expert_cf' => 0.50,
            'is_required' => false,
        ]);

        $result = (new CertaintyFactorService())->evaluateDisease($disease, [
            'RING_SHAPED_EDGE' => 0.8,
            'ITCHING' => 0.6,
        ]);

        $this->assertEqualsWithDelta(0.72, $result['textual_cf'], 0.0001);
        $this->assertSame(72.0, $result['textual_cf_percent']);
        $this->assertCount(2, $result['matched_symptoms']);
        $this->assertSame([], $result['missing_required_symptoms']);
    }

    public function test_missing_required_symptom_blocks_textual_cf(): void
    {
        $disease = Disease::query()->create([
            'code' => 'CANDIDIASIS',
            'name' => 'Candidiasis',
            'slug' => 'candidiasis',
            'severity_scope' => 'mild',
            'default_action' => 'recommend_otc',
            'is_active' => true,
        ]);

        $symptom = Symptom::query()->create([
            'code' => 'MOIST_FOLD_RASH',
            'name' => 'Ruam lipatan',
            'question' => 'Apakah ruam berada di lipatan?',
            'input_type' => 'scale',
            'is_active' => true,
        ]);

        DiseaseSymptomRule::query()->create([
            'disease_id' => $disease->id,
            'symptom_id' => $symptom->id,
            'mb' => 0.90,
            'md' => 0.10,
            'expert_cf' => 0.80,
            'is_required' => true,
        ]);

        $result = (new CertaintyFactorService())->evaluateDisease($disease, [
            'MOIST_FOLD_RASH' => 0.0,
        ]);

        $this->assertSame(0.0, $result['textual_cf']);
        $this->assertCount(1, $result['missing_required_symptoms']);
    }
}
