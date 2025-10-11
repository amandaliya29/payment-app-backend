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
        Schema::table('user_bank_credit_upis', function (Blueprint $table) {
            $table->string('pin_code')->after('available_credit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_bank_credit_upis', function (Blueprint $table) {
            $table->dropColumn('pin_code');
        });
    }
};
