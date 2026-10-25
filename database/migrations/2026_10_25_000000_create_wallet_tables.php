<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete(); $table->unsignedBigInteger('balance_minor')->default(0); $table->timestamps();
        });
        Schema::create('wallet_limits', function (Blueprint $table) {
            $table->id(); $table->foreignId('wallet_id')->unique()->constrained()->cascadeOnDelete(); $table->unsignedBigInteger('per_transaction_minor')->default(500000); $table->unsignedBigInteger('daily_minor')->default(2000000); $table->timestamps();
        });
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id(); $table->string('reference', 24)->unique(); $table->foreignId('idempotency_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('idempotency_key', 100); $table->foreignId('source_wallet_id')->nullable()->constrained('wallets')->restrictOnDelete();
            $table->foreignId('destination_wallet_id')->nullable()->constrained('wallets')->restrictOnDelete();
            $table->string('type', 20); $table->unsignedBigInteger('amount_minor'); $table->string('status', 20)->default('pending');
            $table->string('description', 160)->nullable(); $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('reviewed_at')->nullable(); $table->timestamps();
            $table->unique(['idempotency_user_id', 'idempotency_key']); $table->index(['status', 'created_at']);
        });
        Schema::create('wallet_transaction_events', function (Blueprint $table) {
            $table->id(); $table->foreignId('wallet_transaction_id')->constrained()->restrictOnDelete(); $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40); $table->string('from_status', 20)->nullable(); $table->string('to_status', 20); $table->text('note')->nullable(); $table->timestamp('created_at')->useCurrent();
            $table->index(['wallet_transaction_id', 'created_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('wallet_transaction_events'); Schema::dropIfExists('wallet_transactions'); Schema::dropIfExists('wallet_limits'); Schema::dropIfExists('wallets');
    }
};
