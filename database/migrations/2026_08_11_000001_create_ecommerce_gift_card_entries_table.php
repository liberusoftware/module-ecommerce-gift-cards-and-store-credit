<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **The ledger. Every number this module reports is built from these rows.**
 *
 * Append-only, enforced in three layers — the model's `updating` and `deleting`
 * events throw, `LedgerBuilder` refuses the mass operations those events never
 * see, and `GiftCardEntryPolicy` refuses every write ability by name. A
 * correction is a **new row**; that is not a workaround for the restriction, it
 * is what a ledger is.
 *
 * The host's `gift_card_transactions` is the shape this replaces. It had
 * `decimal('amount', 10, 2)` with "negative for usage, positive for refunds" in a
 * comment, and it sat beside a mutable `balance` column that was the number
 * anybody actually read.
 *
 * ### Money
 *
 * Integer minor units, always. `(int) (19.99 * 100)` is 1998, and a penny per
 * transaction, silently, forever, is what a `decimal` column buys. `SchemaTest`
 * asserts no `decimal`, `float`, `double`, `numeric` or `real` column exists in
 * either table.
 *
 * `amount_minor` is **signed**, and only for `adjusted`: an operator correcting a
 * card downwards writes a negative one. Every other kind is positive and the
 * direction is the kind's, not the sign's — a ledger whose direction lives in a
 * sign is a ledger where a missing minus is a gift.
 *
 * ### Currency rides on the entry
 *
 * Every entry must match the account's currency; the write path refuses one that
 * does not, with `CurrencyMismatch`. It is stored anyway, on every row, because
 * if a row in another currency ever exists — a migration, a second writer, a
 * restore — the fold has to be able to *see* that rather than adding unlike
 * units. It excludes it from the sums and counts it, and
 * `AccountState::needsReconciliation()` is the queue.
 *
 * ### `reason_code` is a code, never prose
 *
 * Free text beside money is where a customer's email address ends up — a finding
 * wave 4 made and wave 5 acted on twice. An adjustment carries a short code the
 * deployment defines (`goodwill`, `writeoff`, `correction`), and there is no
 * `note` column on either table.
 *
 * ### No code, in any form, ever appears here
 *
 * Not the code, not the normalised code, not the lookup index, not the hash.
 * `SchemaTest` asserts every one of those names absent from this table. A ledger
 * row is read by reporting, exported to spreadsheets and shown on panels, and a
 * bearer credential in any of those places is cash in a place nobody is guarding.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('ecommerce_gift_card_entries', function (Blueprint $table) {
            $table->id();

            // The one foreign key in this module, and it points at this module's
            // own table. An entry means nothing without its account, so it goes
            // when the account goes.
            $table->foreignId('account_id')->constrained('ecommerce_gift_card_accounts')->cascadeOnDelete();

            $table->string('kind')->index();

            // The idempotency guarantee, at the index. Two workers processing the
            // same retry both insert; the database picks one.
            $table->string('entry_key')->unique();
            $table->char('entry_hash', 64);

            // Signed, and only ever negative for an adjustment.
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->unsignedTinyInteger('currency_exponent')->default(2);

            // Why a credit exists — a refund, a reversal of a redemption, a
            // top-up. Null on everything else.
            $table->string('origin')->nullable()->index();
            // Whatever the caller is settling against: an order id, a refund's
            // reference, a checkout reference. An opaque string. **This is where
            // the two modules of this wave meet**: Refunds decides an amount and a
            // destination, and the reference it decided lands here. Neither module
            // imports the other.
            $table->string('source_reference')->nullable()->index();
            // A short code, never prose.
            $table->string('reason_code')->nullable();

            // Identifiers, unconstrained, exactly as on the account.
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->unsignedBigInteger('recorded_by')->nullable();

            $table->timestamp('occurred_at')->index();

            $table->timestamps();

            // The read the fold performs, every time.
            $table->index(['account_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_gift_card_entries');
    }
};
