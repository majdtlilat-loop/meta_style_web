/*
 * Manager — queue. Behaviour for this area only (Alpine data/directives).
 *
 * Auto-print after issuing a number is a per-DEVICE preference: the reception
 * tablet with a printer beside it wants it, the manager's laptop does not. It
 * lives in this browser's storage and nowhere else — never in the database,
 * never shared between desks. Storage can be unavailable (private window,
 * blocked site data), in which case auto-print is simply off; the "Print"
 * button beside every issued number always works.
 *
 * The print page calls window.print() itself (docs/17-QUEUE.md §17). Browsers
 * outside kiosk mode may block a window opened after a network round trip;
 * then the visible Print button is the path, which is why it is always there.
 *
 * The screen preview (`queueDisplayPreview`) frames the real television page at
 * a television's size and scales it to the dialog (./queue-preview-fit.js).
 */

import { previewFit } from './queue-preview-fit';

const KEY = 'metastyle.queue.autoPrint';

function readPreference() {
    try {
        return window.localStorage.getItem(KEY) === '1';
    } catch (e) {
        return false;
    }
}

function writePreference(enabled) {
    try {
        window.localStorage.setItem(KEY, enabled ? '1' : '0');
    } catch (e) {
        /* A preference that cannot be saved is a preference that is off. */
    }
}

function register(Alpine) {
    Alpine.data('queueAutoPrint', () => ({
        enabled: readPreference(),
        init() {
            this.$watch('enabled', (value) => writePreference(Boolean(value)));
        },
    }));

    /*
     * The preview dialog. Unpinned (`lang` = '') it shows the screen as it runs
     * — rotating, or in the language it starts in; a language chip pins any of
     * the center's languages. The frame keeps a television's logical size and
     * is scaled to fit, so the page inside never sees a laptop-sized window.
     */
    Alpine.data('queueDisplayPreview', () => ({
        open: false,
        url: '',
        name: '',
        langs: [],
        rotates: false,
        start: '',
        lang: '',
        sample: false,
        shape: 'landscape',
        stamp: 0,
        fit: previewFit('landscape', 0, 0),
        observer: null,
        onResize: null,

        init() {
            this.$watch('shape', () => this.measure());
            this.$watch('open', (value) => {
                if (value) {
                    this.$nextTick(() => this.measure());
                }
            });

            if (typeof window.ResizeObserver === 'function') {
                this.observer = new window.ResizeObserver(() => this.measure());
                this.observer.observe(this.$refs.stage);
            } else {
                this.onResize = () => this.measure();
                window.addEventListener('resize', this.onResize);
            }
        },

        destroy() {
            if (this.observer) {
                this.observer.disconnect();
            }

            if (this.onResize) {
                window.removeEventListener('resize', this.onResize);
            }
        },

        show(detail) {
            this.url = detail.url;
            this.name = detail.name;
            this.langs = detail.languages || [];
            this.rotates = detail.rotates === true;
            this.start = detail.start || '';
            this.lang = '';
            this.sample = false;
            this.stamp = Date.now();
            this.open = true;
        },

        pick(code) {
            this.lang = code;
            this.stamp = Date.now();
        },

        /** The chip showing now: the pinned one, or the start of a screen that does not rotate. */
        pressed(code) {
            return this.lang === code || (this.lang === '' && !this.rotates && this.start === code);
        },

        src() {
            return `${this.url}?lang=${encodeURIComponent(this.lang)}${this.sample ? '&sample=1' : ''}&t=${this.stamp}`;
        },

        measure() {
            const stage = this.$refs.stage;

            if (!stage) {
                return;
            }

            const style = window.getComputedStyle(stage);
            const width = stage.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);
            const height = stage.clientHeight - parseFloat(style.paddingTop) - parseFloat(style.paddingBottom);

            this.fit = previewFit(this.shape, width, height);
        },
    }));
}

if (window.Alpine) {
    register(window.Alpine);
} else {
    document.addEventListener('alpine:init', () => register(window.Alpine));
}

document.addEventListener('livewire:init', () => {
    window.Livewire.on('queue-ticket-issued', (payload) => {
        const url = (Array.isArray(payload) ? payload[0] : payload)?.url;

        if (typeof url !== 'string' || url === '' || !readPreference()) {
            return;
        }

        window.open(url, '_blank', 'noopener');
    });
});
