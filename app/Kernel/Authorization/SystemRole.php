<?php

declare(strict_types=1);

namespace App\Kernel\Authorization;

/**
 * Roles every center starts with.
 *
 * These are defaults, not authorization. Nothing in the application asks "is
 * this user a manager" — it asks whether they hold a permission. A center is
 * free to edit these roles' permission sets or add its own, and the code does
 * not care (docs/06-AUTH-ROLES-PERMISSIONS.md §4).
 */
enum SystemRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Host = 'host';
    case Cashier = 'cashier';
    case Employee = 'employee';

    /**
     * @return array<string, string>
     */
    public function name(): array
    {
        return match ($this) {
            self::Owner => ['en' => 'Owner', 'ar' => 'المالك', 'ckb' => 'خاوەن'],
            self::Manager => ['en' => 'Manager', 'ar' => 'المدير', 'ckb' => 'بەڕێوەبەر'],
            self::Host => ['en' => 'Host / Reception', 'ar' => 'الاستقبال', 'ckb' => 'پێشوازی'],
            self::Cashier => ['en' => 'Cashier', 'ar' => 'أمين الصندوق', 'ckb' => 'کاشێر'],
            self::Employee => ['en' => 'Employee', 'ar' => 'موظف', 'ckb' => 'کارمەند'],
        };
    }

    /**
     * The permissions this role is seeded with.
     *
     * Owner returns the ENTIRE catalog — as explicit, stored, individually
     * revocable grants. It is deliberately not a `if ($user->isOwner()) return
     * true` bypass: a bypass cannot be audited, cannot be narrowed, and would
     * silently swallow every future security boundary the moment it is added
     * (docs/DECISIONS.md ADR-029).
     *
     * The consequence is that system roles must be re-synced when the catalog
     * grows — see SystemRoleSynchroniser.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),

            // Runs the place day to day: staff, catalog, menu. Not billing,
            // and not the audit trail — a manager who can rewrite history is
            // not a control.
            self::Manager => [
                Permission::StaffView,
                Permission::StaffCreate,
                Permission::StaffUpdate,
                Permission::StaffDeactivate,
                Permission::StaffAccessManage,
                Permission::RoleView,
                Permission::BranchView,
                Permission::BranchManage,
                Permission::DepartmentView,
                Permission::DepartmentManage,
                Permission::CategoryView,
                Permission::CategoryManage,
                Permission::ServiceView,
                Permission::ServiceCreate,
                Permission::ServiceUpdate,
                Permission::ServiceArchive,
                Permission::MenuView,
                Permission::MenuManage,
                Permission::MediaUpload,
                Permission::CustomerView,
                Permission::CustomerCreate,
                Permission::CustomerUpdate,
                Permission::CustomerArchive,
                Permission::CustomerContactView,
                Permission::CustomerNoteView,
                Permission::CustomerNoteManage,
                Permission::CustomerAccountManage,
                Permission::CustomerTagManage,
                Permission::AppointmentView,
                Permission::AppointmentCreate,
                Permission::AppointmentUpdate,
                Permission::AppointmentCancel,
                Permission::AppointmentConfirm,
                Permission::AppointmentComplete,
                Permission::AppointmentNoShow,
                Permission::AppointmentNoteView,
                Permission::AppointmentNoteManage,
                // Owns the equipment list, the breaks rota, and the floor.
                Permission::ResourceView,
                Permission::ResourceManage,
                Permission::AvailabilityBlockManage,
                Permission::JourneyView,
                Permission::JourneyManage,
                Permission::JourneyStageStart,
                Permission::JourneyStageComplete,
                Permission::JourneyStageReassign,
                Permission::JourneyNoteView,
                Permission::JourneyNoteManage,
                // Walk-ins and the whole queue, including the screens: a
                // manager decides what the center's destinations are called
                // and what the television shows.
                Permission::JourneyWalkInCreate,
                Permission::QueueView,
                Permission::QueueManage,
                Permission::QueueCall,
                Permission::QueueTicketPrint,
                Permission::QueueDisplayManage,
                // The whole till, including the calls a cashier does not get
                // to make: discounts, voids, other people's shifts, and what
                // the center sells over the counter.
                Permission::SaleView,
                Permission::SaleCreate,
                Permission::SaleFinalize,
                Permission::SaleAdjust,
                Permission::SaleVoid,
                Permission::InvoicePrint,
                Permission::CashierShiftManage,
                Permission::CashierShiftSupervise,
                Permission::ProductManage,
                Permission::SettingsView,
            ],

            // Reception. Needs to answer "what do you offer, how long, how
            // much" from the desk — so it reads the catalog and changes none
            // of it.
            // Reception books people and has to be able to phone them back,
            // so this is the non-manager role that gets contact details.
            self::Host => [
                Permission::StaffView,
                Permission::BranchView,
                Permission::DepartmentView,
                Permission::CategoryView,
                Permission::ServiceView,
                Permission::CustomerView,
                Permission::CustomerCreate,
                Permission::CustomerUpdate,
                Permission::CustomerContactView,
                Permission::CustomerNoteView,
                // Reception IS the booking desk. Everything except
                // manager-only booking notes.
                Permission::AppointmentView,
                Permission::AppointmentCreate,
                Permission::AppointmentUpdate,
                Permission::AppointmentCancel,
                Permission::AppointmentConfirm,
                Permission::AppointmentComplete,
                Permission::AppointmentNoShow,
                Permission::AppointmentNoteView,
                /*
                 * Reception runs the floor: it checks people in, moves them
                 * between departments and rooms, and closes the visit. It reads
                 * the equipment list to do that and does not edit it, and it
                 * does not set anybody's breaks — that is a manager's call
                 * about somebody's working day (Phase 7 §§30, 33).
                 */
                Permission::ResourceView,
                Permission::JourneyView,
                Permission::JourneyManage,
                Permission::JourneyStageStart,
                Permission::JourneyStageComplete,
                Permission::JourneyStageReassign,
                Permission::JourneyNoteView,
                /*
                 * Reception IS the queue. It takes walk-ins, issues numbers,
                 * calls, recalls, holds, transfers and prints tickets — but it
                 * does not configure the destinations or the screens, which is
                 * a decision about how the center is laid out
                 * (docs/17-QUEUE.md §18).
                 */
                Permission::JourneyWalkInCreate,
                Permission::QueueView,
                Permission::QueueManage,
                Permission::QueueCall,
                Permission::QueueTicketPrint,
                /*
                 * Reception closes the visit, so it can PREPARE the bill: open
                 * checkout, review what was performed, build the cart. It does
                 * not publish the invoice or change a price — that is the till
                 * (docs/18-SALES.md §36).
                 */
                Permission::SaleView,
                Permission::SaleCreate,
            ],

            // Prices at the till, and enough of a customer to attach a sale
            // to the right record. NOT their contact details — masking is what
            // protects those, so `customer.view` alone is safe to hand out.
            self::Cashier => [
                Permission::BranchView,
                Permission::CategoryView,
                Permission::ServiceView,
                Permission::CustomerView,
                // Reads the day's book to find the visit a sale belongs to.
                // Cannot create, move or close one.
                Permission::AppointmentView,
                /*
                 * The till: build, finalize and print, inside their own shift.
                 * Not discounts or price overrides, not voids, and not anybody
                 * else's shift — those are a manager's decisions, and a
                 * center that trusts its cashier with them grants them in the
                 * role editor (docs/18-SALES.md §36).
                 */
                Permission::SaleView,
                Permission::SaleCreate,
                Permission::SaleFinalize,
                Permission::InvoicePrint,
                Permission::CashierShiftManage,
            ],

            // The narrowest role. `customer.view` because an employee has to
            // know who they are serving; contact details and notes are not
            // theirs. Contextual "only customers I served" access needs a
            // service history, which arrives with Booking
            // (docs/13-ROADMAP.md Phase 5 §21).
            self::Employee => [
                Permission::BranchView,
                Permission::DepartmentView,
                Permission::ServiceView,
                Permission::CustomerView,
                /*
                 * NARROWED IN PHASE 7, and this is a visible change: the role
                 * held `appointment.view` — the whole branch's book — because
                 * Phase 6 had no way to say "their own". It does now
                 * ({@see \App\Kernel\Authorization\AppointmentScope}), and a
                 * stylist reading every customer's day at the branch was always
                 * more than the role needed.
                 *
                 * `metastyle:roles:sync` REVOKES the broader grant on deploy
                 * (ADR-032, docs/12 §3.3). A center that genuinely wants the
                 * old behaviour grants `appointment.view` through the role
                 * editor, which is where that decision belongs.
                 */
                Permission::AppointmentViewOwn,
                Permission::JourneyViewOwn,
                // Starts and finishes their own work. Cannot move a customer
                // to somebody else, and cannot check anybody in.
                Permission::JourneyStageStart,
                Permission::JourneyStageComplete,
                Permission::JourneyNoteView,
                /*
                 * Reads the queue so they can see who is waiting for them. The
                 * board narrows to their own stages through `journey.view_own`
                 * rather than through a check on the role's name — roles grant
                 * permissions and nothing in an Action ever asks which role
                 * somebody holds (ADR-029).
                 */
                Permission::QueueView,
            ],
        };
    }

    /**
     * Roles a center may not delete. All five are protected: they are what the
     * product documentation and support conversations refer to.
     */
    public function isProtected(): bool
    {
        return true;
    }
}
