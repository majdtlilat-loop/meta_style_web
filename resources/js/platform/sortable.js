/*
 * x-sortable — dependency-free drag & drop ordering for Livewire lists.
 *
 *   <ul x-data x-sortable="moveService" data-sortable-group="services" data-sortable-container="{{ $categoryUuid }}">
 *       <li data-sortable-item="{{ $uuid }}" wire:key="service-{{ $uuid }}">
 *           <button type="button" data-sortable-handle aria-label="…">⋮⋮</button> …
 *       </li>
 *   </ul>
 *
 * On drop it calls the component method named by the expression ONCE:
 *     $wire.<method>(itemKey, newIndex[, targetContainerKey])
 * The third argument is only sent when the item moved to another list of the
 * same `data-sortable-group`. The server re-derives and validates the order —
 * the client only states an intent ("put X at position N of list Y"), and the
 * next render is the truth. The DOM move before the round trip is optimistic
 * and purely visual.
 *
 * Accessibility (WCAG 2.2 "dragging movements"): dragging is never the only
 * way. Every list keeps visible Move up / Move down buttons, and the handle
 * itself answers ArrowUp / ArrowDown (and Home / End) with the same call.
 * Pointer events cover mouse, pen and touch; the handle sets
 * `touch-action: none` so a drag never scrolls the page instead.
 */

const ITEM = '[data-sortable-item]';
const HANDLE = '[data-sortable-handle]';

function itemsOf(container) {
    return Array.from(container.children).filter((el) => el.matches(ITEM));
}

function indexOf(container, item) {
    return itemsOf(container).indexOf(item);
}

/** The list (same group) whose box contains the point, else null. */
function containerAt(group, x, y) {
    const lists = document.querySelectorAll(`[data-sortable-group="${CSS.escape(group)}"]`);
    for (const list of lists) {
        const r = list.getBoundingClientRect();
        if (x >= r.left && x <= r.right && y >= r.top && y <= r.bottom) {
            return list;
        }
    }
    return null;
}

/** The sibling the dragged item should go before, by nearest centre (works for lists and grids, LTR and RTL). */
function insertionTarget(container, dragged, x, y) {
    let best = null;
    let bestDistance = Infinity;
    for (const candidate of itemsOf(container)) {
        if (candidate === dragged) continue;
        const r = candidate.getBoundingClientRect();
        const cx = r.left + r.width / 2;
        const cy = r.top + r.height / 2;
        const distance = Math.hypot(cx - x, cy - y);
        if (distance < bestDistance) {
            bestDistance = distance;
            best = { el: candidate, after: y > cy || (Math.abs(y - cy) < r.height / 2 && isAfterInline(x, cx)) };
        }
    }
    return best;
}

function isAfterInline(x, cx) {
    const rtl = document.documentElement.dir === 'rtl';
    return rtl ? x < cx : x > cx;
}

/*
 * Focus survives the re-render. Moving a DOM node blurs whatever inside it had
 * focus, so after Move up / Move down, an arrow key on the handle or a drop,
 * the next render would leave keyboard users on <body>. The control that
 * started the move is remembered by item key and position, and focused again
 * once the list has been re-rendered — wherever the item landed.
 */
let pendingFocus = null;

function rememberFocus(item, control) {
    const controls = Array.from(item.querySelectorAll('button'));
    pendingFocus = { key: item.dataset.sortableItem, index: controls.indexOf(control), at: Date.now() };
}

function restoreFocus() {
    if (!pendingFocus || Date.now() - pendingFocus.at > 10000) {
        pendingFocus = null;
        return;
    }
    const active = document.activeElement;
    if (active && active !== document.body) return;
    const item = document.querySelector(`[data-sortable-item="${CSS.escape(pendingFocus.key)}"]`);
    if (!item) return;
    const controls = Array.from(item.querySelectorAll('button'));
    const wanted = controls[pendingFocus.index];
    const target = wanted && !wanted.disabled ? wanted : item.querySelector(HANDLE) ?? controls.find((b) => !b.disabled);
    pendingFocus = null;
    target?.focus({ preventScroll: true });
}

function autoScroll(y) {
    const edge = 56;
    if (y < edge) window.scrollBy(0, -12);
    else if (y > window.innerHeight - edge) window.scrollBy(0, 12);
}

function register(Alpine) {
    Alpine.directive('sortable', (container, { expression }, { cleanup }) => {
        const method = expression.trim();
        const group = container.dataset.sortableGroup || null;
        let drag = null;

        const wire = () => {
            const root = container.closest('[wire\\:id]');
            return root && window.Livewire ? window.Livewire.find(root.getAttribute('wire:id')) : null;
        };

        const commit = (item, from, fromIndex) => {
            const to = item.parentElement;
            const toIndex = indexOf(to, item);
            const moved = to !== from;
            if (!moved && toIndex === fromIndex) return;
            const key = item.dataset.sortableItem;
            const component = wire();
            if (!component) return;
            if (moved) {
                component.call(method, key, toIndex, to.dataset.sortableContainer ?? null);
            } else {
                component.call(method, key, toIndex);
            }
        };

        const onPointerDown = (event) => {
            const handle = event.target.closest(HANDLE);
            if (!handle || !container.contains(handle) || event.button > 0) return;
            const item = handle.closest(ITEM);
            if (!item || item.parentElement !== container) return;
            event.preventDefault();
            handle.setPointerCapture?.(event.pointerId);
            drag = { item, handle, from: container, fromIndex: indexOf(container, item), pointerId: event.pointerId };
            item.classList.add('is-dragging');
            document.documentElement.classList.add('is-sorting');
            // On window, not the list: once the item crosses into another list
            // its events bubble there, and only this instance is dragging.
            window.addEventListener('pointermove', onPointerMove, { passive: false });
            window.addEventListener('pointerup', finish);
            window.addEventListener('pointercancel', finish);
        };

        const onPointerMove = (event) => {
            if (!drag || event.pointerId !== drag.pointerId) return;
            event.preventDefault();
            autoScroll(event.clientY);
            const target = group ? (containerAt(group, event.clientX, event.clientY) ?? drag.item.parentElement) : container;
            if (!target) return;
            target.classList.add('is-drop-target');
            document.querySelectorAll('.is-drop-target').forEach((el) => { if (el !== target) el.classList.remove('is-drop-target'); });
            const spot = insertionTarget(target, drag.item, event.clientX, event.clientY);
            if (!spot) {
                if (drag.item.parentElement !== target) target.appendChild(drag.item);
                return;
            }
            const reference = spot.after ? spot.el.nextElementSibling : spot.el;
            if (reference !== drag.item && reference !== drag.item.nextElementSibling) {
                target.insertBefore(drag.item, reference);
            } else if (reference === null && target.lastElementChild !== drag.item) {
                target.appendChild(drag.item);
            }
        };

        const finish = (event) => {
            if (!drag || (event && event.pointerId !== drag.pointerId)) return;
            const { item, from, fromIndex, handle } = drag;
            drag = null;
            window.removeEventListener('pointermove', onPointerMove);
            window.removeEventListener('pointerup', finish);
            window.removeEventListener('pointercancel', finish);
            item.classList.remove('is-dragging');
            document.documentElement.classList.remove('is-sorting');
            document.querySelectorAll('.is-drop-target').forEach((el) => el.classList.remove('is-drop-target'));
            handle.focus({ preventScroll: true });
            rememberFocus(item, handle);
            commit(item, from, fromIndex);
        };

        // Move up / Move down (and any other control on an item) keep focus too.
        const onClick = (event) => {
            const control = event.target.closest('button');
            const item = control && control.closest(ITEM);
            if (item && container.contains(item) && !control.matches(HANDLE)) rememberFocus(item, control);
        };

        const onKeyDown = (event) => {
            const handle = event.target.closest(HANDLE);
            if (!handle || !container.contains(handle)) return;
            const item = handle.closest(ITEM);
            if (!item || item.parentElement !== container) return;
            const index = indexOf(container, item);
            const last = itemsOf(container).length - 1;
            const next = { ArrowUp: index - 1, ArrowDown: index + 1, Home: 0, End: last }[event.key];
            if (next === undefined || next < 0 || next > last || next === index) return;
            event.preventDefault();
            const component = wire();
            if (!component) return;
            rememberFocus(item, handle);
            component.call(method, item.dataset.sortableItem, next);
        };

        // The morph that re-orders the list is when focus is lost; put it back.
        const observer = new MutationObserver(restoreFocus);
        observer.observe(container, { childList: true, subtree: true });

        container.addEventListener('pointerdown', onPointerDown);
        container.addEventListener('keydown', onKeyDown);
        container.addEventListener('click', onClick);

        cleanup(() => {
            observer.disconnect();
            container.removeEventListener('pointerdown', onPointerDown);
            container.removeEventListener('keydown', onKeyDown);
            container.removeEventListener('click', onClick);
            window.removeEventListener('pointermove', onPointerMove);
            window.removeEventListener('pointerup', finish);
            window.removeEventListener('pointercancel', finish);
        });
    });
}

if (window.Alpine) {
    register(window.Alpine);
} else {
    document.addEventListener('alpine:init', () => register(window.Alpine));
}
