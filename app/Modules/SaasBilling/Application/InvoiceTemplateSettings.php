<?php

declare(strict_types=1);

namespace App\Modules\SaasBilling\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Audit\Enums\AuditSeverity;
use App\Kernel\SaaS\Models\PlatformSetting;
use App\Modules\SaasBilling\Domain\InvoiceTemplate;
use Illuminate\Support\Facades\DB;

/**
 * The SaaS billing document template, as the Super Admin configured it.
 *
 * Stored as one structured platform setting and validated by
 * {@see InvoiceTemplate} on every save; every change is audited with what it
 * was before. The issuer block is what {@see issuer()} hands to a new invoice
 * as its snapshot.
 */
final class InvoiceTemplateSettings
{
    public const KEY = 'billing.invoice_template';

    /** @var array<string, mixed>|null */
    private ?array $memo = null;

    public function __construct(private readonly Audit $audit, private readonly InvoiceTemplate $schema) {}

    /** @return array<string, mixed> */
    public function current(): array
    {
        if ($this->memo === null) {
            $stored = PlatformSetting::get(self::KEY);
            $this->memo = InvoiceTemplate::hydrate(is_array($stored) ? $stored : []);
        }

        return $this->memo;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(array $input, Actor $actor): array
    {
        $clean = $this->schema->normalize($input);
        $before = $this->current();

        DB::connection('control')->transaction(static function () use ($clean): void {
            PlatformSetting::put(self::KEY, $clean);
        });
        $this->memo = null;

        $identityChanged = $before['company'] !== $clean['company'];
        $this->audit->record(new AuditEvent(
            action: $identityChanged ? 'platform.billing_identity.updated' : 'platform.invoice_template.updated',
            category: AuditCategory::Config,
            actor: $actor,
            severity: $identityChanged ? AuditSeverity::Warning : AuditSeverity::Notice,
            targetType: PlatformSetting::class,
            targetId: self::KEY,
            targetLabel: 'Invoice template',
            before: $before,
            after: $clean,
        ));

        return $clean;
    }

    /**
     * Who is billing, as it stands now: the block a new invoice snapshots.
     *
     * @return array<string, mixed>
     */
    public function issuer(): array
    {
        return $this->current()['company'];
    }
}
