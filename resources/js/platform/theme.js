/*
 * Shell behaviour shared by Super Admin and the Manager.
 *
 *  - theme            light / dark, remembered per browser
 *  - sidebar          collapsed rail on desktop, off-canvas drawer on mobile
 *  - popovers         <details data-popover>: one open at a time, outside click
 *                     and Escape close it, focus returns to its trigger
 *  - confirm dialog   every `wire:confirm` opens a styled, accessible <dialog>
 *                     instead of the browser's native confirm()
 *  - copy buttons     [data-copy="text"] copies and announces it
 *
 * The first paint is decided by the inline script in each layout's <head>, so
 * none of this changes what a user sees on load — it only reacts.
 */

const root = document.documentElement;
const mobileNavigation = window.matchMedia('(max-width: 64rem)');

/* ── Theme & sidebar state ─────────────────────────────────────────────────
 * The choice lives in localStorage and is mirrored into a plain cookie, so the
 * server renders the next page with the same theme on <html>. Livewire's
 * navigate copies that element's attributes from the new page; with the cookie
 * they already match, and `applyPreferences` runs inside the same swap as a
 * second guarantee — the page is never painted in the other theme.
 */
const readPreference = (key, allowed) => {
    try {
        const value = localStorage.getItem(key);
        return allowed.includes(value) ? value : null;
    } catch {
        return null;
    }
};

// The cookie only lets the server render the right first paint; the choice
// itself is localStorage's.
const mirror = (key, value) => {
    document.cookie = `${key}=${value}; path=/; max-age=31536000; SameSite=Lax`;
};

const preferredTheme = () => readPreference('metastyle-theme', ['light', 'dark'])
    ?? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');

const applyPreferences = () => {
    root.dataset.theme = preferredTheme();
    root.dataset.sidebar = readPreference('metastyle-sidebar', ['collapsed', 'expanded']) ?? 'expanded';
};

applyPreferences();

// Bring the cookie in line with a choice made before cookies were written.
['metastyle-theme', 'metastyle-sidebar'].forEach((key) => {
    const value = readPreference(key, key === 'metastyle-theme' ? ['light', 'dark'] : ['collapsed', 'expanded']);
    if (value && ! document.cookie.split('; ').includes(`${key}=${value}`)) mirror(key, value);
});

document.addEventListener('livewire:navigating', (event) => {
    event.detail?.onSwap?.(applyPreferences);
});

const shell = () => document.querySelector('[data-app-shell], .sadmin-shell');
const sidebar = () => document.querySelector('[data-app-sidebar], #sadmin-navigation');
const navToggle = () => document.querySelector('[data-nav-toggle]');

const syncShellState = () => {
    let collapsed = false;
    try { collapsed = localStorage.getItem('metastyle-sidebar') === 'collapsed'; } catch { /* storage may be blocked */ }
    root.dataset.sidebar = collapsed ? 'collapsed' : 'expanded';
    root.dataset.theme = preferredTheme();

    document.querySelectorAll('[data-sidebar-collapse]').forEach((button) => {
        button.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
        const label = collapsed ? button.dataset.labelExpand : button.dataset.labelCollapse;
        if (label) {
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
        }
    });

    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        const dark = root.dataset.theme === 'dark';
        const label = dark ? button.dataset.labelLight : button.dataset.labelDark;
        if (label) {
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
        }
    });
};

const syncNavigationAccessibility = () => {
    const navigation = sidebar();
    const closedOnMobile = mobileNavigation.matches && shell()?.dataset.navOpen !== 'true';

    if (navigation instanceof HTMLElement) {
        navigation.inert = closedOnMobile;
        navigation.setAttribute('aria-hidden', closedOnMobile ? 'true' : 'false');
    }
};

const closeNavigation = (restoreFocus = false) => {
    const current = shell();
    const toggle = navToggle();

    if (current) current.dataset.navOpen = 'false';
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
    syncNavigationAccessibility();
    if (restoreFocus && toggle instanceof HTMLElement && mobileNavigation.matches) toggle.focus();
};

syncShellState();
syncNavigationAccessibility();
mobileNavigation.addEventListener('change', syncNavigationAccessibility);

/* ── Popovers ──────────────────────────────────────────────────────────── */
const closePopovers = (except = null) => {
    document.querySelectorAll('details[data-popover][open]').forEach((popover) => {
        if (popover !== except) popover.open = false;
    });
};

/*
 * A menu inside a scrolling table would be clipped by it, so there the panel
 * floats: fixed to the viewport under its trigger, flipped above when there is
 * no room below, aligned to the inline end in either direction. Any scroll
 * closes it rather than letting it drift away from its row.
 */
const floatPanel = (popover) => {
    const panel = popover.querySelector(':scope > .dropdown__panel, :scope > .menu-panel');
    const trigger = popover.querySelector(':scope > summary');
    if (! panel || ! trigger || ! popover.closest('.table-shell, .table-scroll, .card, [data-float-popovers]')) return;

    const box = trigger.getBoundingClientRect();
    panel.classList.add('is-floating');
    const width = panel.offsetWidth;
    const height = panel.offsetHeight;
    const rtl = getComputedStyle(popover).direction === 'rtl';
    const gap = 6;
    const left = rtl ? box.left : box.right - width;
    const below = box.bottom + gap + height <= window.innerHeight - 8;

    panel.style.left = `${Math.max(8, Math.min(left, window.innerWidth - width - 8))}px`;
    panel.style.top = `${below ? box.bottom + gap : Math.max(8, box.top - gap - height)}px`;
};

const resetPanel = (popover) => {
    const panel = popover.querySelector(':scope > .is-floating');
    if (! panel) return;
    panel.classList.remove('is-floating');
    panel.style.left = '';
    panel.style.top = '';
};

document.addEventListener('toggle', (event) => {
    const popover = event.target;
    if (! (popover instanceof HTMLDetailsElement) || ! popover.matches('[data-popover]')) return;

    if (popover.open) {
        closePopovers(popover);
        floatPanel(popover);
    } else {
        resetPanel(popover);
    }
}, true);

window.addEventListener('scroll', (event) => {
    const open = document.querySelector('details[data-popover][open] > .is-floating');
    if (open && ! open.contains(event.target instanceof Node ? event.target : null)) {
        closePopovers();
    }
}, true);
window.addEventListener('resize', () => {
    if (document.querySelector('details[data-popover][open] > .is-floating')) closePopovers();
});

/* ── Clicks ────────────────────────────────────────────────────────────── */
document.addEventListener('click', (event) => {
    if (! (event.target instanceof Element)) return;

    if (! event.target.closest('details[data-popover]')) closePopovers();

    const themeButton = event.target.closest('[data-theme-toggle]');
    if (themeButton) {
        const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
        try { localStorage.setItem('metastyle-theme', next); } catch { /* storage may be blocked */ }
        mirror('metastyle-theme', next);
        syncShellState();
    }

    const navButton = event.target.closest('[data-nav-toggle]');
    if (navButton) {
        const current = shell();
        const open = current?.dataset.navOpen !== 'true';
        if (current) current.dataset.navOpen = open ? 'true' : 'false';
        navButton.setAttribute('aria-expanded', open ? 'true' : 'false');
        syncNavigationAccessibility();
        if (open) sidebar()?.querySelector('nav a')?.focus();
    }

    if (event.target.closest('[data-nav-close]')) closeNavigation(true);

    // A link chosen inside the mobile drawer navigates; the drawer must not
    // stay open over the page it just opened.
    if (event.target.closest('[data-app-sidebar] nav a, #sadmin-navigation nav a') && mobileNavigation.matches) {
        closeNavigation(false);
    }

    const collapseButton = event.target.closest('[data-sidebar-collapse]');
    if (collapseButton) {
        const collapsed = root.dataset.sidebar !== 'collapsed';
        try { localStorage.setItem('metastyle-sidebar', collapsed ? 'collapsed' : 'expanded'); } catch { /* storage may be blocked */ }
        mirror('metastyle-sidebar', collapsed ? 'collapsed' : 'expanded');
        syncShellState();
    }

    // Password show / hide. A type="button" control, so it never submits.
    const passwordToggle = event.target.closest('[data-password-toggle]');
    if (passwordToggle instanceof HTMLElement) {
        const input = document.getElementById(passwordToggle.getAttribute('aria-controls') ?? '');
        if (input instanceof HTMLInputElement) {
            const reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            passwordToggle.setAttribute('aria-pressed', reveal ? 'true' : 'false');
            const label = reveal ? passwordToggle.dataset.labelHide : passwordToggle.dataset.labelShow;
            if (label) {
                passwordToggle.setAttribute('aria-label', label);
                passwordToggle.setAttribute('title', label);
            }
        }
    }

    const copyButton = event.target.closest('[data-copy]');
    if (copyButton instanceof HTMLElement) {
        navigator.clipboard?.writeText(copyButton.dataset.copy ?? '').then(() => {
            const original = copyButton.getAttribute('aria-label');
            const done = copyButton.dataset.copied;
            copyButton.dataset.state = 'copied';
            if (done) {
                copyButton.setAttribute('aria-label', done);
                announce(done);
            }
            window.setTimeout(() => {
                delete copyButton.dataset.state;
                if (original) copyButton.setAttribute('aria-label', original);
            }, 1600);
        });
    }
});

/* ── Keyboard ──────────────────────────────────────────────────────────── */
document.addEventListener('keydown', (event) => {
    const current = shell();
    const navigation = sidebar();

    // Trap focus inside the open mobile drawer.
    if (event.key === 'Tab' && mobileNavigation.matches && current?.dataset.navOpen === 'true' && navigation instanceof HTMLElement) {
        const focusable = [...navigation.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])')]
            .filter((element) => element instanceof HTMLElement && ! element.hidden && element.offsetParent !== null);
        const first = focusable[0];
        const last = focusable.at(-1);

        if (event.shiftKey && document.activeElement === first && last instanceof HTMLElement) {
            event.preventDefault();
            last.focus();
        } else if (! event.shiftKey && document.activeElement === last && first instanceof HTMLElement) {
            event.preventDefault();
            first.focus();
        }
    }

    if (event.key !== 'Escape') return;

    const openPopover = document.querySelector('details[data-popover][open]');
    if (openPopover instanceof HTMLDetailsElement) {
        openPopover.open = false;
        openPopover.querySelector('summary')?.focus();
        return;
    }

    closeNavigation(true);
});

/* ── Screen-reader announcements ───────────────────────────────────────── */
const announce = (message) => {
    let region = document.getElementById('ms-live-region');
    if (! region) {
        region = document.createElement('div');
        region.id = 'ms-live-region';
        region.className = 'sr-only';
        region.setAttribute('role', 'status');
        region.setAttribute('aria-live', 'polite');
        document.body.append(region);
    }
    region.textContent = '';
    window.setTimeout(() => { region.textContent = message; }, 40);
};

/* ── Confirm dialog ────────────────────────────────────────────────────────
 * Livewire's `wire:confirm` calls `el.__livewire_confirm(action, instead)`.
 * Replacing that function just before Livewire's own click handler runs lets
 * every existing confirmation use one designed, accessible dialog — no call
 * site changes, and a keyboard or screen-reader user gets a real modal with
 * focus trapped and Escape to cancel.
 */
const confirmDialog = ({ title, message, confirmLabel, tone }) => new Promise((resolve) => {
    const template = document.getElementById('ms-confirm-template');
    if (! (template instanceof HTMLTemplateElement) || typeof HTMLDialogElement !== 'function') {
        resolve(window.confirm(message));
        return;
    }

    const dialog = template.content.firstElementChild.cloneNode(true);
    const heading = dialog.querySelector('[data-confirm-title]');
    const body = dialog.querySelector('[data-confirm-message]');
    const accept = dialog.querySelector('[data-confirm-accept]');
    const icon = dialog.querySelector('[data-confirm-icon]');

    if (title && heading) heading.textContent = title;
    if (body) body.textContent = message;
    if (confirmLabel && accept) accept.textContent = confirmLabel;
    if (tone === 'danger') {
        accept?.classList.add('button--danger');
        icon?.setAttribute('data-tone', 'danger');
    }

    const opener = document.activeElement;
    let settled = false;

    /*
     * While the dialog is open, Escape and Tab belong to it alone. A drawer or
     * modal underneath listens for Escape on window (and would close itself,
     * or click its own close action), and its focus trap listens for Tab in the
     * capture phase (and would pull focus back behind the dialog). A window
     * capture listener runs before both; the browser's own handling — Escape
     * cancels the dialog, Tab moves between its buttons — is a default action
     * and is unaffected by stopping propagation.
     */
    const guard = (event) => {
        if (event.key === 'Escape' || event.key === 'Tab') event.stopPropagation();
    };
    window.addEventListener('keydown', guard, true);

    const finish = (value) => {
        if (settled) return;
        settled = true;
        window.removeEventListener('keydown', guard, true);
        dialog.close();
        dialog.remove();
        if (opener instanceof HTMLElement) opener.focus();
        resolve(value);
    };

    dialog.addEventListener('cancel', (event) => { event.preventDefault(); finish(false); });
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) finish(false);
        if (event.target instanceof Element && event.target.closest('[data-confirm-cancel]')) finish(false);
        if (event.target instanceof Element && event.target.closest('[data-confirm-accept]')) finish(true);
    });

    document.body.append(dialog);
    dialog.showModal();
    (tone === 'danger' ? dialog.querySelector('[data-confirm-cancel]') : accept)?.focus();
});

/*
 * The drawer or modal that answers Escape: only the top visible layer, and
 * none while a confirmation dialog is open. Every x-ui.drawer / x-ui.modal
 * listens on window, so without this one Escape closed a stacked confirm AND
 * the drawer beneath it.
 */
window.msIsTopLayer = (element) => {
    if (document.querySelector('dialog[open]')) return false;
    const layers = [...document.querySelectorAll('.modal-backdrop, .drawer-backdrop')]
        .filter((layer) => layer.getClientRects().length > 0);

    return layers.at(-1) === element;
};

/*
 * Armed on click AND on submit: a confirmation on a form (wire:submit +
 * wire:confirm on the <form>, which is where Livewire looks) is reached by
 * pressing Enter in a field too, with no click at all — and would otherwise
 * fall back to the browser's native confirm().
 */
const armConfirm = (event) => {
    if (! (event.target instanceof Element)) return;
    const trigger = event.target.closest('[wire\\:confirm]');
    if (! trigger || typeof trigger.__livewire_confirm !== 'function') return;
    if ([...trigger.attributes].some((attribute) => attribute.name.startsWith('wire:confirm.prompt'))) return;

    const message = (trigger.getAttribute('wire:confirm') || '').replaceAll('\\n', '\n');
    const tone = trigger.dataset.confirmTone
        ?? (trigger.matches('.button--danger, .button--danger-soft, .text-button--danger, .menu-item--danger, .btn.danger') ? 'danger' : 'default');

    trigger.__livewire_confirm = (action) => {
        confirmDialog({
            title: trigger.dataset.confirmTitle,
            message,
            confirmLabel: trigger.dataset.confirmLabel,
            tone,
        }).then((accepted) => { if (accepted) action(); });
    };
};

document.addEventListener('click', armConfirm, true);
document.addEventListener('submit', armConfirm, true);

/* ── Chart tooltips ─────────────────────────────────────────────────────────
 * One floating tooltip for every [data-tip] mark, on hover AND keyboard
 * focus. Built with textContent only: labels can come from data a center
 * typed, so nothing here is ever parsed as HTML.
 */
let tooltip = null;

const showTip = (target) => {
    if (! (target instanceof HTMLElement)) return;
    let rows = [];
    try { rows = JSON.parse(target.dataset.tipRows ?? '[]'); } catch { rows = []; }

    tooltip ??= Object.assign(document.createElement('div'), { className: 'ms-tooltip', role: 'tooltip' });
    if (! tooltip.isConnected) document.body.append(tooltip);
    tooltip.replaceChildren();

    const title = document.createElement('span');
    title.className = 'ms-tooltip__title';
    title.textContent = target.dataset.tipTitle ?? '';
    tooltip.append(title);

    rows.forEach((row) => {
        const line = document.createElement('div');
        line.className = 'ms-tooltip__row';
        const value = document.createElement('strong');
        value.textContent = String(row.value ?? '');
        line.append(value);
        if (rows.length > 1) {
            const key = document.createElement('span');
            key.className = 'ms-tooltip__key';
            key.dataset.series = String(row.series ?? 1);
            key.textContent = String(row.label ?? '');
            line.append(key);
        }
        tooltip.append(line);
    });

    tooltip.hidden = false;
    const box = target.getBoundingClientRect();
    const tip = tooltip.getBoundingClientRect();
    const left = Math.min(Math.max(8, box.left + box.width / 2 - tip.width / 2), window.innerWidth - tip.width - 8);
    const top = box.top - tip.height - 8 < 8 ? box.bottom + 8 : box.top - tip.height - 8;
    tooltip.style.left = `${left}px`;
    tooltip.style.top = `${Math.max(8, top)}px`;
};

const hideTip = () => { if (tooltip) tooltip.hidden = true; };

document.addEventListener('pointerover', (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-tip]') : null;
    target ? showTip(target) : hideTip();
});
document.addEventListener('focusin', (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-tip]') : null;
    target ? showTip(target) : hideTip();
});
document.addEventListener('focusout', hideTip);
window.addEventListener('scroll', hideTip, { passive: true });

/* ── Notification sound ───────────────────────────────────────────────────
 * A short two-note chime made with Web Audio (no file to load), played only
 * when the bell reports a notification newer than any this browser has seen,
 * never more than once every ten seconds, and never when muted. The choice is
 * remembered per browser. Browsers only allow sound after the person has
 * interacted with the page; the first click unlocks it.
 */
let audioContext = null;
let lastChime = 0;

const soundOn = () => readPreference('metastyle-notify-sound', ['on', 'off']) !== 'off';

const syncSoundToggles = () => {
    const muted = ! soundOn();
    root.dataset.sound = muted ? 'off' : 'on';
    document.querySelectorAll('[data-sound-toggle]').forEach((button) => {
        button.setAttribute('aria-pressed', muted ? 'true' : 'false');
        const label = muted ? button.dataset.labelUnmute : button.dataset.labelMute;
        if (label) {
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
        }
    });
};

const unlockAudio = () => {
    try {
        audioContext ??= new (window.AudioContext || window.webkitAudioContext)();
        if (audioContext.state === 'suspended') audioContext.resume();
    } catch { /* no audio available */ }
};

const chime = () => {
    if (! soundOn() || Date.now() - lastChime < 10000) return;
    lastChime = Date.now();
    try {
        unlockAudio();
        if (! audioContext) return;
        const start = audioContext.currentTime;
        [[880, 0], [1318.5, 0.13]].forEach(([frequency, offset]) => {
            const oscillator = audioContext.createOscillator();
            const gain = audioContext.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.value = frequency;
            gain.gain.setValueAtTime(0.0001, start + offset);
            gain.gain.exponentialRampToValueAtTime(0.07, start + offset + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + offset + 0.4);
            oscillator.connect(gain).connect(audioContext.destination);
            oscillator.start(start + offset);
            oscillator.stop(start + offset + 0.45);
        });
    } catch { /* sound is a courtesy, never an error */ }
};

window.addEventListener('platform-notification', (event) => {
    chime();
    const count = event.detail?.count;
    if (count) announce(String(count));
});
document.addEventListener('pointerdown', unlockAudio, { once: true });
document.addEventListener('keydown', unlockAudio, { once: true });

document.addEventListener('click', (event) => {
    const toggle = event.target instanceof Element ? event.target.closest('[data-sound-toggle]') : null;
    if (! toggle) return;
    try { localStorage.setItem('metastyle-notify-sound', soundOn() ? 'off' : 'on'); } catch { /* storage may be blocked */ }
    syncSoundToggles();
});

syncSoundToggles();

/* ── Livewire navigation ───────────────────────────────────────────────── */
document.addEventListener('livewire:navigated', () => {
    closeNavigation(false);
    closePopovers();
    syncShellState();
    syncNavigationAccessibility();
    syncSoundToggles();
});
