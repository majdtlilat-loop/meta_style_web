{{--
    The waiting-room television.

    docs/17-QUEUE.md §§14, 25, 26, 49.

    A NUMBER and a DESTINATION. Nothing that could identify the person holding
    it — the feed this polls is an allow-list and never carries a name, a phone,
    a note or an employee.

    The feed is the state. This page holds none: a screen that loses its network
    recovers completely on its next successful poll, because there is no event
    stream to catch up on (§49).
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $displayName }}</title>

    <style>
        :root {
            color-scheme: dark;
            --ink: #f5f5f4;
            --muted: #a8a29e;
            --ground: #0c0a09;
            --panel: #1c1917;
            --accent: #f5f5f4;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-block-size: 100vh;
            background: var(--ground);
            color: var(--ink);
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            display: flex;
            flex-direction: column;
        }

        header {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            padding: 2vh 3vw;
            font-size: clamp(1rem, 2.2vw, 2rem);
            color: var(--muted);
        }

        main {
            flex: 1;
            display: grid;
            grid-template-columns: 3fr 2fr;
            gap: 3vw;
            padding-inline: 3vw;
            padding-block-end: 3vh;
        }

        @media (max-width: 900px) {
            main { grid-template-columns: 1fr; }
        }

        .now {
            background: var(--panel);
            border-radius: 1.5vw;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 4vh 2vw;
            text-align: center;
        }

        .now .label {
            font-size: clamp(1rem, 2vw, 1.8rem);
            color: var(--muted);
            letter-spacing: 0.08em;
        }

        .now .number {
            /* Read from across a room: the single most important element. */
            font-size: clamp(4rem, 18vw, 16rem);
            font-weight: 800;
            line-height: 1;
            margin-block: 2vh;
            letter-spacing: 0.03em;
        }

        .now .destination {
            font-size: clamp(1.5rem, 5vw, 4rem);
            font-weight: 600;
        }

        .recent h2 {
            font-size: clamp(1rem, 1.8vw, 1.6rem);
            color: var(--muted);
            letter-spacing: 0.08em;
            margin-block-start: 0;
        }

        .recent ul { list-style: none; margin: 0; padding: 0; }

        .recent li {
            display: flex;
            justify-content: space-between;
            gap: 1vw;
            padding-block: 1.2vh;
            border-block-end: 1px solid #292524;
            font-size: clamp(1.1rem, 2.4vw, 2rem);
        }

        .recent .number { font-weight: 700; }
        .recent .destination { color: var(--muted); }

        .empty { color: var(--muted); font-size: clamp(1rem, 2vw, 1.6rem); }
    </style>
</head>
<body>
    <header>
        <span>{{ $branchName }}</span>
        <span id="clock" aria-hidden="true"></span>
    </header>

    <main>
        <section class="now" aria-live="polite">
            <div class="label" id="now-label">{{ __('queue_public.now_calling') }}</div>
            <div class="number" id="now-number">—</div>
            <div class="destination" id="now-destination"></div>
        </section>

        <section class="recent">
            <h2>{{ __('queue_public.recently_called') }}</h2>
            <ul id="recent"></ul>
            <p class="empty" id="recent-empty">{{ __('queue_public.waiting') }}…</p>
        </section>
    </main>

    <script>
        (function () {
            var FEED = @json($feedUrl);

            // Announcements already spoken. The feed hands over a stable
            // `announcement_id` per CALL, so an unchanged poll says nothing and
            // a recall — a new event, a new id — speaks again (correction 2).
            var spoken = Object.create(null);
            var speaking = false;
            var pending = [];

            function text(id, value) {
                var el = document.getElementById(id);
                if (el) { el.textContent = value == null ? '' : value; }
            }

            function renderRecent(rows) {
                var list = document.getElementById('recent');
                var empty = document.getElementById('recent-empty');

                list.innerHTML = '';
                empty.hidden = rows.length > 0;

                rows.forEach(function (row) {
                    var li = document.createElement('li');

                    var number = document.createElement('span');
                    number.className = 'number';
                    number.textContent = row.number;

                    var dest = document.createElement('span');
                    dest.className = 'destination';
                    dest.textContent = row.destination_code || '';

                    li.appendChild(number);
                    li.appendChild(dest);
                    list.appendChild(li);
                });
            }

            /*
             * A small announcement queue, so pressing recall three times does
             * not produce three overlapping voices. No audio mixing, no backend
             * synthesis — just one utterance at a time (§31).
             */
            function drain() {
                if (speaking || pending.length === 0) { return; }

                var next = pending.shift();

                if (!('speechSynthesis' in window)) { chime(); return drain(); }

                var voices = window.speechSynthesis.getVoices() || [];
                var said = false;

                Object.keys(next.lines).forEach(function (locale) {
                    var voice = voices.filter(function (v) {
                        return v.lang && v.lang.toLowerCase().indexOf(locale.toLowerCase()) === 0;
                    })[0];

                    /*
                     * Only a locale the browser can actually pronounce. Kurdish
                     * Sorani has effectively no voice anywhere, so it is shown
                     * and not spoken — substituting an Arabic voice for Kurdish
                     * text would be pretending (§16).
                     */
                    if (!voice) { return; }

                    var utterance = new SpeechSynthesisUtterance(next.lines[locale]);
                    utterance.voice = voice;
                    utterance.lang = voice.lang;
                    said = true;

                    window.speechSynthesis.speak(utterance);
                });

                if (!said) { chime(); }

                speaking = true;
                window.setTimeout(function () { speaking = false; drain(); }, 2500);
            }

            function chime() {
                try {
                    var ctx = new (window.AudioContext || window.webkitAudioContext)();
                    var osc = ctx.createOscillator();
                    var gain = ctx.createGain();
                    osc.connect(gain); gain.connect(ctx.destination);
                    osc.frequency.value = 880;
                    gain.gain.setValueAtTime(0.15, ctx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
                    osc.start(); osc.stop(ctx.currentTime + 0.4);
                } catch (e) { /* a silent screen is still a working screen */ }
            }

            function apply(payload) {
                var now = payload.now_calling;

                text('now-number', now ? now.number : '—');
                text('now-destination', now ? (now.destination_name || now.destination_code || '') : '');

                renderRecent(payload.recent || []);

                var announcement = payload.announcement;

                if (announcement && announcement.announcement_id && !spoken[announcement.announcement_id]) {
                    spoken[announcement.announcement_id] = true;

                    if (payload.display && payload.display.voice_enabled) {
                        pending.push(announcement);
                        drain();
                    } else if (payload.display && payload.display.sound_enabled) {
                        chime();
                    }
                }
            }

            function poll() {
                fetch(FEED, { headers: { 'Accept': 'application/json' }, cache: 'no-store' })
                    .then(function (r) { return r.ok ? r.json() : null; })
                    .then(function (body) {
                        if (body && body.data) { apply(body.data); }
                    })
                    // A failed poll is not an error state: the next one is a
                    // complete re-read of the canonical feed (§49).
                    .catch(function () {});
            }

            function tick() {
                var el = document.getElementById('clock');
                if (el) { el.textContent = new Date().toLocaleTimeString(); }
            }

            poll();
            tick();
            window.setInterval(poll, 3000);
            window.setInterval(tick, 1000);
        })();
    </script>
</body>
</html>
