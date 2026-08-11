<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **A balance a merchant owes, and — for a gift card — the bearer credential that
 * spends it.**
 *
 * One table for both gift cards and store credit. They are the same ledger with
 * different issue paths: a gift card is bought, has a code and belongs to whoever
 * holds it; store credit is granted, has no code and belongs to a customer. Every
 * other fact about them is identical, and the arithmetic certainly is. Two tables
 * would mean two copies of the fold, which is two answers waiting to disagree.
 * `kind` tells them apart and `AccountKind::isBearer()` is the one behaviour that
 * turns on it.
 *
 * ### There is no `code` column, and that is the whole point
 *
 * The host's `gift_cards.code` is `string(16)->unique()`, in plain text. A gift
 * card code is a **bearer credential** — whoever has it has the money — so that
 * column means a database read, a leaked backup, a logged slow query or any staff
 * member with table access is holding cash.
 *
 * Two columns replace it, and neither can be turned back into a code:
 *
 * - `code_index` — `hash_hmac('sha256', $normalisedCode, $pepper)`, unique. This
 *   is what makes redemption **one query**: the presented code is normalised,
 *   hashed with the same pepper, and looked up. The pepper is configuration and
 *   never a column, so a stolen database has no material to build a lookup table
 *   against this.
 * - `code_hash` — a per-row bcrypt of the same code, salted per row and depending
 *   on no shared secret. It is verified after the index finds the row. What it
 *   buys over the index alone is independence from the pepper: a pepper that
 *   leaks, or one that a deployment rotates, leaves this column sound.
 *
 * `code_hash` is **deliberately not indexed**. It is not a lookup key and nothing
 * should ever be able to search on it.
 *
 * `last_four` is what a receipt, a support screen and an email show. Four
 * characters of a twenty-character code, which is what the host's own
 * `last_characters` column was for — kept, because the alternative is somebody
 * re-adding the full code to make support workable.
 *
 * ### There is no balance column
 *
 * The host has `balance` sitting beside a transactions table: two sources of
 * truth that can disagree, and the mutable one wins by accident. What this card
 * is worth is a **fold over `ecommerce_gift_card_entries`**, computed every time
 * it is asked for. `SchemaTest` asserts `balance`, `initial_value` and the cached
 * totals absent by name, so a convenience column cannot quietly undo it.
 *
 * There is no `status` and no `disabled_at` either, for the same reason.
 * Disabling a card is a ledger entry.
 *
 * ### Currency has no default
 *
 * The host's is `char(3)->default('USD')`. A default currency is the `default(1)`
 * mistake wave 2 spent a wave unpicking: it is a value that looks deliberate and
 * was not chosen by anybody. A card is denominated in the currency it was sold
 * in, that currency is required at issue, and redeeming against a different one
 * is **refused, not converted**.
 *
 * ### Expiry ends redeemability, not the money
 *
 * `expires_at` is nullable with no default, so a deployment that never passes one
 * has cards that never expire — which is what most jurisdictions require and this
 * module deliberately does not know. When it is set and passes, the balance is
 * untouched: nothing is zeroed, no entry is written, the ledger does not change.
 * Only redemption is refused. See `docs/domain.md`.
 *
 * It is written once, at issue, and there is no path that edits it. A merchant
 * that must honour an expired card issues a replacement and transfers the
 * balance, which leaves a trail.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('ecommerce_gift_card_accounts', function (Blueprint $table) {
            $table->id();

            // Public, quotable, and **not a credential**. A reference identifies a
            // card in a support ticket or a reconciliation export; presenting one
            // redeems nothing, because `RedeemByCode` is the only path that debits
            // and it takes a code.
            $table->string('reference')->unique();

            $table->string('kind')->index();

            // The bearer credential, stored as two things that are not it.
            // Nullable because store credit has no code at all.
            $table->char('code_index', 64)->nullable()->unique();
            // Not indexed, deliberately. It is verified, never searched.
            $table->string('code_hash')->nullable();
            $table->char('last_four', 4)->nullable();

            // Identifiers. Deliberately not constrained: customers, teams and
            // whatever sold the card belong to other modules and to the host, and
            // a package that constrained a table it does not own could not be
            // installed without it.
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            // Whatever caused this card to exist — an order, a promotion, a
            // support ticket. An opaque string this module never interprets.
            $table->string('source_reference')->nullable()->index();

            // No default. A card is denominated in the currency it was sold in.
            $table->char('currency', 3);
            $table->unsignedTinyInteger('currency_exponent')->default(2);

            $table->timestamp('expires_at')->nullable()->index();

            // The idempotency guarantee for issue, at the index rather than in
            // application code. A double-clicked "issue card" button is one card.
            $table->string('issue_key')->unique();
            $table->char('issue_hash', 64);

            $table->timestamps();

            $table->index(['team_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_gift_card_accounts');
    }
};
