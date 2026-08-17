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
        // Eczema termasuk 15 kelas ruang lingkup, jadi kelas dataset 'Eczema'
        // menunjuk penyakit ECZEMA itu sendiri.
        $this->assertTrue($analysis['candidates'][0]['disease']->is(Disease::query()->where('code', 'ECZEMA')->firstOrFail()));
    }

    public function test_request_includes_linked_production_classes_outside_textual_top_eight(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        config(['services.dermacerdas_ai.url' => 'http://dermacerdas-ai.test']);

        // Melasma sengaja dipilih karena BUKAN salah satu dari 15 kelas ruang
        // lingkup, sehingga benar-benar menguji kelas tertaut di luar peringkat teks.
        $disease = Disease::query()->where('code', 'ECZEMA')->firstOrFail();
        DatasetClassMapping::query()->updateOrCreate(
            ['dataset_class_name' => 'Melasma'],
            [
                'dataset_class_id' => 117,
                'nama_indonesia' => 'Melasma',
                'scope_category' => 'edukasi',
                'boleh_rekomendasi_obat' => false,
                'default_action' => 'educate_only',
                'disease_id' => $disease->id,
            ]
        );

        Http::fake(function ($request) {
            $this->assertContains('Melasma', $request['candidate_classes']);

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

        // Psoriasis termasuk 15 kelas dan sudah diseed lengkap dengan pemetaannya.

        Http::fake(function ($request) {
            $this->assertSame('Saya menduga psoriasis dengan bercak merah bersisik.', $request['complaint_text']);
            $this->assertSame('Psoriasis', $request['candidate_classes'][0]);
            $this->assertNotEmpty($request['symptom_questions']);

            return Http::response([
                'provider' => 'nvidia',
                'is_valid_skin_image' => true,
                'suggested_symptom_codes' => ['P2_DATAR', 'UNKNOWN'],
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

        $this->assertSame(['P2_DATAR'], $analysis['suggested_symptom_codes']);
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
