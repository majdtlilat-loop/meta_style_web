<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Authorization;

enum PlatformPermission: string
{
    case DashboardView = 'platform.dashboard.view';
    case CenterView = 'platform.center.view';
    case CenterManage = 'platform.center.manage';
    case PlanManage = 'platform.plan.manage';
    case SubscriptionManage = 'platform.subscription.manage';
    case EntitlementManage = 'platform.entitlement.manage';
    case UsageManage = 'platform.usage.manage';
    case BillingManage = 'platform.billing.manage';
    case OperationsView = 'platform.operations.view';
    case OperationsManage = 'platform.operations.manage';
    case ProviderView = 'platform.provider.view';
    case SupportView = 'platform.support.view';
    case SupportManage = 'platform.support.manage';
    case CmsManage = 'platform.cms.manage';
    case AuditView = 'platform.audit.view';
    case UserManage = 'platform.user.manage';
    case SettingsManage = 'platform.settings.manage';
    case SecurityManage = 'platform.security.manage';
    case AnnouncementSend = 'platform.announcement.send';
    case BrandingManage = 'platform.branding.manage';
    case AiManage = 'platform.ai.manage';
    case CenterUserView = 'platform.center_user.view';
    case CenterUserManage = 'platform.center_user.manage';

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(static fn (self $permission): string => $permission->value, self::cases());
    }
}
