<?php

declare(strict_types=1);

use App\Modules\CenterSite\Domain\InvalidSiteContent;
use App\Modules\CenterSite\Domain\SiteContent;
use App\Modules\CenterSite\Domain\SiteContext;

/*
|--------------------------------------------------------------------------
| The site content allow-list
|--------------------------------------------------------------------------
|
| SiteContent::normalize() is the security boundary between a Livewire
| payload and a guest page (ADR-038): plain text only, links that are a
| section, one of the center's pages or https, media and records that belong
| to THIS center, choices from the catalog. It refuses — with a path — rather
| than silently dropping. The PRIMARY content language is the required one.
|
*/

const SB_IMAGE = '11111111-1111-4111-8111-111111111111';
const SB_VIDEO = '22222222-2222-4222-8222-222222222222';
const SB_EMPLOYEE = '33333333-3333-4333-8333-333333333333';
const SB_SERVICE = '44444444-4444-4444-8444-444444444444';
const SB_FOREIGN = '99999999-9999-4999-8999-999999999999';

function sbContext(string $primary = 'ar'): SiteContext
{
    return new SiteContext(
        primary: $primary,
        locales: ['en', 'ar', 'ckb'],
        media: [SB_IMAGE => 'image', SB_VIDEO => 'video'],
        refs: [
            'services' => [SB_SERVICE => true],
            'categories' => [],
            'employees' => [SB_EMPLOYEE => true],
            'branches' => [],
            'memberships' => [],
            'packages' => [],
        ],
    );
}

/**
 * A minimal valid document in the given primary language.
 *
 * @return array<string, mixed>
 */
function sbDocument(string $primary = 'ar'): array
{
    $content = SiteContent::skeleton();
    $content['hero']['title'] = [$primary => 'Hero'];
    $content['seo']['title'] = [$primary => 'Title'];
    $content['seo']['description'] = [$primary => 'Description'];

    return $content;
}

function sbRefusal(callable $work): InvalidSiteContent
{
    try {
        $work();
    } catch (InvalidSiteContent $e) {
        return $e;
    }

    throw new RuntimeException('Expected the normalizer to refuse.');
}

it('accepts a minimal document and keeps only non-empty translations', function (): void {
    $clean = (new SiteContent(sbContext()))->normalize(sbDocument());

    expect($clean['hero']['title'])->toBe(['ar' => 'Hero'])
        ->and($clean['sections'])->toBe([])
        ->and($clean['seo']['canonical'])->toBe('self');
});

it('requires the center primary language, not English', function (): void {
    $document = sbDocument('ar');
    $document['hero']['title'] = ['en' => 'Only English'];

    $refused = sbRefusal(fn () => (new SiteContent(sbContext('ar')))->normalize($document));

    expect($refused->reason)->toBe('primary_required')
        ->and($refused->path)->toBe('hero.title.ar');

    // The same English-only title is fine for an English-primary center.
    $english = sbDocument('en');
    $english['hero']['title'] = ['en' => 'Only English'];
    expect((new SiteContent(sbContext('en')))->normalize($english)['hero']['title'])->toBe(['en' => 'Only English']);
});

it('keeps a supported language that is not enabled, so disabling never deletes', function (): void {
    $document = sbDocument('ar');
    $document['hero']['title'] = ['ar' => 'عنوان', 'ckb' => 'ناونیشان'];

    expect((new SiteContent(sbContext('ar')))->normalize($document)['hero']['title'])
        ->toBe(['ar' => 'عنوان', 'ckb' => 'ناونیشان']);
});

it('refuses HTML and script in any text', function (string $text): void {
    $document = sbDocument();
    $document['hero']['body'] = ['ar' => $text];

    expect(sbRefusal(fn () => (new SiteContent(sbContext()))->normalize($document))->reason)->toBe('text_only');
})->with([
    'script tag' => ['<script>alert(1)</script>'],
    'image onerror' => ['<img src=x onerror=alert(1)>'],
    'bold' => ['<b>bold</b>'],
    'control character' => ["hello\x07"],
]);

it('refuses a section type and a field the catalog does not have', function (): void {
    $document = sbDocument();
    $document['sections']['offers'] = ['type' => 'offers'];
    expect(sbRefusal(fn () => (new SiteContent(sbContext()))->normalize($document))->reason)->toBe('section_type');

    $document = sbDocument();
    $document['sections']['about'] = SiteContent::blankSection('about');
    $document['sections']['about']['custom_css'] = 'body{display:none}';
    $refused = sbRefusal(fn () => (new SiteContent(sbContext()))->normalize($document));
    expect($refused->reason)->toBe('unknown_field')
        ->and($refused->path)->toBe('sections.about.custom_css');

    $document = sbDocument();
    $document['hero']['style'] = 'color:red';
    expect(sbRefusal(fn () => (new SiteContent(sbContext()))->normalize($document))->reason)->toBe('unknown_field');
});

it('accepts only https links, a page key, or a section anchor', function (string $type, string $target, bool $accepted): void {
    $document = sbDocument();
    $document['navigation'] = [['id' => 'abcd1234', 'enabled' => true, 'label' => ['ar' => 'رابط'], 'link_type' => $type, 'target' => $target, 'new_tab' => false]];

    $normalize = fn () => (new SiteContent(sbContext()))->normalize($document);

    if ($accepted) {
        expect($normalize()['navigation'][0]['target'])->toBe(ltrim($target, '#'));
    } else {
        expect(in_array(sbRefusal($normalize)->reason, ['https_url', 'page_target', 'section_target'], true))->toBeTrue();
    }
})->with([
    'https' => ['external', 'https://example.com/terms', true],
    'http' => ['external', 'http://example.com', false],
    'javascript' => ['external', 'javascript:alert(1)', false],
    'data url' => ['external', 'data:text/html;base64,PHNjcmlwdD4=', false],
    'protocol relative' => ['external', '//evil.example', false],
    'credentials' => ['external', 'https://user:pass@example.com', false],
    'a page key' => ['page', 'booking', true],
    'a typed path' => ['page', '/admin', false],
    'an anchor' => ['section', '#services', true],
    'an anchor with markup' => ['section', 'x"><script>', false],
]);

it('pins each social network to its own site', function (): void {
    $document = sbDocument();
    $document['footer']['social'] = [['id' => 'soc12345', 'enabled' => true, 'network' => 'instagram', 'url' => 'https://evil.example/instagram']];
    expect(sbRefusal(fn () => (new SiteContent(sbContext()))->normalize($document))->reason)->toBe('social_host');

    $document['footer']['social'][0]['url'] = 'https://www.instagram.com/center';
    expect((new SiteContent(sbContext()))->normalize($document)['footer']['social'][0]['url'])->toBe('https://www.instagram.com/center');
});

it('refuses media that is not in this center library, or of the wrong kind', function (): void {
    $document = sbDocument();
    $document['hero']['image'] = SB_FOREIGN;
    expect(sbRefusal(fn () => (new SiteContent(sbContext()))->normalize($document))->reason)->toBe('media');

    $document['hero']['image'] = SB_VIDEO;
    expect(sbRefusal(fn () => (new SiteContent(sbContext()))->normalize($document))->reason)->toBe('media');

    $document['hero']['image'] = 'cms/landing/x.png';
    expect(sbRefusal(fn () => (new SiteContent(sbContext()))->normalize($document))->reason)->toBe('media');

    $document['hero']['image'] = SB_IMAGE;
    $document['hero']['video'] = SB_VIDEO;
    $clean = (new SiteContent(sbContext()))->normalize($document);
    expect($clean['hero']['image'])->toBe(SB_IMAGE)->and($clean['hero']['video'])->toBe(SB_VIDEO);
});

it('refuses a team member or a service that is not this center\'s', function (): void {
    $document = sbDocument();
    $team = SiteContent::blankSection('team');
    $team['items'] = [SiteContent::blankItem('team')];
    $team['items'][0]['employee_uuid'] = SB_FOREIGN;
    $document['sections']['team'] = $team;

    $refused = sbRefusal(fn () => (new SiteContent(sbContext()))->normalize($document));
    expect($refused->reason)->toBe('reference')
        ->and($refused->path)->toBe('sections.team.items.0.employee_uuid');

    $document['sections']['team']['items'][0]['employee_uuid'] = SB_EMPLOYEE;
    expect((new SiteContent(sbContext()))->normalize($document)['sections']['team']['items'][0]['employee_uuid'])->toBe(SB_EMPLOYEE);

    $featured = SiteContent::blankSection('featured_services');
    $featured['source']['service_uuids'] = [SB_FOREIGN];
    $document['sections']['featured'] = $featured;
    expect(sbRefusal(fn () => (new SiteContent(sbContext()))->normalize($document))->reason)->toBe('reference');
});

it('refuses two sections with the same anchor', function (): void {
    $document = sbDocument();
    $document['sections']['about'] = SiteContent::blankSection('about') + [];
    $document['sections']['about']['anchor'] = 'about';
    $document['sections']['about_2'] = $document['sections']['about'];

    $refused = sbRefusal(fn () => (new SiteContent(sbContext()))->normalize($document));
    expect($refused->reason)->toBe('anchor_taken');
});

it('validates brand-safe choices and ranges', function (): void {
    $document = sbDocument();
    $section = SiteContent::blankSection('services');
    $section['source']['limit'] = 999;
    $document['sections']['services'] = $section;
    expect(sbRefusal(fn () => (new SiteContent(sbContext()))->normalize($document))->reason)->toBe('presentation');

    $section['source']['limit'] = 6;
    $section['background']['color'] = '#ff0000';
    $document['sections']['services'] = $section;
    expect(sbRefusal(fn () => (new SiteContent(sbContext()))->normalize($document))->path)->toBe('sections.services.background.color');
});

it('hydrates stored content: unknown types dropped, order repaired, ids assigned', function (): void {
    $hydrated = SiteContent::hydrate([
        'sections' => [
            'faq' => ['type' => 'faq', 'items' => [['title' => ['en' => 'Q'], 'body' => ['en' => 'A']]]],
            'offers' => ['type' => 'offers'],
            'about' => ['type' => 'about'],
        ],
        'section_order' => ['about', 'ghost', 'faq'],
        'navigation' => [['label' => ['en' => 'Home'], 'link_type' => 'page', 'target' => 'home']],
    ]);

    expect($hydrated['section_order'])->toBe(['about', 'faq'])
        ->and(array_keys($hydrated['sections']))->toBe(['faq', 'about'])
        ->and($hydrated['sections']['faq']['items'][0]['id'])->toMatch('/^[a-z0-9]{8}$/')
        ->and($hydrated['navigation'][0]['id'])->toMatch('/^[a-z0-9]{8}$/')
        ->and($hydrated['header']['sticky'])->toBeTrue();
});
