<?php

declare(strict_types=1);

namespace App\Modules\LandingCms\Domain;

use DomainException;

/**
 * The allow-listed shape of the corporate landing page.
 *
 * CMS content is data, never executable markup. Every text is plain text in
 * the three platform languages; every link is a section anchor, a site path or
 * an http(s) URL; every media path is one this platform uploaded; every
 * presentation choice is one of a fixed list. A crafted Livewire payload
 * cannot smuggle HTML, script URLs, CSS or arbitrary assets into the page.
 *
 * Sections are a keyed map (the key is the section's id) plus `section_order`,
 * so a section can be added, duplicated, removed, reordered and switched off
 * without its fields moving. Pricing and plan comparison are read from the
 * real plans at render time — the CMS holds only their headings.
 */
final class LandingContent
{
    public const LOCALES = ['en', 'ar', 'ckb'];

    public const SECTION_TYPES = ['features', 'modules', 'steps', 'benefits', 'text_media', 'pricing', 'comparison', 'faq', 'cta', 'contact'];

    public const ICONS = ['sparkles', 'calendar', 'queue', 'journey', 'pos', 'loyalty', 'memberships', 'packages', 'reviews', 'reports',
        'conversations', 'customers', 'branches', 'shield', 'check', 'support', 'globe', 'wallet', 'clock', 'star', 'users', 'languages', 'zap', 'layers'];

    public const BACKGROUNDS = ['none', 'tint', 'brand', 'dark', 'image'];

    public const HERO_BACKGROUNDS = ['none', 'color', 'gradient', 'image', 'video'];

    public const HERO_COLORS = ['cream', 'white', 'beige', 'rose', 'chocolate'];

    public const HERO_GRADIENTS = ['rose_cream', 'chocolate_rose', 'beige_white'];

    public const SOCIAL_NETWORKS = ['facebook', 'instagram', 'x', 'linkedin', 'youtube', 'tiktok', 'whatsapp', 'telegram', 'website'];

    private const MAX_SECTIONS = 24;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        $sections = [
            'features' => self::section('features', 'platform', 'Clear workflows from the front desk to the control room.', [
                self::item('Appointments and queue', 'Coordinate bookings, walk-ins, staff and branch resources without losing the day’s rhythm.', 'calendar'),
                self::item('Customer growth', 'Keep customer history, loyalty, memberships and service conversations connected.', 'customers'),
                self::item('Operational clarity', 'Use standard and advanced reporting to understand what is happening across the center.', 'reports'),
            ], eyebrow: 'Platform'),
            'modules' => self::section('modules', 'modules', 'Everything a service center runs on', [
                self::item('Booking and calendar', 'Online booking, staff calendars and branch schedules in one place.', 'calendar'),
                self::item('Queue and visit journey', 'Walk-ins, called tickets and every stage of a visit, from arrival to checkout.', 'journey'),
                self::item('Point of sale and invoices', 'A fast till with shifts, invoices, and cash or transfer collection.', 'pos'),
                self::item('Loyalty, memberships and packages', 'Reward returning customers and sell memberships and session packages.', 'loyalty'),
                self::item('Reviews and reports', 'Collect ratings after each visit and read standard and advanced reports.', 'reports'),
                self::item('Conversations and assistant', 'Handle WhatsApp conversations with an assistant that hands over to your team.', 'conversations'),
            ], eyebrow: 'Modules'),
            'how_it_works' => self::section('steps', 'how-it-works', 'How it works', [
                self::item('Create your center', 'Choose a plan and your center’s address. Your workspace is ready in minutes.', 'sparkles'),
                self::item('Set up services and team', 'Add branches, services, prices and the roles your staff need.', 'users'),
                self::item('Open for bookings', 'Share your public page and start taking bookings and walk-ins.', 'calendar'),
            ]),
            'benefits' => self::section('benefits', 'benefits', 'Built for daily operations', [
                self::item('Separate and secure', 'Every center has its own database, and staff see only what their role allows.', 'shield'),
                self::item('Three languages', 'Arabic, Kurdish Sorani and English, right to left and left to right.', 'languages'),
                self::item('Ready for branches', 'Run several branches with shared customers and their own schedules.', 'branches'),
                self::item('Exact amounts', 'Prices and payments kept in your currency, without rounding surprises.', 'wallet'),
            ]),
            'pricing' => self::section('pricing', 'plans', 'Start with the capabilities your center needs.', [], body: 'Every new center starts with a free trial.'),
            'comparison' => self::section('comparison', 'compare', 'Compare plans', []),
            'faq' => self::section('faq', 'faq', 'Frequently asked questions', [
                self::item('Is there a free trial?', 'Yes. Every new center starts with a free trial, and you choose a plan when it ends.', 'check'),
                self::item('Which languages are supported?', 'Arabic, Kurdish Sorani and English, for your team and your customers.', 'check'),
                self::item('Can I change my plan later?', 'Yes. You can move to another plan or billing cycle, and our team can help.', 'check'),
                self::item('Is my center’s data kept separate?', 'Yes. Every center has its own isolated database.', 'check'),
            ]),
            'cta' => self::section('cta', 'start', 'Ready to give your center one clear workspace?', [], body: 'Create your center in minutes.'),
            'contact' => self::section('contact', 'contact', 'Contact Meta Style', [], body: 'Questions about plans or setup? Our team is here to help.'),
        ];
        $sections['pricing']['background'] = 'tint';
        $sections['comparison']['layout'] = 'list';
        $sections['cta']['background'] = 'brand';
        $sections['cta']['cta'] = self::cta('Create your center', '/register', 'primary');
        $sections['benefits']['layout'] = 'split';
        $sections['benefits']['columns'] = 2;
        $sections['how_it_works']['layout'] = 'list';

        return [
            'header' => [
                'logo_variant' => 'auto',
                'style' => 'solid',
                'sticky' => true,
                'show_language_switcher' => true,
                'primary_cta' => self::cta('Create account', '/register', 'primary'),
                'secondary_cta' => self::cta('', '', 'secondary'),
            ],
            'navigation' => [
                self::navigation('Platform', 'platform'),
                self::navigation('Modules', 'modules'),
                self::navigation('Plans', 'plans'),
                self::navigation('FAQ', 'faq'),
            ],
            'hero' => [
                'enabled' => true,
                'layout' => 'split',
                'eyebrow' => self::localizedDefault('The operating system for beauty and wellness centers'),
                'title' => self::localizedDefault('Run the center. Grow the experience.'),
                'subtitle' => self::localizedDefault('One operating system for service centers'),
                'body' => self::localizedDefault('Bookings, walk-ins, queue, till, loyalty and reports — in one secure workspace built for Arabic, Kurdish and English.'),
                'primary_cta' => self::cta('Create your center', '/register', 'primary'),
                'secondary_cta' => self::cta('Explore the platform', '#platform', 'secondary'),
                'alignment' => 'start',
                'overlay' => 'soft',
                'image' => '',
                'video' => '',
                'background_type' => 'gradient',
                'background_color' => 'cream',
                'background_gradient' => 'rose_cream',
                'background_image' => '',
                'background_video' => '',
                'poster_image' => '',
                'media_alt' => self::localizedDefault(''),
            ],
            'section_order' => array_keys($sections),
            'sections' => $sections,
            'footer' => [
                'enabled' => true,
                'show_logo' => true,
                'description' => self::localizedDefault('One secure workspace for modern service centers.'),
                'email' => '',
                'phone' => '',
                'address' => self::localizedDefault(''),
                'social_links' => [],
                'navigation' => [
                    self::link('Platform', '#platform'),
                    self::link('Plans', '#plans'),
                    self::link('FAQ', '#faq'),
                    self::link('Create account', '/register'),
                ],
                'groups' => [],
                'legal_links' => [],
                'copyright' => self::localizedDefault('Meta Style. All rights reserved.'),
            ],
            'seo' => [
                'title' => self::localizedDefault('Meta Style'),
                'description' => self::localizedDefault('One secure workspace for modern service centers.'),
                'canonical' => '',
                'index' => true,
                'follow' => true,
                'og_title' => self::localizedDefault('Meta Style'),
                'og_description' => self::localizedDefault('One secure workspace for modern service centers.'),
                'og_image' => '',
                'og_image_alt' => self::localizedDefault('Meta Style'),
                'twitter_card' => 'summary_large_image',
            ],
        ];
    }

    /**
     * A fresh section of a type, with that type's sensible defaults.
     *
     * @return array<string, mixed>
     */
    public static function blank(string $type): array
    {
        if (! in_array($type, self::SECTION_TYPES, true)) {
            throw new DomainException(__('sadmin_cms.errors.section_type'));
        }
        $section = self::section($type, '', '', []);
        $section['title'] = self::emptyLocalized();
        if (in_array($type, ['features', 'modules', 'steps', 'benefits', 'faq'], true)) {
            $section['items'] = [self::blankItem()];
        }
        if ($type === 'pricing') {
            $section['options'] = ['default_cycle' => 'monthly', 'show_comparison_link' => true];
        }
        if ($type === 'comparison') {
            $section['options'] = ['show_limits' => true];
        }
        if ($type === 'cta') {
            $section['cta'] = self::cta('Create your center', '/register', 'primary');
            $section['background'] = 'brand';
        }

        return $section;
    }

    /** @return array<string, mixed> */
    public static function blankItem(): array
    {
        return ['title' => self::emptyLocalized(), 'body' => self::emptyLocalized(), 'icon' => 'sparkles', 'image' => '', 'image_alt' => self::emptyLocalized(), 'url' => '', 'enabled' => true];
    }

    /**
     * Stored content brought up to the current shape. Older revisions (fixed
     * sections, no order, hero media in the background slots) still load.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public static function hydrate(array $stored): array
    {
        $defaults = self::defaults();
        $storedSections = is_array($stored['sections'] ?? null) ? $stored['sections'] : null;
        $withoutSections = $stored;
        unset($withoutSections['sections'], $withoutSections['section_order']);

        $content = array_replace_recursive($defaults, $withoutSections);

        foreach (['navigation', 'footer.social_links', 'footer.navigation', 'footer.groups', 'footer.legal_links'] as $path) {
            $value = data_get($stored, $path);
            if (is_array($value)) {
                data_set($content, $path, array_values($value));
            }
        }

        // Older revisions showed `hero.background_image` as the hero picture.
        if (! array_key_exists('background_type', (array) ($stored['hero'] ?? [])) && is_array($stored['hero'] ?? null)) {
            $content['hero']['image'] = $content['hero']['image'] !== '' ? $content['hero']['image'] : (string) ($stored['hero']['background_image'] ?? '');
            $content['hero']['video'] = $content['hero']['video'] !== '' ? $content['hero']['video'] : (string) ($stored['hero']['background_video'] ?? '');
            $content['hero']['background_type'] = 'gradient';
        }

        if ($storedSections !== null) {
            $legacyTypes = ['how_it_works' => 'steps'];
            $sections = [];
            foreach ($storedSections as $id => $section) {
                if (! is_array($section) || ! is_string($id)) {
                    continue;
                }
                $type = (string) ($section['type'] ?? ($legacyTypes[$id] ?? $id));
                if (! in_array($type, self::SECTION_TYPES, true)) {
                    continue;
                }
                $base = $defaults['sections'][$id] ?? self::blank($type);
                $merged = array_replace_recursive($base, $section);
                $merged['type'] = $type;
                if (is_array($section['items'] ?? null)) {
                    $merged['items'] = array_values($section['items']);
                }
                $sections[$id] = $merged;
            }
            $order = is_array($stored['section_order'] ?? null)
                ? array_values(array_filter($stored['section_order'], fn (mixed $id): bool => is_string($id) && isset($sections[$id])))
                : array_values(array_intersect(array_keys($defaults['sections']), array_keys($sections)));
            foreach (array_keys($sections) as $id) {
                if (! in_array($id, $order, true)) {
                    $order[] = $id;
                }
            }
            $content['sections'] = $sections;
            $content['section_order'] = $order;
        }

        return self::fillDefaultTranslations($content);
    }

    /**
     * Revisions saved before the default copy was translated hold the English
     * default with empty Arabic and Kurdish. Where the English is still exactly
     * the default, the missing translations come from the default copy; any
     * text a person wrote is left as it is.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function fillDefaultTranslations(array $value): array
    {
        if (array_keys($value) === self::LOCALES && is_string($value['en']) && isset(self::DEFAULT_COPY[$value['en']])) {
            foreach (['ar', 'ckb'] as $locale) {
                if (($value[$locale] ?? '') === '') {
                    $value[$locale] = self::DEFAULT_COPY[$value['en']][$locale];
                }
            }

            return $value;
        }
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::fillDefaultTranslations($child);
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function normalize(array $input): array
    {
        $input = self::hydrate($input);
        $hero = is_array($input['hero'] ?? null) ? $input['hero'] : [];
        $header = is_array($input['header'] ?? null) ? $input['header'] : [];
        $footer = is_array($input['footer'] ?? null) ? $input['footer'] : [];
        $seo = is_array($input['seo'] ?? null) ? $input['seo'] : [];

        $content = [
            'header' => [
                'logo_variant' => $this->choice($header['logo_variant'] ?? 'auto', ['auto', 'light', 'dark']),
                'style' => $this->choice($header['style'] ?? 'solid', ['solid', 'transparent', 'blur']),
                'sticky' => (bool) ($header['sticky'] ?? true),
                'show_language_switcher' => (bool) ($header['show_language_switcher'] ?? true),
                'primary_cta' => $this->normalizeCta($header['primary_cta'] ?? []),
                'secondary_cta' => $this->normalizeCta($header['secondary_cta'] ?? []),
            ],
            'navigation' => $this->normalizeNavigation($input['navigation'] ?? []),
            'hero' => [
                'enabled' => (bool) ($hero['enabled'] ?? true),
                'layout' => $this->choice($hero['layout'] ?? 'split', ['split', 'centered', 'cover']),
                'eyebrow' => $this->localized($hero['eyebrow'] ?? [], 120),
                'title' => $this->localized($hero['title'] ?? [], 190, true),
                'subtitle' => $this->localized($hero['subtitle'] ?? [], 190),
                'body' => $this->localized($hero['body'] ?? [], 600, true),
                'primary_cta' => $this->normalizeCta($hero['primary_cta'] ?? []),
                'secondary_cta' => $this->normalizeCta($hero['secondary_cta'] ?? []),
                'alignment' => $this->choice($hero['alignment'] ?? 'start', ['start', 'center']),
                'overlay' => $this->choice($hero['overlay'] ?? 'soft', ['none', 'soft', 'strong']),
                'image' => $this->media($hero['image'] ?? '', false),
                'video' => $this->media($hero['video'] ?? '', true),
                'background_type' => $this->choice($hero['background_type'] ?? 'gradient', self::HERO_BACKGROUNDS),
                'background_color' => $this->choice($hero['background_color'] ?? 'cream', self::HERO_COLORS),
                'background_gradient' => $this->choice($hero['background_gradient'] ?? 'rose_cream', self::HERO_GRADIENTS),
                'background_image' => $this->media($hero['background_image'] ?? '', false),
                'background_video' => $this->media($hero['background_video'] ?? '', true),
                'poster_image' => $this->media($hero['poster_image'] ?? '', false),
                'media_alt' => $this->localized($hero['media_alt'] ?? [], 190),
            ],
            'section_order' => [],
            'sections' => [],
            'footer' => [
                'enabled' => (bool) ($footer['enabled'] ?? true),
                'show_logo' => (bool) ($footer['show_logo'] ?? true),
                'description' => $this->localized($footer['description'] ?? [], 600),
                'email' => $this->email($footer['email'] ?? ''),
                'phone' => $this->plain($footer['phone'] ?? '', 48),
                'address' => $this->localized($footer['address'] ?? [], 300),
                'social_links' => $this->normalizeSocial($footer['social_links'] ?? []),
                'navigation' => $this->normalizeLinks($footer['navigation'] ?? [], 12),
                'groups' => $this->normalizeGroups($footer['groups'] ?? []),
                'legal_links' => $this->normalizeLinks($footer['legal_links'] ?? [], 8),
                'copyright' => $this->localized($footer['copyright'] ?? [], 190),
            ],
            'seo' => [
                'title' => $this->localized($seo['title'] ?? [], 70, true),
                'description' => $this->localized($seo['description'] ?? [], 170, true),
                'canonical' => $this->url($seo['canonical'] ?? '', true),
                'index' => (bool) ($seo['index'] ?? true),
                'follow' => (bool) ($seo['follow'] ?? true),
                'og_title' => $this->localized($seo['og_title'] ?? [], 95),
                'og_description' => $this->localized($seo['og_description'] ?? [], 220),
                'og_image' => $this->media($seo['og_image'] ?? '', false),
                'og_image_alt' => $this->localized($seo['og_image_alt'] ?? [], 190),
                'twitter_card' => $this->choice($seo['twitter_card'] ?? 'summary_large_image', ['summary', 'summary_large_image']),
            ],
        ];

        $sections = is_array($input['sections'] ?? null) ? $input['sections'] : [];
        if (count($sections) > self::MAX_SECTIONS) {
            throw new DomainException(__('sadmin_cms.errors.too_many_sections'));
        }
        $anchors = [];
        foreach ($sections as $id => $section) {
            if (! is_string($id) || preg_match('/^[a-z][a-z0-9_]{1,40}$/', $id) !== 1 || ! is_array($section)) {
                throw new DomainException(__('sadmin_cms.errors.section_id'));
            }
            $normalized = $this->normalizeSection($section);
            if ($normalized['anchor'] !== '') {
                if (in_array($normalized['anchor'], $anchors, true)) {
                    throw new DomainException(__('sadmin_cms.errors.anchor_taken', ['anchor' => $normalized['anchor']]));
                }
                $anchors[] = $normalized['anchor'];
            }
            $content['sections'][$id] = $normalized;
        }
        $order = is_array($input['section_order'] ?? null) ? $input['section_order'] : [];
        foreach ($order as $id) {
            if (is_string($id) && isset($content['sections'][$id]) && ! in_array($id, $content['section_order'], true)) {
                $content['section_order'][] = $id;
            }
        }
        foreach (array_keys($content['sections']) as $id) {
            if (! in_array($id, $content['section_order'], true)) {
                $content['section_order'][] = $id;
            }
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function normalizeSection(array $section): array
    {
        $type = $this->choice($section['type'] ?? '', self::SECTION_TYPES);
        $anchor = trim((string) ($section['anchor'] ?? ''));
        if ($anchor !== '' && preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $anchor) !== 1) {
            throw new DomainException(__('sadmin_cms.errors.section_target'));
        }
        $options = is_array($section['options'] ?? null) ? $section['options'] : [];

        return [
            'type' => $type,
            'enabled' => (bool) ($section['enabled'] ?? true),
            'anchor' => $anchor,
            'eyebrow' => $this->localized($section['eyebrow'] ?? [], 120),
            'title' => $this->localized($section['title'] ?? [], 190),
            'body' => $this->localized($section['body'] ?? [], 600),
            'layout' => $this->choice($section['layout'] ?? 'grid', ['grid', 'list', 'split']),
            'columns' => (int) $this->choice((string) ($section['columns'] ?? '3'), ['2', '3', '4']),
            'background' => $this->choice($section['background'] ?? 'none', self::BACKGROUNDS),
            'background_image' => $this->media($section['background_image'] ?? '', false),
            'image' => $this->media($section['image'] ?? '', false),
            'image_alt' => $this->localized($section['image_alt'] ?? [], 190),
            'video' => $this->media($section['video'] ?? '', true),
            'poster' => $this->media($section['poster'] ?? '', false),
            'media_position' => $this->choice($section['media_position'] ?? 'end', ['start', 'end']),
            'cta' => $this->normalizeCta($section['cta'] ?? []),
            'items' => $this->normalizeItems($section['items'] ?? []),
            'options' => [
                'default_cycle' => $this->choice($options['default_cycle'] ?? 'monthly', ['monthly', 'yearly']),
                'show_comparison_link' => (bool) ($options['show_comparison_link'] ?? true),
                'show_limits' => (bool) ($options['show_limits'] ?? true),
            ],
        ];
    }

    /**
     * The default copy in every platform language, keyed by the English.
     */
    private const DEFAULT_COPY = [
        'Create account' => ['ar' => 'إنشاء حساب', 'ckb' => 'هەژمار دروستبکە'],
        'Platform' => ['ar' => 'المنصة', 'ckb' => 'پلاتفۆرم'],
        'Modules' => ['ar' => 'الوحدات', 'ckb' => 'مۆدیولەکان'],
        'Plans' => ['ar' => 'الخطط', 'ckb' => 'پلانەکان'],
        'FAQ' => ['ar' => 'الأسئلة', 'ckb' => 'پرسیارەکان'],
        'The operating system for beauty and wellness centers' => ['ar' => 'نظام تشغيل مراكز التجميل والعناية', 'ckb' => 'سیستەمی کارپێکردنی سەنتەرەکانی جوانکاری و چاودێری'],
        'Run the center. Grow the experience.' => ['ar' => 'أدِر المركز. وطوّر التجربة.', 'ckb' => 'سەنتەرەکە بەڕێوەببە. ئەزموونەکە گەشە پێبدە.'],
        'One operating system for service centers' => ['ar' => 'نظام تشغيل واحد لمراكز الخدمات', 'ckb' => 'یەک سیستەمی کارپێکردن بۆ سەنتەرە خزمەتگوزارییەکان'],
        'Bookings, walk-ins, queue, till, loyalty and reports — in one secure workspace built for Arabic, Kurdish and English.' => ['ar' => 'الحجوزات والزيارات المباشرة وقائمة الانتظار ونقطة البيع والولاء والتقارير — في مساحة عمل آمنة واحدة مصممة للعربية والكردية والإنجليزية.', 'ckb' => 'حجز، سەردانی ڕاستەوخۆ، ڕیز، فرۆشگا، دڵسۆزی و ڕاپۆرت — لە یەک شوێنی کاری پارێزراودا کە بۆ عەرەبی، کوردی و ئینگلیزی دروستکراوە.'],
        'One secure workspace for modern service centers.' => ['ar' => 'مساحة عمل آمنة واحدة لمراكز الخدمات الحديثة.', 'ckb' => 'یەک شوێنی کاری پارێزراو بۆ سەنتەرە خزمەتگوزارییە نوێیەکان.'],
        'Create your center' => ['ar' => 'أنشئ مركزك', 'ckb' => 'سەنتەرەکەت دروستبکە'],
        'Explore the platform' => ['ar' => 'استكشف المنصة', 'ckb' => 'پلاتفۆرمەکە بگەڕێ'],
        'Clear workflows from the front desk to the control room.' => ['ar' => 'سير عمل واضح من مكتب الاستقبال إلى غرفة التحكم.', 'ckb' => 'ڕەوتی کاری ڕوون لە پێشوازییەوە تا ژووری کۆنترۆڵ.'],
        'Appointments and queue' => ['ar' => 'المواعيد وقائمة الانتظار', 'ckb' => 'وعدە و ڕیز'],
        'Coordinate bookings, walk-ins, staff and branch resources without losing the day’s rhythm.' => ['ar' => 'نسّق الحجوزات والزيارات والموظفين وموارد الفروع دون إرباك إيقاع اليوم.', 'ckb' => 'حجز، سەردان، ستاف و سەرچاوەکانی لق ڕێکبخە بەبێ تێکدانی ڕەوتی ڕۆژەکە.'],
        'Customer growth' => ['ar' => 'نمو العملاء', 'ckb' => 'گەشەی کڕیار'],
        'Keep customer history, loyalty, memberships and service conversations connected.' => ['ar' => 'اربط سجل العميل والولاء والعضويات ومحادثات الخدمة.', 'ckb' => 'مێژووی کڕیار، دڵسۆزی، ئەندامێتی و گفتوگۆی خزمەتگوزاری پەیوەست بپارێزە.'],
        'Operational clarity' => ['ar' => 'وضوح العمليات', 'ckb' => 'ڕوونی کارگێڕی'],
        'Use standard and advanced reporting to understand what is happening across the center.' => ['ar' => 'استخدم التقارير القياسية والمتقدمة لفهم ما يجري في المركز.', 'ckb' => 'ڕاپۆرتی ئاسایی و پێشکەوتوو بەکاربهێنە بۆ تێگەیشتن لەوەی لە سەنتەر ڕوودەدات.'],
        'Everything a service center runs on' => ['ar' => 'كل ما يحتاجه مركز الخدمات', 'ckb' => 'هەموو ئەوەی سەنتەرێکی خزمەتگوزاری پێویستیەتی'],
        'Booking and calendar' => ['ar' => 'الحجز والتقويم', 'ckb' => 'حجز و ساڵنامە'],
        'Online booking, staff calendars and branch schedules in one place.' => ['ar' => 'حجز عبر الإنترنت وتقويمات الموظفين وجداول الفروع في مكان واحد.', 'ckb' => 'حجزی ئۆنلاین، ساڵنامەی ستاف و خشتەی لقەکان لە یەک شوێندا.'],
        'Queue and visit journey' => ['ar' => 'قائمة الانتظار ورحلة الزيارة', 'ckb' => 'ڕیز و گەشتی سەردان'],
        'Walk-ins, called tickets and every stage of a visit, from arrival to checkout.' => ['ar' => 'الزيارات المباشرة والتذاكر المنادى عليها وكل مرحلة من الزيارة، من الوصول حتى الدفع.', 'ckb' => 'سەردانی ڕاستەوخۆ، تیکێتی بانگکراو و هەموو قۆناغێکی سەردان، لە گەیشتنەوە تا پارەدان.'],
        'Point of sale and invoices' => ['ar' => 'نقطة البيع والفواتير', 'ckb' => 'فرۆشگا و پسوڵەکان'],
        'A fast till with shifts, invoices, and cash or transfer collection.' => ['ar' => 'نقطة بيع سريعة مع ورديات وفواتير وتحصيل نقدي أو بالتحويل.', 'ckb' => 'فرۆشگایەکی خێرا لەگەڵ شەفت، پسوڵە و وەرگرتنی پارەی نەختینە یان گواستنەوە.'],
        'Loyalty, memberships and packages' => ['ar' => 'الولاء والعضويات والباقات', 'ckb' => 'دڵسۆزی، ئەندامێتی و پاکێجەکان'],
        'Reward returning customers and sell memberships and session packages.' => ['ar' => 'كافئ العملاء العائدين وبع العضويات وباقات الجلسات.', 'ckb' => 'پاداشتی کڕیارە گەڕاوەکان بدەرەوە و ئەندامێتی و پاکێجی دانیشتن بفرۆشە.'],
        'Reviews and reports' => ['ar' => 'التقييمات والتقارير', 'ckb' => 'هەڵسەنگاندن و ڕاپۆرتەکان'],
        'Collect ratings after each visit and read standard and advanced reports.' => ['ar' => 'اجمع التقييمات بعد كل زيارة واطّلع على التقارير القياسية والمتقدمة.', 'ckb' => 'دوای هەر سەردانێک هەڵسەنگاندن کۆبکەرەوە و ڕاپۆرتی ئاسایی و پێشکەوتوو بخوێنەرەوە.'],
        'Conversations and assistant' => ['ar' => 'المحادثات والمساعد', 'ckb' => 'گفتوگۆ و یاریدەدەر'],
        'Handle WhatsApp conversations with an assistant that hands over to your team.' => ['ar' => 'أدِر محادثات واتساب مع مساعد يحوّل المحادثة إلى فريقك عند الحاجة.', 'ckb' => 'گفتوگۆکانی واتسئاپ بەڕێوەببە لەگەڵ یاریدەدەرێک کە گفتوگۆکە دەداتە تیمەکەت.'],
        'How it works' => ['ar' => 'كيف يعمل', 'ckb' => 'چۆن کاردەکات'],
        'Choose a plan and your center’s address. Your workspace is ready in minutes.' => ['ar' => 'اختر خطة وعنوان مركزك. تصبح مساحة عملك جاهزة خلال دقائق.', 'ckb' => 'پلانێک و ناونیشانی سەنتەرەکەت هەڵبژێرە. شوێنی کارەکەت لە چەند خولەکێکدا ئامادە دەبێت.'],
        'Set up services and team' => ['ar' => 'جهّز الخدمات والفريق', 'ckb' => 'خزمەتگوزاری و تیم ڕێکبخە'],
        'Add branches, services, prices and the roles your staff need.' => ['ar' => 'أضف الفروع والخدمات والأسعار والأدوار التي يحتاجها موظفوك.', 'ckb' => 'لق، خزمەتگوزاری، نرخ و ئەو ڕۆڵانەی ستافەکەت پێویستیانە زیاد بکە.'],
        'Open for bookings' => ['ar' => 'ابدأ استقبال الحجوزات', 'ckb' => 'دەستپێکردنی وەرگرتنی حجز'],
        'Share your public page and start taking bookings and walk-ins.' => ['ar' => 'شارك صفحتك العامة وابدأ باستقبال الحجوزات والزيارات المباشرة.', 'ckb' => 'پەڕە گشتییەکەت هاوبەش بکە و دەست بکە بە وەرگرتنی حجز و سەردانی ڕاستەوخۆ.'],
        'Built for daily operations' => ['ar' => 'مصممة للعمليات اليومية', 'ckb' => 'بۆ کارە ڕۆژانەکان دروستکراوە'],
        'Separate and secure' => ['ar' => 'منفصل وآمن', 'ckb' => 'جیا و پارێزراو'],
        'Every center has its own database, and staff see only what their role allows.' => ['ar' => 'لكل مركز قاعدة بياناته الخاصة، ولا يرى الموظفون إلا ما يسمح به دورهم.', 'ckb' => 'هەر سەنتەرێک بنکەی دراوەی خۆی هەیە، و ستاف تەنها ئەوە دەبینن کە ڕۆڵەکەیان ڕێگەی پێدەدات.'],
        'Three languages' => ['ar' => 'ثلاث لغات', 'ckb' => 'سێ زمان'],
        'Arabic, Kurdish Sorani and English, right to left and left to right.' => ['ar' => 'العربية والكردية السورانية والإنجليزية، من اليمين إلى اليسار ومن اليسار إلى اليمين.', 'ckb' => 'عەرەبی، کوردی سۆرانی و ئینگلیزی، لە ڕاستەوە بۆ چەپ و لە چەپەوە بۆ ڕاست.'],
        'Ready for branches' => ['ar' => 'جاهز للفروع', 'ckb' => 'ئامادە بۆ لقەکان'],
        'Run several branches with shared customers and their own schedules.' => ['ar' => 'أدِر عدة فروع بعملاء مشتركين وجداول خاصة بكل فرع.', 'ckb' => 'چەند لقێک بەڕێوەببە بە کڕیاری هاوبەش و خشتەی تایبەت بە هەر لقێک.'],
        'Exact amounts' => ['ar' => 'مبالغ دقيقة', 'ckb' => 'بڕی ورد'],
        'Prices and payments kept in your currency, without rounding surprises.' => ['ar' => 'الأسعار والمدفوعات محفوظة بعملتك دون مفاجآت التقريب.', 'ckb' => 'نرخ و پارەدانەکان بە دراوی خۆت دەپارێزرێن، بەبێ سەرسوڕمانی خڕکردنەوە.'],
        'Start with the capabilities your center needs.' => ['ar' => 'ابدأ بالقدرات التي يحتاجها مركزك.', 'ckb' => 'بەو توانایانە دەستپێبکە کە سەنتەرەکەت پێویستی پێیانە.'],
        'Every new center starts with a free trial.' => ['ar' => 'يبدأ كل مركز جديد بتجربة مجانية.', 'ckb' => 'هەر سەنتەرێکی نوێ بە تاقیکردنەوەیەکی بێبەرامبەر دەست پێدەکات.'],
        'Compare plans' => ['ar' => 'قارن بين الخطط', 'ckb' => 'بەراوردکردنی پلانەکان'],
        'Frequently asked questions' => ['ar' => 'الأسئلة الشائعة', 'ckb' => 'پرسیارە باوەکان'],
        'Is there a free trial?' => ['ar' => 'هل توجد تجربة مجانية؟', 'ckb' => 'تاقیکردنەوەی بێبەرامبەر هەیە؟'],
        'Yes. Every new center starts with a free trial, and you choose a plan when it ends.' => ['ar' => 'نعم. يبدأ كل مركز جديد بتجربة مجانية، وتختار خطة عند انتهائها.', 'ckb' => 'بەڵێ. هەر سەنتەرێکی نوێ بە تاقیکردنەوەیەکی بێبەرامبەر دەست پێدەکات و کاتێک تەواو بوو پلانێک هەڵدەبژێریت.'],
        'Which languages are supported?' => ['ar' => 'ما اللغات المدعومة؟', 'ckb' => 'کام زمانانە پشتگیری دەکرێن؟'],
        'Arabic, Kurdish Sorani and English, for your team and your customers.' => ['ar' => 'العربية والكردية السورانية والإنجليزية، لفريقك ولعملائك.', 'ckb' => 'عەرەبی، کوردی سۆرانی و ئینگلیزی، بۆ تیمەکەت و کڕیارەکانت.'],
        'Can I change my plan later?' => ['ar' => 'هل يمكنني تغيير خطتي لاحقاً؟', 'ckb' => 'دەتوانم دواتر پلانەکەم بگۆڕم؟'],
        'Yes. You can move to another plan or billing cycle, and our team can help.' => ['ar' => 'نعم. يمكنك الانتقال إلى خطة أو دورة فوترة أخرى، ويمكن لفريقنا المساعدة.', 'ckb' => 'بەڵێ. دەتوانیت بگوازیتەوە بۆ پلان یان خولی پارەدانێکی تر، و تیمەکەمان یارمەتیت دەدات.'],
        'Is my center’s data kept separate?' => ['ar' => 'هل تُحفظ بيانات مركزي بشكل منفصل؟', 'ckb' => 'داتای سەنتەرەکەم جیا دەپارێزرێت؟'],
        'Yes. Every center has its own isolated database.' => ['ar' => 'نعم. لكل مركز قاعدة بيانات معزولة خاصة به.', 'ckb' => 'بەڵێ. هەر سەنتەرێک بنکەی دراوەیەکی جیاکراوەی خۆی هەیە.'],
        'Ready to give your center one clear workspace?' => ['ar' => 'هل أنت مستعد لمنح مركزك مساحة عمل واضحة واحدة؟', 'ckb' => 'ئامادەیت یەک شوێنی کاری ڕوون بە سەنتەرەکەت بدەیت؟'],
        'Create your center in minutes.' => ['ar' => 'أنشئ مركزك خلال دقائق.', 'ckb' => 'لە چەند خولەکێکدا سەنتەرەکەت دروستبکە.'],
        'Contact Meta Style' => ['ar' => 'تواصل مع Meta Style', 'ckb' => 'پەیوەندی بە Meta Style بکە'],
        'Questions about plans or setup? Our team is here to help.' => ['ar' => 'لديك أسئلة عن الخطط أو الإعداد؟ فريقنا هنا للمساعدة.', 'ckb' => 'پرسیارت هەیە دەربارەی پلان یان ڕێکخستن؟ تیمەکەمان لێرەیە بۆ یارمەتی.'],
        'Meta Style. All rights reserved.' => ['ar' => 'Meta Style. جميع الحقوق محفوظة.', 'ckb' => 'Meta Style. هەموو مافەکان پارێزراون.'],
        'Meta Style' => ['ar' => 'Meta Style', 'ckb' => 'Meta Style'],
    ];

    /** @return array<string, string> */
    private static function localizedDefault(string $english): array
    {
        $copy = self::DEFAULT_COPY[$english] ?? [];

        return ['en' => $english, 'ar' => $copy['ar'] ?? '', 'ckb' => $copy['ckb'] ?? ''];
    }

    /** @return array<string, string> */
    private static function emptyLocalized(): array
    {
        return ['en' => '', 'ar' => '', 'ckb' => ''];
    }

    /** @return array<string, mixed> */
    private static function cta(string $english, string $url, string $style): array
    {
        return ['label' => self::localizedDefault($english), 'url' => $url, 'style' => $style, 'new_tab' => false, 'enabled' => $english !== ''];
    }

    /** @return array<string, mixed> */
    private static function link(string $english, string $url): array
    {
        return ['label' => self::localizedDefault($english), 'url' => $url, 'new_tab' => false, 'enabled' => true];
    }

    /** @return array<string, mixed> */
    private static function navigation(string $english, string $target): array
    {
        return ['label' => self::localizedDefault($english), 'link_type' => 'section', 'target' => $target, 'style' => 'link', 'new_tab' => false, 'enabled' => true];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private static function section(string $type, string $anchor, string $title, array $items, string $eyebrow = '', string $body = ''): array
    {
        return [
            'type' => $type, 'enabled' => true, 'anchor' => $anchor,
            'eyebrow' => self::localizedDefault($eyebrow), 'title' => self::localizedDefault($title), 'body' => self::localizedDefault($body),
            'layout' => 'grid', 'columns' => 3, 'background' => 'none', 'background_image' => '',
            'image' => '', 'image_alt' => self::emptyLocalized(), 'video' => '', 'poster' => '', 'media_position' => 'end',
            'cta' => self::cta('', '', 'primary'), 'items' => $items,
            'options' => ['default_cycle' => 'monthly', 'show_comparison_link' => true, 'show_limits' => true],
        ];
    }

    /** @return array<string, mixed> */
    private static function item(string $english, string $body, string $icon = 'sparkles'): array
    {
        return ['title' => self::localizedDefault($english), 'body' => self::localizedDefault($body), 'icon' => $icon, 'image' => '', 'image_alt' => self::emptyLocalized(), 'url' => '', 'enabled' => true];
    }

    /**
     * @return array<string, string>
     */
    private function localized(mixed $value, int $max, bool $englishRequired = false): array
    {
        $value = is_array($value) ? $value : [];
        $clean = [];
        foreach (self::LOCALES as $locale) {
            $clean[$locale] = $this->plain($value[$locale] ?? '', $max);
        }
        if ($englishRequired && $clean['en'] === '') {
            throw new DomainException(__('sadmin_cms.errors.english_required'));
        }

        return $clean;
    }

    private function plain(mixed $value, int $max): string
    {
        $value = trim((string) $value);
        if ($value !== strip_tags($value)) {
            throw new DomainException(__('sadmin_cms.errors.text_only'));
        }
        if (mb_strlen($value) > $max) {
            throw new DomainException(__('sadmin_cms.errors.too_long'));
        }

        return $value;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function choice(mixed $value, array $allowed): string
    {
        $value = (string) $value;
        if (! in_array($value, $allowed, true)) {
            throw new DomainException(__('sadmin_cms.errors.presentation'));
        }

        return $value;
    }

    private function url(mixed $value, bool $allowEmpty = false): string
    {
        $value = trim((string) $value);
        if ($value === '' && $allowEmpty) {
            return '';
        }
        if (preg_match('/^(#[a-z0-9][a-z0-9_-]*|\/[a-zA-Z0-9_\-\/.?=&%]*)$/', $value) === 1) {
            return $value;
        }
        if (filter_var($value, FILTER_VALIDATE_URL) !== false && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return $value;
        }
        if ($value === '') {
            return '';
        }

        throw new DomainException(__('sadmin_cms.errors.safe_url'));
    }

    private function email(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException(__('sadmin_cms.errors.email'));
        }

        return $value;
    }

    private function media(mixed $value, bool $video): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $extensions = $video ? 'mp4|webm' : 'jpg|jpeg|png|webp';
        if (str_contains($value, '..') || preg_match('/^cms\/landing\/[a-zA-Z0-9_\-\/.]+\.('.$extensions.')$/i', $value) !== 1) {
            throw new DomainException(__('sadmin_cms.errors.media'));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeCta(mixed $value): array
    {
        $value = is_array($value) ? $value : [];

        return [
            'label' => $this->localized($value['label'] ?? [], 80),
            'url' => $this->url($value['url'] ?? '', true),
            'style' => $this->choice($value['style'] ?? 'primary', ['primary', 'secondary', 'link']),
            'new_tab' => (bool) ($value['new_tab'] ?? false),
            'enabled' => (bool) ($value['enabled'] ?? false),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeNavigation(mixed $value): array
    {
        if (! is_array($value) || count($value) > 12) {
            throw new DomainException(__('sadmin_cms.errors.navigation_limit'));
        }
        $items = [];
        foreach (array_values($value) as $item) {
            $item = is_array($item) ? $item : [];
            $type = $this->choice($item['link_type'] ?? 'section', ['section', 'internal', 'external']);
            $target = trim((string) ($item['target'] ?? ''));
            if ($type === 'section') {
                $target = ltrim($this->plain($target, 64), '#');
                if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $target) !== 1) {
                    throw new DomainException(__('sadmin_cms.errors.section_target'));
                }
            } elseif ($type === 'internal') {
                $target = $this->url($target);
                if (! str_starts_with($target, '/')) {
                    throw new DomainException(__('sadmin_cms.errors.internal_url'));
                }
            } else {
                $target = $this->url($target);
                if (! str_starts_with($target, 'http://') && ! str_starts_with($target, 'https://')) {
                    throw new DomainException(__('sadmin_cms.errors.external_url'));
                }
            }
            $items[] = [
                'label' => $this->localized($item['label'] ?? [], 80, true),
                'link_type' => $type,
                'target' => $target,
                'style' => $this->choice($item['style'] ?? 'link', ['link', 'primary', 'secondary']),
                'new_tab' => (bool) ($item['new_tab'] ?? false),
                'enabled' => (bool) ($item['enabled'] ?? true),
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeItems(mixed $value): array
    {
        if (! is_array($value) || count($value) > 20) {
            throw new DomainException(__('sadmin_cms.errors.section_limit'));
        }
        $items = [];
        foreach (array_values($value) as $item) {
            $item = is_array($item) ? $item : [];
            $items[] = [
                'title' => $this->localized($item['title'] ?? [], 190),
                'body' => $this->localized($item['body'] ?? [], 600),
                'icon' => $this->choice($item['icon'] ?? 'sparkles', self::ICONS),
                'image' => $this->media($item['image'] ?? '', false),
                'image_alt' => $this->localized($item['image_alt'] ?? [], 190),
                'url' => $this->url($item['url'] ?? '', true),
                'enabled' => (bool) ($item['enabled'] ?? true),
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeLinks(mixed $value, int $limit): array
    {
        if (! is_array($value) || count($value) > $limit) {
            throw new DomainException(__('sadmin_cms.errors.link_limit'));
        }
        $links = [];
        foreach (array_values($value) as $link) {
            $link = is_array($link) ? $link : [];
            $links[] = [
                'label' => $this->localized($link['label'] ?? [], 80, true),
                'url' => $this->url($link['url'] ?? ''),
                'new_tab' => (bool) ($link['new_tab'] ?? false),
                'enabled' => (bool) ($link['enabled'] ?? true),
            ];
        }

        return $links;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeSocial(mixed $value): array
    {
        if (! is_array($value) || count($value) > 9) {
            throw new DomainException(__('sadmin_cms.errors.link_limit'));
        }
        $links = [];
        foreach (array_values($value) as $link) {
            $link = is_array($link) ? $link : [];
            $url = $this->url($link['url'] ?? '');
            if (! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://')) {
                throw new DomainException(__('sadmin_cms.errors.external_url'));
            }
            $links[] = [
                'network' => $this->choice($link['network'] ?? 'website', self::SOCIAL_NETWORKS),
                'label' => $this->localized($link['label'] ?? [], 80),
                'url' => $url,
                'new_tab' => true,
                'enabled' => (bool) ($link['enabled'] ?? true),
            ];
        }

        return $links;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeGroups(mixed $value): array
    {
        if (! is_array($value) || count($value) > 4) {
            throw new DomainException(__('sadmin_cms.errors.link_limit'));
        }
        $groups = [];
        foreach (array_values($value) as $group) {
            $group = is_array($group) ? $group : [];
            $groups[] = [
                'title' => $this->localized($group['title'] ?? [], 80, true),
                'links' => $this->normalizeLinks($group['links'] ?? [], 8),
            ];
        }

        return $groups;
    }
}
