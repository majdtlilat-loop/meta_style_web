<?php

declare(strict_types=1);

namespace App\View\Queue;

use App\Modules\Queue\Domain\Models\QueueDisplay;
use RuntimeException;

/**
 * What `resources/views/queue/display.blade.php` needs, for the television and
 * for the Manager's preview of it — one template, one client, so the preview
 * can never drift from what the wall shows (docs/17-QUEUE.md §9).
 *
 * The client script is a code-owned file, `resources/js/queue-display/display-client.js`,
 * inlined into the page: a television loads one document and needs nothing
 * else, and no bundler manifest has to exist for a screen to work.
 */
final class DisplayPage
{
    private const SCRIPT = 'js/queue-display/display-client.js';

    private static ?string $script = null;

    /**
     * @param  array<string, mixed>  $presentation  from `DisplayPresentation::forDisplay()`
     * @return array<string, mixed>
     */
    public function data(
        QueueDisplay $screen,
        array $presentation,
        string $feedUrl,
        bool $wantsSound,
        bool $preview = false,
        ?string $lockLocale = null,
        bool $sample = false,
    ): array {
        /** @var array<string, array{dir: string, label: string, branch: string|null, text: array<string, string>}> $languages */
        $languages = $presentation['languages'];
        /** @var string $start */
        $start = $presentation['start'];
        $first = $lockLocale !== null && isset($languages[$lockLocale]) ? $lockLocale : $start;
        $labels = $languages[$first];

        /** @var array{enabled: bool, locales: list<string>} $rotation */
        $rotation = $presentation['rotation'];

        return [
            'displayName' => $screen->name,
            'locale' => $first,
            'direction' => $labels['dir'],
            // The panels keep their places while the language rotates; only
            // the text inside them turns. A layout that swapped sides every
            // ten seconds would be the most distracting thing in the room.
            'layoutDirection' => $languages[$start]['dir'],
            'text' => $labels['text'],
            'branchName' => $labels['branch'],
            'rotating' => $rotation['enabled'] && $lockLocale === null,
            'languageChips' => array_map(static fn (string $locale): array => [
                'code' => $locale,
                'label' => $languages[$locale]['label'],
            ], $rotation['locales']),
            'wantsSound' => $wantsSound && ! $preview,
            'preview' => $preview,
            'config' => [
                'feed' => $feedUrl,
                'presentation' => $presentation,
                'preview' => $preview,
                'sample' => $preview && $sample,
                'lockLocale' => $first === $lockLocale ? $lockLocale : null,
            ],
            'clientScript' => self::script(),
        ];
    }

    /**
     * The client, read once per process. `</script` can never close the tag
     * early: the file is code-owned, and the guard makes that a checked fact.
     */
    public static function script(): string
    {
        if (self::$script === null) {
            $source = file_get_contents(resource_path(self::SCRIPT));

            if ($source === false) {
                throw new RuntimeException('The queue display client is missing.');
            }

            self::$script = str_ireplace('</script', '<\/script', $source);
        }

        return self::$script;
    }
}
