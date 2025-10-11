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
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'aadhar_number')) {
                $table->string('aadhar_number')->nullable()->after('firebase_uid');
            }
            if (!Schema::hasColumn('users', 'pan_number')) {
                $table->string('pan_number')->nullable()->after('aadhar_number');
            }
        });

        DB::statement("
            UPDATE users
            INNER JOIN (
                SELECT 
                    user_id,
                    aadhaar_number,
                    pan_number
                FROM user_bank_accounts AS uba
                WHERE id = (
                    SELECT MIN(id)
                    FROM user_bank_accounts
                    WHERE user_id = uba.user_id
                )
            ) AS first_account
            ON users.id = first_account.user_id
            SET 
                users.aadhar_number = COALESCE(users.aadhar_number, first_account.aadhaar_number),
                users.pan_number = COALESCE(users.pan_number, first_account.pan_number)
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'aadhar_number')) {
                $table->dropColumn('aadhar_number');
            }
            if (Schema::hasColumn('users', 'pan_number')) {
                $table->dropColumn('pan_number');
            }
        });
    }
};
