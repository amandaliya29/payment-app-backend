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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['bank', 'credit_upi'])->default('bank');
            $table->unsignedBigInteger('from_account_id')->nullable();
            $table->string('from_upi_id', 50)->nullable();
            $table->unsignedBigInteger('to_account_id')->nullable();
            $table->string('to_upi_id', 50)->nullable();
            $table->decimal('amount', 18, 2);
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->index('status', 'idx_status');
            $table->index('created_at', 'idx_created_at');
            $table->index('type', 'idx_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
