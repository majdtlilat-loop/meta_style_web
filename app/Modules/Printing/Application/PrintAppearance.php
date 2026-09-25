<?php

declare(strict_types=1);

namespace App\Modules\Printing\Application;

use App\Kernel\Appearance\Appearance;
use App\Kernel\Appearance\AppearanceSchema;
use App\Kernel\Appearance\AppearanceStore;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Modules\CenterSite\Contracts\CenterBrandReader;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * How the center's own operational paper looks — the 80mm receipt, the A4
 * invoice, the queue ticket.
 *
 * ## Render time only
 *
 * These settings are applied when a document is DRAWN. Nothing here is ever
 * written into an invoice: the invoice keeps its immutable snapshot, and this
 * class only decides which of the snapshot's already-allow-listed fields the
 * paper shows, what logo and lines frame it, and which language it is printed
 * in (docs/18-SALES.md §22). Reprinting an old invoice after a footer change
 * gives the new footer and the same numbers.
 *
 * ## Separate from Meta Style's own invoices
 *
 * The platform's SaaS invoices to centers have their own template in
 * SaasBilling. Nothing there reads this, and nothing here reads that.
 *
 * The logo comes from the center's brand ({@see CenterBrandReader}) when that
 * contract is bound; without it the paper simply has no logo.
 */
final class PrintAppearance
{
    private const KEY = 'print_appearance';

    public function __construct(
        private readonly AppearanceStore $store,
        private readonly LanguageRegistry $languages,
        private readonly TenantLocales $locales,
        private readonly Container $container,
    ) {}

    public function schema(): AppearanceSchema
    {
        /** @var array<string, mixed> $config */
        $config = config('printing.appearance', []);

        return AppearanceSchema::fromConfig('print', $config);
    }

    public function get(): Appearance
    {
        return Appearance::fromStored($this->schema(), $this->store->read(self::KEY), $this->languages->supported());
    }

    /**
     * Stores a validated document. Only {@see Actions\UpdatePrintAppearance}
     * calls this.
     */
    public function put(Appearance $appearance): void
    {
        $this->store->write(self::KEY, $appearance->toArray());
    }

    /**
     * The language a document prints in: the person printing's, or always the
     * center's primary content language, as the center chose.
     */
    public function documentLocale(string $staffLocale, ?Appearance $override = null): string
    {
        $appearance = $override ?? $this->get();

        if ($appearance->choice('document_language') === 'center') {
            return $this->locales->default();
        }

        return $this->languages->supports($staffLocale) ? $staffLocale : $this->locales->default();
    }

    /**
     * What a print template needs, already resolved to `$locale`.
     *
     * @return array{logo: array{url: string, size: string, align: string}|null, show_center_name: bool, show_branch_name: bool, show_branch_address: bool, show_branch_phone: bool, show_customer_name: bool, header_lines: list<string>, footer: string|null, text_size: string, density: string, ticket_show_service: bool, ticket_show_date: bool, ticket_footer: string|null}
     */
    public function view(string $locale, ?Appearance $override = null): array
    {
        $appearance = $override ?? $this->get();
        $primary = $this->locales->default();
        $logoUrl = $appearance->flag('show_logo') ? $this->logoUrl() : null;

        $header = (string) $appearance->text('header_text', $locale, $primary);
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $header)),
            static fn (string $line): bool => $line !== '',
        ));

        return [
            'logo' => $logoUrl === null ? null : [
                'url' => $logoUrl,
                'size' => $appearance->choice('logo_size'),
                'align' => $appearance->choice('logo_align'),
            ],
            'show_center_name' => $appearance->flag('show_center_name'),
            'show_branch_name' => $appearance->flag('show_branch_name'),
            'show_branch_address' => $appearance->flag('show_branch_address'),
            'show_branch_phone' => $appearance->flag('show_branch_phone'),
            'show_customer_name' => $appearance->flag('show_customer_name'),
            // At most three short lines: a receipt header, not a letterhead.
            'header_lines' => array_slice($lines, 0, 3),
            'footer' => $appearance->text('footer_text', $locale, $primary),
            'text_size' => $appearance->choice('receipt_text_size'),
            'density' => $appearance->choice('a4_density'),
            'ticket_show_service' => $appearance->flag('ticket_show_service'),
            'ticket_show_date' => $appearance->flag('ticket_show_date'),
            'ticket_footer' => $appearance->text('ticket_footer', $locale, $primary),
        ];
    }

    /**
     * The invoice's own allow-listed view model with the fields the center
     * chose not to print taken out. A copy for the paper — the invoice and its
     * snapshot are untouched.
     *
     * @param  array<string, mixed>  $document  from InvoiceRenderer::document()
     * @param  array<string, mixed>  $print  from {@see view()}
     * @return array<string, mixed>
     */
    public function invoice(array $document, array $print): array
    {
        if (! ($print['show_branch_address'] ?? true)) {
            $document['branch_address'] = null;
        }

        if (! ($print['show_branch_phone'] ?? true)) {
            $document['branch_phone'] = null;
        }

        if (! ($print['show_customer_name'] ?? true)) {
            $document['customer_name'] = null;
        }

        return $document;
    }

    /**
     * The same for a queue ticket's payload.
     *
     * @param  array<string, mixed>  $ticket  from TicketPrinter::payload()
     * @param  array<string, mixed>  $print
     * @return array<string, mixed>
     */
    public function ticket(array $ticket, array $print): array
    {
        if (! ($print['ticket_show_service'] ?? true)) {
            $ticket['service_name'] = null;
        }

        if (! ($print['show_branch_name'] ?? true)) {
            $ticket['branch_name'] = null;
        }

        return $ticket;
    }

    private function logoUrl(): ?string
    {
        if (! $this->container->bound(CenterBrandReader::class)) {
            return null;
        }

        try {
            /** @var CenterBrandReader $reader */
            $reader = $this->container->make(CenterBrandReader::class);
            $url = $reader->forPublic()['logo_light_url'] ?? null;
        } catch (Throwable $e) {
            // Paper without a logo is still a valid receipt.
            report($e);

            return null;
        }

        if (! is_string($url) || $url === '') {
            return null;
        }

        $local = str_starts_with($url, '/') && ! str_starts_with($url, '//');

        return $local || preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }
}
