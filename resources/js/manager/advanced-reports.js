/*
 * Manager — advanced-reports. Behaviour for this page only.
 *
 * A CSV link downloads in place, so its menu closes after the click (the
 * shared popover code closes it only on an outside click or Escape).
 *
 * advancedTable(pageSize) — sorts a server-rendered detail table by any
 * column (each cell's `data-sort`: numbers as numbers, text in the page's
 * language, blanks last) and pages it. It decides nothing: every value was
 * formatted and authorized on the server; this only reorders and shows rows.
 */
document.addEventListener('click', (event) => {
    const link = event.target instanceof Element ? event.target.closest('.adv-export a[download]') : null;
    const menu = link?.closest('details');

    if (menu instanceof HTMLDetailsElement) {
        menu.open = false;
    }
});

function register(Alpine) {
    Alpine.data('advancedTable', (pageSize = 10) => ({
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
            const headers = this.$root.querySelectorAll('thead th');
            const numeric = headers[index] instanceof HTMLElement && headers[index].classList.contains('numeric');

            if (this.column === index) {
                this.direction = this.direction === 'ascending' ? 'descending' : 'ascending';
            } else {
                // Figures read largest first; names read A to Z.
                this.column = index;
                this.direction = numeric ? 'descending' : 'ascending';
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
            headers.forEach((th, i) => th.setAttribute('aria-sort', i === index ? this.direction : 'none'));
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
