/*
 * Manager — Standard Reports. Behaviour for this page only (Alpine data).
 *
 * standardReports — while another report view loads (a tab was chosen), the
 *   current one gives way to its skeleton instead of sitting there stale. A
 *   modified click (new tab/window) leaves the page as it is.
 *
 * reportTable(pageSize) — sorts a server-rendered detail table by any column
 *   (numbers as numbers, text in the page's language, blanks last) and pages
 *   it client-side. It decides nothing: every value was formatted and
 *   authorized on the server; this only reorders and shows rows.
 */

function register(Alpine) {
    Alpine.data('standardReports', () => ({
        switching: false,

        open(event) {
            const link = event.currentTarget;

            // Not `event.defaultPrevented`: wire:navigate's own click handler
            // runs first and always prevents the default of a plain click —
            // that IS the navigation this skeleton stands in for.
            if (event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            if (link instanceof HTMLElement && link.getAttribute('aria-current') === 'page') {
                return;
            }

            this.switching = true;
        },

        init() {
            // A page restored from the back/forward cache is not loading anything.
            this.onShow = () => { this.switching = false; };
            window.addEventListener('pageshow', this.onShow);
        },

        destroy() {
            window.removeEventListener('pageshow', this.onShow);
        },
    }));

    Alpine.data('reportTable', (pageSize = 10) => ({
        page: 0,
        column: null,
        direction: 'none',
        rows: [],

        init() {
            this.rows = Array.from(this.$root.querySelectorAll('tbody > tr'));
            this.show();
        },

        get pages() {
            return Math.max(1, Math.ceil(this.rows.length / pageSize));
        },

        get last() {
            return this.page >= this.pages - 1;
        },

        get summary() {
            const template = this.$root.dataset.range || ':from–:to / :total';
            const from = this.rows.length === 0 ? 0 : this.page * pageSize + 1;
            const to = Math.min(this.rows.length, (this.page + 1) * pageSize);

            return template.replace(':from', String(from)).replace(':to', String(to)).replace(':total', String(this.rows.length));
        },

        sort(index) {
            const header = this.$root.querySelectorAll('thead th')[index];
            const numericColumn = header instanceof HTMLElement && header.classList.contains('numeric');

            if (this.column === index) {
                this.direction = this.direction === 'ascending' ? 'descending' : 'ascending';
            } else {
                // Figures read largest first; names read A to Z.
                this.column = index;
                this.direction = numericColumn ? 'descending' : 'ascending';
            }

            const locale = document.documentElement.lang || undefined;
            const value = (row) => (row.cells[index] ? row.cells[index].dataset.sort ?? '' : '');
            const isNumber = (text) => text !== '' && Number.isFinite(Number(text));

            this.rows.sort((a, b) => {
                const x = value(a);
                const y = value(b);

                if (x === '' || y === '') {
                    return x === y ? 0 : (x === '' ? 1 : -1);
                }

                const order = isNumber(x) && isNumber(y) ? Number(x) - Number(y) : x.localeCompare(y, locale);

                return this.direction === 'ascending' ? order : -order;
            });

            const body = this.$root.querySelector('tbody');
            this.rows.forEach((row) => body.appendChild(row));
            this.$root.querySelectorAll('thead th').forEach((th, i) => th.setAttribute('aria-sort', i === index ? this.direction : 'none'));
            this.page = 0;
            this.show();
        },

        next() {
            if (! this.last) {
                this.page++;
                this.show();
            }
        },

        previous() {
            if (this.page > 0) {
                this.page--;
                this.show();
            }
        },

        show() {
            const start = this.page * pageSize;

            this.rows.forEach((row, i) => {
                row.hidden = i < start || i >= start + pageSize;
            });
        },
    }));
}

if (window.Alpine) {
    register(window.Alpine);
} else {
    document.addEventListener('alpine:init', () => register(window.Alpine));
}
