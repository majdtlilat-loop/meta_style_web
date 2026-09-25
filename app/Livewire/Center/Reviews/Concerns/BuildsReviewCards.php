<?php

declare(strict_types=1);

namespace App\Livewire\Center\Reviews\Concerns;

use App\Modules\Reviews\Domain\Enums\ReviewStatus;
use Carbon\CarbonImmutable;

/**
 * Turns presented reviews (ReviewPresenter's allow-list) into what a card
 * shows: a translated status and its tone, the stars, and when it was written
 * on the BRANCH's clock. Presentation only — the customer's words pass through
 * untouched, and Blade escapes them (docs/22 §56).
 */
trait BuildsReviewCards
{
    /**
     * @param  list<array<string, mixed>>  $presented
     * @return list<array<string, mixed>>
     */
    protected function reviewCards(array $presented): array
    {
        $locale = app()->getLocale();

        return array_map(function (array $review) use ($locale): array {
            $status = ReviewStatus::tryFrom((string) $review['status']) ?? ReviewStatus::Submitted;
            $rating = max(0, min(5, (int) $review['overall_rating']));
            $timezone = is_string($review['timezone'] ?? null) && $review['timezone'] !== '' ? $review['timezone'] : 'UTC';
            $at = CarbonImmutable::parse((string) $review['submitted_at'])->setTimezone($timezone)->locale($locale);
            /** @var array{at: string, by: string|null, reason: string|null}|null $moderation */
            $moderation = $review['moderation'];

            return $review + [
                'status_label' => $status->label(),
                'status_tone' => match ($status) {
                    ReviewStatus::Submitted => 'success',
                    ReviewStatus::Hidden => 'neutral',
                    ReviewStatus::Flagged => 'warning',
                },
                'stars' => self::stars($rating),
                'low' => $rating <= 2,
                'when' => $at->diffForHumans(),
                'when_full' => $at->isoFormat('D MMM YYYY, HH:mm'),
                'moderated_when' => $moderation === null ? null
                    : CarbonImmutable::parse($moderation['at'])->setTimezone($timezone)->locale($locale)->isoFormat('D MMM YYYY'),
            ];
        }, $presented);
    }

    protected static function stars(int $rating): string
    {
        $rating = max(0, min(5, $rating));

        return str_repeat('★', $rating).str_repeat('☆', 5 - $rating);
    }
}
