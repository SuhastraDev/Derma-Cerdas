<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table): void {
            $table->text('complaint_text')->nullable()->after('visitor_name');
            $table->jsonb('complaint_features')->nullable()->after('complaint_text');
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table): void {
            $table->dropColumn(['complaint_text', 'complaint_features']);
        });
    }
};
