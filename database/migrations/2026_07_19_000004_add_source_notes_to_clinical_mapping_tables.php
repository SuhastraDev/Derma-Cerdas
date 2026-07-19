<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diseases', function (Blueprint $table): void {
            $table->text('source_note')->nullable()->after('description');
        });

        Schema::table('dataset_class_mappings', function (Blueprint $table): void {
            $table->string('clinical_group')->nullable()->after('nama_indonesia')->index();
            $table->text('source_note')->nullable()->after('risk_note');
        });
    }

    public function down(): void
    {
        Schema::table('dataset_class_mappings', function (Blueprint $table): void {
            $table->dropColumn(['clinical_group', 'source_note']);
        });

        Schema::table('diseases', function (Blueprint $table): void {
            $table->dropColumn('source_note');
        });
    }
};
