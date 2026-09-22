<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The financial source of truth. Append-only.
     *
     * Everything the system reports — earned, paid, outstanding — is derived by
     * summing this table. Nothing else is authoritative, and nothing in here is
     * ever updated or deleted: a correction is a new row that refers back to the
     * event it corrects.
     */
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();

            // Two kinds of account take part. The platform's commission is a real
            // ledger entry rather than an implied remainder, which is what makes
            // "every minor unit is accounted for" a checkable claim.
            $table->enum('account_type', ['instructor', 'platform']);

            // Restricted, not cascaded: an instructor with financial history must
            // not be deletable, or the ledger would silently lose entries.
            $table->foreignId('instructor_id')->nullable()->constrained()->restrictOnDelete();

            $table->enum('entry_type', [
                'earning',            // + instructor share of a subscription
                'platform_fee',       // + platform commission
                'payout',             // - money sent to an instructor and CONFIRMED
                'refund_adjustment',  // -/+ clawback when a subscription is refunded
                'manual_adjustment',  // -/+ deliberate correction, with a reason
            ]);

            // SIGNED. Credits to the account are positive, debits negative.
            // Minor units, never a decimal type.
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);

            // What caused this entry: subscription / refund / payout + its id.
            // Every row can be walked back to the business event behind it.
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id');

            // The identity of the logical financial event, derived deterministically
            // from that event — never random. Re-running the same work recomputes the
            // same key and the unique index below rejects the duplicate.
            //
            // This single constraint is what makes retries safe across the system.
            $table->string('idempotency_key')->unique();

            // Written once. There is deliberately no updated_at: nothing updates.
            $table->timestamp('created_at')->useCurrent();

            // Balance recomputation for one instructor, and the payout cutoff lookup.
            $table->index(['instructor_id', 'id']);

            // Audit: "show me every entry this refund produced".
            $table->index(['source_type', 'source_id']);

            $table->index('created_at');
        });

        // An instructor entry must name an instructor; a platform entry must not.
        // Without this, a NULL instructor_id on an 'earning' row would quietly
        // vanish from every per-instructor balance while still counting globally.
        DB::statement("
            ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_account_consistent CHECK (
                (account_type = 'instructor' AND instructor_id IS NOT NULL)
                OR
                (account_type = 'platform' AND instructor_id IS NULL)
            )
        ");

        // Immutability enforced by the database, not by convention.
        //
        // An ORM callback, a trait, or a code review can all be bypassed by a
        // migration, a console one-liner, or a well-meaning fix in production.
        // A trigger cannot.
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
