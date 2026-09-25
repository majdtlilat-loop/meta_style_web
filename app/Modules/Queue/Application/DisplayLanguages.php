<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application;

use App\Kernel\Localization\TenantLocales;
use App\Modules\Queue\Domain\Models\QueueDisplay;

/**
 * Which languages a waiting-room screen shows, and in which it starts.
 *
 * ## Only languages the CENTER has switched on
 *
 * The center decides which languages exist for it (`TenantLocales`); a screen
 * only chooses among them. This is answered on every read rather than when the
 * screen is saved, so switching Kurdish off for the center removes it from
 * every screen at once — and turning it back on restores each screen's own
 * choice, because disabling never deletes what a screen asked for
 * (docs/07-LOCALIZATION.md §4, docs/17-QUEUE.md §9).
 *
 * ## Rotation is presentation, never queue state
 *
 * A rotating screen cycles its LABELS: EN, then AR, then KU, then EN again.
 * The order is the center's own language order. Fewer than two usable
 * languages means no rotation at all — a screen never "rotates" to the language
 * it is already showing. Nothing here decides what is spoken: the ticket voice
 * keeps its own `voice_locales` and its own announcement identifier (§16).
 *
 * ## A preview may be pinned to any of the center's languages
 *
 * The Manager's preview checks a screen in each language the center publishes
 * in — not only the ones it cycles — so a manager sees every language and its
 * direction before choosing. A pinned read is that one language alone and
 * never rotates; a language the center has not switched on is not pinnable.
 */
final class DisplayLanguages
{
    public function __construct(private readonly TenantLocales $locales) {}

    /**
     * @param  string  $requested  the request's language (the `locale` middleware
     *                             already narrowed it to the center's languages)
     * @param  string|null  $pin  a preview's pinned language; ignored unless the
     *                            center has it switched on
     * @return array{start: string, locales: list<string>, rotate: bool, seconds: int}
     */
    public function forDisplay(QueueDisplay $display, string $requested, ?string $pin = null): array
    {
        $pinned = $this->pinnable($pin);

        if ($pinned !== null) {
            return ['start' => $pinned, 'locales' => [$pinned], 'rotate' => false, 'seconds' => $display->rotationSeconds()];
        }

        $cycle = $this->cycle($display->rotation_enabled, $display->rotationLocales());
        $start = $this->start($display->locale, $cycle, $requested);

        return [
            'start' => $start,
            'locales' => $cycle === [] ? [$start] : $cycle,
            'rotate' => $cycle !== [],
            'seconds' => $display->rotationSeconds(),
        ];
    }

    /**
     * The languages a screen actually cycles through — THE one definition of
     * "this screen rotates", used by the public screen, its preview and the
     * Manager's list alike.
     *
     * Its chosen languages, narrowed to the center's, in the center's order;
     * none at all when rotation is off or fewer than two remain.
     *
     * @param  list<string>  $chosen
     * @return list<string>
     */
    public function cycle(bool $rotationEnabled, array $chosen): array
    {
        $cycle = array_values(array_filter(
            $this->locales->enabled(),
            static fn (string $locale): bool => in_array($locale, $chosen, true),
        ));

        return $rotationEnabled && count($cycle) > 1 ? $cycle : [];
    }

    /**
     * Where a screen starts: its own language when the center still publishes
     * in it, else the request's, else the center's default — and, for a
     * rotating screen, the first language it cycles when that one is not
     * among them.
     *
     * @param  list<string>  $cycle  from {@see cycle()}
     */
    public function start(?string $screenLocale, array $cycle, string $requested): string
    {
        $enabled = $this->locales->enabled();
        $start = $this->locales->default();

        foreach ([$screenLocale, $requested] as $candidate) {
            if (is_string($candidate) && in_array($candidate, $enabled, true)) {
                $start = $candidate;

                break;
            }
        }

        return $cycle === [] || in_array($start, $cycle, true) ? $start : $cycle[0];
    }

    /**
     * A language a preview may be pinned to: one the CENTER has switched on,
     * whatever the screen itself cycles — or none.
     */
    public function pinnable(mixed $locale): ?string
    {
        return is_string($locale) && in_array($locale, $this->locales->enabled(), true) ? $locale : null;
    }
}
