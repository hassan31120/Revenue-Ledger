<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();

            $table->enum('account_type', ['instructor', 'platform']);

            $table->foreignId('instructor_id')->nullable()->constrained()->restrictOnDelete();

            $table->enum('entry_type', [
                'earning',
                'platform_fee',
                'payout',
                'refund_adjustment',
                'manual_adjustment',
            ]);

            $table->bigInteger('amount_minor');
            $table->char('currency', 3);

            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');

            $table->string('idempotency_key')->unique();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['instructor_id', 'id']);

            $table->index(['source_type', 'source_id']);

            $table->index('created_at');
        });

        DB::statement("
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_account_consistent CHECK (
                (account_type = 'instructor' AND instructor_id IS NOT NULL)
                OR
                (account_type = 'platform' AND instructor_id IS NULL)
            )
        ");

        DB::unprepared("
            CREATE TRIGGER ledger_entries_block_update
            BEFORE UPDATE ON ledger_entries
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'ledger_entries is append-only: rows cannot be updated. Post a correcting entry instead.';
            END
        ");

        DB::unprepared("
            CREATE TRIGGER ledger_entries_block_delete
            BEFORE DELETE ON ledger_entries
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                SET MESSAGE_TEXT = 'ledger_entries is append-only: rows cannot be deleted. Post a reversing entry instead.';
            END
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_block_update');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_block_delete');

        Schema::dropIfExists('ledger_entries');
    }
};
