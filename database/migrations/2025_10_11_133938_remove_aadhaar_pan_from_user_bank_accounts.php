<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_bank_accounts', function (Blueprint $table) {
            $table->dropColumn(['aadhaar_number', 'pan_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_bank_accounts', function (Blueprint $table) {
            $table->string('aadhaar_number');
            $table->string('pan_number');
        });
    }
};
