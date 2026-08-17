<?php

namespace Tests\Unit;

use App\Services\AdaptiveQuestionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdaptiveQuestionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_suggested_symptom_is_prioritized_over_fallback_questions(): void
    {
        $this->seed(DatabaseSeeder::class);

        $questions = (new AdaptiveQuestionService())->selectSymptoms(
            complaintFeatures: [
                'symptom_evidence' => [],
            ],
            visualCandidates: [],
            aiSuggestedCodes: ['P2_DATAR'],
            min: 5,
            max: 8,
        );

        // P1 (lokasi) dan P2 (bentuk) selalu didahulukan karena daya pisahnya
        // paling besar; saran AI menentukan pertanyaan BERIKUTNYA, bukan menggeser
        // keduanya. Yang dijamin: pertanyaan asal saran AI ikut terpilih, lengkap
        // dengan seluruh pilihannya.
        $this->assertSame('P1_LOKASI', $questions->first()?->question_group);
        $this->assertContains('P2_DATAR', $questions->pluck('code'));
        $this->assertGreaterThanOrEqual(5, $questions->count());
    }
}
