<?php

declare(strict_types=1);

namespace App\Modules\Reports\Data;

final readonly class ReportKpi
{
    public function __construct(
        public string $key,
        public string $label,
        public int|float|string|null $value,
        public string $format = 'number',
        public ?string $help = null,
    ) {}

    /** @return array<string, int|float|string|null> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'value' => $this->value,
            'format' => $this->format,
            'help' => $this->help,
        ];
    }
}
