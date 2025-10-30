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
            if (Schema::hasColumn('user_bank_accounts', 'ifsc_code')) {
                $table->dropColumn('ifsc_code');
            }

            // Add a reference to ifsc_details table
            $table->unsignedBigInteger('ifsc_detail_id')->nullable()->after('bank_id');
        });

        $userAccounts = DB::table('user_bank_accounts')->get();

        foreach ($userAccounts as $account) {
            // Get a random IFSC detail for the same bank
            $ifscDetail = DB::table('ifsc_details')
                ->where('bank_id', $account->bank_id)
                ->inRandomOrder()
                ->first();

            // If found, assign it to user_bank_accounts
            DB::table('user_bank_accounts')
                ->where('id', $account->id)
                ->update(['ifsc_detail_id' => $ifscDetail->id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_bank_accounts', function (Blueprint $table) {
            $table->dropColumn('ifsc_detail_id');
            $table->string('ifsc_code', 20)->nullable();
        });
    }
};
