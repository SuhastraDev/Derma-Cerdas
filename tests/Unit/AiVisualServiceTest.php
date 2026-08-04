<?php

namespace Tests\Unit;

use App\Models\Disease;
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
                'provider' => 'gemini',
                'is_valid_skin_image' => false,
                'candidates' => [
                    [
                        'dataset_class_name' => 'Eczema',
                        'visual_score' => 0.57,
                        'reason' => 'Area kulit tampak kemerahan dan bersisik.',
                    ],
                ],
                'warnings' => ['Foto agak buram.'],
                'raw_response' => ['model' => 'gemini-test'],
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
}
