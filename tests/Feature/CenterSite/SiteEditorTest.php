<?php

declare(strict_types=1);

use App\Modules\CenterSite\Domain\SiteCatalog;
use App\Modules\CenterSite\Domain\SiteContent;
use App\Modules\CenterSite\Domain\SiteEditor;

/*
|--------------------------------------------------------------------------
| The builder's structural operations
|--------------------------------------------------------------------------
|
| Pure functions over the content array. A move names ONE item and a target
| position, clamped to the list that exists; an unknown id or list path is a
| no-op. Nothing trusts a client-sent order.
|
*/

it('adds a section after the one being edited, with a unique id and anchor', function (): void {
    $content = SiteContent::skeleton();
    [$content, $first] = SiteEditor::addSection($content, 'faq');
    [$content, $second] = SiteEditor::addSection($content, 'about');
    [$content, $third] = SiteEditor::addSection($content, 'faq', $first);

    expect([$first, $second, $third])->toBe(['faq', 'about', 'faq_2'])
        ->and($content['section_order'])->toBe(['faq', 'faq_2', 'about'])
        ->and($content['sections']['faq_2']['anchor'])->toBe('faq-2')
        ->and($content['sections']['faq']['items'])->toHaveCount(1);

    [, $none] = SiteEditor::addSection($content, 'offers');
    expect($none)->toBeNull();
});

it('duplicates a section with fresh item ids and its own anchor', function (): void {
    [$content] = SiteEditor::addSection(SiteContent::skeleton(), 'why_us');
    $original = $content['sections']['why_us']['items'][0]['id'];

    [$content, $copy] = SiteEditor::duplicateSection($content, 'why_us');

    expect($copy)->toBe('why_us_2')
        ->and($content['section_order'])->toBe(['why_us', 'why_us_2'])
        ->and($content['sections']['why_us_2']['anchor'])->toBe('why-us-2')
        ->and($content['sections']['why_us_2']['items'][0]['id'])->not->toBe($original);
});

it('removes, toggles and moves sections', function (): void {
    $content = SiteContent::skeleton();
    foreach (['about', 'services', 'faq'] as $type) {
        [$content] = SiteEditor::addSection($content, $type);
    }

    $content = SiteEditor::toggleSection($content, 'services');
    expect($content['sections']['services']['enabled'])->toBeFalse();

    $content = SiteEditor::moveSection($content, 'faq', -1);
    expect($content['section_order'])->toBe(['about', 'faq', 'services']);

    // Moving past either end is clamped, not wrapped.
    $content = SiteEditor::moveSection($content, 'about', -1);
    expect($content['section_order'])->toBe(['about', 'faq', 'services']);

    $content = SiteEditor::placeSection($content, 'about', 99);
    expect($content['section_order'])->toBe(['faq', 'services', 'about']);

    $content = SiteEditor::removeSection($content, 'faq');
    expect($content['section_order'])->toBe(['services', 'about'])
        ->and(isset($content['sections']['faq']))->toBeFalse();

    // Unknown ids change nothing.
    expect(SiteEditor::placeSection($content, 'ghost', 0))->toBe($content)
        ->and(SiteEditor::removeSection($content, 'ghost'))->toBe($content);
});

it('adds, duplicates, removes and reorders items in an allowed list only', function (): void {
    [$content] = SiteEditor::addSection(SiteContent::skeleton(), 'faq');
    $list = 'sections.faq.items';

    [$content, $second] = SiteEditor::addItem($content, $list);
    [$content, $third] = SiteEditor::addItem($content, $list);
    $first = $content['sections']['faq']['items'][0]['id'];

    $content = SiteEditor::placeItem($content, $list, $third, 0);
    expect(array_column($content['sections']['faq']['items'], 'id'))->toBe([$third, $first, $second]);

    $content = SiteEditor::moveItem($content, $list, $first, 1);
    expect(array_column($content['sections']['faq']['items'], 'id'))->toBe([$third, $second, $first]);

    $content = SiteEditor::duplicateItem($content, $list, $second);
    expect($content['sections']['faq']['items'])->toHaveCount(4);

    $content = SiteEditor::removeItem($content, $list, $third);
    expect(array_column($content['sections']['faq']['items'], 'id'))->not->toContain($third);

    // A list path that is not a builder list is refused as a no-op.
    [$same, $id] = SiteEditor::addItem($content, 'seo.title');
    expect($id)->toBeNull()->and($same)->toBe($content);
    [, $none] = SiteEditor::addItem($content, 'sections.ghost.items');
    expect($none)->toBeNull();

    // A section type without items has no item list.
    [$content] = SiteEditor::addSection($content, 'services');
    [, $noItems] = SiteEditor::addItem($content, 'sections.services.items');
    expect($noItems)->toBeNull();
});

it('stops adding at the catalog limits', function (): void {
    $content = SiteContent::skeleton();
    for ($i = 0; $i < SiteCatalog::MAX_NAVIGATION + 3; $i++) {
        [$content] = SiteEditor::addItem($content, 'navigation');
    }

    expect($content['navigation'])->toHaveCount(SiteCatalog::MAX_NAVIGATION);
});

it('points a new menu link at the first section on the page', function (): void {
    [$content] = SiteEditor::addSection(SiteContent::skeleton(), 'hours');
    [$content] = SiteEditor::addItem($content, 'navigation');

    expect($content['navigation'][0]['link_type'])->toBe('section')
        ->and($content['navigation'][0]['target'])->toBe('hours');

    [$empty] = SiteEditor::addItem(SiteContent::skeleton(), 'navigation');
    expect($empty['navigation'][0]['link_type'])->toBe('page')
        ->and($empty['navigation'][0]['target'])->toBe('booking');
});
