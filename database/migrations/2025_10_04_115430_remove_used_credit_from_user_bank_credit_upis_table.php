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
        // Drop old column in a separate statement
        Schema::table('user_bank_credit_upis', function (Blueprint $table) {
            $table->dropColumn('used_credit');
        });

        // Add new column in another statement
        Schema::table('user_bank_credit_upis', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->after('id');
            $table->string('upi_id', 100)->after('bank_account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop upi_id first
        Schema::table('user_bank_credit_upis', function (Blueprint $table) {
            $table->dropColumn(['user_id', 'upi_id']);
        });

        // Restore used_credit
        Schema::table('user_bank_credit_upis', function (Blueprint $table) {
            $table->decimal('used_credit', 10, 2)->default(0)->after('id');
        });
    }
};
