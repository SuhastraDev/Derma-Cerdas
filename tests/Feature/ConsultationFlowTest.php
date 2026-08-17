<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationFinalResult;
use App\Models\DatasetClassMapping;
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
            'complaint_text' => 'Gatal sejak satu minggu, ruam melingkar di badan dan tepinya bersisik. Tidak demam dan tidak bernanah.',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([
                'P1_BADAN' => 1.0, // lokasi: badan
                'P4_GATAL' => 1.0, // rasa: gatal
                'P3_HALUS' => 1.0, // sisik halus
                'P2_CINCIN' => 1.0, // bentuk melingkar
                'P8_TENGAHBERSIH' => 1.0, // tengah lebih bersih
                'P5_MINGGU' => 1.0, // beberapa minggu
                'P7_KERINGAT' => 1.0, // memburuk saat berkeringat
            ]),
            'red_flags' => $this->redFlags([]),
        ];

        $response = $this->post(route('consultation.store'), $payload);

        $consultation = Consultation::query()->firstOrFail();
        $response->assertRedirect(route('consultation.result', $consultation->session_code));

        $this->assertSame('completed', $consultation->refresh()->status);
        $this->assertSame('Indra Suhastra', $consultation->visitor_name);
        $this->assertNotEmpty($consultation->complaint_features['symptom_evidence']['P2_CINCIN'] ?? []);
        $this->assertSame('recommend_otc', $consultation->final_action);
        $this->assertDatabaseCount('consultation_symptoms', Symptom::query()->where('is_active', true)->count());
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

    public function test_precheck_returns_adaptive_symptom_questions(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $this->mockValidVisualAnalysis('TINEA_CORPORIS');

        $response = $this->postJson(route('consultation.precheck'), [
            'complaint_text' => 'Gatal sejak satu minggu, ruam melingkar di badan, tepinya merah dan bersisik. Tidak demam.',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
        ]);

        $response->assertOk()
            ->assertJsonPath('visual.status', 'valid');

        $codes = collect($response->json('selected_symptoms'))->pluck('code');

        // Seluruh pertanyaan kini diajukan - yang adaptif hanya urutannya. Sesi
        // produksi DC-20260817-205612-FUTCL menunjukkan bahaya melewatkan
        // pertanyaan: penanda khas biduran tidak pernah ditanyakan.
        $this->assertSame(Symptom::query()->where('is_active', true)->count(), $codes->count());
        $this->assertContains('P2_CINCIN', $codes);
        $this->assertContains('P5_JAM', $codes);

        // Lokasi dan bentuk tetap didahulukan karena daya pisahnya terbesar.
        $groups = collect($response->json('selected_symptoms'))->pluck('question_group')->unique()->values();
        $this->assertSame('P1_LOKASI', $groups->first());
        $this->assertSame('P2_BENTUK', $groups->get(1));

        $this->assertLessThan(RedFlag::query()->where('is_active', true)->count(), count($response->json('selected_red_flags')));
        $this->assertGreaterThanOrEqual(4, count($response->json('selected_red_flags')));
    }

    public function test_precheck_uses_psoriasis_context_for_plain_language_questions(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $psoriasis = $this->createPsoriasisDisease();

        $this->mock(AiVisualService::class, function ($mock) use ($psoriasis): void {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn([
                    'provider' => 'dermacerdas_ai',
                    'provider_status' => 'ok',
                    'is_valid_skin_image' => true,
                    'validation_status' => 'valid',
                    'candidates' => [[
                        'disease' => $psoriasis,
                        'provider' => 'dermacerdas_ai',
                        'visual_score' => 0.50,
                        'visual_reason' => 'Kandidat awal psoriasis dari konteks dan foto.',
                        'raw_response' => [],
                    ]],
                    'suggested_symptom_codes' => [],
                    'warnings' => [],
                    'raw_response' => [],
                ]);

            $mock->shouldReceive('assessRedFlags')->andReturn([]);
        });

        $response = $this->postJson(route('consultation.precheck'), [
            'complaint_text' => 'Saya menduga psoriasis, bercak merah tebal dan bersisik di lengan sudah berminggu minggu.',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
        ]);

        $response->assertOk();

        $questions = collect($response->json('selected_symptoms'));

        $this->assertContains('P2_PLAK', $questions->pluck('code'));
        // Pertanyaan sisik-lah yang memisahkan psoriasis dari eksim dan panu.
        $this->assertContains('P3_TEBAL', $questions->pluck('code'));
        $this->assertLessThan(RedFlag::query()->where('is_active', true)->count(), count($response->json('selected_red_flags')));
    }

    public function test_context_aligned_psoriasis_visual_result_is_not_replaced_by_eczema_text_result(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $psoriasis = $this->createPsoriasisDisease();

        $this->mock(AiVisualService::class, function ($mock) use ($psoriasis): void {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn([
                    'provider' => 'nvidia',
                    'is_valid_skin_image' => true,
                    'validation_status' => 'valid',
                    'candidates' => [[
                        'disease' => $psoriasis,
                        'provider' => 'nvidia',
                        'visual_score' => 0.78,
                        'visual_reason' => 'Bercak bersisik selaras dengan kandidat visual.',
                        'raw_response' => ['dataset_class_name' => 'Psoriasis'],
                    ]],
                    'warnings' => [],
                    'raw_response' => [],
                ]);
        });

        $this->post(route('consultation.store'), [
            'visitor_name' => 'Pengguna Psoriasis',
            'complaint_text' => 'Saya menduga psoriasis, bercak merah dan bersisik sejak beberapa minggu.',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([
                'P3_TEBAL' => 1.0,
                'P2_PLAK' => 1.0,
            ]),
            'red_flags' => $this->redFlags([]),
        ])->assertRedirect();

        $finalResult = ConsultationFinalResult::query()->firstOrFail();

        $this->assertSame($psoriasis->id, $finalResult->disease_id);
        $this->assertSame('F09', $finalResult->fusion_rule_code);
        $this->assertSame('educate_only', $finalResult->action);
        $this->assertSame([], $finalResult->recommendations_snapshot);
    }

    public function test_red_flag_forces_refer_action(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $this->mockValidVisualAnalysis('URTICARIA');

        $this->post(route('consultation.store'), [
            'visitor_name' => 'Pengguna Red Flag',
            'complaint_text' => 'Bentol gatal muncul hilang timbul disertai bibir bengkak dan sesak.',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([
                'WHEALS_COME_GO' => 1.0,
                'P4_GATAL' => 1.0,
                'P2_MERAHLUAS' => 1.0,
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
            'complaint_text' => 'Gatal dan merah ringan sejak beberapa hari tanpa demam.',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('random.png', 320, 320),
            'symptoms' => $this->symptoms([
                'P4_GATAL' => 1.0,
                'P2_MERAHLUAS' => 1.0,
            ]),
            'red_flags' => $this->redFlags([]),
        ])->assertSessionHasErrors('image');

        $this->assertDatabaseCount('consultations', 0);
        $this->assertDatabaseCount('consultation_final_results', 0);
    }

    public function test_quota_exhaustion_reports_ai_service_problem_instead_of_blaming_photo(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $this->mock(AiVisualService::class, function ($mock): void {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn([
                    'provider' => 'nvidia',
                    'provider_status' => 'quota_exceeded',
                    'is_valid_skin_image' => null,
                    'validation_status' => 'unavailable',
                    'candidates' => [],
                    'warnings' => ['Kuota/limit NVIDIA NIM API telah habis.'],
                    'raw_response' => ['error_code' => 'quota_exceeded'],
                ]);
        });

        $this->post(route('consultation.store'), [
            'visitor_name' => 'Kuota Habis',
            'complaint_text' => 'Bercak pada kulit terlihat jelas dan tidak terasa nyeri.',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms(['PATCHES' => 0.8]),
            'red_flags' => $this->redFlags([]),
        ])->assertSessionHasErrors([
            'image' => 'Kuota/limit analisis visual NVIDIA NIM sedang habis. Tunggu hingga kuota tersedia kembali atau gunakan API key dengan limit aktif.',
        ]);
    }

    public function test_valid_skin_image_without_visual_candidates_uses_textual_result(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $this->mock(AiVisualService::class, function ($mock): void {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn([
                    'provider' => 'dermacerdas_ai',
                    'is_valid_skin_image' => true,
                    'validation_status' => 'valid',
                    'candidates' => [],
                    'warnings' => ['Foto kulit valid, tetapi kandidat visual belum cukup yakin.'],
                    'raw_response' => [],
                ]);
        });

        $response = $this->post(route('consultation.store'), [
            'visitor_name' => 'Kulit Valid',
            'complaint_text' => 'Gatal sejak satu minggu, ruam melingkar di badan dan tepinya bersisik.',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([
                'RING_SHAPED_EDGE' => 0.8,
                'ITCHING' => 0.6,
                'P2_MERAHLUAS' => 1.0,
            ]),
            'red_flags' => $this->redFlags([]),
        ]);

        $consultation = Consultation::query()->firstOrFail();

        $response->assertRedirect(route('consultation.result', $consultation->session_code));
        $this->assertSame('completed', $consultation->refresh()->status);
        $this->assertDatabaseCount('consultation_visual_results', 0);
        $this->assertDatabaseHas('consultation_final_results', [
            'consultation_id' => $consultation->id,
        ]);

        $this->get(route('consultation.result', $consultation->session_code))
            ->assertOk();
    }

    public function test_consultation_is_rejected_when_visual_ai_is_not_configured(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $this->post(route('consultation.store'), [
            'visitor_name' => 'AI Belum Aktif',
            'complaint_text' => 'Gatal dan merah ringan sejak beberapa hari tanpa demam.',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([
                'P4_GATAL' => 1.0,
                'P2_MERAHLUAS' => 1.0,
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
            'complaint_text' => 'Gatal sejak beberapa hari dengan ruam melingkar dan tepi merah.',
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

            $mock->shouldReceive('assessRedFlags')->andReturn([]);
        });
    }

    /**
     * Regresi dari sesi produksi DC-20260817-144600-BZ0GW: keluhan "bercak merah
     * tebal bersisik putih, sudah berminggu-minggu" membuat sistem mengisi
     * sendiri RED_RASH, DRY_SCALY_SKIN, dan WHITE_BROWN_PATCHES lewat max(),
     * menghasilkan DRY_SKIN_ECZEMA dengan CF 0,85 padahal pengguna tidak memilih
     * satu pun gejala. Teks keluhan tidak boleh lagi menjadi nilai gejala.
     */
    public function test_complaint_text_never_fills_in_symptom_values(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $this->mock(AiVisualService::class, function ($mock): void {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn([
                    'provider' => 'dermacerdas_ai',
                    'provider_status' => 'ok',
                    'is_valid_skin_image' => true,
                    'validation_status' => 'valid',
                    'candidates' => [],
                    'suggested_symptom_codes' => [],
                    'warnings' => [],
                    'raw_response' => [],
                ]);
        });

        $this->post(route('consultation.store'), [
            'visitor_name' => 'Pengguna Tanpa Jawaban',
            'complaint_text' => 'bercak merah tebal bersisik putih, sudah berminggu-minggu',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([]),
            'red_flags' => $this->redFlags([]),
        ])->assertRedirect();

        $finalResult = ConsultationFinalResult::query()->firstOrFail();

        $this->assertSame(0.0, (float) $finalResult->textual_cf);
        $this->assertSame([], $finalResult->recommendations_snapshot);

        $consultation = Consultation::query()->firstOrFail();
        $this->assertSame(
            0,
            $consultation->symptoms()->where('user_cf', '>', 0)->count(),
            'Tidak satu pun gejala boleh bernilai > 0 tanpa jawaban pengguna.'
        );
    }

    /**
     * Regresi kasus nyata: pengguna hanya mengunggah foto psoriasis tanpa
     * menyebut nama penyakitnya, lalu menjawab gejala umum (gatal, kemerahan).
     *
     * Sebelum perbaikan, Eczema menang di jalur gejala dengan CF 0,82 dan hasil
     * visual psoriasis dibuang, sehingga sistem menampilkan "Eksim" beserta
     * rekomendasi obat. Aturan F09 tidak menolong karena mensyaratkan pengguna
     * mengetik nama penyakitnya.
     */
    public function test_visual_psoriasis_is_not_masked_by_generic_symptom_answers(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $psoriasis = $this->createPsoriasisDisease();

        $this->mock(AiVisualService::class, function ($mock) use ($psoriasis): void {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn([
                    'provider' => 'dermacerdas_ai',
                    'provider_status' => 'ok',
                    'is_valid_skin_image' => true,
                    'validation_status' => 'valid',
                    'candidates' => [[
                        'disease' => $psoriasis,
                        'provider' => 'dermacerdas_ai',
                        'visual_score' => 0.72,
                        'visual_reason' => 'Plak tebal bersisik tebal keperakan.',
                        'raw_response' => ['dataset_class_name' => 'Psoriasis'],
                    ]],
                    'suggested_symptom_codes' => [],
                    'warnings' => [],
                    'raw_response' => [],
                ]);
        });

        $this->post(route('consultation.store'), [
            'visitor_name' => 'Pengguna Foto Saja',
            // Sengaja tidak menyebut nama penyakit apa pun.
            'complaint_text' => 'Kulit saya gatal dan kemerahan di bagian lengan.',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms(['P4_GATAL' => 1.0, 'P2_MERAHLUAS' => 1.0]),
            'red_flags' => $this->redFlags([]),
        ])->assertRedirect();

        $finalResult = ConsultationFinalResult::query()->firstOrFail();

        // Psoriasis kini punya basis gejala/CF sendiri, sehingga F08 (khusus
        // kandidat visual TANPA basis pengetahuan) tidak lagi berlaku. Jalur yang
        // benar adalah F05: hasil visual dan gejala tidak sepakat sementara CFt
        // belum tinggi, jadi safety-net menahan rekomendasi dan merujuk.
        // Jawaban gejala yang diberikan cukup kuat (CFt >= 0,60), sehingga aturan
        // yang berlaku F04: keputusan disandarkan pada gejala, TETAPI ketidaksesuaian
        // dengan hasil visual wajib dinyatakan - bukan dibuang diam-diam seperti
        // sebelumnya. Penahanan mutlak hanya untuk kandidat visual bergolongan rujuk.
        $this->assertSame('F04', $finalResult->fusion_rule_code);
        $this->assertStringContainsString('Psoriasis', $finalResult->explanation);
        $this->assertDatabaseHas('consultation_visual_results', ['disease_id' => $psoriasis->id]);
        $this->assertSame([], $finalResult->recommendations_snapshot);
    }

    /**
     * Psoriasis kini termasuk 15 kelas ruang lingkup dan sudah diseed lengkap
     * dengan pemetaan dataset serta profil Certainty Factor-nya, sehingga tidak
     * perlu - dan tidak boleh - dibuat ulang di sini.
     */
    private function createPsoriasisDisease(): Disease
    {
        return Disease::query()->where('code', 'PSORIASIS')->firstOrFail();
    }
}
