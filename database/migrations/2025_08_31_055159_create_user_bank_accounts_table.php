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
        Schema::create('user_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('bank_id');
            $table->string('account_number');
            $table->string('ifsc_code', 20);
            $table->string('account_holder_name');
            $table->boolean('is_primary')->default(false);
            $table->string('aadhaar_number');
            $table->string('pan_number');
            $table->string('upi_id', 100);
            $table->timestamps();

            // Faster lookup
            $table->index('user_id');
            $table->index('bank_id');
            $table->index('upi_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_bank_accounts');
    }
};
