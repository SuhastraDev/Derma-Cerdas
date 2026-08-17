<?php

namespace Tests\Unit;

use App\Models\DatasetClassMapping;
use App\Models\Disease;
use App\Services\ComplaintExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplaintExtractionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_disease_name_is_exposed_as_a_hint_without_being_confirmed(): void
    {
        $disease = Disease::query()->create([
            'code' => 'PSORIASIS_PAPULOSQUAMOUS',
            'name' => 'Psoriasis and papulosquamous disorders',
            'slug' => 'psoriasis-papulosquamous',
            'name_indonesian' => 'Psoriasis dan gangguan papuloskuamosa',
            'description' => 'Edukasi psoriasis.',
            'severity_scope' => 'moderate',
            'default_action' => 'educate_only',
            'is_active' => true,
        ]);

        DatasetClassMapping::query()->create([
            'dataset_class_id' => 353,
            'dataset_class_name' => 'Psoriasis',
            'nama_indonesia' => 'Psoriasis',
            'clinical_group' => 'Psoriasis & papuloskuamosa',
            'scope_category' => 'edukasi',
            'boleh_rekomendasi_obat' => false,
            'default_action' => 'educate_only',
            'disease_id' => $disease->id,
        ]);

        $features = (new ComplaintExtractionService())->extract(
            'Saya menduga ini psoriasis, ada bercak merah dan bersisik sejak beberapa minggu.'
        );

        $hints = collect($features['disease_hints']);

        $this->assertTrue($hints->contains(fn (array $hint): bool => $hint['dataset_class_name'] === 'Psoriasis'));
        $this->assertContains('RED_RASH', array_keys($features['symptom_evidence']));
        $this->assertContains('DRY_SCALY_SKIN', array_keys($features['symptom_evidence']));
    }
}
