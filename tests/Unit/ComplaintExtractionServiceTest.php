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

    /**
     * Regresi salah deteksi substring yang terbukti pada data produksi.
     * Sesi DC-20260817-144600-BZ0GW mencatat "WHITE_BROWN_PATCHES terdeteksi
     * dari kata: putih" hanya karena keluhan menyebut "bersisik putih".
     *
     * @dataProvider substringFalsePositives
     */
    public function test_substring_matches_no_longer_fabricate_symptoms(string $text, string $code): void
    {
        $features = (new ComplaintExtractionService())->extract($text);

        $this->assertNotContains(
            $code,
            array_keys($features['symptom_evidence']),
            sprintf('Teks "%s" seharusnya tidak memicu %s.', $text, $code)
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function substringFalsePositives(): array
    {
        return [
            'berkeringat bukan kulit kering' => ['gatal bertambah setelah berkeringat', 'DRY_SCALY_SKIN'],
            'keringat bukan kulit kering' => ['badan penuh keringat sepanjang hari', 'G18'],
            'kulit putih bukan bercak panu' => ['kulit putih saya muncul ruam merah', 'WHITE_BROWN_PATCHES'],
            'panas tinggi bukan sensasi terbakar' => ['demam dan panas tinggi sejak kemarin', 'BURNING_STINGING'],
            'gatal di kaki bukan kutu air' => ['ada ruam gatal di kaki kanan', 'FOOT_SCALING'],
        ];
    }

    public function test_word_boundary_still_recognises_indonesian_affixes(): void
    {
        $features = (new ComplaintExtractionService())->extract('kulit saya bersisik dan digaruk terus');

        $this->assertContains('DRY_SCALY_SKIN', array_keys($features['symptom_evidence']));
        $this->assertContains('ITCHING', array_keys($features['symptom_evidence']));
    }

    public function test_negation_only_applies_when_every_mention_is_negated(): void
    {
        $service = new ComplaintExtractionService();

        $stillNegated = $service->extract('sama sekali tidak gatal, hanya kemerahan');
        $this->assertNotContains('ITCHING', array_keys($stillNegated['symptom_evidence']));

        // Sebelumnya hanya kemunculan pertama yang diperiksa, sehingga kalimat
        // ini salah disimpulkan sebagai tidak gatal.
        $laterMentionCounts = $service->extract('awalnya tidak gatal, sekarang gatal sekali');
        $this->assertContains('ITCHING', array_keys($laterMentionCounts['symptom_evidence']));
    }
}
