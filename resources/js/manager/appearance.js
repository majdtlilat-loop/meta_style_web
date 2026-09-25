/*
 * Manager — appearance and center settings. Behaviour for this area only.
 *
 * `x-readonly-fields`: for a person who may LOOK at a page but not change it,
 * every text field inside becomes read-only (the language tabs of
 * <x-ui.lang-tabs> keep working, so every language can still be read). Only
 * presentation — the Actions refuse the change on the server regardless.
 * Re-applied when Livewire morphs the fields back in.
 */
function register(Alpine) {
    Alpine.directive('readonly-fields', (el, _directive, { cleanup }) => {
        const apply = () => {
            el.querySelectorAll('input:not([type="hidden"]), textarea').forEach((field) => {
                if (!field.readOnly) {
                    field.readOnly = true;
                }
            });
        };

        apply();

        const observer = new MutationObserver(apply);
        observer.observe(el, { subtree: true, childList: true, attributes: true, attributeFilter: ['readonly'] });

        cleanup(() => observer.disconnect());
    });
}

if (window.Alpine) {
    register(window.Alpine);
} else {
    document.addEventListener('alpine:init', () => register(window.Alpine));
}
