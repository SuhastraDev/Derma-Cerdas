<?php

namespace Tests\Unit;

use App\Support\DatasetDiseaseMapper;
use PHPUnit\Framework\TestCase;

class DatasetDiseaseMapperTest extends TestCase
{
    public function test_it_maps_high_risk_skin_cancer_classes_to_referral(): void
    {
        $payload = DatasetDiseaseMapper::payloadFor('Malignant_Melanoma');

        $this->assertSame('SKIN_CANCER_REFER', $payload['disease']['code']);
        $this->assertSame('rujuk', $payload['mapping']['scope_category']);
        $this->assertSame('refer', $payload['mapping']['default_action']);
        $this->assertFalse($payload['mapping']['boleh_rekomendasi_obat']);
    }

    public function test_it_maps_light_self_care_classes_to_swamedikasi(): void
    {
        $payload = DatasetDiseaseMapper::payloadFor('Tinea_Corporis');

        $this->assertSame('FUNGAL_SUPERFICIAL', $payload['disease']['code']);
        $this->assertSame('swamedikasi', $payload['mapping']['scope_category']);
        $this->assertSame('recommend_otc', $payload['mapping']['default_action']);
        $this->assertTrue($payload['mapping']['boleh_rekomendasi_obat']);
    }

    public function test_it_maps_hair_and_nail_non_scope_classes_to_exclude(): void
    {
        $payload = DatasetDiseaseMapper::payloadFor('Alopecia_Areata');

        $this->assertSame('HAIR_NAIL_EXCLUDE', $payload['disease']['code']);
        $this->assertSame('exclude', $payload['mapping']['scope_category']);
        $this->assertSame('educate_only', $payload['mapping']['default_action']);
    }

    public function test_it_reports_unknown_dataset_classes(): void
    {
        $this->assertSame(['Unknown_Class'], DatasetDiseaseMapper::missingClassNames(['Acne_Vulgaris', 'Unknown_Class']));
    }
}
