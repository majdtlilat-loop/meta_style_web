<?php

declare(strict_types=1);

namespace App\Modules\SaasBilling\Infrastructure\Pdf;

use Illuminate\Support\Facades\File;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Server-side PDF for SaaS billing documents (docs/DECISIONS.md ADR-079).
 *
 * mPDF renders the SAME HTML document the print view shows — no browser, no
 * screenshot — so a PDF is deterministic for the same data. Latin text uses
 * the brand's Poppins. Arabic and Kurdish (Sorani) runs use XB Riyaz, the
 * Arabic-script font mPDF ships and maps for Arabic, Persian and Kurdish, with
 * OpenType shaping.
 *
 * Not the brand's Noto Sans Arabic, which the print view uses: mPDF's OpenType
 * engine refuses lookups in it (GPOS type 8, mark filtering sets — any word
 * with a harakah), and it carries no Latin digits or brackets, so mixed runs
 * such as an invoice number inside an Arabic sentence would print as boxes.
 */
final class PdfRenderer
{
    public function render(string $html, string $direction, string $paper = 'A4', string $title = ''): string
    {
        $tempDir = storage_path('app/mpdf');
        File::ensureDirectoryExists($tempDir);

        $defaults = (new ConfigVariables)->getDefaults();
        $fonts = (new FontVariables)->getDefaults();

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => in_array($paper, ['A4', 'Letter'], true) ? $paper : 'A4',
            'margin_left' => 14,
            'margin_right' => 14,
            'margin_top' => 14,
            'margin_bottom' => 16,
            'tempDir' => $tempDir,
            'fontDir' => array_merge($defaults['fontDir'], [resource_path('fonts')]),
            'fontdata' => $fonts['fontdata'] + [
                'poppins' => ['R' => 'Poppins-Regular.ttf', 'B' => 'Poppins-SemiBold.ttf'],
            ],
            'default_font' => 'poppins',
            'directionality' => $direction === 'rtl' ? 'rtl' : 'ltr',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'languageToFont' => new ArabicScriptFonts,
        ]);
        // Documents never load remote resources: every image is a local file path.
        $mpdf->SetTitle($title);
        $mpdf->SetCreator('Meta Style');
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }
}
