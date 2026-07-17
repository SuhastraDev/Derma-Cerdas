<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diseases', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('name_indonesian')->nullable();
            $table->text('description')->nullable();
            $table->string('severity_scope')->default('mild')->index();
            $table->string('default_action')->default('recommend_otc')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('symptoms', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('question');
            $table->string('input_type')->default('scale');
            $table->boolean('is_red_flag_candidate')->default(false)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('disease_symptom_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('disease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('symptom_id')->constrained()->cascadeOnDelete();
            $table->decimal('mb', 4, 2)->default(0);
            $table->decimal('md', 4, 2)->default(0);
            $table->decimal('expert_cf', 4, 2)->default(0);
            $table->boolean('is_required')->default(false);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['disease_id', 'symptom_id']);
        });

        Schema::create('medicines', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('category')->index();
            $table->string('dosage_form')->nullable();
            $table->text('usage_instruction')->nullable();
            $table->text('warnings')->nullable();
            $table->text('source_note')->nullable();
            $table->boolean('is_limited_otc')->default(true)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('disease_medicine_recommendations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('disease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->text('recommendation_note')->nullable();
            $table->unsignedSmallInteger('priority')->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['disease_id', 'medicine_id']);
        });

        Schema::create('red_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->text('question');
            $table->text('action_message');
            $table->string('severity')->default('refer')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('dataset_class_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('dataset_class_id')->unique();
            $table->string('dataset_class_name')->unique();
            $table->string('nama_indonesia')->nullable();
            $table->string('scope_category')->default('exclude')->index();
            $table->boolean('boleh_rekomendasi_obat')->default(false)->index();
            $table->string('default_action')->default('refer')->index();
            $table->foreignId('disease_id')->nullable()->constrained()->nullOnDelete();
            $table->text('risk_note')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->jsonb('value');
            $table->string('group')->default('system')->index();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('consultations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_code')->unique();
            $table->string('image_path')->nullable();
            $table->string('status')->default('draft')->index();
            $table->decimal('final_score', 5, 2)->nullable();
            $table->string('final_action')->nullable()->index();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('consultation_symptoms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('symptom_id')->constrained()->cascadeOnDelete();
            $table->decimal('user_cf', 4, 2)->default(0);
            $table->boolean('selected')->default(false);
            $table->timestamps();

            $table->unique(['consultation_id', 'symptom_id']);
        });

        Schema::create('consultation_visual_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->default('gemini')->index();
            $table->foreignId('disease_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('visual_score', 5, 2)->default(0);
            $table->text('visual_reason')->nullable();
            $table->jsonb('raw_response')->nullable();
            $table->timestamps();
        });

        Schema::create('consultation_final_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('disease_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('textual_cf', 5, 2)->default(0);
            $table->decimal('visual_score', 5, 2)->default(0);
            $table->decimal('fusion_score', 5, 2)->default(0);
            $table->string('action')->index();
            $table->text('explanation')->nullable();
            $table->jsonb('recommendations_snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('consultation_red_flags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('red_flag_id')->constrained()->cascadeOnDelete();
            $table->boolean('detected')->default(false)->index();
            $table->timestamps();

            $table->unique(['consultation_id', 'red_flag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_red_flags');
        Schema::dropIfExists('consultation_final_results');
        Schema::dropIfExists('consultation_visual_results');
        Schema::dropIfExists('consultation_symptoms');
        Schema::dropIfExists('consultations');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('dataset_class_mappings');
        Schema::dropIfExists('red_flags');
        Schema::dropIfExists('disease_medicine_recommendations');
        Schema::dropIfExists('medicines');
        Schema::dropIfExists('disease_symptom_rules');
        Schema::dropIfExists('symptoms');
        Schema::dropIfExists('diseases');
    }
};
