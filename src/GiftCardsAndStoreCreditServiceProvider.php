<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardAccount;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Models\GiftCardEntry;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Policies\GiftCardAccountPolicy;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Policies\GiftCardEntryPolicy;
use Liberu\Ecommerce\GiftCardsAndStoreCredit\Telemetry\DomainEventLogger;

/**
 * Registered by the module manager from `module.json`, never by Composer
 * discovery — the package ships no `extra.laravel.providers`, so an install boots
 * nothing until the deployment names the module in `MODULES_ENABLED`.
 *
 * **Nothing here listens for another module's event.** Every subscription in this
 * file is to something this package publishes itself; a subscription to anything
 * else would be an import of a sibling package, and this module has none.
 * `BoundaryTest` reads this file and asserts that every commerce namespace it
 * mentions is this one.
 *
 * In particular there is no listener for a refund. A refund onto a gift card is a
 * listener the **host** writes, taking an amount and a reference off whatever
 * decided them and calling `RecordCredit` — see `docs/adoption.md`. A subscription
 * here would be this package requiring a refunds module to exist.
 */
class GiftCardsAndStoreCreditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/gift-cards.php', 'gift-cards');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Registered here rather than left to Laravel's convention: the
        // convention maps `App\Models\X` to `App\Policies\XPolicy`, and this
        // module's models are in neither namespace. An unregistered policy is not
        // a closed door — the unanswered gate case is permissive, and this fleet
        // has shipped that leak five times.
        //
        // Both roots, including the one nobody expects to put on a panel. A model
        // reachable from a relation is a model somebody's gate call will reach
        // eventually.
        Gate::policy(GiftCardAccount::class, GiftCardAccountPolicy::class);
        Gate::policy(GiftCardEntry::class, GiftCardEntryPolicy::class);

        // Subscribed unconditionally, and silent unless the deployment turns
        // telemetry on. Gating the subscription on config instead would make the
        // setting un-changeable at runtime, which is exactly the thing a
        // deployment wants to flip while it is investigating something.
        Event::subscribe(DomainEventLogger::class);

        $this->publishes([
            __DIR__.'/../config/gift-cards.php' => config_path('gift-cards.php'),
        ], 'gift-cards-config');
    }
}
