<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Kernel\Identity\Actions\IssueCenterPasswordLink;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Identity\Mail\CenterPasswordResetMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class RequestCenterPasswordReset
{
    public function __construct(private readonly TenantContext $tenants, private readonly IssueCenterPasswordLink $links) {}

    public function __invoke(string $email, string $slug): void
    {
        $email = Str::lower(trim($email));
        /** @var User|null $user */ $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->where('is_active', true)->first();
        if (! $user instanceof User || ! is_string($user->email)) {
            return;
        }
        $url = ($this->links)($user, $slug, (int) config('auth.passwords.users.expire', 60));
        Mail::to($user->email)->locale(app()->getLocale())->queue(new CenterPasswordResetMail($user->name, $this->tenants->require()->name, $url));
    }
}
