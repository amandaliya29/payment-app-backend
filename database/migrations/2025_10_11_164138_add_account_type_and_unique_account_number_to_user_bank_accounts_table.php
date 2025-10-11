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
            $table->enum('account_type', ['saving', 'current', 'salary', 'fixed_deposit'])
                ->default('saving')
                ->after('account_holder_name');

            $table->unique('account_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_bank_accounts', function (Blueprint $table) {
            $table->dropUnique(['account_number']);
            $table->dropColumn('account_type');
        });
    }
};
