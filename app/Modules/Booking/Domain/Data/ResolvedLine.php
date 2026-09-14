<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Data;

use App\Kernel\Money\Currency;
use App\Modules\Booking\Domain\Availability\LineResolver;
use App\Modules\Booking\Domain\Enums\EmployeeSelection;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceAddon;
use App\Modules\Catalog\Domain\Models\ServiceVariation;

/**
 * A {@see BookingLine} after the catalog has been read and validated.
 *
 * This is where price and duration STOP being questions. The variation's
 * override has been applied (or its inheritance resolved), every add-on's
 * minutes and money have been added in, and what comes out is what will be
 * written into the appointment item as a snapshot — so nothing downstream needs
 * to re-read the catalog, and nothing downstream can accidentally read a price
 * that changed in between (docs/13-ROADMAP.md Phase 6 §§3, 18, 19).
 *
 * Built only by {@see LineResolver},
 * which is also the thing that refuses an inactive service, an add-on that does
 * not belong to it, or a variation belonging to a different service.
 */
final readonly class ResolvedLine
{
    /**
     * @param  list<ServiceAddon>  $addons
     * @param  list<ResourceRequirement>  $resourceRequirements  what the service
     *                                                           needs in rooms, chairs and devices
     * @param  list<string>  $pinnedResourceUuids  resources staff named
     *                                             explicitly; empty means "any available"
     */
    public function __construct(
        public Service $service,
        public ?ServiceVariation $variation,
        public array $addons,
        public int $durationMinutes,
        public int $priceMinor,
        public Currency $currency,
        public ?int $employeeId,
        public EmployeeSelection $selection,
        public ?string $note = null,
        public array $resourceRequirements = [],
        public array $pinnedResourceUuids = [],
        public ?int $offsetMinutes = null,
    ) {}

    /**
     * A copy with the employee decided.
     *
     * Used when a line asked for "any available" and the engine has chosen. The
     * SELECTION is not overwritten — it still records that the customer did not
     * name anybody, which is what makes a later reassignment a scheduling
     * detail rather than a phone call (§5).
     */
    public function assignedTo(?int $employeeId): self
    {
        return new self(
            $this->service,
            $this->variation,
            $this->addons,
            $this->durationMinutes,
            $this->priceMinor,
            $this->currency,
            $employeeId,
            $this->selection,
            $this->note,
            $this->resourceRequirements,
            $this->pinnedResourceUuids,
            $this->offsetMinutes,
        );
    }

    public function requiresSpecificEmployee(): bool
    {
        return $this->selection === EmployeeSelection::Specific;
    }
}
