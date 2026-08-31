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
        // P3_TEBAL + P2_PLAK give Psoriasis textual_cf 0.96, and the visual
        // score is 0.78 - both clear the "high" bar. F11 used to fire here,
        // but only 2 distinct symptoms matched - below
        // FusionDecisionService::MIN_MATCHED_SYMPTOMS_FOR_CONFIDENT_LABEL (3),
        // the same substance gate F11 now shares with F06 (added after the
        // bisul/BCC/Keloid regressions). So it correctly falls to F09
        // (context-aligned visual) instead - same safe outcome either way:
        // no OTC medicine, still educate_only.
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
        $this->assertSame('recommend_otc_mismatch', $finalResult->action);

        // Inti jaminannya: temuan visual yang berbeda TIDAK boleh hilang dari
        // layar. Eksim kini tergolong boleh-obat, jadi obatnya memang muncul -
        // tetapi wajib disertai catatan bahwa foto mengarah ke tempat lain.
        $this->assertStringContainsString('Psoriasis', $finalResult->explanation);
        $this->assertDatabaseHas('consultation_visual_results', ['disease_id' => $psoriasis->id]);
    }

    /**
     * Regresi untuk kandidat visual di luar 16 penyakit cakupan (mis. golongan
     * DatasetDiseaseMapper VIRAL_EDUCATION untuk kutil/molluscum) yang KALAH
     * dari CF gejala tinggi terhadap salah satu 16 penyakit (F04). Sebelum
     * perbaikan ini, temuan visual itu hanya muncul sebagai satu kalimat di
     * explanation - sekarang wajib juga muncul sebagai secondary_visual_note
     * TANPA mengubah disease/action/rekomendasi obat yang sudah benar.
     */
    public function test_out_of_scope_visual_candidate_adds_secondary_note_without_changing_the_otc_decision(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $outOfScopeGroup = $this->createOutOfScopeGroupDisease();

        $this->mock(AiVisualService::class, function ($mock) use ($outOfScopeGroup): void {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn([
                    'provider' => 'dermacerdas_ai',
                    'provider_status' => 'ok',
                    'is_valid_skin_image' => true,
                    'validation_status' => 'valid',
                    'candidates' => [[
                        'disease' => $outOfScopeGroup,
                        'provider' => 'dermacerdas_ai',
                        'visual_score' => 0.65,
                        'visual_reason' => 'Pola benjolan kecil menyerupai lesi virus jinak.',
                        'raw_response' => ['dataset_class_name' => 'Molluscum_Contagiosum'],
                    ]],
                    'suggested_symptom_codes' => [],
                    'warnings' => [],
                    'raw_response' => [],
                ]);
        });

        $this->post(route('consultation.store'), [
            'visitor_name' => 'Pengguna Luar Cakupan',
            'complaint_text' => 'Gatal sejak satu minggu, ruam melingkar di badan dan tepinya bersisik.',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([
                'P1_BADAN' => 1.0,
                'P4_GATAL' => 1.0,
                'P3_HALUS' => 1.0,
                'P2_CINCIN' => 1.0,
                'P8_TENGAHBERSIH' => 1.0,
                'P5_MINGGU' => 1.0,
                'P7_KERINGAT' => 1.0,
            ]),
            'red_flags' => $this->redFlags([]),
        ])->assertRedirect();

        $finalResult = ConsultationFinalResult::query()->firstOrFail();
        $tineaCorporis = Disease::query()->where('code', 'TINEA_CORPORIS')->firstOrFail();

        // Keputusan lama tetap sama persis: CF gejala tinggi terhadap Tinea
        // Corporis menang lewat F04, obat tetap direkomendasikan untuknya.
        $this->assertSame($tineaCorporis->id, $finalResult->disease_id);
        $this->assertSame('F04', $finalResult->fusion_rule_code);
        $this->assertSame('recommend_otc_mismatch', $finalResult->action);
        $this->assertNotEmpty($finalResult->recommendations_snapshot);

        // Tambahan barunya: catatan edukasi tentang temuan visual di luar
        // cakupan, terpisah dari keputusan di atas.
        $this->assertNotNull($finalResult->secondary_visual_note);
        $this->assertSame(
            $outOfScopeGroup->name_indonesian,
            $finalResult->secondary_visual_note['disease_name_indonesian']
        );
        $this->assertSame(0.65, (float) $finalResult->secondary_visual_note['visual_score']);

        $this->get(route('consultation.result', $consultation = Consultation::query()->firstOrFail()->session_code))
            ->assertOk();
    }

    /**
     * 2026-09-01: gate visualUnreliable dinonaktifkan atas permintaan eksplisit
     * (lihat catatan di FusionDecisionService::decide()) - action sekarang
     * kembali murni berbasis CF teks walau outside_scope=true, seperti sebelum
     * gate ini ditambahkan. metadata visual_validation tetap menyimpan
     * outside_scope/observed_description apa adanya untuk ditampilkan di UI,
     * itu tidak berubah - yang berubah cuma apakah sinyal itu ikut menahan aksi.
     */
    public function test_visual_outside_scope_no_longer_blocks_otc_recommendation(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $this->mock(AiVisualService::class, function ($mock): void {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn([
                    'provider' => 'dermacerdas_ai',
                    'provider_status' => 'dataset_fallback',
                    'is_valid_skin_image' => true,
                    'validation_status' => 'degraded',
                    'outside_scope' => true,
                    'observed_description' => 'Bercak putih dengan batas tegas, tidak bersisik, tidak meradang.',
                    'candidates' => [],
                    'suggested_symptom_codes' => [],
                    'warnings' => ['dermacerdas_ai tidak menghasilkan JSON kandidat yang valid; sistem memakai kandidat fallback dari indeks visual dataset.'],
                    'raw_response' => [],
                ]);
        });

        $this->post(route('consultation.store'), [
            'visitor_name' => 'Pengguna Vitiligo',
            'complaint_text' => 'Muncul bercak putih di kulit sejak beberapa bulan, tidak gatal, tidak nyeri.',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([
                'P1_BADAN' => 1.0,
                'P4_GATAL' => 1.0,
                'P3_HALUS' => 1.0,
                'P2_CINCIN' => 1.0,
                'P8_TENGAHBERSIH' => 1.0,
                'P5_MINGGU' => 1.0,
                'P7_KERINGAT' => 1.0,
            ]),
            'red_flags' => $this->redFlags([]),
        ])->assertRedirect();

        $finalResult = ConsultationFinalResult::query()->firstOrFail();

        $this->assertSame('recommend_otc_unsupported', $finalResult->action);
        $this->assertNotSame([], $finalResult->recommendations_snapshot);
        $this->assertFalse($finalResult->label_suppressed);

        $consultation = Consultation::query()->firstOrFail();
        $this->assertTrue($consultation->refresh()->metadata['visual_validation']['outside_scope']);
        $this->assertSame(
            'Bercak putih dengan batas tegas, tidak bersisik, tidak meradang.',
            $consultation->metadata['visual_validation']['observed_description']
        );

        $this->get(route('consultation.result', $consultation->session_code))
            ->assertOk();
    }

    /**
     * 2026-09-01: gate visualUnreliable (termasuk sinyal validation_status
     * degraded) dinonaktifkan atas permintaan eksplisit - lihat catatan di
     * FusionDecisionService::decide(). Provider yang gagal parsing sama sekali
     * tidak lagi menahan rekomendasi obat; action kembali murni berbasis CF
     * teks seperti sebelum gate ini ditambahkan.
     */
    public function test_degraded_visual_without_explicit_outside_scope_no_longer_blocks_otc_recommendation(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $this->mock(AiVisualService::class, function ($mock): void {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn([
                    'provider' => 'dermacerdas_ai',
                    'provider_status' => 'dataset_fallback',
                    'is_valid_skin_image' => true,
                    'validation_status' => 'degraded',
                    // Persis kasus produksi: provider gagal parsing sama sekali,
                    // sehingga outside_scope tetap default false walau sistem
                    // sebenarnya tidak tahu apa isi foto itu.
                    'outside_scope' => false,
                    'observed_description' => '',
                    'candidates' => [],
                    'suggested_symptom_codes' => [],
                    'warnings' => ['dermacerdas_ai tidak menghasilkan JSON kandidat yang valid; sistem memakai kandidat fallback dari indeks visual dataset.'],
                    'raw_response' => [],
                ]);
        });

        $this->post(route('consultation.store'), [
            'visitor_name' => 'Pengguna Vitiligo Dua',
            'complaint_text' => 'Muncul bercak putih di kulit sejak beberapa bulan, tidak gatal, tidak nyeri, tidak bersisik.',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([
                'P1_BADAN' => 1.0,
                'P4_GATAL' => 1.0,
                'P3_HALUS' => 1.0,
                'P2_CINCIN' => 1.0,
                'P8_TENGAHBERSIH' => 1.0,
                'P5_MINGGU' => 1.0,
                'P7_KERINGAT' => 1.0,
            ]),
            'red_flags' => $this->redFlags([]),
        ])->assertRedirect();

        $finalResult = ConsultationFinalResult::query()->firstOrFail();

        $this->assertSame('recommend_otc_unsupported', $finalResult->action);
        $this->assertNotSame([], $finalResult->recommendations_snapshot);
        $this->assertFalse($finalResult->label_suppressed);
    }

    /**
     * Regresi produksi 2026-08-30: pengguna mengunggah foto bisul (di luar 16
     * penyakit) dan menjawab pertanyaan seadanya. Satu-satunya opsi bentuk
     * yang "paling mendekati" (Benjolan tunggal mengkilap) kebetulan menjadi
     * gejala paling khas Basal Cell Carcinoma (bobot pakar 0,80) - jawaban
     * itu SENDIRIAN sudah melewati HIGH_CF, sehingga sistem sebelumnya
     * menampilkan "Karsinoma sel basal" walau cuma 1 dari 7 gejala BCC yang
     * cocok. Action refer tetap benar (aman), tapi nama spesifiknya harus
     * disembunyikan karena buktinya terlalu tipis untuk memastikan itu.
     *
     * 2026-09-01: label_suppressed dinonaktifkan atas permintaan eksplisit
     * (lihat catatan di FusionDecisionService::decide()) - nama penyakit
     * sekarang tetap tampil walau bukti tipis, selama action-nya sendiri
     * masih benar (refer). Nama test dipertahankan supaya jejak regresi
     * 2026-08-30 tetap tercatat, meski assersi label_suppressed dibalik.
     */
    public function test_single_generic_symptom_match_no_longer_suppresses_disease_label(): void
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
                    'outside_scope' => false,
                    'observed_description' => '',
                    'candidates' => [],
                    'suggested_symptom_codes' => [],
                    'warnings' => [],
                    'raw_response' => [],
                ]);
        });

        $this->post(route('consultation.store'), [
            'visitor_name' => 'Pengguna Bisul',
            'complaint_text' => 'Ada tonjolan keras di kulit, saya jawab seadanya karena tidak ada pilihan yang benar-benar cocok.',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([
                'P2_TANDUK' => 1.0,
            ]),
            'red_flags' => $this->redFlags([]),
        ])->assertRedirect();

        $finalResult = ConsultationFinalResult::query()->firstOrFail();
        $cutaneousHorn = Disease::query()->where('code', 'CUTANEOUS_HORN')->firstOrFail();

        // disease_id tetap tersimpan apa adanya untuk audit - yang berubah
        // cuma apa yang ditonjolkan ke pengguna (label_suppressed).
        $this->assertSame($cutaneousHorn->id, $finalResult->disease_id);
        $this->assertSame('refer', $finalResult->action);
        $this->assertSame([], $finalResult->recommendations_snapshot);
        $this->assertFalse($finalResult->label_suppressed);
        $this->assertStringContainsString('Tanduk kulit', $finalResult->explanation);

        $this->get(route('consultation.result', $consultation = Consultation::query()->firstOrFail()->session_code))
            ->assertOk();
    }

    /**
     * Regresi produksi 2026-08-31 (sesi DC-20260831-143321-ZIS3E): keluhan
     * bisul asli - "benjolan..., berisi nanah, dan terasa nyeri" - dengan
     * benar memicu tanda bahaya (SEVERE_PAIN + PUS_OR_WIDE_INFECTION) via F07,
     * yang MEMANG benar secara medis (nyeri hebat + nanah wajib diperiksa).
     * Tapi F07 melewati SELURUH pengecekan F04-F06 di resolveRule(), sehingga
     * nama penyakit rujukan tetap tampil dari CF tinggi yang cuma dibangun
     * dari 2 gejala generik - padahal kandidat visual sendiri (Jerawat/
     * Impetigo) tidak pernah menyebutnya sama sekali. Rujukannya tetap benar;
     * namanya yang harus disembunyikan. (Basal Cell Carcinoma dari sesi asli
     * digantikan Tanduk kulit pada 2026-08-31; skenario dan mekanismenya
     * tetap sama.)
     *
     * 2026-09-01: label_suppressed dinonaktifkan atas permintaan eksplisit
     * (lihat catatan di FusionDecisionService::decide()) - nama tetap tampil
     * sekarang, action refer dari F07 tidak berubah.
     */
    public function test_red_flag_referral_no_longer_suppresses_thin_evidence_disease_label(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $jerawat = Disease::query()->where('code', 'ACNE_VULGARIS')->firstOrFail();

        $this->mock(AiVisualService::class, function ($mock) use ($jerawat): void {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn([
                    'provider' => 'dermacerdas_ai',
                    'provider_status' => 'ok',
                    'is_valid_skin_image' => true,
                    'validation_status' => 'valid',
                    'outside_scope' => false,
                    'observed_description' => '',
                    'candidates' => [[
                        'disease' => $jerawat,
                        'provider' => 'dermacerdas_ai',
                        'visual_score' => 0.85,
                        'visual_reason' => 'Pustula pada dasar kemerahan khas untuk jerawat meradang.',
                        'raw_response' => [],
                    ]],
                    'suggested_symptom_codes' => [],
                    'warnings' => [],
                    'raw_response' => [],
                ]);

            $mock->shouldReceive('assessRedFlags')->andReturn([]);
        });

        $this->post(route('consultation.store'), [
            'visitor_name' => 'Pengguna Bisul Nyeri',
            'complaint_text' => 'benjolan pada kulit yang berwarna merah, berisi nanah, dan terasa nyeri.',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([
                'P2_TANDUK' => 1.0,
                'P3_TIDAKADA' => 1.0,
            ]),
            'red_flags' => $this->redFlags([
                'SEVERE_PAIN' => true,
                'PUS_OR_WIDE_INFECTION' => true,
            ]),
        ])->assertRedirect();

        $finalResult = ConsultationFinalResult::query()->firstOrFail();
        $cutaneousHorn = Disease::query()->where('code', 'CUTANEOUS_HORN')->firstOrFail();

        $this->assertSame('F07', $finalResult->fusion_rule_code);
        $this->assertSame('refer', $finalResult->action);
        // disease_id tetap tersimpan (Tanduk kulit menang CF teks) untuk audit
        // - yang berubah cuma apa yang ditonjolkan ke pengguna.
        $this->assertSame($cutaneousHorn->id, $finalResult->disease_id);
        $this->assertFalse($finalResult->label_suppressed);
        $this->assertStringContainsString('Tanduk kulit', $finalResult->explanation);

        $this->get(route('consultation.result', $consultation = Consultation::query()->firstOrFail()->session_code))
            ->assertOk();
    }

    /**
     * Regresi produksi lanjutan (sesi DC-20260831-150845-TQNB0): pengguna
     * dengan BENAR memilih "Tidak yakin/tidak cocok" untuk bentuk, permukaan,
     * rasa, DAN durasi (P2-P5) karena memang tidak ada yang menggambarkan
     * bisulnya - tapi CF Keloid tetap 87% dari 3 gejala KONTEKSTUAL saja
     * (lokasi "badan", sebaran "satu tempat", usia "remaja"), yang juga
     * kebetulan berlaku untuk bisul biasa. Ambang jumlah gejala saja (>=3)
     * tidak cukup di sini karena pas terpenuhi tanpa satu pun bukti
     * deskriptif - textualEvidenceIsThin() harus menolaknya lewat syarat
     * kelompok deskriptif, bukan cuma hitungan.
     *
     * 2026-09-01: label_suppressed dinonaktifkan atas permintaan eksplisit
     * (lihat catatan di FusionDecisionService::decide()) - nama tetap tampil
     * sekarang, action refer dari F07 tidak berubah.
     */
    public function test_contextual_only_symptoms_without_any_descriptive_match_no_longer_suppress_label(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $jerawat = Disease::query()->where('code', 'ACNE_VULGARIS')->firstOrFail();

        $this->mock(AiVisualService::class, function ($mock) use ($jerawat): void {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn([
                    'provider' => 'dermacerdas_ai',
                    'provider_status' => 'ok',
                    'is_valid_skin_image' => true,
                    'validation_status' => 'valid',
                    'outside_scope' => false,
                    'observed_description' => '',
                    'candidates' => [[
                        'disease' => $jerawat,
                        'provider' => 'dermacerdas_ai',
                        'visual_score' => 0.85,
                        'visual_reason' => 'Pustula (kepala putih) pada bintil merah khas jerawat.',
                        'raw_response' => [],
                    ]],
                    'suggested_symptom_codes' => [],
                    'warnings' => [],
                    'raw_response' => [],
                ]);

            $mock->shouldReceive('assessRedFlags')->andReturn([]);
        });

        $this->post(route('consultation.store'), [
            'visitor_name' => 'Pengguna Bisul Tiga',
            'complaint_text' => 'benjolan pada kulit yang berwarna merah, berisi nanah, dan terasa nyeri.',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([
                'P1_BADAN' => 1.0,
                'P6_SETEMPAT' => 1.0,
                'P9_REMAJA' => 1.0,
            ]),
            'red_flags' => $this->redFlags([
                'SEVERE_PAIN' => true,
                'PUS_OR_WIDE_INFECTION' => true,
            ]),
        ])->assertRedirect();

        $finalResult = ConsultationFinalResult::query()->firstOrFail();
        $keloid = Disease::query()->where('code', 'KELOID')->firstOrFail();

        $this->assertSame('F07', $finalResult->fusion_rule_code);
        $this->assertSame('refer', $finalResult->action);
        $this->assertSame($keloid->id, $finalResult->disease_id);
        $this->assertFalse($finalResult->label_suppressed);
        $this->assertStringContainsString('Keloid', $finalResult->explanation);
    }

    /**
     * F13 (2026-09-01, rancangan pengganti gate visual yang dinonaktifkan):
     * pengguna dengan jujur menjawab "Tidak yakin/tidak ada yang cocok" untuk
     * mayoritas gejala deskriptif (bentuk, permukaan, rasa) - pengakuan
     * eksplisit bahwa profil gejalanya tidak cocok pola manapun di 16
     * penyakit. Sinyal ini murni dari jawaban pengguna sendiri, jadi tetap
     * berfungsi walau visual tersedia dan valid (beda dari gate lama yang
     * bergantung status provider visual).
     */
    public function test_majority_descriptive_tidakyakin_answers_trigger_f13(): void
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
                    'outside_scope' => false,
                    'observed_description' => '',
                    'candidates' => [],
                    'suggested_symptom_codes' => [],
                    'warnings' => [],
                    'raw_response' => [],
                ]);
        });

        $this->post(route('consultation.store'), [
            'visitor_name' => 'Pengguna Gejala Tidak Cocok',
            'complaint_text' => 'Ada keluhan di kulit tapi tidak ada satu pun pilihan yang benar-benar menggambarkannya.',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([
                'P1_BADAN' => 1.0,
                'P2_TIDAKYAKIN' => 1.0,
                'P3_TIDAKYAKIN' => 1.0,
                'P4_TIDAKYAKIN' => 1.0,
                'P5_MINGGU' => 1.0,
            ]),
            'red_flags' => $this->redFlags([]),
        ])->assertRedirect();

        $finalResult = ConsultationFinalResult::query()->firstOrFail();

        $this->assertSame('F13', $finalResult->fusion_rule_code);
        $this->assertSame('refer', $finalResult->action);
        $this->assertSame([], $finalResult->recommendations_snapshot);
        $this->assertTrue($finalResult->label_suppressed);
        $this->assertStringContainsString('tidak cocok', $finalResult->explanation);

        $this->get(route('consultation.result', $consultation = Consultation::query()->firstOrFail()->session_code))
            ->assertOk();
    }

    /**
     * Satu jawaban "tidak yakin" saja (mis. cuma satu ciri lesi yang memang
     * tidak khas) tidak boleh memicu F13 - itu bisa saja cuma satu ciri yang
     * ambigu, bukan sinyal seluruh profil gejala meleset.
     */
    public function test_single_tidakyakin_answer_does_not_trigger_f13(): void
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
                    'outside_scope' => false,
                    'observed_description' => '',
                    'candidates' => [],
                    'suggested_symptom_codes' => [],
                    'warnings' => [],
                    'raw_response' => [],
                ]);
        });

        $this->post(route('consultation.store'), [
            'visitor_name' => 'Pengguna Satu Tidak Yakin',
            'complaint_text' => 'Bercak bersisik di badan, gatal, sudah beberapa minggu.',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([
                'P1_BADAN' => 1.0,
                'P2_TIDAKYAKIN' => 1.0,
                'P3_HALUS' => 1.0,
                'P4_GATAL' => 1.0,
                'P5_MINGGU' => 1.0,
            ]),
            'red_flags' => $this->redFlags([]),
        ])->assertRedirect();

        $finalResult = ConsultationFinalResult::query()->firstOrFail();

        $this->assertNotSame('F13', $finalResult->fusion_rule_code);
        $this->assertFalse($finalResult->label_suppressed);
    }

    /**
     * Regresi produksi (audit 2026-08-31, kode DC-20260831-203327-FGJ3B):
     * foto bisul (di luar 16 penyakit) dijawab 2 dari 4 kelompok deskriptif
     * "Tidak yakin/tidak cocok" (P2, P3) dan 2 dijawab nyata (P4 nyeri,
     * P5 beberapa hari). Ambang awal F13 butuh LEBIH DARI separuh, jadi 2/4
     * (pas separuh) lolos ke CF biasa - hasilnya nama "Cacar ular" tampil
     * dengan CF 96,8% dari cuma 2 gejala generik, padahal fotonya bukan
     * salah satu dari 16 penyakit. Ambang diturunkan jadi "separuh atau
     * lebih" supaya kasus pas-separuh ini juga tertangkap.
     */
    public function test_exactly_half_descriptive_tidakyakin_answers_trigger_f13(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);

        $this->mock(AiVisualService::class, function ($mock): void {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn([
                    'provider' => 'dermacerdas_ai',
                    'provider_status' => 'dataset_fallback',
                    'is_valid_skin_image' => true,
                    'validation_status' => 'degraded',
                    'outside_scope' => false,
                    'observed_description' => '',
                    'candidates' => [],
                    'suggested_symptom_codes' => [],
                    'warnings' => [],
                    'raw_response' => [],
                ]);
        });

        $this->post(route('consultation.store'), [
            'visitor_name' => 'Pengguna Bisul Setengah Tidak Yakin',
            'complaint_text' => 'Ada bisul di kulit, bengkak merah, nyeri, tidak ada pilihan yang benar-benar cocok.',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([
                'P1_BADAN' => 1.0,
                'P2_TIDAKYAKIN' => 1.0,
                'P3_TIDAKYAKIN' => 1.0,
                'P4_NYERI' => 1.0,
                'P5_HARI' => 1.0,
                'P9_DEWASA' => 1.0,
            ]),
            'red_flags' => $this->redFlags([]),
        ])->assertRedirect();

        $finalResult = ConsultationFinalResult::query()->firstOrFail();

        $this->assertSame('F13', $finalResult->fusion_rule_code);
        $this->assertSame('refer', $finalResult->action);
        $this->assertTrue($finalResult->label_suppressed);
    }

    /**
     * Fase 1 (margin untuk F08): kandidat visual di luar 16 penyakit (tanpa
     * basis CF) tidak boleh memaksakan namanya lewat F08 kalau cuma unggul
     * tipis dari kandidat visual lain - model yang skornya menyebar rata ke
     * beberapa kandidat (0,65 vs 0,55, selisih 0,10 < ambang 0,15) berarti
     * tidak benar-benar yakin, sejalan dengan gerbang margin di sisi teks.
     */
    public function test_visual_only_finding_does_not_override_when_visual_margin_is_too_thin(): void
    {
        Storage::fake('public');
        $this->seed(DatabaseSeeder::class);
        $outOfScopeGroup = $this->createOutOfScopeGroupDisease();
        $tineaCorporis = Disease::query()->where('code', 'TINEA_CORPORIS')->firstOrFail();

        $this->mock(AiVisualService::class, function ($mock) use ($outOfScopeGroup, $tineaCorporis): void {
            $mock->shouldReceive('analyze')
                ->once()
                ->andReturn([
                    'provider' => 'dermacerdas_ai',
                    'provider_status' => 'ok',
                    'is_valid_skin_image' => true,
                    'validation_status' => 'valid',
                    'candidates' => [
                        [
                            'disease' => $outOfScopeGroup,
                            'provider' => 'dermacerdas_ai',
                            'visual_score' => 0.65,
                            'visual_reason' => 'Menyerupai lesi virus jinak.',
                            'raw_response' => [],
                        ],
                        [
                            'disease' => $tineaCorporis,
                            'provider' => 'dermacerdas_ai',
                            'visual_score' => 0.55,
                            'visual_reason' => 'Tapi juga bisa jadi kurap.',
                            'raw_response' => [],
                        ],
                    ],
                    'suggested_symptom_codes' => [],
                    'warnings' => [],
                    'raw_response' => [],
                ]);
        });

        $this->post(route('consultation.store'), [
            'visitor_name' => 'Pengguna Margin Visual Tipis',
            'complaint_text' => 'Ada bercak kulit yang tidak biasa, tidak yakin apa penyebabnya.',
            'consent' => '1',
            'image' => UploadedFile::fake()->image('skin.png', 320, 320),
            'symptoms' => $this->symptoms([]),
            'red_flags' => $this->redFlags([]),
        ])->assertRedirect();

        $finalResult = ConsultationFinalResult::query()->firstOrFail();

        // Margin tipis (0,10 < 0,15) berarti F08 tidak boleh menang - kalau
        // ini gagal, disease_id akan sama dengan $outOfScopeGroup->id.
        $this->assertNotSame('F08', $finalResult->fusion_rule_code);
        $this->assertNotSame($outOfScopeGroup->id, $finalResult->disease_id);
    }

    /**
     * Golongan klinis DatasetDiseaseMapper (mis. VIRAL_EDUCATION untuk
     * kutil/molluscum) tidak pernah dibuat lewat DatabaseSeeder biasa kecuali
     * kelas datasetnya sudah dipetakan sebelumnya. Dibuat langsung di sini
     * dengan bentuk yang persis sama dengan DatasetDiseaseMapper::payloadFor():
     * aktif, tanpa satu pun aturan gejala/CF.
     */
    private function createOutOfScopeGroupDisease(): Disease
    {
        return Disease::query()->updateOrCreate(
            ['code' => 'VIRAL_EDUCATION'],
            [
                'name' => 'Benign viral lesions education',
                'slug' => 'viral-education',
                'name_indonesian' => 'Lesi virus jinak untuk edukasi',
                'description' => 'Kelompok lesi virus jinak seperti kutil atau molluscum yang umumnya tidak menjadi target obat OTC otomatis.',
                'source_note' => 'DermNet A-Z viral infections and benign lesions: https://dermnetnz.org/topics',
                'severity_scope' => 'mild',
                'default_action' => 'educate_only',
                'is_active' => true,
            ]
        );
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
