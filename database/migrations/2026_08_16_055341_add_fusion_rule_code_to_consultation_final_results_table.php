<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('consultation_final_results', function (Blueprint $table) {
            $table->string('fusion_rule_code')->nullable()->after('action')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('consultation_final_results', function (Blueprint $table) {
            $table->dropColumn('fusion_rule_code');
        });
    }
};
