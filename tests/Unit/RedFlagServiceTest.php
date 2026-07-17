<?php

namespace Tests\Unit;

use App\Models\RedFlag;
use App\Services\RedFlagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedFlagServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_detects_active_red_flags_by_code(): void
    {
        RedFlag::query()->create([
            'code' => 'FEVER_HIGH',
            'question' => 'Apakah disertai demam tinggi?',
            'action_message' => 'Segera konsultasi ke dokter.',
            'severity' => 'refer',
            'is_active' => true,
        ]);

        RedFlag::query()->create([
            'code' => 'OLD_INACTIVE_FLAG',
            'question' => 'Flag lama?',
            'action_message' => 'Tidak dipakai.',
            'severity' => 'refer',
            'is_active' => false,
        ]);

        $result = (new RedFlagService())->evaluate([
            'FEVER_HIGH' => true,
            'OLD_INACTIVE_FLAG' => true,
        ]);

        $this->assertTrue($result['has_red_flags']);
        $this->assertSame('refer', $result['action']);
        $this->assertCount(1, $result['detected']);
        $this->assertSame('FEVER_HIGH', $result['detected'][0]['code']);
    }

    public function test_it_allows_flow_when_no_red_flags_are_positive(): void
    {
        RedFlag::query()->create([
            'code' => 'SEVERE_PAIN',
            'question' => 'Apakah sangat nyeri?',
            'action_message' => 'Periksa langsung.',
            'severity' => 'refer',
            'is_active' => true,
        ]);

        $result = (new RedFlagService())->evaluate([
            'SEVERE_PAIN' => false,
        ]);

        $this->assertFalse($result['has_red_flags']);
        $this->assertSame('continue', $result['action']);
        $this->assertSame([], $result['detected']);
    }
}
