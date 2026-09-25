<?php

declare(strict_types=1);

namespace App\Kernel\Audit;

use App\Kernel\Audit\Enums\ActorType;
use App\Kernel\Audit\Enums\AuditSource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Platform\Identity\Models\PlatformUser;

/**
 * Who did something, and from where.
 *
 * The label is captured at the time of the action and stored denormalised, so
 * an entry stays readable after the actor is renamed, deactivated or deleted.
 * Resolving labels at read time would let history rewrite itself
 * (docs/08-AUDIT-SECURITY.md §3).
 */
final readonly class Actor
{
    public function __construct(
        public ActorType $type,
        public AuditSource $source,
        public ?string $id = null,
        public ?string $label = null,
    ) {}

    /** Work performed by the platform itself: provisioning, scheduled jobs. */
    public static function system(string $label = 'system'): self
    {
        return new self(ActorType::System, AuditSource::System, null, $label);
    }

    /** An operator running an artisan command. */
    public static function console(string $command): self
    {
        return new self(ActorType::Console, AuditSource::Console, null, $command);
    }

    /**
     * A signed-in member of staff.
     *
     * The id is stored so "who did this" survives the account being renamed or
     * deactivated; the label is captured now so the entry stays readable.
     */
    public static function staff(User $user, AuditSource $source = AuditSource::Web): self
    {
        return new self(ActorType::Staff, $source, (string) $user->getKey(), $user->name);
    }

    public static function platform(PlatformUser $user, AuditSource $source = AuditSource::Web): self
    {
        return new self(ActorType::Platform, $source, (string) $user->getKey(), $user->name);
    }

    /** A queued job, which carries no human actor of its own. */
    public static function job(string $job): self
    {
        return new self(ActorType::System, AuditSource::Job, null, $job);
    }
}
