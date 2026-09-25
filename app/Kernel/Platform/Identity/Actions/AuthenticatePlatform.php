<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Identity\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Identity\Exceptions\AuthenticationFailed;
use App\Kernel\Identity\LoginThrottle;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use Illuminate\Support\Facades\Hash;

final class AuthenticatePlatform
{
    private static ?string $decoyHash = null;

    public function __construct(private readonly Audit $audit, private readonly LoginThrottle $throttle) {}

    public function __invoke(string $email, string $password): PlatformUser
    {
        $email = mb_strtolower(trim($email));
        $this->throttle->assertAllowed('platform:'.$email);

        /** @var PlatformUser|null $user */
        $user = PlatformUser::query()->where('email', $email)->first();
        $hash = $user instanceof PlatformUser
            ? $user->password
            : (self::$decoyHash ??= Hash::make('meta-style/no-platform-account'));

        if (! $user instanceof PlatformUser || ! $user->is_active || ! Hash::check($password, $hash)) {
            $this->throttle->recordFailure('platform:'.$email);

            $this->audit->record(new AuditEvent(
                action: 'platform.identity.login.failed',
                category: AuditCategory::Security,
                actor: new Actor(ActorType::Guest, AuditSource::Web, null, 'unauthenticated'),
                severity: AuditSeverity::Warning,
                targetType: $user instanceof PlatformUser ? PlatformUser::class : null,
                targetId: $user instanceof PlatformUser ? $user->uuid : null,
                meta: ['identifier_fingerprint' => substr(hash('sha256', $email), 0, 16)],
            ));

            throw AuthenticationFailed::invalidCredentials();
        }

        $this->throttle->clear('platform:'.$email);
        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->record(new AuditEvent(
            action: 'platform.identity.login.password_accepted',
            category: AuditCategory::Security,
            actor: Actor::platform($user),
            targetType: PlatformUser::class,
            targetId: $user->uuid,
            targetLabel: $user->name,
        ));

        return $user;
    }
}
