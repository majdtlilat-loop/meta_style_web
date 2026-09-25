<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Actions;

use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\PlatformHosts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mints a one-time link for a center user to (re)set their own password.
 *
 * Runs in tenant context. Only the SHA-256 of the token is stored; the
 * plaintext exists in the returned URL and nowhere else, and any older unused
 * link for the same address is retired first. The center's reset page
 * (`/reset-password/{token}`) redeems it.
 *
 * Used for "forgot password", and for the first sign-in of an owner whose
 * center a Super Admin created — nobody ever chooses a password for them.
 */
final class IssueCenterPasswordLink
{
    public function __construct(private readonly PlatformHosts $hosts) {}

    public function __invoke(User $user, string $slug, int $minutes = 60): string
    {
        $email = Str::lower(trim((string) $user->email));
        $token = Str::random(64);
        $now = now();

        DB::connection('tenant')->transaction(function () use ($email, $token, $now, $minutes): void {
            DB::connection('tenant')->table('password_reset_tokens')->where('email', $email)->whereNull('used_at')->update(['used_at' => $now, 'updated_at' => $now]);
            DB::connection('tenant')->table('password_reset_tokens')->insert([
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $now->copy()->addMinutes($minutes),
                'used_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return $this->hosts->centerUrl($slug, '/reset-password/'.$token);
    }
}
