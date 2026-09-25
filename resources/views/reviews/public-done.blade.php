{{--
    After the review is in — and for a link that has already been used.

    docs/22-REVIEWS.md §22. One sentence, no resubmission, no promotional
    upsell, and no sign of the token: whoever is here has already spent it.
--}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app(App\Kernel\Localization\LanguageRegistry::class)->direction(app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('review_public.done_title') }}</title>

    <style>
        body {
            font-family: system-ui, -apple-system, "Segoe UI", "Noto Sans Arabic", sans-serif;
            margin: 0;
            background: #f4f4f2;
            color: #111;
            display: grid;
            place-items: center;
            min-block-size: 100vh;
        }

        .sheet {
            background: #fff;
            max-inline-size: 30rem;
            margin: 1rem;
            padding: 2rem 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            text-align: center;
        }

        h1 { font-size: 1.4rem; margin: 0 0 0.5rem; }
        p { color: #555; margin: 0; line-height: 1.5; }
    </style>
</head>
<body>
<main class="sheet">
    <h1>{{ __('review_public.done_title') }}</h1>
    <p>{{ __('review_public.done_body') }}</p>
</main>
</body>
</html>
