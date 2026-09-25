<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Identity\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ResetCenterPassword
{
    public function __construct(private readonly Audit $audit) {}

    public function __invoke(string $email, string $token, string $password): User
    {
        $email = Str::lower(trim($email));
        /** @var User $user */
        $user = DB::connection('tenant')->transaction(function () use ($email, $token, $password): User {
            $reset = DB::connection('tenant')->table('password_reset_tokens')->where('email', $email)->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            if ($reset === null || $reset->used_at !== null || now()->greaterThan($reset->expires_at)) {
                throw new DomainException('This password reset link is invalid or expired.');
            }
            /** @var User|null $user */ $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->where('is_active', true)->first();
            if (! $user instanceof User) {
                throw new DomainException('This password reset link is invalid or expired.');
            }
            $user->forceFill(['password' => $password, 'password_changed_at' => now(), 'remember_token' => Str::random(60)])->save();
            DB::connection('tenant')->table('password_reset_tokens')->where('id', $reset->id)->update(['used_at' => now(), 'updated_at' => now()]);
            $user->tokens()->delete();

            return $user;
        });
        $this->audit->record(new AuditEvent(action: 'identity.password.reset', category: AuditCategory::Security, actor: Actor::staff($user), targetType: User::class, targetId: $user->uuid, targetLabel: $user->name));

        return $user;
    }
}
