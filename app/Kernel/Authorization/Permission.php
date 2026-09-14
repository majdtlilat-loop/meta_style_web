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
