<?php

namespace Tests\Feature;

use App\Models\DatasetClassMapping;
use App\Models\Disease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatasetLinkDiseasesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_create_or_link_records(): void
    {
        DatasetClassMapping::query()->create([
            'dataset_class_id' => 1,
            'dataset_class_name' => 'Acne_Vulgaris',
            'scope_category' => 'edukasi',
            'default_action' => 'educate_only',
        ]);

        $this->artisan('dataset:link-diseases --dry-run')
            ->assertSuccessful();

        $this->assertDatabaseCount('diseases', 0);
        $this->assertDatabaseHas('dataset_class_mappings', [
            'dataset_class_name' => 'Acne_Vulgaris',
            'disease_id' => null,
            'scope_category' => 'edukasi',
        ]);
    }

    public function test_force_creates_disease_and_links_dataset_mapping(): void
    {
        DatasetClassMapping::query()->create([
            'dataset_class_id' => 1,
            'dataset_class_name' => 'Acne_Vulgaris',
            'scope_category' => 'edukasi',
            'default_action' => 'educate_only',
        ]);

        $this->artisan('dataset:link-diseases --force')
            ->assertSuccessful();

        $disease = Disease::query()->where('code', 'ACNE_FOLLICULAR')->firstOrFail();
        $mapping = DatasetClassMapping::query()->where('dataset_class_name', 'Acne_Vulgaris')->firstOrFail();

        $this->assertSame($disease->id, $mapping->disease_id);
        $this->assertSame('swamedikasi', $mapping->scope_category);
        $this->assertSame('Akne & folikular ringan', $mapping->clinical_group);
        $this->assertNotNull($mapping->source_note);
    }

    public function test_command_fails_when_a_production_class_is_unmapped(): void
    {
        DatasetClassMapping::query()->create([
            'dataset_class_id' => 1,
            'dataset_class_name' => 'Unknown_Class',
            'scope_category' => 'edukasi',
            'default_action' => 'educate_only',
        ]);

        $this->artisan('dataset:link-diseases --dry-run')
            ->assertFailed();
    }
}
