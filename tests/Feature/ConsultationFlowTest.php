<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationFinalResult;
use App\Models\Disease;
use App\Models\RedFlag;
use App\Models\Symptom;
use App\Services\AiVisualService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConsultationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_public_consultation_page_can_be_rendered(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->get(route('consultation.start'))
            ->assertOk();
    }

    public function test_user_can_submit_consultation_and_view_result(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $this->mockValidVisualAnalysis('TINEA_CORPORIS');

        $payload = [
            'visitor_name' => 'Indra Suhastra',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([
                'RING_SHAPED_EDGE' => 0.8,
                'ITCHING' => 0.6,
                'RED_RASH' => 0.6,
                'DRY_SCALY_SKIN' => 0.4,
            ]),
            'red_flags' => $this->redFlags([]),
        ];

        $response = $this->post(route('consultation.store'), $payload);

        $consultation = Consultation::query()->firstOrFail();
        $response->assertRedirect(route('consultation.result', $consultation->session_code));

        $this->assertSame('completed', $consultation->refresh()->status);
        $this->assertSame('Indra Suhastra', $consultation->visitor_name);
        $this->assertSame('recommend_otc', $consultation->final_action);
        $this->assertDatabaseCount('consultation_symptoms', Symptom::query()->count());
        $this->assertDatabaseCount('consultation_red_flags', RedFlag::query()->count());
        $this->assertDatabaseCount('consultation_visual_results', 1);
        $this->assertDatabaseHas('consultation_final_results', [
            'consultation_id' => $consultation->id,
            'action' => 'recommend_otc',
        ]);
        $this->assertSame('valid', $consultation->refresh()->metadata['visual_validation']['status']);
        Storage::disk('public')->assertExists($consultation->image_path);

        $this->get(route('consultation.result', $consultation->session_code))
            ->assertOk();
    }

    public function test_red_flag_forces_refer_action(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $this->mockValidVisualAnalysis('URTICARIA');

        $this->post(route('consultation.store'), [
            'visitor_name' => 'Pengguna Red Flag',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([
                'WHEALS_COME_GO' => 1.0,
                'ITCHING' => 0.8,
                'RED_RASH' => 0.6,
            ]),
            'red_flags' => $this->redFlags([
                'BREATHING_OR_FACE_SWELLING' => true,
            ]),
        ])->assertRedirect();

        $finalResult = ConsultationFinalResult::query()->firstOrFail();

        $this->assertSame('refer', $finalResult->action);
        $this->assertSame([], $finalResult->recommendations_snapshot);
        $this->assertDatabaseHas('consultation_red_flags', [
            'detected' => true,
        ]);
    }

    public function test_invalid_skin_image_is_rejected_when_ai_marks_it_invalid(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $this->mock(AiVisualService::class, function ($mock): void {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn([
                    'provider' => 'dermacerdas_ai',
                    'is_valid_skin_image' => false,
                    'validation_status' => 'invalid',
                    'candidates' => [],
                    'warnings' => ['Gambar bukan foto kulit.'],
                    'raw_response' => [],
                ]);
        });

        $this->post(route('consultation.store'), [
            'visitor_name' => 'Foto Tidak Valid',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('random.png', 320, 320),
            'symptoms' => $this->symptoms([
                'ITCHING' => 0.8,
                'RED_RASH' => 0.6,
            ]),
            'red_flags' => $this->redFlags([]),
        ])->assertSessionHasErrors('image');

        $this->assertDatabaseCount('consultations', 0);
        $this->assertDatabaseCount('consultation_final_results', 0);
    }

    public function test_consultation_is_rejected_when_visual_ai_is_not_configured(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $this->post(route('consultation.store'), [
            'visitor_name' => 'AI Belum Aktif',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([
                'ITCHING' => 0.8,
                'RED_RASH' => 0.6,
            ]),
            'red_flags' => $this->redFlags([]),
        ])->assertSessionHasErrors('image');

        $this->assertDatabaseCount('consultations', 0);
        $this->assertCount(0, Storage::disk('public')->allFiles('consultations'));
    }

    public function test_history_code_redirects_to_existing_result_and_export_renders(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $this->mockValidVisualAnalysis('TINEA_CORPORIS');

        $this->post(route('consultation.store'), [
            'visitor_name' => 'Riwayat User',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([
                'RING_SHAPED_EDGE' => 0.8,
                'ITCHING' => 0.6,
            ]),
            'red_flags' => $this->redFlags([]),
        ]);

        $consultation = Consultation::query()->firstOrFail();

        $this->post(route('consultation.history.check'), [
            'session_code' => strtolower($consultation->session_code),
        ])->assertRedirect(route('consultation.result', $consultation->session_code));

        $this->get(route('consultation.export', $consultation->session_code))
            ->assertOk()
            ->assertSee($consultation->session_code);
    }

    /**
     * @param  array<string, float>  $selected
     * @return array<string, float>
     */
    private function symptoms(array $selected): array
    {
        return Symptom::query()
            ->pluck('code')
            ->mapWithKeys(fn (string $code): array => [$code => $selected[$code] ?? 0.0])
            ->all();
    }

    /**
     * @param  array<string, bool>  $selected
     * @return array<string, bool>
     */
    private function redFlags(array $selected): array
    {
        return RedFlag::query()
            ->pluck('code')
            ->mapWithKeys(fn (string $code): array => [$code => $selected[$code] ?? false])
            ->all();
    }

    private function mockValidVisualAnalysis(string $diseaseCode): void
    {
        $disease = Disease::query()->where('code', $diseaseCode)->firstOrFail();

        $this->mock(AiVisualService::class, function ($mock) use ($disease): void {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn([
                    'provider' => 'dermacerdas_ai',
                    'is_valid_skin_image' => true,
                    'validation_status' => 'valid',
                    'candidates' => [
                        [
                            'disease' => $disease,
                            'provider' => 'dermacerdas_ai',
                            'visual_score' => 0.82,
                            'visual_reason' => 'Foto terdeteksi sebagai area kulit dan cocok sebagai kandidat awal.',
                            'raw_response' => [],
                        ],
                    ],
                    'warnings' => [],
                    'raw_response' => [],
                ]);
        });
    }
}
