/*
 * Manager — shell. Behaviour for this area only.
 *
 *  - locked features  any [data-upgrade-feature] (the sidebar's locked items,
 *                     or any other surface that points at a plan feature)
 *                     opens the upgrade prompt instead of following its link.
 *                     The link is the no-JavaScript fallback (the plan page),
 *                     and a modified click (new tab / window) still follows it.
 *                     The server validates the key; this only asks.
 *  - plan comparison  a highlighted feature row, or #compare, scrolls into view
 *                     after the page (or a wire:navigate swap) arrives.
 */

document.addEventListener('click', (event) => {
    if (! (event.target instanceof Element) || event.defaultPrevented) return;

    const trigger = event.target.closest('[data-upgrade-feature]');
    if (! trigger) return;
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (typeof window.Livewire?.dispatch !== 'function') return;

    event.preventDefault();
    window.Livewire.dispatch('open-upgrade', { feature: trigger.dataset.upgradeFeature ?? '' });
});

const revealPlanHighlight = () => {
    const row = document.querySelector('[data-plan-highlight]');
    const target = row ?? (window.location.hash === '#compare' ? document.getElementById('compare') : null);
    if (! (target instanceof HTMLElement)) return;

    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    target.scrollIntoView({ block: row ? 'center' : 'start', behavior: reduced ? 'auto' : 'smooth' });
};

// A timeout rather than requestAnimationFrame: a background tab never runs the latter.
document.addEventListener('livewire:navigated', () => window.setTimeout(revealPlanHighlight, 50));
