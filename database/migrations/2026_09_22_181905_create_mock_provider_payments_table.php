<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The mock provider's OWN records — conceptually the external system's
     * database, not part of this application's ledger.
     *
     * It exists so the mock can behave like a real payment rail rather than a
     * stub: after a timeout, the provider must be able to truthfully answer "yes,
     * I already moved that money", which is impossible without provider-side
     * state. Nothing in the financial core reads this table; it is only ever
     * reached through the PaymentProvider interface.
     */
    public function up(): void
    {
        Schema::create('mock_provider_payments', function (Blueprint $table) {
            // The key the caller supplied. Primary key, so the provider physically
            // cannot record the same logical payment twice — which is what makes a
            // retry after a timeout safe.
            $table->string('idempotency_key')->primary();

            $table->string('reference')->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('destination_account');
            $table->string('status', 16);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_provider_payments');
    }
};
