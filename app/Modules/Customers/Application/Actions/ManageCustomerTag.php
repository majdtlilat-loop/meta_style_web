<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Customers\Domain\Models\CustomerTag;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The center's customer tags: create, rename, archive, restore.
 *
 * A tag is a label staff put on customers ("VIP", "Prefers mornings") to find
 * them again. Its name is content, so it is Translatable — one text per content
 * language, never `name_ar` columns (docs/07-LOCALIZATION.md).
 *
 * ARCHIVE, NEVER DELETE. An archived tag leaves the filters and the form; the
 * customers who carry it keep the link, so restoring it loses nothing.
 * `customer.tag.manage`, audited without any customer data.
 */
final class ManageCustomerTag
{
    public const MAX_NAME = 60;

    public const MAX_TAGS = 100;

    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array<string, string|null>  $name
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function save(User $actingUser, array $name, ?CustomerTag $tag = null): CustomerTag
    {
        $this->authorize($actingUser);

        $clean = [];

        foreach ($name as $locale => $text) {
            $text = is_string($text) ? trim($text) : '';

            if ($text === '') {
                continue;
            }

            if (mb_strlen($text) > self::MAX_NAME) {
                throw ValidationException::withMessages(['name' => __('manager_customers.errors.tag_name_long', ['max' => self::MAX_NAME])]);
            }

            $clean[(string) $locale] = $text;
        }

        if ($clean === []) {
            throw ValidationException::withMessages(['name' => __('manager_customers.errors.tag_name_required')]);
        }

        if ($tag === null && CustomerTag::query()->count() >= self::MAX_TAGS) {
            throw ValidationException::withMessages(['name' => __('manager_customers.errors.tag_limit', ['max' => self::MAX_TAGS])]);
        }

        /** @var CustomerTag $saved */
        $saved = DB::connection('tenant')->transaction(function () use ($clean, $tag): CustomerTag {
            $target = $tag ?? new CustomerTag([
                'is_active' => true,
                'sort_order' => min(65535, (int) CustomerTag::query()->max('sort_order') + 1),
            ]);

            $target->forceFill(['name' => TranslatedText::fromArray($clean)])->save();

            return $target;
        });

        $this->record($tag === null ? 'crm.customer_tag.created' : 'crm.customer_tag.updated', $actingUser, $saved, ['name' => $clean]);

        return $saved;
    }

    /**
     * @throws AuthorizationException
     */
    public function archive(User $actingUser, CustomerTag $tag): CustomerTag
    {
        $this->authorize($actingUser);

        if ($tag->archived_at === null) {
            $tag->forceFill(['archived_at' => Carbon::now(), 'is_active' => false])->save();
            $this->record('crm.customer_tag.archived', $actingUser, $tag);
        }

        return $tag;
    }

    /**
     * @throws AuthorizationException
     */
    public function restore(User $actingUser, CustomerTag $tag): CustomerTag
    {
        $this->authorize($actingUser);

        if ($tag->archived_at !== null) {
            $tag->forceFill(['archived_at' => null, 'is_active' => true])->save();
            $this->record('crm.customer_tag.restored', $actingUser, $tag);
        }

        return $tag;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::CustomerTagManage)) {
            throw new AuthorizationException(__('manager_customers.errors.may_not_manage_tags'));
        }
    }

    /**
     * @param  array<string, mixed>|null  $after
     */
    private function record(string $action, User $actingUser, CustomerTag $tag, ?array $after = null): void
    {
        $this->audit->record(new AuditEvent(
            action: $action,
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: CustomerTag::class,
            targetId: $tag->uuid,
            targetLabel: (string) $tag->name->get(),
            after: $after,
        ));
    }
}
