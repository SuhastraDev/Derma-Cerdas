<?php

namespace Tests\Unit;

use App\Models\Disease;
use App\Models\DatasetClassMapping;
use App\Services\AiVisualService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiVisualServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_candidate_output_overrides_false_skin_flag(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        config(['services.dermacerdas_ai.url' => 'http://dermacerdas-ai.test']);

        Http::fake([
            'dermacerdas-ai.test/analyze-image' => Http::response([
                'provider' => 'nvidia',
                'is_valid_skin_image' => false,
                'candidates' => [
                    [
                        'dataset_class_name' => 'Eczema',
                        'visual_score' => 0.57,
                        'reason' => 'Area kulit tampak kemerahan dan bersisik.',
                    ],
                ],
                'warnings' => ['Foto agak buram.'],
                'raw_response' => ['model' => 'nvidia-test'],
            ]),
        ]);

        $imagePath = UploadedFile::fake()
            ->image('skin.png', 320, 320)
            ->store('consultations', 'public');

        $analysis = (new AiVisualService())->analyze($imagePath, []);

        $this->assertTrue($analysis['is_valid_skin_image']);
        $this->assertSame('valid', $analysis['validation_status']);
        $this->assertCount(1, $analysis['candidates']);
        $this->assertTrue($analysis['candidates'][0]['disease']->is(Disease::query()->where('code', 'ECZEMA')->firstOrFail()));
    }

    public function test_request_includes_linked_production_classes_outside_textual_top_eight(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        config(['services.dermacerdas_ai.url' => 'http://dermacerdas-ai.test']);

        $disease = Disease::query()->where('code', 'ECZEMA')->firstOrFail();
        DatasetClassMapping::query()->create([
            'dataset_class_id' => 999,
            'dataset_class_name' => 'Basal_Cell_Carcinoma',
            'nama_indonesia' => 'Karsinoma sel basal',
            'scope_category' => 'rujuk',
            'boleh_rekomendasi_obat' => false,
            'default_action' => 'refer',
            'disease_id' => $disease->id,
        ]);

        Http::fake(function ($request) {
            $this->assertContains('Basal_Cell_Carcinoma', $request['candidate_classes']);

            return Http::response([
                'provider' => 'nvidia',
                'is_valid_skin_image' => true,
                'candidates' => [],
                'warnings' => [],
                'raw_response' => [],
            ]);
        });

        $imagePath = UploadedFile::fake()
            ->image('skin.png', 320, 320)
            ->store('consultations', 'public');

        (new AiVisualService())->analyze($imagePath, []);
    }

    public function test_request_includes_complaint_context_disease_hint_and_question_bank(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        config(['services.dermacerdas_ai.url' => 'http://dermacerdas-ai.test']);

        $disease = Disease::query()->create([
            'code' => 'PSORIASIS_PAPULOSQUAMOUS_TEST',
            'name' => 'Psoriasis test',
            'slug' => 'psoriasis-papulosquamous-test',
            'name_indonesian' => 'Psoriasis test',
            'description' => 'Konteks test.',
            'severity_scope' => 'moderate',
            'default_action' => 'educate_only',
            'is_active' => true,
        ]);

        DatasetClassMapping::query()->create([
            'dataset_class_id' => 353,
            'dataset_class_name' => 'Psoriasis',
            'nama_indonesia' => 'Psoriasis',
            'scope_category' => 'edukasi',
            'boleh_rekomendasi_obat' => false,
            'default_action' => 'educate_only',
            'disease_id' => $disease->id,
        ]);

        Http::fake(function ($request) {
            $this->assertSame('Saya menduga psoriasis dengan bercak merah bersisik.', $request['complaint_text']);
            $this->assertSame('Psoriasis', $request['candidate_classes'][0]);
            $this->assertNotEmpty($request['symptom_questions']);

            return Http::response([
                'provider' => 'nvidia',
                'is_valid_skin_image' => true,
                'suggested_symptom_codes' => ['G11', 'UNKNOWN'],
                'candidates' => [],
                'warnings' => [],
                'raw_response' => [],
            ]);
        });

        $imagePath = UploadedFile::fake()
            ->image('skin.png', 320, 320)
            ->store('consultations', 'public');

        $analysis = (new AiVisualService())->analyze(
            $imagePath,
            [],
            'Saya menduga psoriasis dengan bercak merah bersisik.',
            [['dataset_class_name' => 'Psoriasis']]
        );

        $this->assertSame(['G11'], $analysis['suggested_symptom_codes']);
    }

    public function test_quota_exhaustion_is_reported_as_unavailable_instead_of_invalid_skin(): void
    {
        Storage::fake('public');
        config(['services.dermacerdas_ai.url' => 'http://dermacerdas-ai.test']);

        Http::fake([
            'dermacerdas-ai.test/analyze-image' => Http::response([
                'provider' => 'nvidia',
                'provider_status' => 'quota_exceeded',
                'is_valid_skin_image' => false,
                'candidates' => [],
                'warnings' => ['Kuota/limit NVIDIA NIM API telah habis.'],
                'raw_response' => ['error_code' => 'quota_exceeded'],
            ]),
        ]);

        $imagePath = UploadedFile::fake()
            ->image('skin.png', 320, 320)
            ->store('consultations', 'public');

        $analysis = (new AiVisualService())->analyze($imagePath, []);

        $this->assertNull($analysis['is_valid_skin_image']);
        $this->assertSame('unavailable', $analysis['validation_status']);
        $this->assertSame('quota_exceeded', $analysis['provider_status']);
    }
}
