<?php

declare(strict_types=1);

namespace App\Modules\SaasBilling\Infrastructure\Pdf;

use Mpdf\Language\LanguageToFont;

/**
 * Every Arabic-script run — Arabic, Kurdish Sorani (`ckb`, which mPDF's own
 * table does not list), and any text detected as `und-Arab` — uses one font,
 * so an Arabic and a Kurdish document look alike; everything else keeps
 * mPDF's mapping.
 */
final class ArabicScriptFonts extends LanguageToFont
{
    private const ARABIC_SCRIPT = ['ar', 'ara', 'ku', 'kur', 'ckb', 'fa', 'fas'];

    public function __construct(private readonly string $font = 'xbriyaz') {}

    /**
     * @param  string  $llcc
     * @param  bool  $adobeCJK
     * @return array{0: bool, 1: string}
     */
    public function getLanguageOptions($llcc, $adobeCJK)
    {
        $tags = explode('-', strtolower((string) $llcc));
        if (in_array($tags[0], self::ARABIC_SCRIPT, true) || in_array('arab', $tags, true)) {
            return [false, $this->font];
        }

        return parent::getLanguageOptions($llcc, $adobeCJK);
    }
}
