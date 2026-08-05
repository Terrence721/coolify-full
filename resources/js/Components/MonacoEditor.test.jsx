import { render } from '@testing-library/react';
import { act } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import MonacoEditor from './MonacoEditor';

// The shared code editor wrapper (compose files, env vars), untested despite real imperative
// logic: the external-value-sync effect guards against an infinite loop (only calls setValue()
// when the incoming prop actually differs from the editor's own current value - without that
// guard, typing would trigger onChange -> parent state update -> this effect -> setValue() ->
// another onDidChangeModelContent fire), the onChange ref pattern avoiding stale closures, real
// teardown on unmount (dispose the editor, revoke the worker blob URL), and the readOnly prop
// threading into 4 separate Monaco config fields. window.monaco is pre-seeded truthy so
// loadMonacoLoader() short-circuits past the real AMD script-loading path entirely - window.require
// is still mocked since the component calls it unconditionally afterward regardless of which
// loader branch resolved. Editor creation happens inside a promise chain (loadMonacoLoader().then),
// so every test flushes it with an async act() before asserting.

let fakeEditor;
let changeListener;
let currentValue;
let createCalls;

function makeFakeEditor(initialValue) {
    currentValue = initialValue ?? '';
    changeListener = null;

    return {
        getValue: vi.fn(() => currentValue),
        setValue: vi.fn((v) => {
            currentValue = v;
        }),
        onDidChangeModelContent: vi.fn((cb) => {
            changeListener = cb;
        }),
        dispose: vi.fn(),
    };
}

beforeEach(() => {
    createCalls = [];
    fakeEditor = null;

    window.monaco = {
        editor: {
            create: vi.fn((_container, config) => {
                createCalls.push(config);
                fakeEditor = makeFakeEditor(config.value);

                return fakeEditor;
            }),
            setTheme: vi.fn(),
        },
    };
    window.require = vi.fn((_deps, callback) => callback());
    window.require.config = vi.fn();
    window.MonacoEnvironment = undefined;
    URL.createObjectURL = vi.fn(() => 'blob:fake-worker-url');
    URL.revokeObjectURL = vi.fn();

    document.documentElement.className = '';
});

afterEach(() => {
    delete window.monaco;
    delete window.require;
    vi.restoreAllMocks();
});

async function renderAndFlush(ui) {
    const result = render(ui);
    await act(async () => {});

    return result;
}

describe('MonacoEditor', () => {
    it('creates the editor once with the initial value and language', async () => {
        await renderAndFlush(<MonacoEditor value="name: test" language="yaml" onChange={() => {}} />);

        expect(window.monaco.editor.create).toHaveBeenCalledTimes(1);
        expect(createCalls[0]).toMatchObject({ value: 'name: test', language: 'yaml', readOnly: false });
    });

    it('threads readOnly into all 4 dependent config fields', async () => {
        await renderAndFlush(<MonacoEditor value="" onChange={() => {}} readOnly />);

        expect(createCalls[0]).toMatchObject({
            readOnly: true,
            domReadOnly: true,
            contextmenu: false,
            renderLineHighlight: 'none',
        });
    });

    it('uses vs-dark when the document is already in dark mode at mount', async () => {
        document.documentElement.classList.add('dark');
        await renderAndFlush(<MonacoEditor value="" onChange={() => {}} />);

        expect(createCalls[0]).toMatchObject({ theme: 'vs-dark' });
    });

    it('calls onChange with the editor value when the content changes', async () => {
        const onChange = vi.fn();
        await renderAndFlush(<MonacoEditor value="" onChange={onChange} />);

        act(() => {
            fakeEditor.getValue.mockReturnValue('new content');
            changeListener();
        });

        expect(onChange).toHaveBeenCalledWith('new content');
    });

    it('does not call setValue when the value prop change originated from the editor itself - the infinite-loop guard', async () => {
        // Real scenario: user types -> onDidChangeModelContent fires -> onChange(newValue) is
        // called -> the parent's state updates -> the parent passes that same value back down as
        // a prop. The effect's [value] dependency genuinely changed (so it does re-run - a prior
        // version of this test used an unchanged value and passed even with the guard removed,
        // since React's own dependency-array comparison skips the effect entirely in that case,
        // never actually exercising the internal getValue() !== value check), but the editor's
        // own content already equals it, so setValue must not be called again.
        const onChange = vi.fn();
        const { rerender } = await renderAndFlush(<MonacoEditor value="original" onChange={onChange} />);

        act(() => {
            fakeEditor.setValue('typed by user');
            changeListener();
        });
        expect(onChange).toHaveBeenCalledWith('typed by user');
        fakeEditor.setValue.mockClear();

        rerender(<MonacoEditor value="typed by user" onChange={onChange} />);

        expect(fakeEditor.setValue).not.toHaveBeenCalled();
    });

    it('calls setValue when the incoming value genuinely differs from the editor', async () => {
        const { rerender } = await renderAndFlush(<MonacoEditor value="original" onChange={() => {}} />);
        fakeEditor.setValue.mockClear();

        rerender(<MonacoEditor value="updated externally" onChange={() => {}} />);

        expect(fakeEditor.setValue).toHaveBeenCalledWith('updated externally');
    });

    it('disposes the editor and revokes the worker blob URL on unmount', async () => {
        const { unmount } = await renderAndFlush(<MonacoEditor value="" onChange={() => {}} />);

        unmount();

        expect(fakeEditor.dispose).toHaveBeenCalledTimes(1);
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:fake-worker-url');
    });
});
