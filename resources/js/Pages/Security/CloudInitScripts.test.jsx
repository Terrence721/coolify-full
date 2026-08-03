import { fireEvent, render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CloudInitScripts from './CloudInitScripts';

// Full CRUD (create/edit/delete) with a single shared modal, unlike its Cloud Tokens sibling
// (create + delete only). Real risk: the same `data`/reset() state backs both "New" and "Edit" -
// openEditModal must actually populate the form from the clicked script, not just open the modal
// with stale/empty data from a previous open, and submit must branch to post (create) vs put
// (edit) based on modalScript.id, not just always take one path. Previously entirely untested.

const postSpy = vi.fn();
const putSpy = vi.fn();
const deleteSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: { delete: (url) => deleteSpy(url) },
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (keyOrObject, value) => {
                if (typeof keyOrObject === 'object') {
                    setDataState((prev) => ({ ...prev, ...keyOrObject }));
                } else {
                    setDataState((prev) => ({ ...prev, [keyOrObject]: value }));
                }
            },
            post: (url, options) => {
                postSpy(url);
                options?.onSuccess?.();
            },
            put: (url, options) => {
                putSpy(url);
                options?.onSuccess?.();
            },
            processing: false,
            errors: {},
            reset: (...keys) => {
                setDataState((prev) => {
                    const next = { ...prev };
                    for (const key of keys) {
                        next[key] = initial[key];
                    }
                    return next;
                });
            },
        };
    },
}));

function baseScript(overrides = {}) {
    return {
        id: 1,
        name: 'Install Docker',
        script: '#!/bin/bash\napt-get update',
        createdAgo: '2 days ago',
        canUpdate: true,
        canDelete: true,
        updateUrl: '/security/cloud-init-scripts/1',
        destroyUrl: '/security/cloud-init-scripts/1',
        ...overrides,
    };
}

function baseProps(overrides = {}) {
    return {
        scripts: [],
        canCreate: true,
        storeUrl: '/security/cloud-init-scripts',
        ...overrides,
    };
}

describe('Security/CloudInitScripts', () => {
    beforeEach(() => {
        postSpy.mockClear();
        putSpy.mockClear();
        deleteSpy.mockClear();
        vi.spyOn(window, 'prompt');
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows the empty state when there are no scripts', () => {
        render(<CloudInitScripts {...baseProps({ scripts: [] })} />);
        expect(screen.getByText('No cloud-init scripts found. Create one to get started.')).toBeInTheDocument();
    });

    it('hides the Add button when canCreate is false', () => {
        render(<CloudInitScripts {...baseProps({ canCreate: false })} />);
        expect(screen.queryByRole('button', { name: '+ Add' })).not.toBeInTheDocument();
    });

    it('hides Edit/Delete on a per-script basis via canUpdate/canDelete', () => {
        render(<CloudInitScripts {...baseProps({ scripts: [baseScript({ id: 1, name: 'A', canUpdate: false, canDelete: true })] })} />);
        expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Delete' })).toBeInTheDocument();
    });

    it('opens the create modal with a blank form, independent of any existing script data', () => {
        render(<CloudInitScripts {...baseProps({ scripts: [baseScript()] })} />);

        act(() => screen.getByRole('button', { name: '+ Add' }).click());

        expect(screen.getByText('New Cloud-Init Script')).toBeInTheDocument();
        expect(screen.getByLabelText('Name')).toHaveValue('');
        expect(screen.getByLabelText('Script')).toHaveValue('');
    });

    it('opens the edit modal pre-populated with the clicked script, not a stale/previous one', () => {
        render(
            <CloudInitScripts
                {...baseProps({
                    scripts: [
                        baseScript({ id: 1, name: 'First Script', script: 'echo first' }),
                        baseScript({ id: 2, name: 'Second Script', script: 'echo second' }),
                    ],
                })}
            />,
        );

        const editButtons = screen.getAllByRole('button', { name: 'Edit' });
        act(() => editButtons[1].click());

        expect(screen.getByText('Edit Cloud-Init Script')).toBeInTheDocument();
        expect(screen.getByLabelText('Name')).toHaveValue('Second Script');
        expect(screen.getByLabelText('Script')).toHaveValue('echo second');
    });

    it('submits a new script via post(storeUrl), not put', () => {
        render(<CloudInitScripts {...baseProps({ storeUrl: '/security/cloud-init-scripts' })} />);

        act(() => screen.getByRole('button', { name: '+ Add' }).click());
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'New Script' } });
        fireEvent.change(screen.getByLabelText('Script'), { target: { value: 'echo hi' } });
        act(() => fireEvent.submit(screen.getByLabelText('Name').closest('form')));

        expect(postSpy).toHaveBeenCalledWith('/security/cloud-init-scripts');
        expect(putSpy).not.toHaveBeenCalled();
    });

    it('submits an edited script via put(script.updateUrl), not post', () => {
        render(<CloudInitScripts {...baseProps({ scripts: [baseScript({ id: 7, updateUrl: '/security/cloud-init-scripts/7' })] })} />);

        act(() => screen.getByRole('button', { name: 'Edit' }).click());
        act(() => fireEvent.submit(screen.getByLabelText('Name').closest('form')));

        expect(putSpy).toHaveBeenCalledWith('/security/cloud-init-scripts/7');
        expect(postSpy).not.toHaveBeenCalled();
    });

    it('closes the modal and resets the form on successful submit', () => {
        render(<CloudInitScripts {...baseProps()} />);

        act(() => screen.getByRole('button', { name: '+ Add' }).click());
        fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'New Script' } });
        fireEvent.change(screen.getByLabelText('Script'), { target: { value: 'echo hi' } });
        act(() => fireEvent.submit(screen.getByLabelText('Name').closest('form')));

        expect(screen.queryByText('New Cloud-Init Script')).not.toBeInTheDocument();
    });

    it('does not delete if the typed name does not match', () => {
        window.prompt.mockReturnValueOnce('Wrong Name');
        render(<CloudInitScripts {...baseProps({ scripts: [baseScript({ name: 'Install Docker' })] })} />);

        act(() => screen.getByRole('button', { name: 'Delete' }).click());

        expect(deleteSpy).not.toHaveBeenCalled();
    });

    it('deletes via router.delete(script.destroyUrl) once the typed name matches', () => {
        window.prompt.mockReturnValueOnce('Install Docker');
        render(
            <CloudInitScripts {...baseProps({ scripts: [baseScript({ name: 'Install Docker', destroyUrl: '/security/cloud-init-scripts/1' })] })} />,
        );

        act(() => screen.getByRole('button', { name: 'Delete' }).click());

        expect(deleteSpy).toHaveBeenCalledWith('/security/cloud-init-scripts/1');
    });

    it('reopening the create modal after closing an edit does not leak the previous script into the form', () => {
        render(<CloudInitScripts {...baseProps({ scripts: [baseScript({ name: 'Existing Script', script: 'echo existing' })] })} />);

        act(() => screen.getByRole('button', { name: 'Edit' }).click());
        act(() => screen.getByRole('button', { name: '✕' }).click());
        act(() => screen.getByRole('button', { name: '+ Add' }).click());

        expect(screen.getByLabelText('Name')).toHaveValue('');
        expect(screen.getByLabelText('Script')).toHaveValue('');
    });
});
