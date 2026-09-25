/*
 * The waiting-room television — client. docs/17-QUEUE.md §§9, 13, 16.
 *
 * Inlined into resources/views/queue/display.blade.php (no bundler, no
 * dependency): a television loads it once and leaves it running all day.
 * Written in ES5 on purpose — TV browsers are old.
 *
 * Two halves:
 *
 *   core   logic with no page of its own — the language rotation, the
 *          new-call ledger, the view model, the media carousel, the poll loop,
 *          the wiring between them (`createScreen`) and the promotional media
 *          element (`createMediaElement`, given any document). Tested with
 *          `node --test tests/Js/queue-display-client.test.mjs` (tests/Unit/Queue/QueueDisplayClientTest.php).
 *   mount  the DOM glue: rendering, speech, the chime, media elements.
 *
 * The rules this file keeps:
 *
 *   - The FEED is the queue. The page holds no queue state beyond the last
 *     payload it rendered; a missed poll is recovered by the next one.
 *   - A new call is recognised by `call_key` ONLY — an opaque digest of the
 *     call event, present on every screen whether it speaks or not. The screen
 *     acts once per key: it speaks when the feed carries an `announcement`
 *     (only a screen that may speak gets one), otherwise it chimes when its
 *     sound is on. The screen's language never takes part: switching
 *     EN → AR → KU re-labels what is already on screen and can never speak,
 *     chime or highlight a call again.
 *   - The language rotation is presentation state. It does not poll, does not
 *     touch the carousel's timer, and never waits on the network.
 *   - Promotional media is always muted and never covers the call.
 */
var QueueDisplayClient = (function () {
    'use strict';

    /* ------------------------------------------------------------------ core */

    function has(object, key) {
        return object !== null && typeof object === 'object' && Object.prototype.hasOwnProperty.call(object, key);
    }

    /**
     * The languages a screen cycles and where it starts, from the server's
     * presentation. The server already narrowed them to the center's enabled
     * languages; the client never adds one.
     */
    function rotationPlan(presentation) {
        var rotation = (presentation && presentation.rotation) || {};
        var languages = (presentation && presentation.languages) || {};
        var locales = [];
        var list = rotation.locales || [];

        for (var i = 0; i < list.length; i++) {
            if (has(languages, list[i]) && locales.indexOf(list[i]) === -1) {
                locales.push(list[i]);
            }
        }

        var start = presentation && has(languages, presentation.start) ? presentation.start : (locales[0] || null);

        if (locales.length === 0 && start !== null) {
            locales = [start];
        }

        var seconds = Math.max(5, Math.min(60, parseInt(rotation.seconds, 10) || 10));

        return {
            locales: locales,
            start: start,
            seconds: seconds,
            enabled: rotation.enabled === true && locales.length > 1,
        };
    }

    /**
     * EN → (n s) → AR → (n s) → KU → EN …
     *
     * One timer, ever. `start()` twice does not start two loops; `stop()`
     * clears it. `canSwitch()` lets the page postpone a switch by a moment
     * while a media transition is running, so a direction flip never lands in
     * the middle of a fade.
     */
    function Rotator(options) {
        this.locales = options.locales || [];
        this.seconds = options.seconds || 10;
        this.onChange = options.onChange || function () {};
        this.canSwitch = options.canSwitch || function () { return true; };
        this.setTimer = options.setTimer;
        this.clearTimer = options.clearTimer;
        this.index = Math.max(0, this.locales.indexOf(options.start));
        this.timer = null;
    }

    Rotator.prototype.current = function () {
        return this.locales[this.index] || null;
    };

    Rotator.prototype.running = function () {
        return this.timer !== null;
    };

    Rotator.prototype.start = function () {
        this.stop();

        if (this.locales.length < 2) {
            return;
        }

        this.schedule(this.seconds * 1000);
    };

    Rotator.prototype.stop = function () {
        if (this.timer !== null) {
            this.clearTimer(this.timer);
            this.timer = null;
        }
    };

    Rotator.prototype.schedule = function (ms) {
        var self = this;

        this.timer = this.setTimer(function () {
            self.timer = null;
            self.tick();
        }, ms);
    };

    Rotator.prototype.tick = function () {
        if (!this.canSwitch()) {
            this.schedule(300);

            return;
        }

        this.index = (this.index + 1) % this.locales.length;
        this.onChange(this.current());
        this.schedule(this.seconds * 1000);
    };

    /**
     * Which calls this screen has already acted on.
     *
     * Keyed by the feed's `call_key` and nothing else. Bounded, so a screen
     * left on for a week does not accumulate every call it ever saw.
     */
    function AnnouncementLedger(limit) {
        this.limit = limit || 200;
        this.seen = Object.create(null);
        this.order = [];
    }

    /** True exactly once per id; false for a missing id. */
    AnnouncementLedger.prototype.fresh = function (id) {
        if (typeof id !== 'string' || id === '') {
            return false;
        }

        if (this.seen[id] === true) {
            return false;
        }

        this.seen[id] = true;
        this.order.push(id);

        while (this.order.length > this.limit) {
            delete this.seen[this.order.shift()];
        }

        return true;
    };

    AnnouncementLedger.prototype.size = function () {
        return this.order.length;
    };

    /**
     * The key a new call is recognised by: the feed's opaque `call_key`, the
     * same whether or not the screen may speak — never the language.
     */
    function callKey(payload) {
        var key = payload && payload.call_key;

        return typeof key === 'string' && key !== '' ? key : null;
    }

    /**
     * What a screen does for a NEW call: speak when the feed sent words and the
     * screen may speak (the server sends them only then), otherwise chime when
     * its sound is on, otherwise nothing.
     */
    function cue(announcement, display) {
        var screen = display || {};

        if (announcement && typeof announcement === 'object' && screen.voice_enabled === true) {
            return 'speak';
        }

        return screen.sound_enabled === true ? 'chime' : null;
    }

    function textsFor(presentation, locale) {
        var languages = (presentation && presentation.languages) || {};
        var own = has(languages, locale) ? languages[locale] : null;
        var start = presentation && has(languages, presentation.start) ? languages[presentation.start] : null;

        return {
            dir: own ? own.dir : (start ? start.dir : 'ltr'),
            label: own ? own.label : '',
            branch: own && own.branch ? own.branch : (start && start.branch ? start.branch : ''),
            text: own ? own.text : (start ? start.text : {}),
        };
    }

    /** One called ticket, labelled in `locale`. */
    function lineView(line, locale) {
        if (!line) {
            return null;
        }

        var names = line.destination_names || {};
        var name = has(names, locale) && names[locale] ? names[locale] : (line.destination_name || '');

        return {
            key: line.announcement_id || line.number,
            number: line.number || '',
            code: line.destination_code || '',
            destination: name || line.destination_code || '',
            state: line.state || '',
        };
    }

    /**
     * Everything the page draws, for one language. Pure: the same payload in
     * three languages gives three views with the same tickets.
     */
    function view(presentation, payload, locale) {
        var labels = textsFor(presentation, locale);
        var recent = [];
        var rows = (payload && payload.recent) || [];

        for (var i = 0; i < rows.length; i++) {
            recent.push(lineView(rows[i], locale));
        }

        return {
            locale: locale,
            dir: labels.dir,
            label: labels.label,
            branch: labels.branch,
            text: labels.text || {},
            now: lineView(payload && payload.now_calling, locale),
            recent: recent,
        };
    }

    /**
     * The screen's state machine, without a DOM.
     *
     * hooks:   render(view), speak(announcement), chime(), highlight(line),
     *          presentation(next, previous)
     * options: lockLocale — a preview pinned to one language;
     *          silent — a preview, which never speaks or chimes
     */
    function createController(initial, hooks, options) {
        var state = {
            presentation: initial,
            locale: rotationPlan(initial).start,
            payload: null,
            ledger: new AnnouncementLedger(200),
            lastCall: null,
            locked: options && options.lockLocale ? options.lockLocale : null,
            silent: !!(options && options.silent),
        };

        if (state.locked && has(initial.languages, state.locked)) {
            state.locale = state.locked;
        }

        function current() {
            return view(state.presentation, state.payload, state.locale);
        }

        function setPresentation(next) {
            var previous = state.presentation;
            state.presentation = next;

            var plan = rotationPlan(next);

            if (plan.locales.indexOf(state.locale) === -1) {
                state.locale = plan.start;
            }

            hooks.presentation(next, previous);
        }

        return {
            locale: function () { return state.locale; },
            presentation: function () { return state.presentation; },
            view: current,

            /** One poll's payload. The ONLY path that can speak or chime. */
            receive: function (payload) {
                if (payload && payload.presentation && payload.presentation.version !== state.presentation.version) {
                    setPresentation(payload.presentation);
                }

                state.payload = payload;

                var key = callKey(payload);

                // Once per new call, whatever the language; a poll that sees
                // the same key does nothing.
                if (!state.silent && key !== null && state.ledger.fresh(key)) {
                    var action = cue(payload.announcement, payload.display);

                    if (action === 'speak') {
                        hooks.speak(payload.announcement);
                    } else if (action === 'chime') {
                        hooks.chime();
                    }
                }

                var now = payload && payload.now_calling;
                var callId = now ? (now.announcement_id || now.number) : null;

                if (callId !== null && callId !== state.lastCall) {
                    var first = state.lastCall === null;
                    state.lastCall = callId;

                    if (!first || isRecent(now, payload)) {
                        hooks.highlight(lineView(now, state.locale));
                    }
                } else if (callId === null) {
                    state.lastCall = null;
                }

                hooks.render(current());
            },

            /** Presentation only: re-labels the last payload. Never speaks or chimes. */
            switchLocale: function (locale) {
                if (!has(state.presentation.languages, locale)) {
                    return false;
                }

                state.locale = locale;
                hooks.render(current());

                return true;
            },

            spokenCount: function () { return state.ledger.size(); },
        };
    }

    /** A call made in the last minute, by the server's clock. */
    function isRecent(line, payload) {
        var called = Date.parse(line && line.called_at);
        var now = Date.parse(payload && payload.server_time);

        return !isNaN(called) && !isNaN(now) && now - called < 60000;
    }

    /**
     * The promotional playlist: images for `slideSeconds`, videos until they
     * end (capped), failed items skipped for a while and retried later, and a
     * `hold()` that freezes advancing while a call is on screen.
     */
    function Carousel(options) {
        this.setTimer = options.setTimer;
        this.clearTimer = options.clearTimer;
        this.now = options.now || function () { return Date.now(); };
        this.onShow = options.onShow || function () {};
        this.maxVideoSeconds = options.maxVideoSeconds || 180;
        this.retryMs = options.retryMs || 300000;
        this.items = [];
        this.slideSeconds = 8;
        this.index = -1;
        this.timer = null;
        this.heldUntil = 0;
        this.failedUntil = Object.create(null);
    }

    Carousel.prototype.setItems = function (items, slideSeconds) {
        var currentUrl = this.index >= 0 && this.items[this.index] ? this.items[this.index].url : null;

        this.items = items || [];
        this.slideSeconds = Math.max(4, Math.min(60, parseInt(slideSeconds, 10) || 8));
        this.failedUntil = Object.create(null);

        var keep = -1;

        for (var i = 0; i < this.items.length; i++) {
            if (this.items[i].url === currentUrl) {
                keep = i;
            }
        }

        this.stop();

        if (this.items.length === 0) {
            this.index = -1;
            this.onShow(-1, null);

            return;
        }

        this.index = keep;

        if (keep === -1) {
            this.next();
        } else {
            this.arm(this.items[keep]);
        }
    };

    Carousel.prototype.stop = function () {
        if (this.timer !== null) {
            this.clearTimer(this.timer);
            this.timer = null;
        }
    };

    Carousel.prototype.playable = function (i) {
        var item = this.items[i];

        return !!item && !(this.failedUntil[item.url] > this.now());
    };

    Carousel.prototype.next = function () {
        this.stop();

        var count = this.items.length;

        for (var step = 1; step <= count; step++) {
            var candidate = (this.index + step + count) % count;

            if (this.playable(candidate)) {
                var changed = candidate !== this.index;
                this.index = candidate;
                this.onShow(candidate, this.items[candidate], changed);
                this.arm(this.items[candidate]);

                return;
            }
        }

        // Nothing playable right now: the queue takes the whole screen, and
        // the playlist is tried again later.
        this.index = -1;
        this.onShow(-1, null);

        var self = this;
        this.timer = this.setTimer(function () {
            self.timer = null;
            self.failedUntil = Object.create(null);
            self.next();
        }, this.retryMs);
    };

    Carousel.prototype.arm = function (item) {
        var self = this;
        var single = this.items.length === 1;

        if (single) {
            // One item stays up: a picture does not "rotate" to itself, and a
            // single video loops on its own element.
            return;
        }

        var ms = item.kind === 'video' ? this.maxVideoSeconds * 1000 : this.slideSeconds * 1000;

        this.timer = this.setTimer(function () {
            self.timer = null;
            self.advance();
        }, ms);
    };

    /** Advance unless a call is holding the playlist still. */
    Carousel.prototype.advance = function () {
        var wait = this.heldUntil - this.now();

        if (wait > 0) {
            var self = this;
            this.stop();
            this.timer = this.setTimer(function () {
                self.timer = null;
                self.advance();
            }, wait);

            return;
        }

        this.next();
    };

    Carousel.prototype.hold = function (ms) {
        this.heldUntil = Math.max(this.heldUntil, this.now() + ms);
    };

    /** The current video finished. */
    Carousel.prototype.ended = function (i) {
        if (i === this.index && this.items.length > 1) {
            this.stop();
            this.advance();
        }
    };

    /** An item could not load or play: skip it for a while, never loop on it. */
    Carousel.prototype.failed = function (i) {
        var item = this.items[i];

        if (!item) {
            return;
        }

        this.failedUntil[item.url] = this.now() + this.retryMs;

        if (i === this.index) {
            this.next();
        }
    };

    /**
     * The feed loop: one request awaited at a time, the next one scheduled
     * only once the last has settled.
     *
     * A request that never answers is abandoned after `timeoutMs` (aborted
     * where the browser can) and the loop carries on: one stalled connection
     * must never freeze a screen that is left on all day. A late answer from an
     * abandoned request is ignored, so an old read can never overwrite a newer
     * one. A failed or refused poll is not an error state — the next one is a
     * complete re-read of the canonical feed.
     *
     * options: request(signal) → Promise, receive(payload), setTimer,
     *          clearTimer, intervalMs, timeoutMs, abortable() → AbortController|null
     */
    function Poller(options) {
        this.request = options.request;
        this.receive = options.receive || function () {};
        this.setTimer = options.setTimer;
        this.clearTimer = options.clearTimer;
        this.intervalMs = options.intervalMs || 3000;
        this.timeoutMs = options.timeoutMs || 20000;
        this.abortable = options.abortable || function () { return null; };
        this.awaiting = 0;
        this.next = null;
        this.stopped = true;
    }

    Poller.prototype.start = function () {
        this.stopped = false;

        if (this.awaiting === 0 && this.next === null) {
            this.poll();
        }
    };

    Poller.prototype.stop = function () {
        this.stopped = true;

        if (this.next !== null) {
            this.clearTimer(this.next);
            this.next = null;
        }
    };

    Poller.prototype.poll = function () {
        var self = this;
        var settled = false;
        var abort = this.abortable();
        var pending = null;

        this.next = null;
        this.awaiting += 1;

        var watchdog = this.setTimer(function () {
            if (abort) {
                try { abort.abort(); } catch (e) { /* already settled */ }
            }

            settle();
        }, this.timeoutMs);

        function settle() {
            if (settled) {
                return;
            }

            settled = true;
            self.clearTimer(watchdog);
            self.awaiting -= 1;

            if (!self.stopped) {
                self.next = self.setTimer(function () { self.poll(); }, self.intervalMs);
            }
        }

        try {
            pending = this.request(abort ? abort.signal : null);
        } catch (e) {
            pending = null;
        }

        if (!pending || typeof pending.then !== 'function') {
            settle();

            return;
        }

        pending
            .then(function (payload) {
                if (!settled && payload) {
                    self.receive(payload);
                }
            }, function () {})
            .then(settle, settle);
    };

    /**
     * The element one promotional item plays in, built in `doc` (the page's
     * document, or any object with createElement).
     *
     * A video is MUTED, always — `muted`, `defaultMuted` and the attribute, so
     * no browser default can bring the sound back — plays inline, offers no
     * controls and no picture-in-picture, and fetches nothing until its turn:
     * the file sits in `data-src` until the carousel shows it. A promotional
     * clip never competes with the ticket voice, and muted autoplay is what
     * browsers allow.
     */
    function createMediaElement(doc, item) {
        var media;

        if (item.kind === 'video') {
            media = doc.createElement('video');
            media.muted = true;
            media.defaultMuted = true;
            media.setAttribute('muted', '');
            media.setAttribute('playsinline', '');
            media.setAttribute('disablepictureinpicture', '');
            media.preload = 'none';

            var source = doc.createElement('source');
            source.setAttribute('data-src', item.url);
            source.type = item.type || '';
            media.appendChild(source);

            return media;
        }

        media = doc.createElement('img');
        media.decoding = 'async';
        media.setAttribute('data-src', item.url);

        return media;
    }

    /**
     * The screen's moving parts wired together — the controller, the language
     * rotation and the media carousel — with every DOM effect left to hooks.
     * `mount()` is this plus the page. Kept here so the links between them are
     * tested as they run on the wall:
     *
     *   - the rotation re-labels through the controller and nothing else: it
     *     never advances, resets or re-arms the carousel;
     *   - it waits while a slide is fading in, so a direction flip never lands
     *     mid-transition;
     *   - a pinned preview (`lockLocale`) never starts it;
     *   - a call holds the carousel still while it is highlighted.
     *
     * options: presentation, lockLocale, silent, setTimer, clearTimer, now,
     *          highlightMs, transitionMs,
     *          hooks: render(view), speak(announcement), chime(), highlight(line),
     *                 languages(presentation, plan, pinned, locale), playlist(items),
     *                 show(index, item, changed, locale)
     */
    function createScreen(options) {
        var hooks = options.hooks;
        var setTimer = options.setTimer;
        var clearTimer = options.clearTimer;
        var lockLocale = options.lockLocale || null;
        var highlightMs = options.highlightMs || 12000;
        var transitionMs = options.transitionMs || 700;
        var transitioning = false;
        var transitionTimer = null;
        var rotator = null;
        var controller = null;

        var carousel = new Carousel({
            setTimer: setTimer,
            clearTimer: clearTimer,
            now: options.now,
            onShow: function (index, item, changed) {
                if (index >= 0) {
                    transitioning = true;

                    if (transitionTimer !== null) {
                        clearTimer(transitionTimer);
                    }

                    transitionTimer = setTimer(function () {
                        transitionTimer = null;
                        transitioning = false;
                    }, transitionMs);
                }

                hooks.show(index, item, changed, controller ? controller.locale() : rotationPlan(options.presentation).start);
            },
        });

        function startRotation(next) {
            if (rotator) {
                rotator.stop();
            }

            var plan = rotationPlan(next);

            rotator = new Rotator({
                locales: plan.locales,
                start: controller.locale(),
                seconds: plan.seconds,
                canSwitch: function () { return !transitioning; },
                onChange: function (locale) { controller.switchLocale(locale); },
                setTimer: setTimer,
                clearTimer: clearTimer,
            });

            // A preview pinned to one language shows that language, full stop.
            if (plan.enabled && !lockLocale) {
                rotator.start();
            }
        }

        function applyPresentation(next) {
            var promo = next.promo;
            var items = promo && promo.enabled ? (promo.items || []) : [];

            hooks.languages(next, rotationPlan(next), !!lockLocale, controller.locale());
            hooks.playlist(items);
            carousel.setItems(items, promo ? promo.slide_seconds : 8);
            startRotation(next);
        }

        function highlight(line) {
            carousel.hold(highlightMs);
            hooks.highlight(line);
        }

        controller = createController(options.presentation, {
            render: hooks.render,
            speak: hooks.speak,
            chime: hooks.chime,
            highlight: highlight,
            presentation: function (next) { applyPresentation(next); },
        }, { lockLocale: lockLocale, silent: options.silent === true });

        applyPresentation(options.presentation);

        return {
            controller: controller,
            carousel: carousel,
            receive: controller.receive,
            locale: controller.locale,
            view: controller.view,
            presentation: controller.presentation,
            /** Whether the language is turning by itself right now. */
            rotating: function () { return rotator !== null && rotator.running(); },
            /** The call-over-media moment without a call: the preview's sample. */
            highlight: function () { highlight(null); },
        };
    }

    /* ----------------------------------------------------------------- mount */

    function mount(doc, win, config) {
        var body = doc.body;
        var root = doc.documentElement;
        var feedUrl = config.feed;
        var preview = config.preview === true;
        var pollMs = 3000;
        // Far above a healthy feed's answer, far below "the screen froze".
        var pollTimeoutMs = 20000;
        var highlightMs = 12000;
        var byUrl = Object.create(null);
        var slides = [];
        var highlightTimer = null;
        var formatters = Object.create(null);
        var screen = null;

        function el(id) {
            return doc.getElementById(id);
        }

        function text(node, value) {
            if (node) {
                node.textContent = value === null || value === undefined ? '' : value;
            }
        }

        function currentLocale() {
            return screen ? screen.locale() : rotationPlan(config.presentation).start;
        }

        /* ---- rendering ---- */

        function render(v) {
            root.lang = v.locale || root.lang;
            root.dir = v.dir || root.dir;

            var panels = doc.querySelectorAll('[data-panel]');

            for (var p = 0; p < panels.length; p++) {
                panels[p].dir = v.dir;
            }

            var labels = doc.querySelectorAll('[data-text]');

            for (var i = 0; i < labels.length; i++) {
                var key = labels[i].getAttribute('data-text');

                if (has(v.text, key)) {
                    text(labels[i], v.text[key]);
                }
            }

            // Accessible names switch with the rest of the screen.
            var named = doc.querySelectorAll('[data-label]');

            for (var n = 0; n < named.length; n++) {
                var name = named[n].getAttribute('data-label');

                if (has(v.text, name)) {
                    named[n].setAttribute('aria-label', v.text[name]);
                }
            }

            text(el('branch'), v.branch);
            renderLanguages(v.locale);

            var now = v.now;
            var sample = false;

            if (!now && preview && config.sample) {
                now = { number: 'A001', destination: '', code: '', state: 'called' };
                sample = true;
            }

            text(el('now-number'), now ? now.number : '—');
            text(el('now-destination'), now ? now.destination : '');
            el('now-go').hidden = !now || now.destination === '';
            el('now-sample').hidden = !sample;

            var list = el('recent');
            var fragment = doc.createDocumentFragment();

            for (var r = 0; r < v.recent.length; r++) {
                var row = v.recent[r];
                var li = doc.createElement('li');
                var number = doc.createElement('span');
                var dest = doc.createElement('span');
                var state = doc.createElement('span');

                number.className = 'recent__number';
                number.dir = 'ltr';
                number.textContent = row.number;
                dest.className = 'recent__dest';
                dest.dir = 'auto';
                dest.textContent = row.code || row.destination;
                state.className = 'recent__state';

                if (row.state === 'called' || row.state === 'serving') {
                    state.textContent = v.text['state_' + row.state] || '';
                    state.setAttribute('data-state', row.state);
                }

                li.appendChild(number);
                li.appendChild(dest);
                li.appendChild(state);
                fragment.appendChild(li);
            }

            // Replaced, never appended to: the list is always at most the
            // screen's recent-call limit, however long it stays on.
            while (list.firstChild) {
                list.removeChild(list.firstChild);
            }

            list.appendChild(fragment);
            el('recent-empty').hidden = v.recent.length > 0;

            showCaption(v.locale);
            syncFullscreenLabel(v.text);
            tick();
        }

        function renderLanguages(locale) {
            var chips = doc.querySelectorAll('[data-lang]');

            for (var i = 0; i < chips.length; i++) {
                chips[i].setAttribute('aria-current', chips[i].getAttribute('data-lang') === locale ? 'true' : 'false');
            }
        }

        /* ---- speech and chime (docs/17 §§13, 16) ---- */

        var speaking = false;
        var pending = [];

        function drain() {
            if (speaking || pending.length === 0) {
                return;
            }

            var next = pending.shift();

            if (!('speechSynthesis' in win)) {
                chime();
                drain();

                return;
            }

            var voices = win.speechSynthesis.getVoices() || [];
            var said = false;

            Object.keys(next.lines || {}).forEach(function (locale) {
                var voice = voices.filter(function (v) {
                    return v.lang && v.lang.toLowerCase().indexOf(locale.toLowerCase()) === 0;
                })[0];

                // Only a locale the browser can pronounce. Kurdish Sorani has
                // effectively no voice anywhere, so it is shown, not spoken —
                // an Arabic voice reading Kurdish would be pretending (§16).
                if (!voice) {
                    return;
                }

                var utterance = new win.SpeechSynthesisUtterance(next.lines[locale]);
                utterance.voice = voice;
                utterance.lang = voice.lang;
                said = true;
                win.speechSynthesis.speak(utterance);
            });

            if (!said) {
                chime();
            }

            speaking = true;
            win.setTimeout(function () {
                speaking = false;
                drain();
            }, 2500);
        }

        function chime() {
            try {
                var Context = win.AudioContext || win.webkitAudioContext;
                var ctx = new Context();
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.frequency.value = 880;
                gain.gain.setValueAtTime(0.15, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
                osc.start();
                osc.stop(ctx.currentTime + 0.4);
                osc.onended = function () {
                    if (ctx.close) {
                        ctx.close();
                    }
                };
            } catch (e) { /* a silent screen is still a working screen */ }
        }

        function speak(announcement) {
            pending.push(announcement);
            drain();
        }

        /* ---- the call always outranks the media ---- */

        function highlight() {
            body.setAttribute('data-calling', 'true');

            if (highlightTimer !== null) {
                win.clearTimeout(highlightTimer);
            }

            highlightTimer = win.setTimeout(function () {
                highlightTimer = null;
                body.setAttribute('data-calling', 'false');
            }, highlightMs);
        }

        /* ---- promotional media ---- */

        var frame = el('promo-frame');

        function slideFor(item) {
            var existing = byUrl[item.url];

            if (existing) {
                return existing;
            }

            var figure = doc.createElement('figure');
            figure.className = 'slide';
            figure.setAttribute('data-kind', item.kind);

            var media = createMediaElement(doc, item);

            figure.appendChild(media);
            frame.appendChild(figure);

            var slide = { item: item, figure: figure, media: media, loaded: false };
            byUrl[item.url] = slide;

            // Only a slide that was asked to load can fail. A <source> with no
            // src yet fires an 'error' as soon as it is inserted (the browser's
            // resource selection finds nothing to fetch) — that is not a failure.
            media.addEventListener('error', function () {
                if (slide.loaded) {
                    fail(slide);
                }
            }, true);

            if (item.kind === 'video') {
                media.addEventListener('ended', function () {
                    if (screen) {
                        screen.carousel.ended(slides.indexOf(slide));
                    }
                });
            }

            return slide;
        }

        /** Assign the source once; the element keeps it, so a working item is never fetched twice. */
        function load(slide) {
            if (!slide || slide.loaded) {
                return;
            }

            slide.loaded = true;

            if (slide.item.kind === 'video') {
                var source = slide.media.querySelector('source');
                source.src = source.getAttribute('data-src');
                slide.media.preload = 'auto';
                slide.media.load();
            } else {
                slide.media.src = slide.media.getAttribute('data-src');
            }
        }

        /*
         * A failed item goes back to "not loaded" with no source, so the
         * carousel's later retry really fetches it again (and can fail again)
         * instead of showing the broken element for good.
         */
        function fail(slide) {
            slide.loaded = false;

            if (slide.item.kind === 'video') {
                var source = slide.media.querySelector('source');

                if (source) {
                    source.removeAttribute('src');
                }

                slide.media.preload = 'none';
                slide.media.load();
            } else {
                slide.media.removeAttribute('src');
            }

            var index = slides.indexOf(slide);

            if (index !== -1 && screen) {
                screen.carousel.failed(index);
            }
        }

        /** The slides for a playlist; the carousel itself is the core's. */
        function setPlaylist(items) {
            var keep = Object.create(null);
            var next = [];

            for (var i = 0; i < items.length; i++) {
                var slide = slideFor(items[i]);
                slide.item = items[i];
                keep[items[i].url] = true;
                next.push(slide);
            }

            // Whatever left the playlist leaves the DOM and lets go of its
            // media — the page does not grow over a day of edits.
            Object.keys(byUrl).forEach(function (url) {
                if (keep[url]) {
                    return;
                }

                var gone = byUrl[url];

                if (gone.item.kind === 'video') {
                    gone.media.pause();

                    var source = gone.media.querySelector('source');

                    if (source) {
                        source.removeAttribute('src');
                    }

                    gone.media.load();
                } else {
                    gone.media.removeAttribute('src');
                }

                if (gone.figure.parentNode) {
                    gone.figure.parentNode.removeChild(gone.figure);
                }

                delete byUrl[url];
            });

            slides = next;

            for (var s = 0; s < slides.length; s++) {
                slides[s].media.loop = slides.length === 1 && slides[s].item.kind === 'video';
            }

            body.setAttribute('data-promo', slides.length > 0 ? 'on' : 'off');
        }

        function show(index, item, changed, locale) {
            var current = index >= 0 ? slides[index] : null;

            body.setAttribute('data-promo', current ? 'on' : 'off');

            for (var i = 0; i < slides.length; i++) {
                var active = slides[i] === current;
                var was = slides[i].figure.getAttribute('data-active') === 'true';

                slides[i].figure.setAttribute('data-active', active ? 'true' : 'false');

                if (!active && was && slides[i].item.kind === 'video') {
                    slides[i].media.pause();
                }
            }

            if (!current) {
                showCaption(locale, index);

                return;
            }

            load(current);

            // Preload exactly one ahead, so the next image is ready when its
            // turn comes and nothing else is fetched early.
            if (slides.length > 1) {
                var ahead = slides[(index + 1) % slides.length];

                if (ahead.item.kind === 'image') {
                    load(ahead);
                }
            }

            if (item && item.kind === 'video') {
                try {
                    current.media.currentTime = 0;
                } catch (e) { /* not seekable yet; it starts at 0 anyway */ }

                var played = current.media.play();

                if (played && typeof played.catch === 'function') {
                    // Autoplay refused or the file cannot play: skip it rather
                    // than leave a frozen frame on the wall. An AbortError only
                    // means the start was interrupted — the carousel moved on
                    // and paused it — and the file itself is fine.
                    played.catch(function (error) {
                        if (!error || error.name !== 'AbortError') {
                            fail(current);
                        }
                    });
                }
            }

            showCaption(locale, index);
        }

        function showCaption(locale, at) {
            var caption = el('promo-caption');
            var index = at !== undefined ? at : (screen ? screen.carousel.index : -1);
            var slide = index >= 0 ? slides[index] : null;
            var words = slide && slide.item.caption && slide.item.caption[locale] ? slide.item.caption[locale] : '';

            text(caption, words);
            caption.hidden = words === '';

            if (slide && slide.item.kind === 'image') {
                slide.media.alt = slide.item.alt && slide.item.alt[locale] ? slide.item.alt[locale] : '';
            }
        }

        /* ---- languages ---- */

        function renderLanguageChips(next, plan, pinned, locale) {
            var holder = el('languages');

            while (holder.firstChild) {
                holder.removeChild(holder.firstChild);
            }

            holder.hidden = !plan.enabled || pinned;

            for (var i = 0; i < plan.locales.length; i++) {
                var chip = doc.createElement('span');
                chip.setAttribute('data-lang', plan.locales[i]);
                chip.textContent = next.languages[plan.locales[i]].label;
                holder.appendChild(chip);
            }

            renderLanguages(locale);
        }

        /* ---- the screen: controller, rotation and carousel, wired in the core ---- */

        screen = createScreen({
            presentation: config.presentation,
            lockLocale: config.lockLocale || null,
            // The preview never speaks or chimes.
            silent: preview,
            setTimer: function (fn, ms) { return win.setTimeout(fn, ms); },
            clearTimer: function (id) { win.clearTimeout(id); },
            highlightMs: highlightMs,
            hooks: {
                render: render,
                speak: speak,
                chime: chime,
                highlight: highlight,
                languages: renderLanguageChips,
                playlist: setPlaylist,
                show: show,
            },
        });

        /* ---- polling: one loop, one request awaited at a time ---- */

        var poller = new Poller({
            request: function (signal) {
                var separator = feedUrl.indexOf('?') === -1 ? '?' : '&';
                var url = feedUrl + separator + 'pv=' + encodeURIComponent(screen.presentation().version || '');
                var init = { headers: { Accept: 'application/json' }, cache: 'no-store', credentials: 'same-origin' };

                if (signal) {
                    init.signal = signal;
                }

                return win.fetch(url, init).then(function (response) { return response.ok ? response.json() : null; });
            },
            receive: function (payload) {
                if (payload && payload.data) {
                    screen.receive(payload.data);
                }
            },
            setTimer: function (fn, ms) { return win.setTimeout(fn, ms); },
            clearTimer: function (id) { win.clearTimeout(id); },
            intervalMs: pollMs,
            timeoutMs: pollTimeoutMs,
            abortable: function () {
                return typeof win.AbortController === 'function' ? new win.AbortController() : null;
            },
        });

        /* ---- clock, controls ---- */

        function tick() {
            var clock = el('clock');
            var locale = currentLocale();

            if (!clock) {
                return;
            }

            try {
                // Latin digits by default (docs/07-LOCALIZATION.md §7).
                formatters[locale] = formatters[locale] || new win.Intl.DateTimeFormat(locale + '-u-nu-latn', { hour: '2-digit', minute: '2-digit' });
                clock.textContent = formatters[locale].format(new Date());
            } catch (e) {
                clock.textContent = new Date().toLocaleTimeString();
            }
        }

        var full = el('fullscreen');

        function syncFullscreenLabel(labels) {
            if (!full || !labels) {
                return;
            }

            full.textContent = doc.fullscreenElement ? labels.exit_fullscreen : labels.fullscreen;
        }

        if (full) {
            if (!root.requestFullscreen || preview) {
                full.hidden = true;
            }

            full.addEventListener('click', function () {
                if (doc.fullscreenElement) {
                    doc.exitFullscreen().catch(function () {});
                } else if (root.requestFullscreen) {
                    root.requestFullscreen().catch(function () {});
                }
            });

            doc.addEventListener('fullscreenchange', function () { syncFullscreenLabel(screen.view().text); });
        }

        var start = el('start');
        var startButton = el('start-button');

        if (start && startButton) {
            startButton.addEventListener('click', function () {
                try {
                    var Context = win.AudioContext || win.webkitAudioContext;
                    var ctx = new Context();

                    if (ctx.resume) {
                        ctx.resume();
                    }
                } catch (e) { /* no audio on this device; the screen still works */ }

                if ('speechSynthesis' in win) {
                    try { win.speechSynthesis.getVoices(); } catch (e) { /* ignored */ }
                }

                if (root.requestFullscreen) {
                    root.requestFullscreen().catch(function () {});
                }

                start.hidden = true;
            });
        }

        var idle = null;

        doc.addEventListener('pointermove', function () {
            body.classList.add('pointer-active');
            win.clearTimeout(idle);
            idle = win.setTimeout(function () {
                body.classList.remove('pointer-active');
            }, 3000);
        });

        screen.receive(config.initial || { now_calling: null, recent: [] });

        // The preview's sample call shows the call-over-media moment once.
        if (preview && config.sample) {
            screen.highlight();
        }

        poller.start();
        win.setInterval(tick, 1000);

        return screen.controller;
    }

    return {
        rotationPlan: rotationPlan,
        Rotator: Rotator,
        AnnouncementLedger: AnnouncementLedger,
        callKey: callKey,
        cue: cue,
        lineView: lineView,
        view: view,
        createController: createController,
        Carousel: Carousel,
        Poller: Poller,
        createMediaElement: createMediaElement,
        createScreen: createScreen,
        mount: mount,
    };
})();
