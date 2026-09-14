<?php

declare(strict_types=1);

namespace App\Kernel\SaaS;

/**
 * Where the browser keeps a registration's access token.
 *
 * The web flow has nowhere else to put it. The API returns the token to a
 * client that can hold it; a browser redirecting to a status page cannot carry
 * it in the URL — a capability in a query string is written to browser history,
 * to the `Referer` header of every outbound link on the page, and to the web
 * server's access log (ADR-035).
 *
 * Keyed per registration so one browser can hold several, and so reading one
 * cannot accidentally return another's.
 */
final class RegistrationSession
{
    public static function keyFor(string $uuid): string
    {
        return 'metastyle.registration.'.$uuid;
    }
}
