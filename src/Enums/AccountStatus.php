<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums;

/**
 * What an account currently is, derived and never stored.
 *
 * Four cases, ordered in `AccountState::status()` **most permanent first**, so
 * the answer names the reason a bearer will actually hit. A disabled card that
 * has also expired reports `Disabled`, because that is the one somebody has to
 * do something about.
 *
 * `Expired` is a status with a **balance behind it**, and that is the whole
 * expiry decision in one line: the money is still there, the ledger is untouched,
 * and only redeemability has ended. Nothing in this module zeroes a balance.
 */
enum AccountStatus: string
{
    /** Redeemable right now, for the balance the fold reports. */
    case Active = 'active';

    /** Nothing left to spend. The card still exists and can still be credited. */
    case Empty = 'empty';

    /** Past `expires_at`. **The balance is unchanged** — only redemption stops. */
    case Expired = 'expired';

    /** Stopped, terminally. Reported lost, stolen, or issued in error. */
    case Disabled = 'disabled';
}
