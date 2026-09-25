<?php

declare(strict_types=1);

namespace App\Kernel\Reporting;

use Carbon\CarbonImmutable;

final readonly class BranchWindow
{
    public function __construct(
        public int $branchId,
        public string $branchUuid,
        public string $branchName,
        public string $timezone,
        public CarbonImmutable $fromUtc,
        public CarbonImmutable $untilUtc,
    ) {}

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return [
            'id' => $this->branchId,
            'uuid' => $this->branchUuid,
            'name' => $this->branchName,
            'timezone' => $this->timezone,
            'from_utc' => $this->fromUtc->toIso8601String(),
            'until_utc' => $this->untilUtc->toIso8601String(),
        ];
    }
}
