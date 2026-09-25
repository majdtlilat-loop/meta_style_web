<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application;

use App\Kernel\Platform\Branding\Color;
use App\Modules\CenterSite\Contracts\CenterBrandReader;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * The center's own brand, as the public menu, booking and cart pages inherit it.
 *
 * Read through {@see CenterBrandReader}, the Center Site module's contract —
 * never its storage. The contract may not be bound yet (a release where the
 * brand editor has not shipped, or a test that does not provision it), so the
 * answer is simply "no brand": every page already has its own colours and
 * looks complete without one.
 *
 * Values are re-checked here even though the contract promises they are
 * validated: a colour lands in a `style` attribute and a URL in an `<img src>`,
 * and a second, cheap check at the edge costs nothing.
 */
final class PublicBrand
{
    public function __construct(private readonly Container $container) {}

    /**
     * @return array{name: string|null, logo_url: string|null, logo_dark_url: string|null, favicon_url: string|null, primary: string|null, accent: string|null}|null
     */
    public function resolve(): ?array
    {
        if (! $this->container->bound(CenterBrandReader::class)) {
            return null;
        }

        try {
            /** @var CenterBrandReader $reader */
            $reader = $this->container->make(CenterBrandReader::class);
            $brand = $reader->forPublic();
        } catch (Throwable $e) {
            // A broken brand must never take the menu down with it: the page
            // renders with its own colours and the failure is reported.
            report($e);

            return null;
        }

        $tokens = $brand['tokens'];

        return [
            'name' => $brand['name'] !== '' ? $brand['name'] : null,
            'logo_url' => $this->url($brand['logo_light_url'] ?? null),
            'logo_dark_url' => $this->url($brand['logo_dark_url'] ?? null),
            'favicon_url' => $this->url($brand['favicon_url'] ?? null),
            'primary' => $this->colour($tokens, 'primary'),
            'accent' => $this->colour($tokens, 'accent') ?? $this->colour($tokens, 'secondary'),
        ];
    }

    /**
     * @param  array<mixed>  $tokens
     */
    private function colour(array $tokens, string $name): ?string
    {
        foreach ([$name, '--center-'.$name, 'color-'.$name] as $key) {
            $value = Color::normalize($tokens[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function url(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        // Only the center's own media route or an absolute http(s) URL; never
        // `javascript:`, `data:` or anything else that is not an image address.
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return $value;
        }

        return preg_match('#^https?://#i', $value) === 1 ? $value : null;
    }
}
