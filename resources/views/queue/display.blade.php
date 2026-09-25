{{--
    The waiting-room television. docs/17-QUEUE.md §§9, 13, 16.

    A NUMBER and a DESTINATION first. Nothing that could identify the person
    holding it — the feed this polls is an allow-list and never carries a name,
    a phone, a note or an employee.

    The feed is the state. This page holds none: a screen that loses its network
    recovers completely on its next successful poll.

    Beside the queue it may play the center's own promotional images and videos
    (muted, never over the call) and cycle its labels through the center's
    languages without reloading. `$config.presentation` is the server's
    allow-listed presentation (DisplayPresentation); the client is
    resources/js/queue-display/display-client.js. The same template renders the
    Manager's preview (`$preview`), which never speaks.
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $displayName }}</title>

    <style>
        :root {
            color-scheme: dark;
            --ink: #f5f5f4;
            --muted: #a8a29e;
            --ground: #0c0a09;
            --panel: #1c1917;
            --call: #292524;
            --accent: #fbbf24;
            --rule: #292524;
            --edge: #44403c;
            --scrim: rgb(12 10 9 / .92);
            --shade: rgb(0 0 0 / .72);
        }

        * { box-sizing: border-box; }

        html, body { height: 100%; }

        body {
            margin: 0;
            background: var(--ground);
            color: var(--ink);
            font-family: system-ui, -apple-system, "Segoe UI", "Noto Sans Arabic", Tahoma, sans-serif;
            display: grid;
            grid-template-rows: auto minmax(0, 1fr) auto;
            overflow: hidden;
        }

        .bar {
            display: flex;
            align-items: center;
            gap: 2vw;
            padding: 1.8vh 3vw;
            font-size: clamp(1rem, 2vw, 1.9rem);
            color: var(--muted);
        }

        /* Aligned to the bar, not to the language: it stays put while the language turns. */
        .bar__branch { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: match-parent; }
        .bar__clock { font-variant-numeric: tabular-nums; unicode-bidi: isolate; }

        .langs { display: flex; gap: .4em; font-size: .62em; letter-spacing: .08em; }
        .langs[hidden] { display: none; }
        .langs span { padding: .2em .7em; border: 1px solid var(--edge); border-radius: 999px; opacity: .5; transition: opacity .4s ease, border-color .4s ease; }
        .langs span[aria-current="true"] { opacity: 1; border-color: var(--ink); color: var(--ink); }

        .stage {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 2.5vw;
            padding-inline: 3vw;
            padding-block-end: 1.5vh;
            min-height: 0;
        }

        /* The queue keeps the larger share: the call is the reason the screen exists. */
        body[data-promo="on"] .stage { grid-template-columns: minmax(0, 6fr) minmax(0, 5fr); }

        .queue {
            display: grid;
            grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
            gap: 3vw;
            min-height: 0;
        }

        body[data-promo="on"] .queue {
            grid-template-columns: minmax(0, 1fr);
            grid-template-rows: minmax(0, 3fr) minmax(0, 2fr);
            gap: 2vh;
        }

        .now {
            position: relative;
            background: var(--panel);
            border-radius: 1.5vw;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 4vh 2vw;
            text-align: center;
            min-height: 0;
            transition: background-color .4s ease, box-shadow .4s ease;
        }

        /* A new call outranks everything else on the wall for a few seconds. */
        body[data-calling="true"] .now { background: var(--call); box-shadow: inset 0 0 0 .35vw var(--accent); }

        .now__label { font-size: clamp(1rem, 2vw, 1.8rem); color: var(--muted); letter-spacing: .08em; }

        .now__number {
            /* Read from across a room: the single most important element. */
            font-size: clamp(4rem, 18vw, 16rem);
            font-weight: 800;
            line-height: 1;
            margin-block: 2vh;
            letter-spacing: .03em;
            unicode-bidi: isolate;
        }

        body[data-promo="on"] .now__number { font-size: clamp(3.5rem, 11vw, 12rem); margin-block: 1.5vh; }

        .now__go { font-size: clamp(1.4rem, 4.4vw, 3.6rem); font-weight: 600; }
        body[data-promo="on"] .now__go { font-size: clamp(1.2rem, 3vw, 2.6rem); }
        .now__go[hidden] { display: none; }
        .now__go span { color: var(--muted); font-weight: 400; margin-inline-end: .35em; }

        .now__sample {
            position: absolute; inset-block-start: 1.5vh; inset-inline-end: 1.5vw;
            padding: .2em .8em; border: 1px dashed var(--muted); border-radius: 999px;
            color: var(--muted); font-size: clamp(.8rem, 1.2vw, 1.1rem);
        }
        .now__sample[hidden] { display: none; }

        .recent { min-height: 0; overflow: hidden; }

        .recent h2 {
            font-size: clamp(1rem, 1.8vw, 1.6rem);
            font-weight: 500;
            color: var(--muted);
            letter-spacing: .08em;
            margin-block: 0 1vh;
        }

        .recent ul { list-style: none; margin: 0; padding: 0; }

        .recent li {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            align-items: baseline;
            gap: 1.2vw;
            padding-block: 1.1vh;
            border-block-end: 1px solid var(--rule);
            font-size: clamp(1.1rem, 2.4vw, 2rem);
        }

        body[data-promo="on"] .recent li { font-size: clamp(1rem, 1.9vw, 1.7rem); padding-block: .8vh; }

        .recent__number { font-weight: 700; unicode-bidi: isolate; }
        .recent__dest { color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .recent__state { font-size: .7em; color: var(--muted); }
        .recent__state[data-state="called"] { color: var(--accent); }

        .empty { color: var(--muted); font-size: clamp(1rem, 2vw, 1.6rem); margin: 0; }
        .empty[hidden] { display: none; }

        /*
         * Promotional media. A cell of its own beside the queue — never an
         * overlay — so no picture can ever cover a number. It dims while a call
         * is fresh and holds still until the call has had its moment.
         */
        .promo {
            position: relative;
            min-height: 0;
            border-radius: 1.5vw;
            overflow: hidden;
            background: #000;
            transition: opacity .5s ease;
        }

        body:not([data-promo="on"]) .promo { display: none; }
        body[data-calling="true"] .promo { opacity: .3; }

        .promo__frame { position: absolute; inset: 0; }

        .slide { position: absolute; inset: 0; margin: 0; opacity: 0; transition: opacity .6s ease; }
        .slide[data-active="true"] { opacity: 1; }
        .slide img, .slide video { display: block; width: 100%; height: 100%; object-fit: contain; background: #000; }

        .promo__caption {
            position: absolute;
            inset-inline: 0;
            inset-block-end: 0;
            margin: 0;
            padding: 3vh 2vw 2vh;
            background: linear-gradient(transparent, var(--shade));
            font-size: clamp(1rem, 2.2vw, 2.1rem);
            font-weight: 600;
        }
        .promo__caption[hidden] { display: none; }

        .foot {
            display: flex;
            justify-content: center;
            padding: .6vh 3vw 1.8vh;
            color: var(--muted);
            font-size: clamp(.9rem, 1.4vw, 1.3rem);
        }

        /* A portrait screen or a small window: the queue on top, media below. */
        @media (max-width: 900px), (orientation: portrait) {
            .queue { grid-template-columns: minmax(0, 1fr); }
            body[data-promo="on"] .stage { grid-template-columns: minmax(0, 1fr); grid-template-rows: minmax(0, 11fr) minmax(0, 9fr); }
            body[data-promo="on"] .queue { grid-template-rows: minmax(0, 1fr) auto; }
            body[data-promo="on"] .recent li:nth-child(n+4) { display: none; }
        }

        @media (prefers-reduced-motion: reduce) {
            .slide, .now, .promo, .langs span { transition: none; }
        }

        /*
         * Screen controls. They change what THIS screen does (sound, full
         * screen), never the queue. Browsers refuse to play audio or speak
         * until somebody has touched the page once, so a screen that wants
         * sound shows a single "start" button when it is switched on.
         */
        .start {
            position: fixed; inset: 0; z-index: 10;
            display: grid; place-items: center; gap: 2vh; align-content: center;
            background: var(--scrim); color: var(--ink); text-align: center;
        }
        .start[hidden] { display: none; }
        .start button {
            padding: 2vh 5vw; border: 2px solid var(--ink); border-radius: 999px;
            background: transparent; color: var(--ink); font: inherit; font-size: clamp(1.2rem, 3vw, 2.4rem); cursor: pointer;
        }
        .start p { margin: 0; color: var(--muted); font-size: clamp(.9rem, 1.6vw, 1.3rem); }
        .tools {
            position: fixed; inset-block-end: 1.5vh; inset-inline-end: 1.5vw; z-index: 5;
            display: flex; gap: .5rem; opacity: 0; transition: opacity .3s ease;
        }
        body.pointer-active .tools, .tools:focus-within { opacity: 1; }
        body:not(.pointer-active) { cursor: none; }
        body[data-preview="true"] { cursor: default; }
        .tools button {
            padding: .5rem 1rem; border: 1px solid var(--edge); border-radius: 999px;
            background: var(--panel); color: var(--ink); font: inherit; font-size: 1rem; cursor: pointer;
        }
        .tools button[hidden] { display: none; }
    </style>
</head>
<body data-promo="off" data-calling="false" data-preview="{{ $preview ? 'true' : 'false' }}">
    <header class="bar" dir="{{ $layoutDirection }}">
        <span class="bar__branch" id="branch" data-panel dir="{{ $direction }}">{{ $branchName }}</span>
        <span class="langs" id="languages" aria-hidden="true" @unless($rotating) hidden @endunless>
            @foreach($languageChips as $chip)
                <span data-lang="{{ $chip['code'] }}" aria-current="{{ $chip['code'] === $locale ? 'true' : 'false' }}">{{ $chip['label'] }}</span>
            @endforeach
        </span>
        <span class="bar__clock" id="clock" dir="ltr" aria-hidden="true"></span>
    </header>

    @if($wantsSound)
        <div class="start" id="start" data-panel dir="{{ $direction }}">
            <button type="button" id="start-button" data-text="start">{{ $text['start'] }}</button>
            <p data-text="start_hint">{{ $text['start_hint'] }}</p>
        </div>
    @endif

    {{-- Pinned to the layout's corner like the panels; only its words turn. --}}
    <div class="tools" role="group" aria-label="{{ $text['controls'] }}" data-label="controls" dir="{{ $layoutDirection }}">
        <button type="button" id="fullscreen" dir="auto">{{ $text['fullscreen'] }}</button>
    </div>

    <main class="stage" dir="{{ $layoutDirection }}">
        <section class="queue">
            <div class="now" aria-live="polite" data-panel dir="{{ $direction }}">
                <span class="now__sample" id="now-sample" data-text="sample_call" hidden>{{ $text['sample_call'] }}</span>
                <div class="now__label" data-text="now_calling">{{ $text['now_calling'] }}</div>
                <div class="now__number" id="now-number" dir="ltr">—</div>
                <div class="now__go" id="now-go" hidden><span data-text="destination">{{ $text['destination'] }}</span><strong id="now-destination" dir="auto"></strong></div>
            </div>

            <div class="recent" data-panel dir="{{ $direction }}">
                <h2 data-text="recently_called">{{ $text['recently_called'] }}</h2>
                <ul id="recent"></ul>
                <p class="empty" id="recent-empty"><span data-text="waiting">{{ $text['waiting'] }}</span>…</p>
            </div>
        </section>

        <aside class="promo" id="promo">
            <div class="promo__frame" id="promo-frame"></div>
            <p class="promo__caption" id="promo-caption" dir="auto" hidden></p>
        </aside>
    </main>

    <footer class="foot" data-panel dir="{{ $direction }}">
        <span data-text="thank_you">{{ $text['thank_you'] }}</span>
    </footer>

    <script type="application/json" id="queue-display-config">@json($config)</script>
    <script>{!! $clientScript !!}</script>
    <script>
        (function () {
            var config = JSON.parse(document.getElementById('queue-display-config').textContent);
            QueueDisplayClient.mount(document, window, config);
        })();
    </script>
</body>
</html>
