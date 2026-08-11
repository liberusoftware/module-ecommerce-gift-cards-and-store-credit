<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Actions\RecordCredit;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Data\CreditInput;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums\CreditOrigin;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Tests\Fixtures\FakeTeam;

/**
 * **This module imports nothing and knows about nobody.**
 *
 * No `require` on any sibling `liberusoftware/ecommerce-*` package, and no `use`
 * of any commerce namespace but its own anywhere in `src/`. It does not know what
 * an order is, what a checkout is, what a payment is or what a refund is. It is a
 * balance with a ledger, redeemed by reference.
 *
 * Everything that crosses is an identifier or a value already resolved: an amount
 * in integer minor units, a currency code, and opaque strings this module never
 * interprets.
 */
it('redeems a card against an order that no installed module has ever heard of', function () {
    // **The named test.** This is where the rule is most tempting to break: a
    // gift card is spent against *something*, and the modules that own orders and
    // checkouts both publish read models. Reading them would be an import. So
    // nothing here requires them, nothing names them, none of their tables exists
    // in this database, and the thing being paid for is a string handed in.
    expect(Schema::hasTable('ecommerce_orders_orders'))->toBeFalse()
        ->and(Schema::hasTable('orders'))->toBeFalse()
        ->and(Schema::hasTable('customers'))->toBeFalse()
        ->and(Schema::hasTable('ecommerce_checkout_sessions'))->toBeFalse()
        ->and(Schema::hasTable('ecommerce_payment_payments'))->toBeFalse();

    $card = issueCard(5000);
    $result = redeem((string) $card->code, 3000);

    expect($result->entry->sourceReference)->toBe(GHOST_ORDER_REFERENCE)
        ->and($result->account->state->balance()->minor)->toBe(2000);
});

it('records a refund onto a card with no refunds module installed anywhere', function () {
    // **Where the two modules of wave 7 meet, and the proof they do not touch.**
    //
    // A refunds module decides that a customer is owed an amount and that the
    // destination is a gift card reference. It does not call this package and
    // this package does not call it. A listener the host writes takes the amount
    // and the reference off the refund's event and calls `RecordCredit`.
    //
    // What crosses is an integer, a currency code and two strings. Nothing in
    // this suite has ever heard of a refund, and that is the point.
    expect(Schema::hasTable('ecommerce_refunds_refunds'))->toBeFalse()
        ->and(Schema::hasTable('refunds'))->toBeFalse()
        ->and(Schema::hasTable('refund_items'))->toBeFalse();

    $card = issueCard(2000);

    $result = (new RecordCredit())->handle(new CreditInput(
        accountReference: $card->account->reference,
        entryKey: 'refund-to-card-1',
        amount: money(1500),
        origin: CreditOrigin::Refund,
        // The refunds module's own reference for the refund it decided. An
        // opaque string; this module never interprets it and could not.
        sourceReference: GHOST_REFUND_REFERENCE,
    ));

    expect($result->account->state->balance()->minor)->toBe(3500)
        ->and($result->entry->origin)->toBe(CreditOrigin::Refund)
        ->and($result->entry->sourceReference)->toBe(GHOST_REFUND_REFERENCE);
});

it('names no sibling domain package anywhere in its manifest', function () {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);
    $required = array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? []));

    foreach (['orders', 'checkout', 'cart', 'catalog', 'pricing', 'inventory-ledger', 'commerce-core', 'fulfillment', 'returns', 'refunds', 'payment-operations', 'subscriptions'] as $sibling) {
        expect($required)->not->toContain("liberusoftware/ecommerce-{$sibling}");
    }
});

it('mentions no commerce namespace but its own, anywhere in src', function () {
    // A grep rather than a reflection check, because the thing being forbidden is
    // the *text*: a `use` statement is a dependency whether or not the class is
    // ever loaded, and a docblock explaining that this package does not import
    // something puts that something in the repository just as effectively.
    //
    // Written as "every namespace mentioned is ours" rather than as a list of
    // forbidden names, for exactly that reason — a test that spelled the
    // forbidden token out would be the file that introduces it.
    preg_match_all('/Liberu\\\\Ecommerce\\\\(\w+)/', sourceOfSrc(), $matches);

    expect($matches[1])->not->toBeEmpty()
        ->and(array_unique(array_values($matches[1])))->toBe(['GiftCardsAndStoreCredit']);
});

it('reaches the application namespace nowhere', function () {
    expect(sourceOfSrc())->not->toContain('use App\\')
        ->not->toContain('new App\\')
        ->not->toContain('extends App\\');
});

it('uses no float anywhere in its code', function () {
    // The host's `canUse(float $amount)` and `use(float $amount, …)` are what this
    // replaces, and `(int) (19.99 * 100)` is 1998. There is no float type, no
    // `floatval` and no `(float)` cast in this package.
    //
    // Comments are **stripped before the check**, deliberately: several docblocks
    // quote the signatures this module exists to replace, and a rule that made
    // explaining the old mistake impossible would get the explanation deleted
    // rather than the float avoided. The forbidden thing is the code.
    $code = codeOfSrc();

    // Not vacuous: the stripping keeps the code and only the code.
    expect($code)->toContain('final readonly class Money')
        ->not->toContain('float')
        ->not->toContain('doubleval');

    // And the word *is* in the package, as prose, which is why the strip matters.
    expect(sourceOfSrc())->toContain('float');
});

it('ships no HTTP client and no SDK call, because it integrates with nobody', function () {
    $source = sourceOfSrc();

    expect($source)->not->toContain('Illuminate\\Support\\Facades\\Http')
        ->not->toContain('GuzzleHttp')
        ->not->toContain('curl_init');
});

it('resolves a host model from configuration at call time, against a class it has never seen', function () {
    // ADR 0006. The host names a class; this module resolves it and never imports
    // it, so the package installs into more than one application.
    Schema::create('fake_teams', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    config()->set('gift-cards.team_model', FakeTeam::class);

    $team = FakeTeam::query()->create(['name' => 'A Merchant']);
    $account = GiftCardAccount::factory()->ofTeam($team->id)->create();

    expect($account->team()->getRelated())->toBeInstanceOf(FakeTeam::class)
        ->and($account->team)->not->toBeNull()
        ->and($account->team->name)->toBe('A Merchant');
});

it('throws rather than guessing when the host has named no team model', function () {
    config()->set('gift-cards.team_model', null);

    GiftCardAccount::factory()->create()->team();
})->throws(RuntimeException::class);

it('ships no auto-registered provider, so installing boots nothing', function () {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../module.json'), true);

    expect($composer['extra']['laravel']['providers'] ?? [])->toBe([])
        ->and($composer['version'])->toBe($manifest['version'])
        ->and($composer['extra']['liberu']['name'])->toBe($manifest['name'])
        ->and($manifest['category'])->toBe('product')
        ->and($manifest['name'])->toBe('ecommerce-gift-cards-and-store-credit')
        ->and($composer['name'])->toBe('liberusoftware/ecommerce-gift-cards-and-store-credit');
});

it('subscribes its service provider to nothing it did not publish itself', function () {
    // A subscription to a sibling module's event would be an import wearing a
    // different hat — and the obvious candidate is a refund. There is none: the
    // refund listener is the host's, and `docs/adoption.md` writes it out.
    $provider = (string) file_get_contents(__DIR__.'/../src/GiftCardsAndStoreCreditServiceProvider.php');

    preg_match_all('/Liberu\\\\Ecommerce\\\\(\w+)/', $provider, $matches);

    expect(array_unique(array_values($matches[1])))->toBe(['GiftCardsAndStoreCredit'])
        ->and($provider)->not->toContain('Refund')
        ->not->toContain('Order');
});

/**
 * The same source with every comment removed, for the rules that are about code
 * rather than about text.
 */
function codeOfSrc(): string
{
    $code = '';

    foreach (token_get_all(sourceOfSrc()) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/** Every PHP file under `src/`, concatenated. */
function sourceOfSrc(): string
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../src', RecursiveDirectoryIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = (string) file_get_contents($file->getPathname());
        }
    }

    return implode("\n", $files);
}
