<?php

namespace Liberu\Ecommerce\GiftCardsAndStoreCredit\Enums;

/**
 * Why a redemption was refused — **for the operator's log, never for the
 * presenter.**
 *
 * `RedemptionRefused` carries one of these and its message is a constant. That
 * split is the anti-enumeration decision, and it is the reason this enum is not
 * simply six exception classes:
 *
 * > A bearer who tries a guessed code and is told "that card has expired" has
 * > learned that the code exists. So has one told "insufficient balance", and so
 * > has one told "that card was disabled". Every one of those answers is a hit
 * > confirmed, and a code space is only as strong as the cheapest oracle over it.
 *
 * So every refusal answers with the same bytes. A surface shows the message; a
 * surface that reaches for `->reason` and shows *that* to a bearer has undone the
 * control, and `README.md` says so where somebody building one will read it.
 *
 * The reason still goes somewhere useful: the telemetry logger writes it, and
 * `RedemptionFailed` carries it so a host can alert on a burst of `Unknown`
 * against one throttle key — which is what an enumeration attempt looks like.
 */
enum RefusalReason: string
{
    /** No account has that code. Also what an unparseable code answers. */
    case Unknown = 'unknown';

    /** The code matched the index but not the row's own hash. */
    case HashMismatch = 'hash_mismatch';

    /** Stopped. Terminal. */
    case Disabled = 'disabled';

    /** Past `expires_at`. The balance is untouched and still visible to staff. */
    case Expired = 'expired';

    /** Less on the card than was asked for. Redemption is refused, never clamped. */
    case InsufficientBalance = 'insufficient_balance';

    /** The amount was in a currency the card is not denominated in. */
    case CurrencyMismatch = 'currency_mismatch';

    /** Not a positive amount of money. */
    case InvalidAmount = 'invalid_amount';

    /** Too many attempts from this presenter. */
    case Throttled = 'throttled';
}
