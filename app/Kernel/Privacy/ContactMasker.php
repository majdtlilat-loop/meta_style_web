<?php

declare(strict_types=1);

namespace App\Kernel\Privacy;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;

/**
 * The one place a contact detail is decided to be shown or masked.
 *
 * Meta Style's first real field-level control (docs/06-AUTH-ROLES-PERMISSIONS.md
 * §6). A member of staff may hold `customer.view` — they need to find the
 * customer — without holding `customer.contact.view`, and then the phone and
 * email they see are masked.
 *
 * MASKING HAPPENS HERE, NOT IN BLADE. A template that hides a field still sent
 * the value to the browser, still put it in the JSON a mobile client receives,
 * and still wrote it into whatever export used the same query. One helper,
 * called by every surface, is the only version of this that holds.
 *
 * The masked forms keep enough for a human to recognise a record they already
 * know — the last two digits of a phone — and not enough to learn one they do
 * not. That is the whole trade: recognition, not discovery.
 */
final class ContactMasker
{
    private const DOT = '•';

    /**
     * May this viewer see contact details in full?
     */
    public function allowsFull(?User $viewer): bool
    {
        return $viewer?->hasPermission(Permission::CustomerContactView) ?? false;
    }

    /**
     * `+9647501234567` → `+964 7•• ••• ••67`
     *
     * Keeps the country code, because knowing a customer is local is not
     * sensitive and helps staff read a list.
     */
    public function phone(?string $e164, bool $full): ?string
    {
        if ($e164 === null || $e164 === '') {
            return null;
        }

        if ($full) {
            return $e164;
        }

        $digits = ltrim($e164, '+');

        if (mb_strlen($digits) <= 4) {
            // Too short to mask meaningfully — reveal nothing at all rather
            // than most of it.
            return str_repeat(self::DOT, mb_strlen($digits));
        }

        // Country code is at most 3 digits; the rest is the subscriber number.
        $country = mb_substr($digits, 0, 3);
        $tail = mb_substr($digits, -2);
        $hidden = mb_strlen($digits) - 5;

        return '+'.$country.' '.str_repeat(self::DOT, max($hidden, 1)).$tail;
    }

    /**
     * `sara.ahmed@example.com` → `s•••@•••.com`
     *
     * The first character and the public suffix survive, which is enough to
     * tell two addresses apart in a list and not enough to write to either.
     */
    public function email(?string $email, bool $full): ?string
    {
        if ($email === null || $email === '') {
            return null;
        }

        if ($full) {
            return $email;
        }

        $at = mb_strpos($email, '@');

        if ($at === false || $at === 0) {
            return str_repeat(self::DOT, 3);
        }

        $first = mb_substr($email, 0, 1);
        $domain = mb_substr($email, $at + 1);
        $dot = mb_strrpos($domain, '.');

        $suffix = $dot === false ? '' : mb_substr($domain, $dot);

        return $first.str_repeat(self::DOT, 3).'@'.str_repeat(self::DOT, 3).$suffix;
    }
}
