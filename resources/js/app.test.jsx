import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

// Regression coverage for the "second wave of Alpine remnants" fix (see todo.md's Cleanup
// opportunities / Scrum issue #31): password show/hide toggle, the info-tooltip popup, and the
// two-factor challenge screen all had live Alpine directive markup (x-data/x-on/x-model) with
// zero runtime to execute it once Alpine was removed from package.json - same failure family as
// the toast-notification bug. app.js is the plain-JS replacement; these tests lock its DOM
// behavior in place. Verified only via a one-off manual browser session before this suite existed.

// jsdom doesn't implement HTMLFormElement.requestSubmit() - stub it so the "all digits filled"
// path is testable without attempting a real (unsupported) form submission.
HTMLFormElement.prototype.requestSubmit = vi.fn();

beforeAll(async () => {
    document.body.innerHTML = `
        <div data-password-field>
            <input data-password-input type="password" />
            <button type="button" data-password-toggle>
                <span data-password-icon-shown>show</span>
                <span data-password-icon-hidden class="hidden">hide</span>
            </button>
        </div>

        <div data-helper>
            <button type="button" data-helper-trigger>?</button>
            <div data-helper-popup>tooltip content</div>
        </div>

        <div data-two-factor>
            <form action="/two-factor-challenge">
                <input type="hidden" data-two-factor-code />
                <div data-two-factor-digits>
                    <input data-two-factor-digit />
                    <input data-two-factor-digit />
                    <input data-two-factor-digit />
                </div>
                <div data-two-factor-panel="code">code panel</div>
                <div data-two-factor-panel="recovery" class="hidden">recovery panel</div>
                <button type="button" data-two-factor-toggle>Use a recovery code instead</button>
            </form>
        </div>
    `;

    // Side-effect import: app.js wires its listeners up against whatever DOM exists at import
    // time (the two-factor IIFE bails out early via document.querySelector if its root isn't
    // present yet), so this must run after the fixture above is in place.
    await import('./app.js');
});

beforeEach(() => {
    HTMLFormElement.prototype.requestSubmit.mockClear();

    const input = document.querySelector('[data-password-input]');
    input.type = 'password';
    document.querySelector('[data-password-icon-shown]').classList.remove('hidden');
    document.querySelector('[data-password-icon-hidden]').classList.add('hidden');

    document.querySelector('[data-helper-popup]').classList.remove('block');

    document.querySelectorAll('[data-two-factor-digit]').forEach((el) => (el.value = ''));
    document.querySelector('[data-two-factor-code]').value = '';
    document.querySelector('[data-two-factor-panel="code"]').classList.remove('hidden');
    document.querySelector('[data-two-factor-panel="recovery"]').classList.add('hidden');
});

describe('password show/hide toggle', () => {
    it('flips the input type from password to text on click, and swaps the icons', () => {
        document.querySelector('[data-password-toggle]').click();

        expect(document.querySelector('[data-password-input]').type).toBe('text');
        expect(document.querySelector('[data-password-icon-shown]')).toHaveClass('hidden');
        expect(document.querySelector('[data-password-icon-hidden]')).not.toHaveClass('hidden');
    });

    it('flips back to password on a second click', () => {
        const toggle = document.querySelector('[data-password-toggle]');
        toggle.click();
        toggle.click();

        expect(document.querySelector('[data-password-input]').type).toBe('password');
        expect(document.querySelector('[data-password-icon-shown]')).not.toHaveClass('hidden');
        expect(document.querySelector('[data-password-icon-hidden]')).toHaveClass('hidden');
    });
});

describe('info-tooltip popup', () => {
    it('shows the popup when the trigger is clicked', () => {
        document.querySelector('[data-helper-trigger]').click();

        expect(document.querySelector('[data-helper-popup]')).toHaveClass('block');
    });

    it('closes an open popup when clicking outside it', () => {
        document.querySelector('[data-helper-trigger]').click();
        expect(document.querySelector('[data-helper-popup]')).toHaveClass('block');

        document.body.click();

        expect(document.querySelector('[data-helper-popup]')).not.toHaveClass('block');
    });
});

describe('two-factor challenge', () => {
    it('auto-advances focus to the next digit input as each digit is typed', () => {
        const digits = document.querySelectorAll('[data-two-factor-digit]');
        digits[0].value = '1';
        digits[0].dispatchEvent(new Event('input'));

        expect(document.activeElement).toBe(digits[1]);
    });

    it('moves focus back on backspace from an empty input', () => {
        const digits = document.querySelectorAll('[data-two-factor-digit]');
        digits[1].focus();
        digits[1].dispatchEvent(new KeyboardEvent('keydown', { key: 'Backspace' }));

        expect(document.activeElement).toBe(digits[0]);
    });

    it('assembles the hidden code field and submits once every digit is filled', () => {
        const digits = document.querySelectorAll('[data-two-factor-digit]');
        digits.forEach((input, i) => {
            input.value = String(i + 1);
            input.dispatchEvent(new Event('input'));
        });

        expect(document.querySelector('[data-two-factor-code]').value).toBe('123');
        expect(HTMLFormElement.prototype.requestSubmit).toHaveBeenCalledTimes(1);
    });

    it('does not submit while any digit is still empty', () => {
        const digits = document.querySelectorAll('[data-two-factor-digit]');
        digits[0].value = '1';
        digits[0].dispatchEvent(new Event('input'));

        expect(HTMLFormElement.prototype.requestSubmit).not.toHaveBeenCalled();
    });

    it('distributes a pasted code across the digit inputs and focuses the last filled one', () => {
        const digitsWrapper = document.querySelector('[data-two-factor-digits]');
        const pasteEvent = new Event('paste', { cancelable: true });
        pasteEvent.clipboardData = { getData: () => '456' };
        digitsWrapper.dispatchEvent(pasteEvent);

        const digits = document.querySelectorAll('[data-two-factor-digit]');
        expect(digits[0].value).toBe('4');
        expect(digits[1].value).toBe('5');
        expect(digits[2].value).toBe('6');
        expect(document.activeElement).toBe(digits[2]);
        expect(document.querySelector('[data-two-factor-code]').value).toBe('456');
    });

    it('toggles between the code panel and the recovery-code panel', () => {
        document.querySelector('[data-two-factor-toggle]').click();

        expect(document.querySelector('[data-two-factor-panel="code"]')).toHaveClass('hidden');
        expect(document.querySelector('[data-two-factor-panel="recovery"]')).not.toHaveClass('hidden');
    });
});
