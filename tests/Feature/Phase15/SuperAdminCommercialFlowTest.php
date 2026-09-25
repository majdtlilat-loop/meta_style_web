<?php

declare(strict_types=1);

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Models\PlatformAuditLog;
use App\Kernel\Identity\Models\User;
use App\Kernel\Platform\Identity\Actions\ManagePlatformRoles;
use App\Kernel\Platform\Identity\Actions\ManagePlatformUsers;
use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Kernel\Platform\Notifications\PlatformNotifier;
use App\Kernel\SaaS\Enums\RegistrationStatus;
use App\Kernel\SaaS\Models\Plan;
use App\Kernel\SaaS\Models\Registration;
use App\Kernel\SaaS\Models\Subscription;
use App\Kernel\SaaS\Models\SubscriptionHistory;
use App\Kernel\Tenancy\Enums\TenantStatus;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Modules\Onboarding\Application\CreateCenterForPlatform;
use App\Modules\Onboarding\Infrastructure\Jobs\ProvisionRegisteredTenant;
use App\Modules\Onboarding\Mail\CenterOwnerInvitationEmail;
use App\Modules\PlatformOperations\Application\PlatformAlertFeed;
use App\Modules\PlatformOperations\Application\SendPlatformAnnouncement;
use App\Modules\PlatformOperations\Infrastructure\Jobs\DeliverPlatformAnnouncement;
use App\Modules\SaasAdmin\Application\Actions\ChangeTenantLifecycle;
use App\Modules\SaasAdmin\Application\Actions\ManageSubscription;
use App\Modules\SaasBilling\Application\Actions\CorrectSaasBilling;
use App\Modules\SaasBilling\Application\Actions\IssueSaasInvoice;
use App\Modules\SaasBilling\Application\Actions\RecordManualSaasPayment;
use App\Modules\SaasBilling\Domain\Models\SaasInvoice;
use App\Modules\SaasBilling\Domain\Models\SaasPayment;
use Database\Seeders\LocalDevelopmentPlatformUserSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The commercial life of a center, driven by the Super Admin
|--------------------------------------------------------------------------
|
| A center created from the platform goes through the same provisioning as
| a self-registration, with nobody choosing its owner's password. Plan and
| cycle changes take effect now and leave history. Archiving keeps every
| invoice, and billing mistakes are corrected by voiding or reversing —
| never by deleting.
|
*/

beforeEach(function (): void {
    $this->withoutVite();
    app()->call([app(LocalDevelopmentPlatformUserSeeder::class), 'run']);
    $this->admin = PlatformUser::query()->where('email', 'admin@meta-style.local')->firstOrFail();
    $this->actingAs($this->admin, 'platform');

    $this->growth = Plan::query()->create([
        'code' => 'phase15_growth', 'name' => ['en' => 'Growth', 'ar' => 'النمو', 'ckb' => 'گەشە'],
        'price_minor' => 40000, 'billing_period' => 'monthly', 'currency' => 'IQD',
        'monthly_price_minor' => 40000, 'yearly_price_minor' => 400000,
        'trial_days' => 14, 'is_public' => true, 'is_active' => true, 'sort_order' => 90,
    ]);
    $this->scale = Plan::query()->create([
        'code' => 'phase15_scale', 'name' => ['en' => 'Scale', 'ar' => 'التوسع', 'ckb' => 'فراوانی'],
        'price_minor' => 90000, 'billing_period' => 'monthly', 'currency' => 'IQD',
        'monthly_price_minor' => 90000, 'yearly_price_minor' => 900000,
        'trial_days' => 14, 'is_public' => true, 'is_active' => true, 'sort_order' => 91,
    ]);
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
    $roles = DB::connection('control')->table('platform_roles')->where('is_system', false)->pluck('id');
    DB::connection('control')->table('platform_role_permissions')->whereIn('role_id', $roles)->delete();
    DB::connection('control')->table('platform_roles')->whereIn('id', $roles)->delete();
    // Plans are reference data and are not truncated between tests.
    Schema::connection('control')->disableForeignKeyConstraints();
    DB::connection('control')->table('plans')->whereIn('code', ['phase15_growth', 'phase15_scale', 'phase15_growth_monthly'])->delete();
    Schema::connection('control')->enableForeignKeyConstraints();
});

function provisionPlatformCenter(object $test, string $slug): Registration
{
    Queue::fake([ProvisionRegisteredTenant::class]);

    $registration = app(CreateCenterForPlatform::class)([
        'center_name' => 'Lotus Spa', 'slug' => $slug, 'owner_name' => 'Nour Owner', 'owner_email' => 'nour@lotus.test',
        'owner_phone' => '+9647701234567', 'primary_locale' => 'ar', 'locales' => ['ar', 'en'], 'currency' => 'IQD',
        'timezone' => 'Asia/Baghdad', 'plan_id' => $test->growth->id, 'cycle' => 'yearly', 'trial' => false,
        'trial_days' => null, 'starts_at' => null, 'grants' => [], 'revokes' => [],
    ], Actor::platform($test->admin));

    Queue::assertPushed(ProvisionRegisteredTenant::class);
    app()->call([new ProvisionRegisteredTenant($registration->uuid), 'handle']);

    return $registration->refresh();
}

it('creates a center from the platform through the same pipeline, with no password chosen for the owner', function (): void {
    Mail::fake();
    $registration = provisionPlatformCenter($this, 'lotus-spa');
    $this->trackRegistrationDatabase($registration);

    expect($registration->status)->toBe(RegistrationStatus::Ready)
        ->and($registration->source)->toBe('platform');

    $tenant = TenantModel::query()->findOrFail($registration->tenant_id);
    $subscription = Subscription::query()->where('tenant_id', $tenant->id)->firstOrFail();

    // The Super Admin's commercial choices were applied by the pipeline.
    expect($subscription->plan_id)->toBe($this->growth->id)
        ->and($subscription->billing_period_snapshot)->toBe('yearly')
        ->and((int) $subscription->price_minor_snapshot)->toBe(400000)
        ->and($subscription->status->value)->toBe('active')
        ->and(SubscriptionHistory::query()->where('subscription_id', $subscription->id)->where('event', 'created')->exists())->toBeTrue();

    // The owner exists with a credential nobody knows, and was emailed a set-up link.
    $owner = $this->asCenter($tenant->toValueObject(), fn (): User => User::query()->where('is_owner', true)->firstOrFail());
    expect($owner->email)->toBe('nour@lotus.test')
        // The random bootstrap credential is destroyed once provisioning ends (ADR-031).
        ->and($registration->owner_password_hash)->toBeNull();

    Mail::assertQueued(CenterOwnerInvitationEmail::class, function (CenterOwnerInvitationEmail $mail): bool {
        return $mail->hasTo('nour@lotus.test')
            && $mail->setupUrl !== ''
            && ! str_contains($mail->setupUrl, 'nour@lotus.test');
    });

    expect(PlatformAuditLog::query()->where('action', 'platform.center.create_requested')->where('target_id', $registration->uuid)->exists())->toBeTrue();
});

it('changes plan and cycle immediately, keeps history, and archives without losing invoices', function (): void {
    Mail::fake();
    $registration = provisionPlatformCenter($this, 'lotus-change');
    $this->trackRegistrationDatabase($registration);
    $tenant = TenantModel::query()->findOrFail($registration->tenant_id);
    $subscription = Subscription::query()->where('tenant_id', $tenant->id)->firstOrFail();
    $actor = Actor::platform($this->admin);

    app(ManageSubscription::class)->changePlan($subscription, $this->scale, 'monthly', $actor, 'Upgrade agreed by phone');
    $subscription->refresh();

    expect($subscription->plan_id)->toBe($this->scale->id)
        ->and($subscription->billing_period_snapshot)->toBe('monthly')
        ->and((int) $subscription->price_minor_snapshot)->toBe(90000);

    $change = SubscriptionHistory::query()->where('subscription_id', $subscription->id)->where('event', 'plan_changed')->firstOrFail();
    expect($change->from_plan_id)->toBe($this->growth->id)
        ->and($change->to_plan_id)->toBe($this->scale->id)
        ->and($change->reason)->toBe('Upgrade agreed by phone');

    // A cycle the plan is not sold on is refused rather than guessed.
    $monthlyOnly = Plan::query()->create([
        'code' => 'phase15_growth_monthly', 'name' => ['en' => 'Monthly only', 'ar' => 'شهري', 'ckb' => 'مانگانە'],
        'price_minor' => 1000, 'billing_period' => 'monthly', 'currency' => 'IQD', 'monthly_price_minor' => 1000,
        'is_public' => false, 'is_active' => true, 'sort_order' => 99,
    ]);
    expect(fn () => app(ManageSubscription::class)->changePlan($subscription, $monthlyOnly, 'yearly', $actor, 'Wrong cycle'))
        ->toThrow(DomainException::class);

    // An invoice, a payment and its reversal; a second invoice voided.
    $invoice = app(IssueSaasInvoice::class)($subscription, 'Scale — monthly', 90000, now()->addDays(7), $actor);
    $payment = app(RecordManualSaasPayment::class)($invoice, 90000, 'bank_transfer', now(), $actor, 'TRX-1');
    expect($invoice->refresh()->status)->toBe('settled');

    app(CorrectSaasBilling::class)->reversePayment($payment, $actor, 'Transfer bounced');
    expect($payment->refresh()->reversed_at)->not->toBeNull()
        ->and($invoice->refresh()->paid_minor)->toBe(0)
        ->and($invoice->status)->toBe('issued')
        ->and(SaasPayment::query()->whereKey($payment->id)->exists())->toBeTrue();

    $second = app(IssueSaasInvoice::class)($subscription, 'Duplicate invoice', 90000, now()->addDays(7), $actor);
    app(CorrectSaasBilling::class)->voidInvoice($second, $actor, 'Issued twice by mistake');
    expect($second->refresh()->status)->toBe('void')
        ->and($second->voided_at)->not->toBeNull();

    // Archiving keeps every invoice and payment, and can be undone.
    app(ChangeTenantLifecycle::class)($tenant->id, TenantStatus::Archived, $actor, 'Center closed permanently');
    expect(TenantModel::query()->findOrFail($tenant->id)->archived_at)->not->toBeNull()
        ->and(SaasInvoice::query()->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(SaasPayment::query()->whereKey($payment->id)->exists())->toBeTrue()
        ->and(PlatformAuditLog::query()->where('action', 'platform.center.archived')->exists())->toBeTrue();

    app(ChangeTenantLifecycle::class)($tenant->id, TenantStatus::Active, $actor, 'Reopened after all');
    expect(TenantModel::query()->findOrFail($tenant->id)->status)->toBe('active');
});

it('delivers an announcement to the center inbox and filters platform alerts by permission', function (): void {
    Mail::fake();
    $center = $this->registerCenter('Announcement Center', 'owner@announce.test');
    Queue::fake([DeliverPlatformAnnouncement::class]);

    $announcement = app(SendPlatformAnnouncement::class)(
        ['en' => 'Maintenance tonight', 'ar' => 'صيانة الليلة', 'ckb' => 'چاککردنەوە ئەمشەو'],
        ['en' => 'The platform is read-only from 02:00 to 02:30.', 'ar' => 'المنصة للقراءة فقط.', 'ckb' => 'پلاتفۆرم تەنها بۆ خوێندنەوەیە.'],
        'important',
        ['audience' => 'selected', 'tenant_ids' => [$center['tenant']->id]],
        Actor::platform($this->admin),
    );

    Queue::assertPushed(DeliverPlatformAnnouncement::class, fn (DeliverPlatformAnnouncement $job): bool => $job->tenantId === $center['tenant']->id);
    app()->call([new DeliverPlatformAnnouncement($announcement->uuid, $center['tenant']->id, true), 'handle']);

    $this->asCenter($center['tenant'], function () use ($announcement): void {
        $notification = DB::connection('tenant')->table('notifications')
            ->where('source_type', 'platform_announcement')->where('source_uuid', $announcement->uuid)->first();
        expect($notification)->not->toBeNull();

        $owner = User::query()->where('is_owner', true)->firstOrFail();
        expect(DB::connection('tenant')->table('notification_recipients')
            ->where('notification_id', $notification->id)->where('recipient_id', $owner->id)->exists())->toBeTrue();
    });

    // Platform alerts: a ticket-desk agent sees ticket events, not provisioning ones.
    $desk = app(ManagePlatformRoles::class)->create(['en' => 'Ticket desk', 'ar' => '', 'ckb' => ''], ['en' => '', 'ar' => '', 'ckb' => ''], ManagePlatformRoles::TEMPLATES['support_tickets_only'], $this->admin);
    $agent = app(ManagePlatformUsers::class)->invite('Desk Agent', 'desk@alerts.test', [$desk->id], $this->admin);

    $notifier = app(PlatformNotifier::class);
    $notifier->notify('support.ticket_created', 'info', 'ticket_created', ['reference' => 'T-1', 'center' => 'Announcement Center', 'subject' => 'Printer'], $center['tenant']->id, '/support');
    $notifier->notify('centers.provisioning_failed', 'critical', 'provisioning_failed', ['center' => 'Broken Center'], null, '/centers');

    $feed = app(PlatformAlertFeed::class);
    $agentSources = $feed->query($agent)->pluck('source')->all();
    $adminSources = $feed->query($this->admin)->pluck('source')->all();

    expect($agentSources)->toContain('support.ticket_created')
        ->and(in_array('centers.provisioning_failed', $agentSources, true))->toBeFalse('a ticket-desk agent sees provisioning failures')
        ->and($adminSources)->toContain('centers.provisioning_failed')
        ->and($feed->unreadCount($agent))->toBe(1);

    $alert = $feed->query($agent)->firstOrFail();
    $feed->markRead($agent, (int) $alert->id);
    expect($feed->unreadCount($agent))->toBe(0);
});
