<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Support\Code;

/**
 * The migrations are this module's public surface as much as its classes are — a
 * consumer's money lives in these tables, and a column quietly renamed or dropped
 * between releases is an outage on deploy rather than a failing build.
 *
 * Three sets of assertions here are load-bearing rather than defensive, and all
 * three are written **by name** so the reasoning outlives whoever knew it:
 *
 * - **No column can hold a plaintext gift card code.** The host's
 *   `gift_cards.code` is a plaintext unique `string(16)`, and a gift card code is
 *   a bearer credential. This is what replacing it means.
 * - **No column can hold a balance.** The host has one beside a transactions
 *   table; the balance here is a fold and there is nothing to edit.
 * - **No column can hold a secret.** The pepper is configuration, and a secret in
 *   a row is a secret in every backup and every `select *`.
 */
const GIFT_CARD_TABLES = [
    'ecommerce_gift_card_accounts',
    'ecommerce_gift_card_entries',
];

it('creates every table the module owns', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(GIFT_CARD_TABLES);

it('prefixes every table, because this module invented both of them', function (string $table) {
    // There is no bare-name exception. `MODULE_DEVELOPMENT.md` §1.5 lets an
    // adopted table keep its bare name — and adopting is a choice, not an
    // obligation. The host's `gift_cards` stores a plaintext bearer credential
    // beside a mutable balance in a `decimal`, which is three of the four faults
    // this module exists to fix, so it is replaced rather than adopted.
    expect($table)->toStartWith('ecommerce_gift_card_');
})->with(GIFT_CARD_TABLES);

it('claims none of the bare names the host or another module already uses', function (string $bare) {
    expect(Schema::hasTable($bare))->toBeFalse();
})->with([
    'gift_cards', 'gift_card_transactions', 'store_credits', 'vouchers',
    'orders', 'order_items', 'customers', 'refunds', 'payments',
    'ecommerce_orders_orders', 'ecommerce_checkout_sessions', 'ecommerce_payment_payments',
]);

it('gives each table the columns a consumer reads', function (string $table, array $columns) {
    foreach ($columns as $column) {
        expect(Schema::hasColumn($table, $column))->toBeTrue();
    }
})->with([
    'accounts' => ['ecommerce_gift_card_accounts', [
        'id', 'reference', 'kind', 'code_index', 'code_hash', 'last_four',
        'customer_id', 'team_id', 'source_reference', 'currency',
        'currency_exponent', 'expires_at', 'issue_key', 'issue_hash',
    ]],
    'entries' => ['ecommerce_gift_card_entries', [
        'id', 'account_id', 'kind', 'entry_key', 'entry_hash', 'amount_minor',
        'currency', 'currency_exponent', 'origin', 'source_reference',
        'reason_code', 'team_id', 'recorded_by', 'occurred_at',
    ]],
]);

it('has no column anywhere that could hold a code', function (string $column) {
    // **The named assertion, and this module's sharpest promise.**
    //
    // The host's is `$table->string('code', 16)->unique();` in plain text. A gift
    // card code is a bearer credential — whoever has it has the money — so that
    // column means a database read, a leaked backup, a logged slow query, a
    // `select *` in a support tool or any staff member with table access is
    // holding cash.
    //
    // The fix is not `$hidden`. Wave 6 rejected exactly that for payment
    // instruments: `$hidden` is a serialisation default that `makeVisible()`
    // steps over, that a raw query never consults, and that this very codebase
    // already overrides on purpose in a webhook controller. **A column that
    // cannot hold the credential cannot leak it**, so the assertion is that the
    // column does not exist — on both tables, including the one where nobody
    // would think to add it.
    //
    // What does exist is `code_index` (a keyed HMAC) and `code_hash` (a per-row
    // bcrypt). Neither is a code and neither can be turned back into one.
    foreach (GIFT_CARD_TABLES as $table) {
        expect(Schema::hasColumn($table, $column))->toBeFalse();
    }
})->with([
    // The host's own leak, by name, and the obvious renames of it.
    'code', 'gift_card_code', 'card_code', 'voucher_code', 'coupon_code',
    'plaintext_code', 'raw_code', 'code_plain', 'redemption_code',
    // The other shapes a bearer credential arrives in.
    'pin', 'pin_code', 'card_number', 'serial', 'serial_number', 'barcode',
    'password', 'secret', 'token', 'access_token', 'claim_token',
    // Anywhere one could be smuggled as opaque data or as prose. `note` is on
    // the host's table and is deliberately absent here: free text beside money is
    // where a customer's email address ends up, and a code somebody pasted "for
    // support" ends up there too.
    'payload', 'raw', 'raw_response', 'body', 'data', 'attributes', 'meta',
    'metadata', 'note', 'notes', 'comment', 'description', 'details',
    'template_suffix',
]);

it('has no column anywhere that could hold the pepper', function (string $column) {
    // The pepper is configuration, read from the environment. A secret in a row
    // is a secret in every backup, every read replica, every `select *` a support
    // tool runs and every model a surface accidentally serialises — and the
    // pepper is the one value that would make the lookup index attackable.
    foreach (GIFT_CARD_TABLES as $table) {
        expect(Schema::hasColumn($table, $column))->toBeFalse();
    }
})->with(['pepper', 'code_pepper', 'signing_secret', 'api_key', 'private_key', 'salt', 'encryption_key']);

it('has no balance column, and no cached total either, because the balance is a fold', function (string $column) {
    // The whole design, asserted. The host has `balance` as a mutable `decimal`
    // sitting beside `gift_card_transactions`: two sources of truth that can
    // disagree, and the one anybody reads is the mutable one.
    expect(Schema::hasColumn('ecommerce_gift_card_accounts', $column))->toBeFalse();
})->with([
    'balance', 'balance_minor', 'initial_value', 'initial_value_minor',
    'remaining_minor', 'redeemed_minor', 'issued_minor', 'credited_minor',
    // And no status column either, for the same reason. `disabled_at` is the
    // host's, and disabling is a ledger entry here.
    'status', 'state', 'disabled_at', 'redeemed_at', 'is_active', 'active',
]);

it('stores every money column as an integer, with no decimal anywhere', function (string $table) {
    // Integer minor units, settled in wave 3 and not up for rediscovery. The
    // host's columns are `decimal(10, 2)` and its methods take `float $amount`;
    // `(int) (19.99 * 100)` is 1998. The naming convention is what makes this
    // assertable at all: every money column ends `_minor`, so a new one cannot
    // slip past.
    $money = collect(Schema::getColumns($table))
        ->filter(fn (array $column): bool => str_ends_with($column['name'], '_minor'));

    foreach ($money as $column) {
        expect(strtolower((string) $column['type_name']))->toContain('int');
    }

    foreach (Schema::getColumns($table) as $column) {
        expect(strtolower((string) $column['type_name']))
            ->not->toContain('decimal')
            ->not->toContain('float')
            ->not->toContain('double')
            ->not->toContain('numeric')
            ->not->toContain('real');
    }
})->with(GIFT_CARD_TABLES);

it('holds at least one money column, so the assertion above is not vacuous', function () {
    $money = collect(Schema::getColumns('ecommerce_gift_card_entries'))
        ->filter(fn (array $column): bool => str_ends_with($column['name'], '_minor'))
        ->pluck('name')
        ->all();

    expect($money)->toContain('amount_minor');
});

it('gives currency no default, because a card is denominated in what it was sold in', function () {
    // The host's is `->default('USD')`. A default currency is the `default(1)`
    // mistake wave 2 spent a whole wave unpicking: a value that reads as
    // deliberate and was chosen by nobody, on the one field that decides what a
    // number means.
    $currency = collect(Schema::getColumns('ecommerce_gift_card_accounts'))->firstWhere('name', 'currency');

    expect($currency['default'])->toBeNull()
        ->and($currency['nullable'])->toBeFalse();
});

it('declares the code index as a fixed-width digest and the last four as four characters', function (string $declaration) {
    // Asserted against the migration source rather than against introspection,
    // because SQLite reports `char(n)` back as a bare `varchar` and the check
    // would silently pass on a column somebody had widened. On MySQL and Postgres
    // the declaration is enforced.
    $source = (string) file_get_contents(__DIR__.'/../database/migrations/2026_08_11_000000_create_ecommerce_gift_card_accounts_table.php');

    expect($source)->toContain($declaration);
})->with([
    'the lookup index is exactly a sha256' => ["char('code_index', 64)"],
    'the display fragment is exactly four characters' => ["char('last_four', 4)"],
]);

it('never lets the code hash become a lookup key', function () {
    // It is verified, never searched. An index on it would be the beginning of
    // somebody filtering by it, and both a filter and a search term persist into
    // a query string.
    $indexed = collect(Schema::getIndexes('ecommerce_gift_card_accounts'))
        ->flatMap(fn (array $index): array => $index['columns'])
        ->all();

    expect($indexed)->not->toContain('code_hash')
        // And the columns a surface genuinely reads by *are* indexed, so this is
        // an assertion about a decision rather than about an empty schema.
        ->toContain('code_index')
        ->toContain('reference')
        ->toContain('customer_id');
});

it('carries no foreign key into another module s tables', function (string $table) {
    // The boundary, proved rather than asserted. Every key this schema declares
    // points at a table this package created. `customer_id`, `team_id`,
    // `recorded_by` and `source_reference` are plain columns — customers belong to
    // another module, teams and users to the host, and whatever a
    // `source_reference` names belongs to somebody else entirely. A package that
    // constrained a table it does not own could not be installed without it.
    $foreign = collect(Schema::getForeignKeys($table))
        ->pluck('foreign_table')
        ->unique()
        ->values()
        ->all();

    // Asserted as a set rather than in a loop, so a table with no foreign keys at
    // all still makes an assertion.
    expect(array_diff($foreign, GIFT_CARD_TABLES))->toBe([]);
})->with(GIFT_CARD_TABLES);

it('leaves every cross-boundary identifier unconstrained', function (string $table, string $column) {
    $constrained = collect(Schema::getForeignKeys($table))
        ->contains(fn (array $key): bool => in_array($column, $key['columns'], true));

    expect(Schema::hasColumn($table, $column))->toBeTrue()
        ->and($constrained)->toBeFalse();
})->with([
    'account customer' => ['ecommerce_gift_card_accounts', 'customer_id'],
    'account team' => ['ecommerce_gift_card_accounts', 'team_id'],
    'account source' => ['ecommerce_gift_card_accounts', 'source_reference'],
    'entry team' => ['ecommerce_gift_card_entries', 'team_id'],
    'entry actor' => ['ecommerce_gift_card_entries', 'recorded_by'],
    'entry source' => ['ecommerce_gift_card_entries', 'source_reference'],
]);

it('takes an account s ledger with it, because an entry means nothing alone', function () {
    $key = collect(Schema::getForeignKeys('ecommerce_gift_card_entries'))
        ->first(fn (array $key): bool => in_array('account_id', $key['columns'], true));

    // The declaration is asserted rather than the deletion. SQLite enforces
    // foreign keys only with the pragma on, and a pragma issued inside
    // `RefreshDatabase`'s transaction is a no-op.
    expect($key)->not->toBeNull()
        ->and($key['foreign_table'])->toBe('ecommerce_gift_card_accounts')
        ->and(strtolower((string) $key['on_delete']))->toBe('cascade');
});

it('refuses two accounts under one code index, at the index', function () {
    // **The guarantee that two cards cannot share a code.** Not a
    // `while (exists())` loop like the host's, which is a select followed by an
    // insert with a window in between.
    $row = fn (): array => [
        'reference' => 'GC-'.bin2hex(random_bytes(6)),
        'kind' => 'gift_card',
        'code_index' => str_repeat('a', 64),
        'currency' => 'GBP',
        'issue_key' => 'issue-'.bin2hex(random_bytes(4)),
        'issue_hash' => str_repeat('b', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('ecommerce_gift_card_accounts')->insert($row());
    DB::table('ecommerce_gift_card_accounts')->insert($row());
})->throws(QueryException::class);

it('lets any number of accounts have no code at all', function () {
    // Store credit has none, and a unique index over nulls has to tolerate that
    // on every driver this module supports.
    foreach (['a', 'b', 'c'] as $key) {
        DB::table('ecommerce_gift_card_accounts')->insert([
            'reference' => 'GC-'.bin2hex(random_bytes(6)),
            'kind' => 'store_credit',
            'code_index' => null,
            'currency' => 'GBP',
            'issue_key' => 'issue-'.$key,
            'issue_hash' => str_repeat('b', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    expect(DB::table('ecommerce_gift_card_accounts')->count())->toBe(3);
});

it('refuses two accounts under one issue key, at the index', function () {
    $row = fn (): array => [
        'reference' => 'GC-'.bin2hex(random_bytes(6)),
        'kind' => 'gift_card',
        'currency' => 'GBP',
        'issue_key' => 'the-same-key',
        'issue_hash' => str_repeat('b', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('ecommerce_gift_card_accounts')->insert($row());
    DB::table('ecommerce_gift_card_accounts')->insert($row());
})->throws(QueryException::class);

it('refuses two ledger entries under one entry key, at the index', function () {
    // **The double-redemption guarantee**, and it is an index rather than a
    // `select`: a `select` has a window after it exactly wide enough for the
    // second worker already processing the same retry.
    DB::table('ecommerce_gift_card_accounts')->insert([
        'id' => 1, 'reference' => 'GC-1', 'kind' => 'gift_card', 'currency' => 'GBP',
        'issue_key' => 'k', 'issue_hash' => str_repeat('b', 64),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $row = fn (): array => [
        'account_id' => 1, 'kind' => 'redeemed', 'entry_key' => 'the-same-key',
        'entry_hash' => str_repeat('c', 64), 'amount_minor' => 100,
        'currency' => 'GBP', 'occurred_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ];

    DB::table('ecommerce_gift_card_entries')->insert($row());
    DB::table('ecommerce_gift_card_entries')->insert($row());
})->throws(QueryException::class);

it('holds no trace of a code anywhere in the database once one has been issued', function () {
    // **The assertion the other one cannot make.** Absent column names prove a
    // schema; this proves the running system. A card is issued, and then every
    // cell of every table in the database is searched for the code in the two
    // forms it ever existed in — the display form the customer sees and the
    // normalised form the module hashes.
    //
    // It is deliberately a whole-database scan rather than a two-table one: a
    // future audit table, a job payload or a cache row is exactly where a code
    // would come back.
    $issued = issueCard();

    $code = (string) $issued->code;
    $normalised = Code::normalise($code);

    expect($code)->not->toBeEmpty();

    foreach (Schema::getTables() as $table) {
        $rows = DB::table($table['name'])->get()->map(fn ($row): array => (array) $row)->all();
        $dump = (string) json_encode($rows);

        expect($dump)->not->toContain($code)
            ->not->toContain($normalised)
            // Nor even four consecutive characters more of it than the last four
            // this module deliberately keeps for display.
            ->not->toContain(substr($normalised, 0, 8));
    }
});
