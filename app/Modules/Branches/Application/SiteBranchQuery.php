<?php

declare(strict_types=1);

namespace App\Modules\Branches\Application;

use App\Modules\Branches\Contracts\SiteBranchReader;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Branches\Domain\Models\BranchWorkingHour;

/**
 * {@see SiteBranchReader} over the tenant's branches.
 *
 * `map_url` is passed on only when it is an https address, and only ever as a
 * link: it is center-typed text and is never framed. Coordinates are passed as
 * numbers within range, or not at all.
 */
final class SiteBranchQuery implements SiteBranchReader
{
    public function references(): array
    {
        $references = [];
        foreach (Branch::query()->get(['id', 'uuid', 'is_active', 'is_public', 'archived_at']) as $branch) {
            $references[$branch->uuid] = $branch->is_active && $branch->is_public && $branch->archived_at === null;
        }

        return $references;
    }

    public function options(string $locale): array
    {
        return Branch::query()->publiclyVisible()->get(['id', 'uuid', 'name', 'is_main'])
            ->sortByDesc(fn (Branch $branch): bool => $branch->is_main)
            ->map(fn (Branch $branch): array => ['uuid' => $branch->uuid, 'name' => $branch->name->get($locale), 'is_main' => $branch->is_main])
            ->values()->all();
    }

    public function branches(?array $uuids, string $locale): array
    {
        if ($uuids === []) {
            return [];
        }

        $query = Branch::query()->publiclyVisible()->with('workingHours');
        if ($uuids !== null) {
            $query->whereIn('uuid', $uuids);
        }

        return $query->get()
            ->sortByDesc(fn (Branch $branch): bool => $branch->is_main)
            ->map(function (Branch $branch) use ($locale): array {
                $hours = [];
                foreach ($branch->workingHours as $interval) {
                    /** @var BranchWorkingHour $interval */
                    $hours[$interval->day_of_week][] = [
                        'opens' => mb_substr($interval->opens_at, 0, 5),
                        'closes' => mb_substr($interval->closes_at, 0, 5),
                    ];
                }

                return [
                    'uuid' => $branch->uuid,
                    'name' => $branch->name->get($locale),
                    'address' => $branch->address?->get($locale),
                    'is_main' => $branch->is_main,
                    'contact_phone' => $this->text($branch->getAttribute('phone')),
                    'contact_whatsapp' => $this->text($branch->getAttribute('whatsapp')),
                    'contact_email' => $this->text($branch->getAttribute('email')),
                    'map_url' => $this->https($branch->map_url),
                    'latitude' => $this->coordinate($branch->latitude, 90.0),
                    'longitude' => $this->coordinate($branch->longitude, 180.0),
                    'hours' => $hours,
                ];
            })->values()->all();
    }

    public function mainTimezone(): string
    {
        $timezone = Branch::query()->active()->orderByDesc('is_main')->orderBy('id')->value('timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : (string) config('app.timezone', 'UTC');
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function https(?string $url): ?string
    {
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return null;
        }

        return $url;
    }

    private function coordinate(?string $value, float $bound): ?float
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }
        $number = (float) $value;

        return abs($number) <= $bound ? $number : null;
    }
}
