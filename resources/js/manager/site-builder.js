/*
 * Manager — site-builder. Behaviour for this area only (Alpine data/directives).
 *
 * siteBuilder — the landing page builder's unsaved-changes guard. Typing in
 * any field marks the draft dirty on the client (deferred inputs reach the
 * server only with the next action); the component's own `unsaved` flag
 * covers structural edits (order, visibility, added or removed sections); the
 * server's `site-saved` event clears the client side. While unsaved, leaving
 * asks first: a full page unload through the browser's own prompt, an in-app
 * (wire:navigate) link through the page's dialog. Nothing here decides or
 * saves anything — the server remains the only authority.
 */

function register(Alpine) {
    Alpine.data('siteBuilder', () => ({
        dirty: false,
        leavingTo: null,
        leaving: false,

        get unsaved() {
            return this.dirty || this.$wire.unsaved === true;
        },

        init() {
            this.onUnload = (event) => {
                if (this.unsaved && !this.leaving) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            };

            // Back/forward (`history`) has already changed the address; only
            // a link or a redirect can still be held back.
            this.onNavigate = (event) => {
                if (this.leaving || !this.$root.isConnected || event.detail?.history || !this.unsaved) {
                    return;
                }

                event.preventDefault();
                this.leavingTo = String(event.detail.url);
            };

            window.addEventListener('beforeunload', this.onUnload);
            document.addEventListener('livewire:navigate', this.onNavigate);
        },

        markDirty(event) {
            const target = event.target;

            // Uploads and the preview controls are not edits of the draft.
            if (target instanceof HTMLInputElement && target.type === 'file') {
                return;
            }

            this.dirty = true;
        },

        stay() {
            this.leavingTo = null;
        },

        leave() {
            const url = this.leavingTo;
            this.leaving = true;
            this.leavingTo = null;
            window.Livewire.navigate(url);
        },

        destroy() {
            window.removeEventListener('beforeunload', this.onUnload);
            document.removeEventListener('livewire:navigate', this.onNavigate);
        },
    }));
}

if (window.Alpine) {
    register(window.Alpine);
} else {
    document.addEventListener('alpine:init', () => register(window.Alpine));
}
