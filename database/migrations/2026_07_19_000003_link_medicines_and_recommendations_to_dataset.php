<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table): void {
            $table->foreignId('dataset_class_mapping_id')
                ->nullable()
                ->after('id')
                ->constrained('dataset_class_mappings')
                ->nullOnDelete();
            $table->string('image_path')->nullable()->after('dosage_form');
        });

        Schema::table('disease_medicine_recommendations', function (Blueprint $table): void {
            $table->foreignId('dataset_class_mapping_id')
                ->nullable()
                ->after('medicine_id')
                ->constrained('dataset_class_mappings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('disease_medicine_recommendations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('dataset_class_mapping_id');
        });

        Schema::table('medicines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('dataset_class_mapping_id');
            $table->dropColumn('image_path');
        });
    }
};
