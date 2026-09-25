<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Str;

/**
 * The single authority for Meta Style host and center-subdomain rules.
 *
 * A hostname is never converted into a database name. It is normalized here,
 * then resolved through the control-plane domains registry.
 */
final class PlatformHosts
{
    public function __construct(private readonly Config $config) {}

    public function baseDomain(): string
    {
        return $this->base()['host'];
    }

    public function corporateHost(): string
    {
        return $this->baseDomain();
    }

    public function superAdminHost(): string
    {
        return 'superadmin.'.$this->baseDomain();
    }

    public function scheme(): string
    {
        return $this->base()['scheme'];
    }

    public function port(): ?int
    {
        return $this->base()['port'];
    }

    public function normalizeSlug(string $value): string
    {
        return Str::lower(Str::slug(trim($value)));
    }

    public function isValidCenterSlug(string $slug): bool
    {
        $slug = $this->normalizeSlug($slug);

        return $slug !== ''
            && strlen($slug) <= 63
            && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $slug) === 1
            && ! $this->isReserved($slug);
    }

    public function isReserved(string $slug): bool
    {
        $reserved = $this->config->get('metastyle.domains.reserved', []);

        return is_array($reserved) && in_array($this->normalizeSlug($slug), $reserved, true);
    }

    public function centerHost(string $slug): string
    {
        $slug = $this->normalizeSlug($slug);

        if (! $this->isValidCenterSlug($slug)) {
            throw new \InvalidArgumentException('The requested center subdomain is invalid or reserved.');
        }

        return $slug.'.'.$this->baseDomain();
    }

    public function centerSlugFromHost(string $host): ?string
    {
        $host = $this->host($host);
        $suffix = '.'.$this->baseDomain();

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $slug = substr($host, 0, -strlen($suffix));

        // Exactly one tenant label. Nested and infrastructure hosts fail closed.
        return ! str_contains($slug, '.') && $this->isValidCenterSlug($slug) ? $slug : null;
    }

    public function corporateUrl(string $path = ''): string
    {
        return $this->url($this->corporateHost(), $path);
    }

    public function superAdminUrl(string $path = ''): string
    {
        return $this->url($this->superAdminHost(), $path);
    }

    public function centerUrl(string $slug, string $path = ''): string
    {
        return $this->url($this->centerHost($slug), $path);
    }

    private function host(string $value): string
    {
        $parsed = parse_url(str_contains($value, '://') ? $value : '//'.$value, PHP_URL_HOST);

        return Str::lower(rtrim(is_string($parsed) ? $parsed : $value, '.'));
    }

    private function url(string $host, string $path): string
    {
        $origin = $this->scheme().'://'.$host.($this->port() === null ? '' : ':'.$this->port());
        $path = trim($path);

        return $path === '' || $path === '/'
            ? $origin
            : $origin.'/'.ltrim($path, '/');
    }

    /** @return array{scheme: 'http'|'https', host: string, port: int|null} */
    private function base(): array
    {
        $configured = $this->config->get('app.url');

        if (! is_string($configured) || trim($configured) === '') {
            throw new \RuntimeException('APP_URL must define the Meta Style base URL.');
        }

        $parts = parse_url(trim($configured));
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';

        if (! in_array($scheme, ['http', 'https'], true)
            || ! is_string($host)
            || $host === ''
            || ! in_array($path, ['', '/'], true)
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new \RuntimeException('APP_URL must be an http(s) origin containing only scheme, hostname and optional port.');
        }

        $port = $parts['port'] ?? null;

        return [
            'scheme' => $scheme,
            'host' => Str::lower(rtrim($host, '.')),
            'port' => is_int($port) ? $port : null,
        ];
    }
}
