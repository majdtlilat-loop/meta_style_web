<?php

declare(strict_types=1);

namespace App\Kernel\Notes;

/**
 * The warning shown wherever staff write a free-text note.
 *
 * ## Why this exists
 *
 * Internal notes are free text, and Meta Style's customers include laser
 * centers and beauty clinics. Somebody will eventually type a skin condition, a
 * medication or an allergy into a note field — not maliciously, but because the
 * box was there and the information felt relevant. That turns a general CRM
 * note into a health record living in a table with ordinary staff permissions,
 * ordinary retention and no consent trail behind it.
 *
 * ## What this is NOT
 *
 * It is not classification. Nothing here inspects what anyone types, blocks a
 * word, or tries to decide whether a note "looks medical" — that would be an
 * NLP system with a false-positive rate, refusing legitimate notes and missing
 * the ones that matter, and it would need to be right in three languages.
 *
 * It is not health-record functionality either. Meta Style does not store
 * clinical data, and building a safe place to put it is a product decision with
 * regulatory weight, not a feature that gets added quietly.
 *
 * ## What it is
 *
 * One honest sentence, in front of the person about to type, and in the API
 * response so a mobile client shows the same thing. A person who knows what a
 * field is for makes a better decision than a filter guessing after the fact
 * (docs/13-ROADMAP.md Phase 6 §1).
 */
final class NoteAdvisory
{
    /**
     * Translated, because the person typing may be working in Arabic or
     * Kurdish, and a warning nobody reads is not a warning.
     */
    public static function text(): string
    {
        return __(
            'Operational notes only. Do not record medical, clinical or health information here — '
            .'this is not a medical record, and other staff can read it.'
        );
    }

    /**
     * The API's version of the same sentence.
     *
     * Returned in `meta` on note endpoints rather than hidden in documentation:
     * a mobile client that never reads the docs still gets a string it can
     * render above its own text box.
     *
     * @return array<string, string>
     */
    public static function meta(): array
    {
        return ['note_advisory' => self::text()];
    }
}
