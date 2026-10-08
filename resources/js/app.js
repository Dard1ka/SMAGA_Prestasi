/*
 | SMAGA Talent Hub — small, CSP-safe UI behaviours for the <x-sth.*> components.
 |
 | Everything is event delegation on `document`, so it keeps working after Livewire morphs or
 | wire:navigate page swaps, and it needs no inline scripts, no eval and no Alpine expressions.
 | Livewire (with its bundled Alpine) is loaded separately by @livewireScripts.
 |
 | Hooks (see the component docs in resources/views/components/sth):
 |   data-sth-open="<dialog id>" · data-sth-close · data-sth-autoopen        <x-sth.modal>, mobile menu
 |   data-sth-on-close="<method>" on a <dialog>: calls that Livewire method whenever the dialog closes
 |       (close button, Esc, click outside)                                  <x-sth.modal on-close>
 |   data-sth-countdown="<seconds>" + [data-sth-countdown-text] inside: the text counts down m:ss every
 |       second; a new value from the server (wire:poll) starts it again     activation "Kirim ulang dalam"
 |   window events: sth-modal-open / sth-modal-close {id} · sth-toast {message, tone, key?}
 |                  sth-toast-clear {key} — remove the toasts raised with that key (a failure that is over)
 |                  sth-form-invalid {form} — bring the first validation error into view
 |                  sth-badges {badges: {key: count}} — update [data-sth-badge="key"] in the sidebar
 |   form[data-sth-live-search]                                               top bar search filters as you type
 |   data-sth-toast / data-sth-toast-close / data-announce                    <x-sth.toast>, <x-sth.flash>
 |   data-sth-tabs / data-sth-tab / data-sth-panel                            <x-sth.tabs panels>
 |   data-sth-reveal="<input id>"                                             <x-sth.input revealable>
 |   (no hook) password fields are emptied when a page returns from the back/forward cache and right after
 |             a plain POST form was submitted, see "passwords do not stay on screen"
 |   data-sth-drop / data-sth-file-name                                       <x-sth.file-drop>
 |   data-sth-menu / data-sth-menu-button / data-sth-menu-panel               top bar account menu
 |   data-sth-preview="<inline url>" data-sth-preview-type="pdf|image" data-sth-preview-name="<file name>"
 |       on a link whose href is the download (a <button>: data-sth-preview-download="<url>"): the click shows
 |       the file in the dialog #sth-preview instead of downloading it        <x-sth.preview>, "file preview popup"
 |   data-sth-copy="#target-id"                                               copies that element's innerText,
 |       toast "Data disalin."; no Clipboard API or refused: selects the text, toast "Tekan Ctrl+C untuk menyalin."
 */

const TOAST_TIMEOUT = 6000;

/* ---------------------------------------------------------------- live region */

function announce(text, assertive = false) {
    const region = document.getElementById(assertive ? 'sth-live-assertive' : 'sth-live');
    if (!region || !text) return;
    region.textContent = '';
    window.setTimeout(() => {
        region.textContent = text;
    }, 120);
}

/* ---------------------------------------------------------------- dialogs */

const dialogOpeners = new WeakMap();

function openDialog(id, opener = null) {
    const dialog = document.getElementById(id);
    if (!(dialog instanceof HTMLDialogElement) || dialog.open) return;
    dialog.showModal();
    if (opener instanceof HTMLElement) dialogOpeners.set(dialog, opener);
}

function closeDialog(dialog) {
    if (dialog instanceof HTMLDialogElement && dialog.open) dialog.close();
}

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const opener = target.closest('[data-sth-open]');
    if (opener) {
        event.preventDefault();
        openDialog(opener.getAttribute('data-sth-open'), opener);
        return;
    }

    const closer = target.closest('[data-sth-close]');
    if (closer) {
        event.preventDefault();
        closeDialog(closer.closest('dialog'));
        return;
    }

    // Click on the backdrop: the <dialog> box itself is transparent around .dlg-panel.
    if (target instanceof HTMLDialogElement && target.classList.contains('dlg')) {
        const rect = target.querySelector('.dlg-panel')?.getBoundingClientRect();
        const inside =
            rect &&
            event.clientX >= rect.left &&
            event.clientX <= rect.right &&
            event.clientY >= rect.top &&
            event.clientY <= rect.bottom;
        if (!inside) closeDialog(target);
    }
});

// Return focus to the element that opened the dialog (dialogs opened by a Livewire event rely on the
// browser's own focus restoration).
document.addEventListener(
    'close',
    (event) => {
        const dialog = event.target;
        if (!(dialog instanceof HTMLDialogElement)) return;
        const opener = dialogOpeners.get(dialog);
        dialogOpeners.delete(dialog);
        if (opener?.isConnected && opener.offsetParent !== null) opener.focus();

        // <x-sth.modal on-close="…">: the server resets what the dialog showed (e.g. ?kelas=), however it closed.
        const method = dialog.getAttribute('data-sth-on-close');
        if (method && /^[A-Za-z_][A-Za-z0-9_]*$/.test(method)) {
            // Livewire.all() gives the components (Livewire.find() gives only the $wire proxy).
            const id = dialog.closest('[wire\\:id]')?.getAttribute('wire:id');
            const component = id ? (window.Livewire?.all?.() || []).find((c) => c.id === id) : null;
            component?.$wire?.$call(method);
        }
    },
    true,
);

window.addEventListener('sth-modal-open', (event) => openDialog(event.detail?.id));
window.addEventListener('sth-modal-close', (event) => {
    const id = event.detail?.id;
    if (id) closeDialog(document.getElementById(id));
    else document.querySelectorAll('dialog[open]').forEach(closeDialog);
});

/* ---------------------------------------------------------------- countdown */

// "Kirim ulang dalam 0:54": the server polls every 5 s (and shows the button at 0); this ticks the text in between.
const countdowns = new WeakMap();

function tickCountdowns() {
    document.querySelectorAll('[data-sth-countdown]').forEach((element) => {
        const seconds = Number.parseInt(element.getAttribute('data-sth-countdown') || '', 10);
        const text = element.querySelector('[data-sth-countdown-text]');
        if (!Number.isFinite(seconds) || !text) return;

        let state = countdowns.get(element);
        if (!state || state.seconds !== seconds) {
            state = { seconds, end: Date.now() + seconds * 1000 };
            countdowns.set(element, state);
        }

        const left = Math.max(0, Math.ceil((state.end - Date.now()) / 1000));
        text.textContent = `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`;
    });
}

window.setInterval(tickCountdowns, 1000);

/* ---------------------------------------------------------------- toasts */

const TONES = {
    success: ['bg-green-bg text-green', 'M5 12l5 5 9-10'],
    info: ['bg-blue-bg text-blue', 'M12 11v5M12 8h.01'],
    warning: ['bg-orange-bg text-orange', 'M12 7v5l3 2'],
    error: ['bg-red-bg text-red', 'M12 7.5v5.5M12 16.5h.01'],
};

function svgIcon(path, withCircle) {
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    for (const [key, value] of Object.entries({
        width: '18',
        height: '18',
        viewBox: '0 0 24 24',
        fill: 'none',
        stroke: 'currentColor',
        'stroke-width': '1.8',
        'stroke-linecap': 'round',
        'stroke-linejoin': 'round',
        'aria-hidden': 'true',
    })) {
        svg.setAttribute(key, value);
    }
    if (withCircle) {
        const circle = document.createElementNS(ns, 'circle');
        circle.setAttribute('cx', '12');
        circle.setAttribute('cy', '12');
        circle.setAttribute('r', '9');
        svg.appendChild(circle);
    }
    const p = document.createElementNS(ns, 'path');
    p.setAttribute('d', path);
    svg.appendChild(p);
    return svg;
}

/*
 | A modal <dialog> renders in the top layer, which paints above the entire normal stacking context, so no
 | z-index on .toast-region can reach it -- a toast raised while a drawer is open was simply invisible.
 | Showing the region as a popover puts it in the top layer as well, and re-showing it lifts it back above
 | a dialog that was opened later. Browsers without the Popover API keep the old, normal-layer behaviour.
 */
function liftToastRegion(region) {
    if (typeof region.showPopover !== 'function') return;
    try {
        if (!region.hasAttribute('popover')) region.setAttribute('popover', 'manual');
        if (region.matches(':popover-open')) region.hidePopover();
        region.showPopover();
    } catch {
        // Leave the region in the normal layer rather than risk hiding it behind a half-applied popover.
        region.removeAttribute('popover');
    }
}

function dropToastRegion(region) {
    if (!region || region.querySelector('[data-sth-toast]')) return;
    try {
        if (region.matches(':popover-open')) region.hidePopover();
    } catch {
        // Already closed.
    }
    region.removeAttribute('popover');
}

function dismissToast(toast) {
    if (!toast || toast.dataset.leaving) return;
    const region = toast.parentElement;
    toast.dataset.leaving = '1';
    window.clearTimeout(Number(toast.dataset.timer || 0));
    toast.remove();
    dropToastRegion(region);
}

function armToast(toast) {
    const tone = toast.dataset.tone || 'success';
    if (tone === 'error' || tone === 'warning') return;
    const start = () => {
        toast.dataset.timer = String(window.setTimeout(() => dismissToast(toast), TOAST_TIMEOUT));
    };
    const stop = () => window.clearTimeout(Number(toast.dataset.timer || 0));
    toast.addEventListener('mouseenter', stop);
    toast.addEventListener('focusin', stop);
    toast.addEventListener('mouseleave', start);
    toast.addEventListener('focusout', start);
    start();
}

function showToast(message, tone = 'success', key = '') {
    const region = document.getElementById('sth-toasts');
    if (!region || !message) return;
    const [iconClass, path] = TONES[tone] || TONES.success;

    // Error and warning toasts never time out, so without this a form that is submitted twice leaves two
    // identical notices on the pile. Re-arm the one already on screen instead of stacking a copy.
    const existing = [...region.querySelectorAll('[data-sth-toast]')].find(
        (node) => node.dataset.tone === tone && node.dataset.message === String(message),
    );

    if (existing) {
        liftToastRegion(region);
        armToast(existing);
        announce(String(message), tone === 'error');

        return;
    }

    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.dataset.sthToast = '';
    toast.dataset.tone = TONES[tone] ? tone : 'success';
    toast.dataset.message = String(message);
    if (key) toast.dataset.key = String(key);

    const icon = document.createElement('span');
    icon.className = `toast-ic ${iconClass}`;
    icon.appendChild(svgIcon(path, tone !== 'success'));

    const text = document.createElement('span');
    text.className = 'min-w-0 flex-1';
    text.textContent = String(message);

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'icon-btn icon-btn-ghost icon-btn-sm';
    close.setAttribute('aria-label', 'Tutup pesan');
    close.dataset.sthToastClose = '';
    close.appendChild(svgIcon('M6 6l12 12M18 6 6 18', false));

    toast.append(icon, text, close);
    region.appendChild(toast);
    liftToastRegion(region);
    armToast(toast);
    announce(text.textContent, tone === 'error');
}

document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-sth-toast-close]') : null;
    if (button) dismissToast(button.closest('[data-sth-toast]'));
});

window.addEventListener('sth-toast', (event) => {
    const detail = event.detail || {};
    showToast(detail.message, detail.tone, detail.key);
});

// Error and warning toasts stay until closed. A screen that raised one with a key takes it away again once the
// failure is over (e.g. the PDF was made after all), so it does not keep reporting a problem that is gone.
window.addEventListener('sth-toast-clear', (event) => {
    const key = String((event.detail || {}).key || '');
    const region = document.getElementById('sth-toasts');
    if (!region || !key) return;
    region.querySelectorAll('[data-sth-toast]').forEach((toast) => {
        if (toast.dataset.key === key) dismissToast(toast);
    });
});

/* ---------------------------------------------------------------- form errors */

/*
 | A form inside a drawer scrolls inside .dlg-body, and the submit buttons sit in the sticky footer, so
 | after a failed submit the error is typically hundreds of pixels above the fold and nothing moved the
 | user there. Scroll the first problem into view and focus it.
 |
 | The summary alert wins over the first marked field: it names every missing field at once, so it is the
 | more useful thing to land on when both are present.
 */
function revealFirstError(scope) {
    const root = scope instanceof Element ? scope : document;
    const target = root.querySelector('[data-sth-error-summary]') || root.querySelector('[aria-invalid="true"]');
    if (!target) return false;

    // 'instant', not 'smooth': a smooth scroll is driven by animation frames and silently does nothing in a
    // tab that is not drawing, which would leave the user staring at an unchanged form. Moving focus below
    // is what orients them, so the jump does not need to be animated.
    target.scrollIntoView({ block: 'center', behavior: 'instant' });

    // preventScroll so focus does not jump-cut past the smooth scroll we just started.
    const field = target.matches('input, select, textarea') ? target : target.querySelector('input, select, textarea');
    (field || target).focus({ preventScroll: true });

    return true;
}

window.addEventListener('sth-form-invalid', (event) => {
    const id = event.detail?.form;
    const scope = id ? document.getElementById(id) : null;

    // Livewire has already morphed the error markers in by the time it dispatches this, so look now. No
    // requestAnimationFrame: a backgrounded tab never runs those callbacks, and the jump would be lost.
    if (revealFirstError(scope)) return;

    // Only if the markers somehow are not in yet, give the DOM one more turn. setTimeout still fires when
    // frames do not.
    window.setTimeout(() => revealFirstError(scope), 50);
});

/* ---------------------------------------------------------------- sidebar badges */

/*
 | The sidebar belongs to the layout, which Livewire renders once per page load, so a queue count next to a
 | menu item went stale as soon as an action on the page changed the queue (send a letter, verify a report).
 | Screens dispatch `sth-badges` with fresh counts and the numbers are updated in place; zero hides the badge.
 */
window.addEventListener('sth-badges', (event) => {
    const badges = event.detail?.badges;
    if (!badges || typeof badges !== 'object') return;

    for (const [key, value] of Object.entries(badges)) {
        const count = Math.max(0, Math.trunc(Number(value)) || 0);
        document.querySelectorAll(`[data-sth-badge="${CSS.escape(key)}"]`).forEach((badge) => {
            badge.textContent = String(count);
            badge.hidden = count === 0;
        });
    }
});

/* ---------------------------------------------------------------- top bar search (live) */

/*
 | The top bar search is a plain GET form (?q=) that points at the role's list page. On that list page itself
 | the Livewire screen owns a property with the same name, so typing filters the list right away: no Enter
 | needed, and emptying the box brings the full list back. On every other page Enter still submits the form.
 */
const SEARCH_DELAY = 350;
let searchTimer = 0;

function liveSearchTarget(form, name) {
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-sth-live-search') || !name) return null;
    if (new URL(form.action, window.location.href).pathname !== window.location.pathname) return null;

    const components = window.Livewire?.all?.() || [];

    return components.find((component) => component.canonical && Object.hasOwn(component.canonical, name)) || null;
}

document.addEventListener('input', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) return;
    const component = liveSearchTarget(input.form, input.name);
    if (!component) return;

    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => component.$wire.set(input.name, input.value), SEARCH_DELAY);
});

document.addEventListener('submit', (event) => {
    const form = event.target;
    const input = form instanceof HTMLFormElement ? form.querySelector('input[type="search"]') : null;
    const component = input ? liveSearchTarget(form, input.name) : null;
    if (!component) return;

    event.preventDefault();
    window.clearTimeout(searchTimer);
    component.$wire.set(input.name, input.value);
});

// "Hapus filter" and the like change the value on the server: show it in the box unless the user is typing.
function syncLiveSearch() {
    document.querySelectorAll('form[data-sth-live-search] input[type="search"]').forEach((input) => {
        if (document.activeElement === input) return;
        const component = liveSearchTarget(input.form, input.name);
        const value = component?.canonical?.[input.name];
        if (typeof value === 'string' && input.value !== value) input.value = value;
    });
}

function watchLiveSearch() {
    if (!window.Livewire?.hook || window.sth?.searchWatched) return;
    window.sth.searchWatched = true;
    window.Livewire.hook('commit', ({ succeed }) => {
        succeed(() => window.setTimeout(syncLiveSearch, 0));
    });
}

/* ---------------------------------------------------------------- tabs (client panels) */

function selectTab(tab, focus) {
    const list = tab.closest('[data-sth-tabs]');
    if (!list) return;
    const group = list.getAttribute('data-sth-tabs');
    list.querySelectorAll('[role="tab"]').forEach((other) => {
        const on = other === tab;
        other.setAttribute('aria-selected', on ? 'true' : 'false');
        other.setAttribute('tabindex', on ? '0' : '-1');
    });
    document.querySelectorAll(`[data-sth-panel="${CSS.escape(group)}"]`).forEach((panel) => {
        panel.hidden = panel.id !== tab.getAttribute('aria-controls');
    });
    if (focus) tab.focus();
}

document.addEventListener('click', (event) => {
    const tab = event.target instanceof Element ? event.target.closest('[data-sth-tabs] [role="tab"]') : null;
    if (tab) selectTab(tab, false);
});

document.addEventListener('keydown', (event) => {
    const tab = event.target instanceof Element ? event.target.closest('[role="tab"]') : null;
    const list = tab?.closest('[role="tablist"]');
    if (!tab || !list) return;
    const tabs = [...list.querySelectorAll('[role="tab"]:not([disabled])')];
    const index = tabs.indexOf(tab);
    const next = {
        ArrowRight: tabs[(index + 1) % tabs.length],
        ArrowLeft: tabs[(index - 1 + tabs.length) % tabs.length],
        Home: tabs[0],
        End: tabs[tabs.length - 1],
    }[event.key];
    if (!next) return;
    event.preventDefault();
    if (list.hasAttribute('data-sth-tabs')) {
        selectTab(next, true);
    } else {
        next.focus(); // Livewire tabs: focus moves, Enter/Space activates (wire:click).
    }
});

/* ---------------------------------------------------------------- password reveal */

function setReveal(button, input, show) {
    input.type = show ? 'text' : 'password';
    button.setAttribute('aria-pressed', show ? 'true' : 'false');
    button.setAttribute('aria-label', show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
    button.querySelector('[data-sth-reveal-off]')?.toggleAttribute('hidden', show);
    button.querySelector('[data-sth-reveal-on]')?.toggleAttribute('hidden', !show);
}

document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-sth-reveal]') : null;
    if (!button) return;
    const input = document.getElementById(button.getAttribute('data-sth-reveal'));
    if (!(input instanceof HTMLInputElement)) return;
    setReveal(button, input, input.type === 'password');
});

/* ---------------------------------------------------------------- credentials do not stay on screen */

/*
 | On a shared lab computer, Back after "Keluar" brought the old login page back with the password still in
 | its field (QA Q2-01). The credential pages are sent with `Cache-Control: no-store`
 | (App\Http\Middleware\PreventCredentialPageCaching); this is the second line, for a page the browser kept anyway:
 |   - a page that comes back from the back/forward cache (`pageshow` with `event.persisted`), or that the browser
 |     loaded again for Back/Forward and refilled from its own memory of the form (navigation type
 |     `back_forward`), gets every password field and every account name field emptied;
 |   - a plain POST form (login, confirm password) gets these fields emptied right after the browser has read them
 |     for the request, so the copy of the page that stays in history holds neither a password nor an account name.
 | The account name is the field with autocomplete="username" (NIS, NISN, username or email on the login page):
 | the QA of 2026-10-06 still saw it after Keluar and two Backs, because a browser refills text fields on Back.
 | When a login fails the server sends the page again with the account name in it, as before.
 | A Livewire form (`wire:submit`) is left alone on submit: Livewire sends its own state, and the component
 | empties the fields itself when the action succeeds. Emptying the input under it would only put the screen and
 | that state out of step.
 */
function passwordFields(root) {
    const fields = new Set(root.querySelectorAll('input[type="password"]'));

    // "Tampilkan kata sandi" turns a field into type=text; it is still a password field.
    root.querySelectorAll('[data-sth-reveal]').forEach((button) => {
        const input = document.getElementById(button.getAttribute('data-sth-reveal'));
        if (input instanceof HTMLInputElement) fields.add(input);
    });

    return [...fields];
}

function credentialFields(root) {
    return [...new Set([...passwordFields(root), ...root.querySelectorAll('input[autocomplete="username"]')])];
}

function openedFromHistory() {
    return performance.getEntriesByType('navigation')[0]?.type === 'back_forward';
}

function emptyFields(fields, notify) {
    fields.forEach((input) => {
        const hadValue = input.value !== '';
        input.value = '';

        const button = input.id ? document.querySelector(`[data-sth-reveal="${CSS.escape(input.id)}"]`) : null;
        if (button && input.type !== 'password') setReveal(button, input, false);

        // Tell a bound Livewire property (wire:model) that the field is empty now.
        if (notify && hadValue) {
            input.dispatchEvent(new Event('input', { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
}

window.addEventListener('pageshow', (event) => {
    if (event.persisted || openedFromHistory()) emptyFields(credentialFields(document), true);
});

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.method !== 'post') return;
    if (form.getAttributeNames().some((name) => name.startsWith('wire:submit'))) return;

    if (!passwordFields(form).length) return;
    const fields = credentialFields(form);

    // The browser builds the request body from the fields in this same task, right after the submit event. A
    // timer runs after that, so the password is already on its way when its field is emptied. A submit that some
    // other handler cancelled keeps what was typed.
    window.setTimeout(() => {
        if (!event.defaultPrevented) emptyFields(fields, false);
    }, 0);
});

/* ---------------------------------------------------------------- file drop */

function showFileNames(input) {
    const zone = input.closest('[data-sth-drop]');
    const label = zone?.querySelector('[data-sth-file-name]');
    if (!label) return;
    const names = [...(input.files || [])].map((file) => file.name);
    label.textContent = names.length ? names.join(', ') : label.getAttribute('data-empty') || '';
}

document.addEventListener('change', (event) => {
    const input = event.target;
    if (input instanceof HTMLInputElement && input.type === 'file' && input.closest('[data-sth-drop]')) {
        showFileNames(input);
    }
});

for (const type of ['dragenter', 'dragover']) {
    document.addEventListener(type, (event) => {
        const zone = event.target instanceof Element ? event.target.closest('[data-sth-drop]') : null;
        if (!zone) return;
        event.preventDefault();
        zone.classList.add('is-dragover');
    });
}

for (const type of ['dragleave', 'dragend']) {
    document.addEventListener(type, (event) => {
        const zone = event.target instanceof Element ? event.target.closest('[data-sth-drop]') : null;
        if (zone && !zone.contains(event.relatedTarget)) zone.classList.remove('is-dragover');
    });
}

document.addEventListener('drop', (event) => {
    const zone = event.target instanceof Element ? event.target.closest('[data-sth-drop]') : null;
    if (!zone) return;
    event.preventDefault();
    zone.classList.remove('is-dragover');
    const input = zone.querySelector('input[type="file"]');
    const files = event.dataTransfer?.files;
    if (!(input instanceof HTMLInputElement) || !files?.length) return;
    const transfer = new DataTransfer();
    [...files].slice(0, input.multiple ? files.length : 1).forEach((file) => transfer.items.add(file));
    input.files = transfer.files;
    input.dispatchEvent(new Event('change', { bubbles: true }));
});

/* ---------------------------------------------------------------- disclosure menu (account) */

function setMenu(root, open) {
    const button = root.querySelector('[data-sth-menu-button]');
    const panel = root.querySelector('[data-sth-menu-panel]');
    if (!button || !panel) return;
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    panel.hidden = !open;
}

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const button = target?.closest('[data-sth-menu-button]');
    document.querySelectorAll('[data-sth-menu]').forEach((root) => {
        if (button && root.contains(button)) {
            setMenu(root, button.getAttribute('aria-expanded') !== 'true');
        } else if (!root.contains(target)) {
            setMenu(root, false);
        }
    });
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    document.querySelectorAll('[data-sth-menu]').forEach((root) => {
        const button = root.querySelector('[data-sth-menu-button]');
        if (button?.getAttribute('aria-expanded') === 'true') {
            setMenu(root, false);
            button.focus();
        }
    });
});

/* ---------------------------------------------------------------- file preview popup */

/*
 | "Buka" used to download the certificate; now it shows it. A link carries
 |   href="<download url>" data-sth-preview="<inline url>" data-sth-preview-type="pdf|image"
 |   data-sth-preview-name="<file name>"
 | and the click fills the one dialog of the page (#sth-preview, <x-sth.preview> in layouts::app and
 | layouts::mobile): title = file name, body = the viewer, footer = "Tutup" and "Download" (the href). Without
 | JavaScript, with a modifier key, or on a page without the dialog, the link simply downloads as before.
 |
 | - image: an <img>. An image element never runs anything and never downloads.
 | - pdf: the browser's own PDF viewer in an <iframe> of this origin (the page CSP has `frame-src 'self'` and
 |   `object-src 'none'`, so no <object>/<embed>, no blob:, no other origin). No `sandbox` attribute: Chrome
 |   does not start its PDF viewer in a sandboxed frame. Before the frame gets its address a HEAD request asks
 |   the server what it would send; the frame is only used for `200`, `application/pdf`, `inline`. So the popup
 |   never starts a download by itself, and an expired session, a 404 or a 429 becomes one sentence instead of
 |   a broken frame (an <iframe> reports no HTTP errors).
 | - a browser without a PDF viewer (most phones), an unknown type, a foreign address or a failed load: one
 |   sentence in the dialog; "Download" is always there.
 | Closing (X, Tutup, Esc, click outside) removes the viewer element, which unloads the file. The viewer is
 | created per opening, with its address set before it enters the page, so it adds nothing to the Back history.
 | The texts live in the component (data-msg-*).
 */
const PREVIEW_ID = 'sth-preview';

// Changes whenever the dialog is filled or emptied, so a late answer for an earlier file is ignored.
let previewRun = 0;

function previewParts(dialog) {
    return {
        stage: dialog.querySelector('[data-sth-preview-stage]'),
        status: dialog.querySelector('[data-sth-preview-status]'),
        title: dialog.querySelector(`#${PREVIEW_ID}-title`),
        download: dialog.querySelector('[data-sth-preview-download]'),
    };
}

function resetPreview(dialog) {
    previewRun += 1;
    const { stage, status, title, download } = previewParts(dialog);

    stage?.querySelectorAll('.preview-frame, .preview-image').forEach((viewer) => viewer.remove());
    if (status) {
        status.textContent = '';
        status.hidden = false;
    }
    if (title?.dataset.default) title.textContent = title.dataset.default;
    download?.setAttribute('href', '#');
}

function previewNote(dialog, key) {
    const { stage, status } = previewParts(dialog);
    if (!stage || !status) return;
    status.textContent = stage.getAttribute(`data-msg-${key}`) || '';
    status.hidden = false;
}

function sameOrigin(url) {
    try {
        const parsed = new URL(url, window.location.href);

        return parsed.origin === window.location.origin && /^https?:$/.test(parsed.protocol);
    } catch {
        return false;
    }
}

function canShowPdf() {
    if (typeof navigator.pdfViewerEnabled === 'boolean') return navigator.pdfViewerEnabled;

    return Boolean(navigator.mimeTypes?.['application/pdf']);
}

async function servedInlineAsPdf(url) {
    try {
        const response = await fetch(url, { method: 'HEAD', credentials: 'same-origin', redirect: 'error', cache: 'no-store' });
        const type = (response.headers.get('Content-Type') || '').toLowerCase();
        const disposition = (response.headers.get('Content-Disposition') || '').toLowerCase();

        // A HEAD answer has no body; reading it lets the request finish instead of being cancelled later.
        await response.text();

        return response.ok && type.startsWith('application/pdf') && disposition.startsWith('inline');
    } catch {
        return false;
    }
}

/*
 | Unload the file the moment the dialog closes, however it was closed. This watches the `open` attribute
 | instead of the `close` event: the browser delivers that event with the next painted frame, so in a tab that
 | is not painting it arrives late, after the file should be gone, or even after the next file was opened.
 */
function unloadWhenClosed(dialog, run) {
    const observer = new MutationObserver(() => {
        if (dialog.open) return;
        observer.disconnect();
        if (run === previewRun) resetPreview(dialog);
    });
    observer.observe(dialog, { attributes: true, attributeFilter: ['open'] });
}

function mountPreview(dialog, viewer, url, run) {
    const { stage, status } = previewParts(dialog);
    if (!stage) return;

    viewer.addEventListener(
        'load',
        () => {
            if (run === previewRun && status) status.hidden = true;
        },
        { once: true },
    );
    viewer.src = url;
    stage.appendChild(viewer);
}

function openPreview(opener) {
    const dialog = document.getElementById(PREVIEW_ID);
    const url = opener.getAttribute('data-sth-preview');
    if (!(dialog instanceof HTMLDialogElement) || dialog.open || !url) return false;

    const { stage, title, download } = previewParts(dialog);
    if (!stage) return false;

    const type = (opener.getAttribute('data-sth-preview-type') || '').toLowerCase();
    const name = (opener.getAttribute('data-sth-preview-name') || '').trim();
    const label = `${stage.getAttribute('data-msg-frame') || 'Preview'} ${name}`.trim();

    resetPreview(dialog);
    const run = previewRun;

    if (title) {
        title.dataset.default ||= title.textContent;
        title.textContent = name || title.dataset.default;
    }
    download?.setAttribute('href', opener.getAttribute('href') || opener.getAttribute('data-sth-preview-download') || url);
    previewNote(dialog, 'loading');
    openDialog(PREVIEW_ID, opener);
    unloadWhenClosed(dialog, run);

    if (!sameOrigin(url) || (type !== 'pdf' && type !== 'image')) {
        previewNote(dialog, 'unsupported');
    } else if (type === 'image') {
        const image = document.createElement('img');
        image.className = 'preview-image';
        image.alt = label;
        image.addEventListener(
            'error',
            () => {
                if (run !== previewRun) return;
                image.remove();
                previewNote(dialog, 'error');
            },
            { once: true },
        );
        mountPreview(dialog, image, url, run);
    } else if (!canShowPdf()) {
        previewNote(dialog, 'nopdf');
    } else {
        servedInlineAsPdf(url).then((ok) => {
            if (run !== previewRun || !dialog.open) return;
            if (!ok) {
                previewNote(dialog, 'error');

                return;
            }
            const frame = document.createElement('iframe');
            frame.className = 'preview-frame';
            frame.title = label;
            mountPreview(dialog, frame, url, run);
        });
    }

    return true;
}

document.addEventListener('click', (event) => {
    // A modified click (new tab, save link) keeps the browser's own meaning: the plain download.
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

    const opener = event.target instanceof Element ? event.target.closest('[data-sth-preview]') : null;
    if (!opener || opener.getAttribute('aria-disabled') === 'true') return;

    if (openPreview(opener)) event.preventDefault();
});

/* ---------------------------------------------------------------- copy to clipboard */

/*
 | <button type="button" data-sth-copy="#target-id">Salin data</button> copies the innerText of #target-id and
 | shows "Data disalin.". The Clipboard API only exists on https and localhost and may refuse (permission, no
 | focus); then the text is selected instead, so Ctrl+C finishes the job, and the toast says so.
 */
function selectContents(node) {
    const selection = window.getSelection();
    if (!selection) return;
    const range = document.createRange();
    range.selectNodeContents(node);
    selection.removeAllRanges();
    selection.addRange(range);
}

document.addEventListener('click', async (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-sth-copy]') : null;
    if (!button) return;
    event.preventDefault();

    let target = null;
    try {
        target = document.querySelector(button.getAttribute('data-sth-copy'));
    } catch {
        target = null; // not a valid selector
    }
    if (!(target instanceof HTMLElement)) return;

    try {
        await navigator.clipboard.writeText(target.innerText);
        showToast('Data disalin.', 'success');
    } catch {
        selectContents(target);
        showToast('Tekan Ctrl+C untuk menyalin.', 'info');
    }
});

/* ---------------------------------------------------------------- cover posters */

/*
 | <x-sth.sampul> draws a lomba's own poster with two <img data-sth-sampul> (a blurred backdrop and the
 | poster itself) over the template drawing of its bidang. When the poster cannot be loaded (deleted file,
 | 404, 429, offline), both images go and the cover falls back to the drawing instead of a broken image.
 | `error` does not bubble, so it is caught in the capture phase.
 */
function dropPoster(image) {
    const cover = image.closest('.sampul');
    if (!cover) return;
    cover.querySelectorAll('img[data-sth-sampul]').forEach((img) => img.remove());
    cover.classList.remove('sampul-poster');
}

document.addEventListener(
    'error',
    (event) => {
        if (event.target instanceof HTMLImageElement && event.target.hasAttribute('data-sth-sampul')) {
            dropPoster(event.target);
        }
    },
    true,
);

// An image that already failed before this script ran (cached 404 on a fast page) fires no second event.
function dropBrokenPosters() {
    document.querySelectorAll('img[data-sth-sampul]').forEach((img) => {
        if (img.complete && img.naturalWidth === 0) dropPoster(img);
    });
}

/* ---------------------------------------------------------------- page load */

function boot() {
    dropBrokenPosters();
    document.querySelectorAll('[data-sth-toast]').forEach((toast) => {
        if (toast.dataset.armed) return;
        toast.dataset.armed = '1';
        armToast(toast);
        if (toast.hasAttribute('data-announce')) {
            const text = toast.querySelector('[data-sth-toast-text]')?.textContent?.trim();
            announce(text, toast.dataset.tone === 'error');
        }
    });
    document.querySelectorAll('dialog[data-sth-autoopen]').forEach((dialog) => {
        if (!dialog.dataset.autoopened) {
            dialog.dataset.autoopened = '1';
            openDialog(dialog.id);
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
document.addEventListener('livewire:navigated', boot);

window.sth = { announce, openDialog, showToast };

/* ---------------------------------------------------------------- B2: throttled Livewire requests */

// The Livewire update endpoint is rate limited (AppServiceProvider::configureLivewireEndpoint). A 429 shows a
// short toast with the server's Indonesian text instead of Livewire's full-screen error modal.
function watchThrottledRequests() {
    if (!window.Livewire?.interceptRequest || window.sth.throttleWatched) return;
    window.sth.throttleWatched = true;
    window.Livewire.interceptRequest(({ onError }) => {
        onError(({ response, body, preventDefault }) => {
            if (response?.status !== 429) return;
            preventDefault();
            const text = typeof body === 'string' && body.length < 200 ? body.trim() : '';
            showToast(text || 'Terlalu banyak permintaan. Tunggu sebentar lalu coba lagi.', 'error');
        });
    });
}

watchThrottledRequests();
document.addEventListener('livewire:init', watchThrottledRequests);

watchLiveSearch();
document.addEventListener('livewire:init', watchLiveSearch);
