/*
 * Manager — pos. Behaviour for this area only (Alpine data/directives).
 *
 * posTill — keyboard comfort for the till, nothing that decides anything:
 *   "/" or F2 focuses the catalog search (where a barcode scanner types), unless
 *   the person is already typing in a field. Enter in that box asks the server
 *   to add an exact barcode/SKU match; the server decides whether one exists.
 */

function register(Alpine) {
    Alpine.data('posTill', () => ({
        init() {
            this.onKey = (event) => {
                const target = event.target;
                const typing = target instanceof HTMLElement
                    && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

                if (typing || event.defaultPrevented || event.altKey || event.ctrlKey || event.metaKey) {
                    return;
                }

                if (event.key === '/' || event.key === 'F2') {
                    const search = this.$root.querySelector('[data-pos-search]');

                    if (search) {
                        event.preventDefault();
                        search.focus();
                        search.select();
                    }
                }
            };

            window.addEventListener('keydown', this.onKey);
        },

        destroy() {
            window.removeEventListener('keydown', this.onKey);
        },
    }));
}

if (window.Alpine) {
    register(window.Alpine);
} else {
    document.addEventListener('alpine:init', () => register(window.Alpine));
}
