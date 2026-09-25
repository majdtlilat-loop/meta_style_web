/*
 * The waiting-room screen's client contract. docs/17-QUEUE.md §§9, 13, 16.
 *
 * Runs with Node's built-in runner — no package, no bundler:
 *
 *     node --test tests/Js/queue-display-client.test.mjs
 *
 * and from Pest through tests/Unit/Queue/QueueDisplayClientTest.php.
 *
 * The file under test is inlined into the television page; here it is
 * evaluated in an empty context with no DOM, so only its pure core runs.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const here = path.dirname(fileURLToPath(import.meta.url));
const source = readFileSync(path.join(here, '..', '..', 'resources', 'js', 'queue-display', 'display-client.js'), 'utf8');

const context = vm.createContext({});
vm.runInContext(source, context);
const client = context.QueueDisplayClient;

/** A clock the tests move by hand; timers fire in order, never early. */
function fakeClock() {
    let now = 0;
    let sequence = 0;
    const timers = new Map();

    return {
        set(fn, ms) {
            sequence += 1;
            timers.set(sequence, { at: now + ms, fn });

            return sequence;
        },
        clear(id) {
            timers.delete(id);
        },
        advance(ms) {
            const end = now + ms;

            for (;;) {
                let next = null;

                for (const [id, timer] of timers) {
                    if (timer.at <= end && (next === null || timer.at < next[1].at)) {
                        next = [id, timer];
                    }
                }

                if (next === null) {
                    break;
                }

                timers.delete(next[0]);
                now = next[1].at;
                next[1].fn();
            }

            now = end;
        },
        now: () => now,
        pending: () => timers.size,
    };
}

function labels(nowCalling, dir, label, branch) {
    return {
        dir,
        label,
        branch,
        text: {
            now_calling: nowCalling,
            recently_called: `${label} recent`,
            waiting: `${label} waiting`,
            destination: `${label} go to`,
            thank_you: `${label} thanks`,
            state_called: `${label} called`,
            state_serving: `${label} serving`,
        },
    };
}

function presentation(overrides = {}) {
    return {
        start: 'en',
        rotation: { enabled: true, seconds: 10, locales: ['en', 'ar', 'ckb'] },
        languages: {
            en: labels('Now calling', 'ltr', 'EN', 'Main branch'),
            ar: labels('النداء الحالي', 'rtl', 'AR', 'الفرع الرئيسي'),
            ckb: labels('بانگکردنی ئێستا', 'rtl', 'KU', 'لقی سەرەکی'),
        },
        promo: { enabled: false, slide_seconds: 8, items: [] },
        version: 'v1',
        ...overrides,
    };
}

const SPEAKS = { voice_enabled: true, sound_enabled: true };
const CHIMES = { voice_enabled: false, sound_enabled: true };
const QUIET = { voice_enabled: false, sound_enabled: false };

/**
 * One poll's payload, shaped like the server's: `key` is the feed's opaque
 * `call_key`, and the speech payload rides along only for a screen that may
 * speak — a screen with its voice off gets none.
 */
function payload(key, number = 'A012', recentNumbers = ['A011'], display = SPEAKS) {
    return {
        display,
        call_key: key,
        now_calling: {
            announcement_id: `event-${key}`,
            number,
            destination_code: 'R1',
            destination_name: 'Reception',
            destination_names: { en: 'Reception', ar: 'الاستقبال', ckb: 'پێشوازی' },
            called_at: '2026-09-24T10:00:00+00:00',
            state: 'called',
        },
        recent: recentNumbers.map((n) => ({
            announcement_id: `${n}-event`,
            number: n,
            destination_code: 'L2',
            destination_name: 'Laser',
            destination_names: { en: 'Laser', ar: 'ليزر' },
            state: 'serving',
        })),
        announcement: display.voice_enabled ? { announcement_id: `event-${key}`, number, lines: { en: `Ticket ${number}` } } : null,
        server_time: '2026-09-24T10:00:05+00:00',
    };
}

function recorder() {
    const calls = { render: [], speak: [], chime: 0, highlight: [], presentation: [] };

    return {
        calls,
        hooks: {
            render: (view) => calls.render.push(view),
            speak: (announcement) => calls.speak.push(announcement.announcement_id),
            chime: () => {
                calls.chime += 1;
            },
            highlight: (line) => calls.highlight.push(line.number),
            presentation: (next) => calls.presentation.push(next.version),
        },
    };
}

test('rotates EN → AR → KU → EN, only through the languages the server sent', () => {
    const plan = client.rotationPlan(presentation());

    assert.deepEqual([...plan.locales], ['en', 'ar', 'ckb']);
    assert.equal(plan.start, 'en');
    assert.equal(plan.seconds, 10);
    assert.equal(plan.enabled, true);

    // A locale the server did not describe is never rotated into.
    const unknown = client.rotationPlan(presentation({ rotation: { enabled: true, seconds: 10, locales: ['en', 'fr', 'ar'] } }));
    assert.deepEqual([...unknown.locales], ['en', 'ar']);

    // One language is no rotation at all.
    const single = client.rotationPlan(presentation({ rotation: { enabled: true, seconds: 10, locales: ['ar'] }, start: 'ar' }));
    assert.equal(single.enabled, false);

    // Off stays off, whatever the list says.
    assert.equal(client.rotationPlan(presentation({ rotation: { enabled: false, seconds: 10, locales: ['en', 'ar'] } })).enabled, false);
});

test('clamps the interval to the safe range', () => {
    const at = (seconds) => client.rotationPlan(presentation({ rotation: { enabled: true, seconds, locales: ['en', 'ar'] } })).seconds;

    assert.equal(at(3), 5);
    assert.equal(at(999), 60);
    assert.equal(at('abc'), 10);
    assert.equal(at(15), 15);
});

test('switches language every interval with exactly one timer, and stops cleanly', () => {
    const clock = fakeClock();
    const seen = [];
    const rotator = new client.Rotator({
        locales: ['en', 'ar', 'ckb'],
        start: 'en',
        seconds: 10,
        onChange: (locale) => seen.push([clock.now(), locale]),
        setTimer: clock.set,
        clearTimer: clock.clear,
    });

    rotator.start();
    rotator.start(); // a second start never makes a second loop

    assert.equal(clock.pending(), 1);

    clock.advance(40000);

    assert.deepEqual(seen, [[10000, 'ar'], [20000, 'ckb'], [30000, 'en'], [40000, 'ar']]);
    assert.equal(clock.pending(), 1);

    rotator.stop();
    assert.equal(clock.pending(), 0);
    clock.advance(60000);
    assert.equal(seen.length, 4);
});

test('waits for a running media transition instead of flipping direction mid-fade', () => {
    const clock = fakeClock();
    const seen = [];
    let busy = true;

    const rotator = new client.Rotator({
        locales: ['en', 'ar'],
        start: 'en',
        seconds: 10,
        canSwitch: () => !busy,
        onChange: (locale) => seen.push([clock.now(), locale]),
        setTimer: clock.set,
        clearTimer: clock.clear,
    });

    rotator.start();
    clock.advance(10000);
    assert.deepEqual(seen, []);

    busy = false;
    clock.advance(300);
    assert.deepEqual(seen, [[10300, 'ar']]);
});

test('speaks a call once, by its key only, never by the language', () => {
    const { calls, hooks } = recorder();
    const controller = client.createController(presentation(), hooks);

    controller.receive(payload('call-1'));
    assert.deepEqual(calls.speak, ['event-call-1']);
    assert.equal(client.callKey(payload('call-1')), 'call-1');

    // A full rotation, twice over: re-labelled, never re-announced.
    for (const locale of ['ar', 'ckb', 'en', 'ar', 'ckb', 'en']) {
        assert.equal(controller.switchLocale(locale), true);
    }

    // The next polls carry the same call: still silent, whatever the language.
    controller.receive(payload('call-1'));
    controller.switchLocale('ar');
    controller.receive(payload('call-1'));

    assert.deepEqual(calls.speak, ['event-call-1']);
    // A screen that speaks does not chime on top of it.
    assert.equal(calls.chime, 0);
    assert.equal(calls.highlight.length, 1);

    // A recall is a new event with a new key, and only that speaks again.
    controller.receive(payload('call-2'));
    assert.deepEqual(calls.speak, ['event-call-1', 'event-call-2']);
    assert.equal(controller.spokenCount(), 2);
});

test('a screen with sound on and voice off chimes once per new call key', () => {
    const { calls, hooks } = recorder();
    const controller = client.createController(presentation(), hooks);

    // The server sends such a screen no speech payload, only the key.
    const first = payload('key-1', 'A012', ['A011'], CHIMES);
    assert.equal(first.announcement, null);

    controller.receive(first);
    assert.equal(calls.chime, 1);

    // The next poll carries the same key: no second chime.
    controller.receive(payload('key-1', 'A012', ['A011'], CHIMES));
    assert.equal(calls.chime, 1);

    // A language switch — a whole rotation — never chimes.
    for (const locale of ['ar', 'ckb', 'en']) {
        assert.equal(controller.switchLocale(locale), true);
    }

    controller.receive(payload('key-1', 'A012', ['A011'], CHIMES));
    assert.equal(calls.chime, 1);

    // A new call (a recall included) is a new key: exactly one more chime.
    controller.receive(payload('key-2', 'A012', ['A011'], CHIMES));
    controller.receive(payload('key-2', 'A012', ['A011'], CHIMES));
    assert.equal(calls.chime, 2);
    assert.deepEqual(calls.speak, []);
});

test('speaks only with words from the server, chimes only with sound on', () => {
    const words = { announcement_id: 'event', number: 'A1', lines: { en: 'Ticket A1' } };

    assert.equal(client.cue(words, SPEAKS), 'speak');
    // No words — no `queue_voice`, or the screen's voice is off — is a chime.
    assert.equal(client.cue(null, SPEAKS), 'chime');
    assert.equal(client.cue(null, CHIMES), 'chime');
    assert.equal(client.cue(words, CHIMES), 'chime');
    assert.equal(client.cue(null, QUIET), null);
    assert.equal(client.cue(null, undefined), null);

    // A screen with its sound off stays silent however many calls it sees.
    const { calls, hooks } = recorder();
    const controller = client.createController(presentation(), hooks);

    controller.receive(payload('key-1', 'A012', ['A011'], QUIET));
    controller.receive(payload('key-2', 'A013', ['A012'], QUIET));

    assert.equal(calls.chime, 0);
    assert.deepEqual(calls.speak, []);
    // It still shows the call.
    assert.equal(calls.render[calls.render.length - 1].now.number, 'A013');
});

test('a preview never speaks or chimes', () => {
    const { calls, hooks } = recorder();
    const controller = client.createController(presentation(), hooks, { silent: true });

    controller.receive(payload('key-1'));
    controller.receive(payload('key-2', 'A013', ['A012'], CHIMES));

    assert.deepEqual(calls.speak, []);
    assert.equal(calls.chime, 0);
    assert.equal(calls.render.length, 2);
});

test('a language switch re-labels everything together and keeps every ticket', () => {
    const { calls, hooks } = recorder();
    const controller = client.createController(presentation(), hooks);

    controller.receive(payload('call-1', 'A012', ['A011', 'A010']));

    const views = {};

    for (const locale of ['en', 'ar', 'ckb']) {
        controller.switchLocale(locale);
        views[locale] = calls.render[calls.render.length - 1];
    }

    assert.equal(views.en.dir, 'ltr');
    assert.equal(views.ar.dir, 'rtl');
    assert.equal(views.ckb.dir, 'rtl');

    assert.equal(views.en.text.now_calling, 'Now calling');
    assert.equal(views.ar.text.now_calling, 'النداء الحالي');
    assert.equal(views.ckb.text.now_calling, 'بانگکردنی ئێستا');
    assert.equal(views.ar.text.state_serving, 'AR serving');
    assert.equal(views.ckb.branch, 'لقی سەرەکی');

    assert.equal(views.en.now.destination, 'Reception');
    assert.equal(views.ar.now.destination, 'الاستقبال');
    // A destination with no Kurdish name falls back rather than going blank.
    assert.equal(views.ckb.recent[0].destination, 'Laser');

    for (const locale of ['ar', 'ckb']) {
        assert.equal(views[locale].now.number, views.en.now.number);
        assert.deepEqual(Array.from(views[locale].recent, (r) => r.number), ['A011', 'A010']);
    }
});

test('ignores a language the server did not send', () => {
    const { calls, hooks } = recorder();
    const controller = client.createController(presentation(), hooks);

    assert.equal(controller.switchLocale('fr'), false);
    assert.equal(controller.locale(), 'en');
    assert.equal(calls.render.length, 0);
});

test('a playlist or language change arrives with a poll and keeps the current language', () => {
    const { calls, hooks } = recorder();
    const controller = client.createController(presentation(), hooks);

    controller.switchLocale('ar');
    controller.receive({ ...payload('call-1'), presentation: presentation({ version: 'v2' }) });

    assert.deepEqual(calls.presentation, ['v2']);
    assert.equal(controller.locale(), 'ar');

    // The same version again is not a change.
    controller.receive({ ...payload('call-1'), presentation: presentation({ version: 'v2' }) });
    assert.deepEqual(calls.presentation, ['v2']);

    // Arabic switched off for the center: the screen falls back to its start.
    const withoutArabic = presentation({ version: 'v3', rotation: { enabled: true, seconds: 10, locales: ['en', 'ckb'] } });
    delete withoutArabic.languages.ar;
    controller.receive({ ...payload('call-1'), presentation: withoutArabic });

    assert.equal(controller.locale(), 'en');
    // None of this announced anything new.
    assert.deepEqual(calls.speak, ['event-call-1']);
    assert.equal(calls.chime, 0);
});

test('highlights a fresh call once, and not an old one found on start-up', () => {
    const { calls, hooks } = recorder();
    const controller = client.createController(presentation(), hooks);

    const stale = payload('old-call');
    stale.server_time = '2026-09-24T10:30:00+00:00';
    controller.receive(stale);
    assert.deepEqual(calls.highlight, []);

    controller.receive(payload('call-2', 'A013'));
    controller.receive(payload('call-2', 'A013'));
    assert.deepEqual(calls.highlight, ['A013']);
});

function images(count) {
    return Array.from({ length: count }, (_, i) => ({ kind: 'image', url: `/media/branding/${i}.jpg` }));
}

function carousel(clock, shown) {
    return new client.Carousel({
        setTimer: clock.set,
        clearTimer: clock.clear,
        now: clock.now,
        retryMs: 60000,
        maxVideoSeconds: 120,
        onShow: (index) => shown.push([clock.now(), index]),
    });
}

test('rotates images on their own clock, and holds still while a call is on screen', () => {
    const clock = fakeClock();
    const shown = [];
    const media = carousel(clock, shown);

    media.setItems(images(3), 8);
    clock.advance(24000);

    assert.deepEqual(shown, [[0, 0], [8000, 1], [16000, 2], [24000, 0]]);

    media.hold(12000); // a call at 24 s
    clock.advance(20000);

    // The 32 s change waits until the call has had its 12 seconds, then the
    // playlist carries on at its own pace.
    assert.deepEqual(shown.slice(4), [[36000, 1], [44000, 2]]);
});

/**
 * The screen as mount() wires it — controller, rotation, carousel — on a
 * fake clock, recording what the page would be told to do.
 */
function screenFor(clock, overrides = {}, options = {}) {
    const calls = { locales: [], shown: [], speak: [], chime: 0, pinned: [] };
    const screen = client.createScreen({
        presentation: presentation(overrides),
        setTimer: clock.set,
        clearTimer: clock.clear,
        now: clock.now,
        ...options,
        hooks: {
            render: (view) => calls.locales.push([clock.now(), view.locale]),
            speak: (announcement) => calls.speak.push(announcement.announcement_id),
            chime: () => {
                calls.chime += 1;
            },
            highlight: () => {},
            languages: (next, plan, pinned) => calls.pinned.push(pinned),
            playlist: () => {},
            show: (index) => calls.shown.push([clock.now(), index]),
        },
    });

    return { screen, calls };
}

/** A poll with nobody called: nothing to highlight, so nothing holds the media. */
const idle = { now_calling: null, call_key: null, announcement: null, recent: [], display: SPEAKS };

test('on the wall, a language switch re-labels and never advances, resets or re-arms the media', () => {
    const clock = fakeClock();
    const { screen, calls } = screenFor(clock, { promo: { enabled: true, slide_seconds: 8, items: images(2) } });

    screen.receive(idle);
    clock.advance(33000);

    // The languages turned on their own clock...
    assert.deepEqual(calls.locales, [[0, 'en'], [10000, 'ar'], [20000, 'ckb'], [30000, 'en']]);
    assert.equal(screen.rotating(), true);
    // ...and the playlist on its own, untouched by any of those switches.
    assert.deepEqual(calls.shown, [[0, 0], [8000, 1], [16000, 0], [24000, 1], [32000, 0]]);
    assert.equal(calls.chime + calls.speak.length, 0);
});

test('on the wall, a language switch waits for a slide to finish fading in', () => {
    const clock = fakeClock();
    const { screen, calls } = screenFor(
        clock,
        { promo: { enabled: true, slide_seconds: 8, items: images(2) } },
        { transitionMs: 3000 },
    );

    screen.receive(idle);
    clock.advance(24000);

    // The 10 s switch falls inside the fade that started at 8 s: it waits for
    // the fade to end (11 s) and lands on the next 300 ms retry.
    assert.deepEqual(calls.locales, [[0, 'en'], [11200, 'ar'], [21200, 'ckb']]);
    assert.deepEqual(calls.shown, [[0, 0], [8000, 1], [16000, 0], [24000, 1]]);
});

test('a preview pinned to one language shows only that language and never rotates', () => {
    const clock = fakeClock();
    // The same rotating presentation, pinned to Kurdish.
    const { screen, calls } = screenFor(clock, {}, { lockLocale: 'ckb', silent: true });

    screen.receive(payload('call-1'));
    clock.advance(60000);
    screen.receive(payload('call-1'));

    assert.equal(screen.locale(), 'ckb');
    assert.equal(screen.rotating(), false);
    assert.deepEqual(calls.locales, [[0, 'ckb'], [60000, 'ckb']]);
    assert.deepEqual(calls.pinned, [true]);
    // And, being a preview, it made no sound.
    assert.deepEqual(calls.speak, []);
    assert.equal(calls.chime, 0);

    // Unpinned, the very same presentation does rotate.
    const other = fakeClock();
    const free = screenFor(other);

    free.screen.receive(idle);
    other.advance(10000);
    assert.equal(free.screen.locale(), 'ar');
});

/** Just enough of a document for the media element: attributes and children. */
function fakeDocument() {
    return {
        createElement(tag) {
            return {
                tagName: tag.toUpperCase(),
                attributes: {},
                children: [],
                setAttribute(name, value) {
                    this.attributes[name] = String(value);
                },
                getAttribute(name) {
                    return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null;
                },
                hasAttribute(name) {
                    return Object.prototype.hasOwnProperty.call(this.attributes, name);
                },
                appendChild(child) {
                    this.children.push(child);

                    return child;
                },
            };
        },
    };
}

test('builds every promotional video muted, with no sound control and nothing fetched early', () => {
    const video = client.createMediaElement(fakeDocument(), { kind: 'video', url: '/media/branding/clip.mp4', type: 'video/mp4' });

    assert.equal(video.tagName, 'VIDEO');
    // Muted three ways, so no browser default can bring the sound back.
    assert.equal(video.muted, true);
    assert.equal(video.defaultMuted, true);
    assert.equal(video.hasAttribute('muted'), true);
    assert.equal(video.hasAttribute('playsinline'), true);
    assert.equal(video.hasAttribute('controls'), false);
    // Nothing is downloaded until the carousel shows it.
    assert.equal(video.preload, 'none');
    assert.equal(video.children.length, 1);
    assert.equal(video.children[0].getAttribute('data-src'), '/media/branding/clip.mp4');
    assert.equal(video.children[0].src, undefined);

    const image = client.createMediaElement(fakeDocument(), { kind: 'image', url: '/media/branding/a.jpg' });

    assert.equal(image.tagName, 'IMG');
    assert.equal(image.getAttribute('data-src'), '/media/branding/a.jpg');
    assert.equal(image.src, undefined);
});

test('a video plays to its end, and a failing item is skipped rather than looped on', () => {
    const clock = fakeClock();
    const shown = [];
    const media = carousel(clock, shown);

    media.setItems([{ kind: 'video', url: '/v.mp4' }, ...images(1)], 8);
    assert.deepEqual(shown, [[0, 0]]);

    clock.advance(30000);
    media.ended(0);
    assert.deepEqual(shown.slice(1), [[30000, 1]]);

    // Back to the video, which now fails: skipped straight to the image.
    clock.advance(8000);
    media.failed(0);
    assert.deepEqual(shown.slice(2), [[38000, 0], [38000, 1]]);

    // A video that never reports its end is cut at the cap.
    const other = [];
    const capped = carousel(clock, other);
    capped.setItems([{ kind: 'video', url: '/w.mp4' }, ...images(1)], 8);
    clock.advance(120000);
    assert.equal(other[other.length - 1][1], 1);
});

test('nothing playable gives the screen back to the queue, and is tried again later', () => {
    const clock = fakeClock();
    const shown = [];
    const media = carousel(clock, shown);

    media.setItems(images(1), 8);
    media.failed(0);

    assert.deepEqual(shown, [[0, 0], [0, -1]]);

    clock.advance(60000);
    assert.deepEqual(shown[shown.length - 1], [60000, 0]);
});

test('an edited playlist keeps the item on screen and never duplicates timers', () => {
    const clock = fakeClock();
    const shown = [];
    const media = carousel(clock, shown);

    media.setItems(images(3), 8);
    clock.advance(8000); // showing item 1

    media.setItems(images(3).concat([{ kind: 'image', url: '/media/branding/new.jpg' }]), 8);

    assert.equal(media.index, 1);
    assert.equal(clock.pending(), 1);

    // An empty playlist hides the panel and leaves no timer behind.
    media.setItems([], 8);
    assert.deepEqual(shown[shown.length - 1], [8000, -1]);
    assert.equal(clock.pending(), 0);
});

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });

    return { promise, resolve, reject };
}

/** Lets every settled promise run its callbacks. */
const settle = () => new Promise((resolve) => setImmediate(resolve));

function poller(clock, requests, received, extra = {}) {
    return new client.Poller({
        request: (signal) => {
            const next = deferred();
            requests.push({ ...next, signal });

            return next.promise;
        },
        receive: (payload) => received.push(payload),
        setTimer: clock.set,
        clearTimer: clock.clear,
        intervalMs: 3000,
        timeoutMs: 20000,
        ...extra,
    });
}

test('polls one request at a time, the next only after the last has answered', async () => {
    const clock = fakeClock();
    const requests = [];
    const received = [];
    const feed = poller(clock, requests, received);

    feed.start();
    feed.start(); // a second start never makes a second loop
    assert.equal(requests.length, 1);

    // A slow answer: nothing else is asked meanwhile.
    clock.advance(10000);
    assert.equal(requests.length, 1);

    requests[0].resolve({ data: 'first' });
    await settle();
    assert.deepEqual(received, [{ data: 'first' }]);

    // The interval runs from the answer, never from the question.
    clock.advance(2999);
    assert.equal(requests.length, 1);
    clock.advance(1);
    assert.equal(requests.length, 2);
    assert.equal(clock.pending(), 1);
});

test('abandons a request that never answers, and ignores its late answer', async () => {
    const clock = fakeClock();
    const requests = [];
    const received = [];
    let aborted = 0;
    const feed = poller(clock, requests, received, {
        abortable: () => ({ signal: { request: requests.length }, abort: () => { aborted += 1; } }),
    });

    feed.start();
    assert.deepEqual(requests[0].signal, { request: 0 });

    // A stalled connection: the screen does not freeze on it.
    clock.advance(20000);
    assert.equal(aborted, 1);
    clock.advance(3000);
    assert.equal(requests.length, 2);

    // The abandoned read answers late: an old answer never overwrites a newer one.
    requests[0].resolve({ data: 'stale' });
    requests[1].resolve({ data: 'fresh' });
    await settle();
    assert.deepEqual(received, [{ data: 'fresh' }]);

    // Still exactly one loop.
    assert.equal(clock.pending(), 1);
    clock.advance(3000);
    assert.equal(requests.length, 3);
});

test('a failed poll or a failing render never stops the loop', async () => {
    const clock = fakeClock();
    const requests = [];
    const feed = poller(clock, requests, [], {
        receive: () => {
            throw new Error('render failed');
        },
    });

    feed.start();
    requests[0].reject(new Error('offline'));
    await settle();
    clock.advance(3000);
    assert.equal(requests.length, 2);

    requests[1].resolve({ data: 'ok' });
    await settle();
    clock.advance(3000);
    assert.equal(requests.length, 3);

    // Stopped means stopped.
    feed.stop();
    requests[2].resolve({ data: 'ok' });
    await settle();
    clock.advance(60000);
    assert.equal(requests.length, 3);
    assert.equal(clock.pending(), 0);
});

test('the announcement ledger is bounded and ignores a missing id', () => {
    const ledger = new client.AnnouncementLedger(3);

    assert.equal(ledger.fresh(null), false);
    assert.equal(ledger.fresh(''), false);

    for (const id of ['a', 'b', 'c', 'd']) {
        assert.equal(ledger.fresh(id), true);
    }

    assert.equal(ledger.size(), 3);
    assert.equal(ledger.fresh('d'), false);
});
