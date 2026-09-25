{{--
    The customer's review page.

    docs/22-REVIEWS.md §21. Reached only by an opaque capability link: no login,
    no session, no way from here to any other visit. Nothing on this page links
    anywhere internal, and nothing on it identifies the customer.

    Every value rendered here is escaped by Blade. A center authors no part of
    this page — the only free text on it is what the customer is about to type
    (ADR-038, §56).
--}}
<!DOCTYPE html>
<html lang="{{ $form['locale'] }}" dir="{{ $form['direction'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- A customer's visit has no business in a search index, and the token
         must not leak to anything this page might link to. --}}
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ __('review_public.title') }}</title>

    <style>
        body {
            font-family: system-ui, -apple-system, "Segoe UI", "Noto Sans Arabic", sans-serif;
            margin: 0;
            background: #f4f4f2;
            color: #111;
        }

        .sheet {
            background: #fff;
            max-inline-size: 36rem;
            margin: 1rem auto;
            padding: 1.25rem;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }

        h1 { font-size: 1.35rem; margin: 0 0 0.35rem; }
        .intro { color: #555; margin: 0 0 1.25rem; font-size: 0.95rem; }
        .center { font-weight: 700; }

        fieldset { border: 0; padding: 0; margin: 0 0 1.25rem; }
        legend { font-weight: 600; padding: 0; margin-block-end: 0.25rem; }
        .hint { color: #666; font-size: 0.85rem; margin: 0 0 0.5rem; }

        .stage { border-block-start: 1px solid #eee; padding-block: 0.75rem; }
        .stage-name { font-weight: 600; }
        .stage-by { color: #666; font-size: 0.85rem; }
        .row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-block-start: 0.5rem; flex-wrap: wrap; }
        .row-label { color: #555; font-size: 0.9rem; }

        .scale { display: flex; gap: 0.35rem; }
        .scale input { position: absolute; opacity: 0; pointer-events: none; }
        .scale label {
            inline-size: 2.25rem;
            block-size: 2.25rem;
            display: grid;
            place-items: center;
            border: 1px solid #ccc;
            border-radius: 0.4rem;
            cursor: pointer;
            font-weight: 600;
        }
        .scale input:checked + label { background: #111; color: #fff; border-color: #111; }
        .scale input:focus-visible + label { outline: 2px solid #111; outline-offset: 2px; }

        textarea {
            inline-size: 100%;
            min-block-size: 6rem;
            padding: 0.6rem;
            border: 1px solid #ccc;
            border-radius: 0.4rem;
            font: inherit;
            box-sizing: border-box;
        }

        button {
            inline-size: 100%;
            padding: 0.75rem;
            font: inherit;
            font-weight: 600;
            color: #fff;
            background: #111;
            border: 0;
            border-radius: 0.4rem;
            cursor: pointer;
        }

        .error {
            background: #fdecea;
            border: 1px solid #f5c2c0;
            color: #7a1c18;
            padding: 0.6rem 0.75rem;
            border-radius: 0.4rem;
            margin-block-end: 1rem;
            font-size: 0.9rem;
        }

        .optional { color: #888; font-weight: 400; font-size: 0.85rem; }
    </style>
</head>
<body>
<main class="sheet">
    <h1>{{ __('review_public.title') }}</h1>
    <p class="intro">
        <span class="center">{{ $form['center'] }}</span> —
        {{ __('review_public.intro', ['branch' => $form['branch'], 'date' => $form['visited_on']]) }}
    </p>

    @if (session('review_error'))
        <p class="error">{{ session('review_error') }}</p>
    @endif

    <form method="POST" action="{{ route('review.public.submit', ['center' => request()->route('center'), 'token' => request()->route('token')]) }}">
        @csrf

        <fieldset>
            <legend>{{ __('review_public.overall') }}</legend>
            <p class="hint">{{ __('review_public.overall_hint') }}</p>
            <div class="scale">
                @for ($n = 1; $n <= 5; $n++)
                    <input type="radio" id="overall-{{ $n }}" name="overall" value="{{ $n }}"
                           @checked((int) old('overall') === $n) required>
                    <label for="overall-{{ $n }}" title="{{ __('review_public.stars', ['n' => $n]) }}">{{ $n }}</label>
                @endfor
            </div>
        </fieldset>

        @if ($form['stages'] !== [])
            <fieldset>
                <legend>{{ __('review_public.services') }} <span class="optional">{{ __('review_public.optional') }}</span></legend>

                @foreach ($form['stages'] as $stage)
                    <div class="stage">
                        <div class="stage-name">{{ $stage['service'] }}</div>
                        @if ($stage['employee'] !== null)
                            <div class="stage-by">{{ __('review_public.employee_rating', ['name' => $stage['employee']]) }}</div>
                        @endif

                        <div class="row">
                            <span class="row-label">{{ __('review_public.service_rating') }}</span>
                            <div class="scale">
                                @for ($n = 1; $n <= 5; $n++)
                                    <input type="radio" id="s-{{ $stage['id'] }}-{{ $n }}"
                                           name="service[{{ $stage['id'] }}]" value="{{ $n }}">
                                    <label for="s-{{ $stage['id'] }}-{{ $n }}"
                                           title="{{ __('review_public.stars', ['n' => $n]) }}">{{ $n }}</label>
                                @endfor
                            </div>
                        </div>

                        @if ($stage['employee'] !== null)
                            <div class="row">
                                <span class="row-label">{{ $stage['employee'] }}</span>
                                <div class="scale">
                                    @for ($n = 1; $n <= 5; $n++)
                                        <input type="radio" id="e-{{ $stage['id'] }}-{{ $n }}"
                                               name="employee[{{ $stage['id'] }}]" value="{{ $n }}">
                                        <label for="e-{{ $stage['id'] }}-{{ $n }}"
                                               title="{{ __('review_public.stars', ['n' => $n]) }}">{{ $n }}</label>
                                    @endfor
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </fieldset>
        @endif

        <fieldset>
            <legend>{{ __('review_public.comment') }} <span class="optional">{{ __('review_public.optional') }}</span></legend>
            <textarea name="comment" maxlength="1000"
                      placeholder="{{ __('review_public.comment_placeholder') }}">{{ old('comment') }}</textarea>
        </fieldset>

        <button type="submit">{{ __('review_public.submit') }}</button>
    </form>
</main>
</body>
</html>
