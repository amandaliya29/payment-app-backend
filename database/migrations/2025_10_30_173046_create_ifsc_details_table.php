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
        Schema::create('ifsc_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bank_id');
            $table->string('ifsc_code', 20)->unique();
            $table->string('branch_name');
            $table->string('branch_address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->timestamps();

            $table->index(['ifsc_code', 'branch_name', 'city']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ifsc_details');
    }
};
