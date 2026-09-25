<?php

declare(strict_types=1);

namespace App\Kernel\Authorization;

/**
 * The permission catalog, owned by application code.
 *
 * Naming: `{area}.{resource}.{action}` or `{area}.{action}`. Codes are stable
 * forever — a stored role grant references the string, so renaming one would
 * silently revoke access in every tenant database.
 *
 * ONLY permissions something can actually enforce are defined. Future codes
 * (`finance.*`, `payment.*`, `reports.*`) arrive with the features that check
 * them; a catalog full of unenforceable codes is a list of
 * promises, and it makes the Owner role look complete when it is not.
 *
 * Not to be confused with entitlements. An entitlement answers "does this
 * tenant own the feature"; a permission answers "may this user perform the
 * action". Both gates are required, independently
 * (docs/05-ENTITLEMENTS.md, docs/06-AUTH-ROLES-PERMISSIONS.md).
 */
enum Permission: string
{
    // Staff accounts and their access
    case StaffView = 'staff.view';
    case StaffCreate = 'staff.create';
    case StaffUpdate = 'staff.update';
    case StaffDeactivate = 'staff.deactivate';
    case StaffAccessManage = 'staff.access.manage';

    // Roles and permission assignment
    case RoleView = 'role.view';
    case RoleCreate = 'role.create';
    case RoleUpdate = 'role.update';
    case RoleDelete = 'role.delete';
    case RolePermissionsManage = 'role.permissions.manage';

    // Branches
    case BranchView = 'branch.view';
    case BranchManage = 'branch.manage';

    // Departments — operational organisation
    case DepartmentView = 'department.view';
    case DepartmentManage = 'department.manage';

    // Menu categories — customer-facing organisation
    case CategoryView = 'category.view';
    case CategoryManage = 'category.manage';

    // Services. Split further than departments and categories because a center
    // routinely wants a senior stylist who can edit prices but not retire a
    // service, and archiving is the destructive one.
    case ServiceView = 'service.view';
    case ServiceCreate = 'service.create';
    case ServiceUpdate = 'service.update';
    case ServiceArchive = 'service.archive';

    // Electronic menu appearance
    case MenuView = 'menu.view';
    case MenuManage = 'menu.manage';

    // The center's own public site: brand, landing page, and the
    // booking, cart and print presentation. Content only — never payments.
    case AppearanceView = 'appearance.view';
    case AppearanceManage = 'appearance.manage';

    // Media
    case MediaUpload = 'media.upload';

    // Customers — Meta Style's first real PII domain. `customer.view` finds a
    // customer; seeing how to CONTACT them is a separate grant, because most
    // staff need the first and not the second (docs/06 §6).
    case CustomerView = 'customer.view';
    case CustomerCreate = 'customer.create';
    case CustomerUpdate = 'customer.update';
    case CustomerArchive = 'customer.archive';
    case CustomerContactView = 'customer.contact.view';
    case CustomerNoteView = 'customer.note.view';
    case CustomerNoteManage = 'customer.note.manage';
    case CustomerAccountManage = 'customer.account.manage';
    case CustomerTagManage = 'customer.tag.manage';

    // Appointments. Split by ACTION rather than by role, because a center
    // routinely wants reception to book and cancel while only a manager marks
    // a no-show — that is a real conversation about money and about how a
    // customer is treated, not a scheduling detail (docs/13-ROADMAP.md
    // Phase 6 §30).
    case AppointmentView = 'appointment.view';
    case AppointmentCreate = 'appointment.create';
    case AppointmentUpdate = 'appointment.update';
    case AppointmentCancel = 'appointment.cancel';
    case AppointmentConfirm = 'appointment.confirm';
    case AppointmentComplete = 'appointment.complete';
    case AppointmentNoShow = 'appointment.no_show';
    case AppointmentNoteView = 'appointment.note.view';
    case AppointmentNoteManage = 'appointment.note.manage';

    /*
     * The narrow read. A stylist needs their own day and has no business in the
     * rest of the branch's book — but "their own" is a SCOPE, not a role, so it
     * is a separate grant that any role may hold rather than a condition
     * somewhere on the role name (docs/13-ROADMAP.md Phase 7 §30).
     *
     * `appointment.view` wins where both are held: the broader grant is not
     * narrowed by also holding the narrower one.
     */
    case AppointmentViewOwn = 'appointment.view_own';

    // Operational resources — chairs, rooms, devices. Two codes, not five: a
    // center that lets somebody edit the equipment list lets them add to it,
    // and splitting further would be codes nothing distinguishes (Phase 7 §30).
    case ResourceView = 'resource.view';
    case ResourceManage = 'resource.manage';

    // Employee availability blocks: breaks, training, personal time. Writing
    // one changes who is bookable, so it is a real grant; reading them is part
    // of managing them.
    case AvailabilityBlockManage = 'availability_block.manage';

    /*
     * Service Journey — what actually happens during a visit.
     *
     * Split by ACTION for the same reason appointments are: a center routinely
     * wants a stylist who can start and finish their own stages while only a
     * host may move a customer to a different employee or a different room.
     */
    case JourneyView = 'journey.view';
    case JourneyViewOwn = 'journey.view_own';
    case JourneyManage = 'journey.manage';
    case JourneyStageStart = 'journey.stage.start';
    case JourneyStageComplete = 'journey.stage.complete';
    case JourneyStageReassign = 'journey.stage.reassign';
    case JourneyNoteView = 'journey.note.view';
    case JourneyNoteManage = 'journey.note.manage';

    /*
     * A visit nobody booked. Its own code, because "create a visit out of
     * nothing" is a different trust than "start the service somebody already
     * reserved" — a center may well let every stylist do the second and only
     * reception do the first (docs/17-QUEUE.md §18).
     */
    case JourneyWalkInCreate = 'journey.walk_in.create';

    /*
     * Queue — waiting, calling and routing around a stage.
     *
     * Five codes, and deliberately not nine. Transfer rides `queue.manage` and
     * skip rides `queue.call`, because they are the same operator doing the
     * same job at the same desk, and a code nothing ever distinguishes is a
     * promise rather than a permission (Phase 7 §30, docs/17-QUEUE.md §18).
     *
     * Service points and displays share `queue.display.manage`: a destination
     * exists to be shown on a screen, and configuring one without the other is
     * not a job anybody has.
     */
    case QueueView = 'queue.view';
    case QueueManage = 'queue.manage';
    case QueueCall = 'queue.call';
    case QueueTicketPrint = 'queue.ticket.print';
    case QueueDisplayManage = 'queue.display.manage';

    /*
     * Sales — the commercial transaction, its invoice, and the till session.
     *
     * Nine codes, each an operator distinction somebody actually has:
     *
     *   view      reads sales and their invoices. Viewing an invoice IS viewing
     *             a sale's published document, so there is no `invoice.view`.
     *   create    builds carts and opens visit checkouts — reception can
     *             prepare a bill without issuing it.
     *   finalize  publishes the invoice. The till.
     *   adjust    discounts, surcharges, price overrides and custom lines: every
     *             way of charging something other than the catalog price.
     *   void      cancels a published sale. A manager's call.
     *   print     the paper surfaces, paired with the `printing` entitlement.
     *
     * Shifts split "my own till" from "anybody's": a supervisor closes the
     * shift a cashier forgot, and a cashier must not close a colleague's.
     *
     * `product.manage` edits the small product catalog POS sells from
     * (docs/18-SALES.md §23).
     */
    case SaleView = 'sale.view';
    case SaleCreate = 'sale.create';
    case SaleFinalize = 'sale.finalize';
    case SaleAdjust = 'sale.adjust';
    case SaleVoid = 'sale.void';
    case InvoicePrint = 'invoice.print';
    case CashierShiftManage = 'cashier_shift.manage';
    case CashierShiftSupervise = 'cashier_shift.supervise';
    case ProductManage = 'product.manage';

    /*
     * Payments — money collected against invoices, and money returned.
     *
     *   view            reads payments, refunds and an invoice's settlement.
     *   collect         takes a payment: cash, a manually confirmed transfer, or
     *                   an online payment through the branch's gateway.
     *   refund          returns money. A different person from the one who
     *                   collects, in most centers.
     *   gateway.manage  configures a branch's merchant account — the one place
     *                   credentials are written. Never granted to a cashier.
     *
     * Reconciling a drawer reuses `cashier_shift.manage` (your own till) and
     * `cashier_shift.supervise` (anybody's): that distinction already exists
     * (docs/19-PAYMENTS.md §46).
     */
    case PaymentView = 'payment.view';
    case PaymentCollect = 'payment.collect';
    case PaymentRefund = 'payment.refund';
    case PaymentGatewayManage = 'payment.gateway.manage';

    /*
     * Finance — the center's ledger, dashboard and expenses
     * (docs/20-FINANCE.md §46).
     *
     *   view    the dashboard and the ledger.
     *   manage  expense categories, posting and voiding expenses.
     */
    case FinanceView = 'finance.view';
    case ExpenseManage = 'expense.manage';

    /*
     * Loyalty, memberships and service packages — a customer's benefits
     * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §20).
     *
     *   *.view           a customer's points, memberships and packages — and,
     *                    with `sale.create`, applying the ones they are entitled
     *                    to at checkout. An entitled benefit is not a
     *                    discretionary discount, so it needs no `sale.adjust`.
     *   loyalty.manage   the program's rules and tiers.
     *   loyalty.adjust   a manual, reasoned points adjustment.
     *   membership.manage / package.manage
     *                    plans and definitions, and cancelling a customer's.
     */
    case LoyaltyView = 'loyalty.view';
    case LoyaltyManage = 'loyalty.manage';
    case LoyaltyAdjust = 'loyalty.adjust';
    case MembershipView = 'membership.view';
    case MembershipManage = 'membership.manage';
    case PackageView = 'package.view';
    case PackageManage = 'package.manage';

    /*
     * Reviews — what customers said about their visits
     * (docs/22-REVIEWS.md §19).
     *
     * TWO codes, and deliberately not six. There is no `review.rating.view`
     * beside `review.view`, because a rating with the review it came from
     * hidden is a number nobody can act on; and no code per dimension, because
     * "may read service ratings but not employee ratings" is not a job anybody
     * has.
     *
     *   view    read reviews, ratings and the summaries, within branch scope.
     *   manage  hide, unhide, flag, and issue or revoke a customer's review
     *           link. A moderation action is a manager's, and every one of them
     *           is audited with its reason.
     *
     * Reading history and moderating it survive a downgrade; ISSUING a new
     * invitation does not — the Booking rule (§18).
     */
    case ReviewView = 'review.view';
    case ReviewManage = 'review.manage';

    /*
     * Reports — the two actions that are meaningfully different for a center.
     * Viewing remains branch- and domain-permission-scoped. Exporting is a
     * separate grant because it creates a portable copy of the same data.
     */
    case ReportView = 'report.view';
    case ReportExport = 'report.export';

    // Center-to-platform support. Separate from customer conversations and CRM.
    case PlatformSupportView = 'platform_support.view';
    case PlatformSupportManage = 'platform_support.manage';

    /*
     * Conversations — WhatsApp threads with customers, and the assistant that
     * answers them (docs/25-WHATSAPP.md §19).
     *
     * THREE codes, split the way a center actually splits the work:
     *
     *   view      read threads within branch scope. A supervisor auditing what
     *             the bot said to their customers needs this and nothing else.
     *   reply     write in a thread. A different trust: these messages go out
     *             from the center's own WhatsApp number, in the center's name.
     *   takeover  silence the assistant and hold the thread, or hand it back.
     *             Separate from `reply` because it changes who is ANSWERING
     *             every future message, not just who wrote this one.
     *
     * No `conversation.close`: closing is the end of holding a thread, and a
     * center that trusts somebody to take one over trusts them to finish it.
     *
     * The two config codes are deliberately coarse. `whatsapp.manage` writes
     * provider credentials and is never granted to a receptionist;
     * `ai.manage` changes how the assistant behaves for every customer. Neither
     * is split further, because nobody has a job that is half of either.
     */
    case ConversationView = 'conversation.view';
    case ConversationReply = 'conversation.reply';
    case ConversationTakeover = 'conversation.takeover';
    case WhatsAppManage = 'whatsapp.manage';
    case AiManage = 'ai.manage';

    // Center administration
    case SettingsView = 'settings.view';
    case SettingsManage = 'settings.manage';
    case AuditView = 'audit.view';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }

    public static function tryFromCode(string $code): ?self
    {
        return self::tryFrom($code);
    }

    /**
     * Grouping for the role editor. Presentation only — never authorization.
     */
    public function group(): string
    {
        return match ($this) {
            self::StaffView, self::StaffCreate, self::StaffUpdate,
            self::StaffDeactivate, self::StaffAccessManage => 'staff',

            self::RoleView, self::RoleCreate, self::RoleUpdate,
            self::RoleDelete, self::RolePermissionsManage => 'roles',

            self::BranchView, self::BranchManage => 'branches',

            self::DepartmentView, self::DepartmentManage => 'departments',

            self::CategoryView, self::CategoryManage,
            self::ServiceView, self::ServiceCreate,
            self::ServiceUpdate, self::ServiceArchive => 'catalog',

            self::MenuView, self::MenuManage => 'menu',

            self::AppearanceView, self::AppearanceManage => 'appearance',

            self::MediaUpload => 'media',

            self::ResourceView, self::ResourceManage => 'resources',

            self::AvailabilityBlockManage => 'employees',

            self::JourneyView, self::JourneyViewOwn, self::JourneyManage,
            self::JourneyStageStart, self::JourneyStageComplete,
            self::JourneyStageReassign, self::JourneyNoteView,
            self::JourneyNoteManage, self::JourneyWalkInCreate => 'journey',

            self::QueueView, self::QueueManage, self::QueueCall,
            self::QueueTicketPrint, self::QueueDisplayManage => 'queue',

            self::SaleView, self::SaleCreate, self::SaleFinalize, self::SaleAdjust,
            self::SaleVoid, self::InvoicePrint, self::CashierShiftManage,
            self::CashierShiftSupervise, self::ProductManage => 'sales',

            self::PaymentView, self::PaymentCollect, self::PaymentRefund,
            self::PaymentGatewayManage => 'payments',

            self::FinanceView, self::ExpenseManage => 'finance',

            self::LoyaltyView, self::LoyaltyManage, self::LoyaltyAdjust => 'loyalty',

            self::MembershipView, self::MembershipManage => 'memberships',

            self::PackageView, self::PackageManage => 'packages',

            self::ReviewView, self::ReviewManage => 'reviews',

            self::ReportView, self::ReportExport => 'reports',

            self::PlatformSupportView, self::PlatformSupportManage => 'platform_support',

            self::ConversationView, self::ConversationReply,
            self::ConversationTakeover, self::WhatsAppManage,
            self::AiManage => 'conversations',

            self::CustomerView, self::CustomerCreate, self::CustomerUpdate,
            self::CustomerArchive, self::CustomerContactView,
            self::CustomerNoteView, self::CustomerNoteManage,
            self::CustomerAccountManage, self::CustomerTagManage => 'customers',

            self::AppointmentView, self::AppointmentCreate, self::AppointmentUpdate,
            self::AppointmentCancel, self::AppointmentConfirm, self::AppointmentComplete,
            self::AppointmentNoShow, self::AppointmentNoteView,
            self::AppointmentNoteManage, self::AppointmentViewOwn => 'appointments',

            self::SettingsView, self::SettingsManage, self::AuditView => 'administration',
        };
    }
}
