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
            aiSuggestedCodes: ['G11'],
            min: 5,
            max: 8,
        );

        $this->assertSame('G11', $questions->first()?->code);
        $this->assertGreaterThanOrEqual(5, $questions->count());
    }
}
