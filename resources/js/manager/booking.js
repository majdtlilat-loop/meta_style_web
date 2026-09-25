/*
 * Manager — booking. Behaviour for this area only (Alpine data/directives).
 *
 * The day timeline opens scrolled to "now" (or the first booking) instead of
 * midnight-side hours nobody is working. It happens once per day shown, so a
 * poll that redraws the same day never yanks the view away from where the desk
 * scrolled to.
 */

const bringTimelineIntoView = () => {
    document.querySelectorAll('[data-bk-timeline]').forEach((scroller) => {
        const day = scroller.getAttribute('data-bk-day') ?? '';
        if (scroller.__bkDay === day) return;

        const target = scroller.querySelector('.bk-now') ?? scroller.querySelector('.bk-block');
        if (! target) return;

        scroller.__bkDay = day;

        const offset = target.getBoundingClientRect().top - scroller.getBoundingClientRect().top + scroller.scrollTop;
        scroller.scrollTop = Math.max(0, offset - 72);
    });
};

document.addEventListener('DOMContentLoaded', bringTimelineIntoView);
document.addEventListener('livewire:navigated', bringTimelineIntoView);
document.addEventListener('livewire:init', () => {
    window.Livewire?.hook('morphed', () => window.requestAnimationFrame(bringTimelineIntoView));
});
