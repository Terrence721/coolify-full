// Vite entrypoint for the remaining Blade-only pages (guest/auth screens, error pages).
// Livewire's own JS runtime (and the wire:navigate FOUC workaround this file used to carry)
// was removed once the Livewire→React migration completed and app/Livewire/ emptied out.
//
// The three listeners below are plain-JS replacements for markup that used to be driven by
// Alpine.js (x-data/x-show/x-model/etc.), which was removed from package.json once the
// Livewire→React migration completed. Alpine's removal left these Blade components with dead,
// non-executing directives - found via a full-repo sweep after the same failure pattern broke
// toast notifications (see Toast.jsx's docblock and todo.md's "Correction" note).

// Password show/hide toggle (components/forms/input.blade.php)
document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-password-toggle]');
    if (!toggle) return;

    const field = toggle.closest('[data-password-field]');
    const input = field?.querySelector('[data-password-input]');
    if (!input) return;

    const showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    field.querySelector('[data-password-icon-shown]')?.classList.toggle('hidden', !showing);
    field.querySelector('[data-password-icon-hidden]')?.classList.toggle('hidden', showing);
});

// Info-tooltip popup, click-to-toggle (components/helper.blade.php)
document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-helper-trigger]');
    if (trigger) {
        event.stopPropagation();
        trigger.closest('[data-helper]')?.querySelector('[data-helper-popup]')?.classList.toggle('block');
        return;
    }

    document.querySelectorAll('[data-helper-popup].block').forEach((popup) => popup.classList.remove('block'));
});

// Two-factor challenge: digit inputs + authenticator/recovery-code toggle (auth/two-factor-challenge.blade.php)
(function twoFactorChallenge() {
    const root = document.querySelector('[data-two-factor]');
    if (!root) return;

    const form = root.querySelector('form[action="/two-factor-challenge"]');
    const codeInput = root.querySelector('[data-two-factor-code]');
    const digitInputs = Array.from(root.querySelectorAll('[data-two-factor-digit]'));

    function updateCode() {
        codeInput.value = digitInputs.map((input) => input.value).join('');
        if (digitInputs.every((input) => input.value !== '')) {
            form?.requestSubmit();
        }
    }

    digitInputs.forEach((input, index) => {
        input.addEventListener('input', () => {
            if (input.value && digitInputs[index + 1]) {
                digitInputs[index + 1].focus();
            }
            updateCode();
        });
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Backspace' && !input.value && digitInputs[index - 1]) {
                digitInputs[index - 1].focus();
            }
        });
    });

    root.querySelector('[data-two-factor-digits]')?.addEventListener('paste', (event) => {
        event.preventDefault();
        const pasted = (event.clipboardData || window.clipboardData).getData('text');
        const pasteDigits = pasted.replace(/\D/g, '').slice(0, digitInputs.length).split('');

        pasteDigits.forEach((digit, index) => {
            if (digitInputs[index]) digitInputs[index].value = digit;
        });
        updateCode();

        if (pasteDigits.length > 0) {
            digitInputs[Math.min(pasteDigits.length - 1, digitInputs.length - 1)].focus();
        }
    });

    root.querySelectorAll('[data-two-factor-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            root.querySelectorAll('[data-two-factor-panel="code"]').forEach((panel) => panel.classList.toggle('hidden'));
            root.querySelectorAll('[data-two-factor-panel="recovery"]').forEach((panel) => panel.classList.toggle('hidden'));
        });
    });
})();
