import { render, screen } from '@testing-library/react';
import { act, useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import GithubAppCreateModal from './GithubAppCreateModal';

// Covers the default-name prefill, the isCloud-conditional System Wide checkbox and its
// "Not Recommended" warning callout, the Self-hosted/Enterprise accordion toggle (collapsed by
// default, its own default field values once expanded), the open/close (X button + backdrop
// click) behavior, and the submit-to-storeUrl call - none of it previously tested. Manually
// verified live during the 2026-07-23 Sources smoke test; this locks that behavior in.

const postSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    useForm: (initial) => {
        const [data, setDataState] = useState(initial);
        return {
            data,
            setData: (key, value) => setDataState((prev) => ({ ...prev, [key]: value })),
            post: (url, options) => postSpy(url, options),
            processing: false,
            errors: {},
        };
    },
}));

function baseProps(overrides = {}) {
    return {
        open: true,
        onClose: vi.fn(),
        storeUrl: '/source/github',
        defaultName: 'encouraging-emu-z11htex4keu2y8',
        isCloud: false,
        ...overrides,
    };
}

beforeEach(() => {
    postSpy.mockClear();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('GithubAppCreateModal', () => {
    it('renders nothing when closed', () => {
        const { container } = render(<GithubAppCreateModal {...baseProps({ open: false })} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('pre-fills the Name field with defaultName', () => {
        render(<GithubAppCreateModal {...baseProps()} />);
        expect(screen.getByLabelText('Name')).toHaveValue('encouraging-emu-z11htex4keu2y8');
    });

    it('shows the System Wide checkbox and its warning outside cloud mode', () => {
        render(<GithubAppCreateModal {...baseProps({ isCloud: false })} />);

        expect(screen.getByLabelText('System Wide')).toBeInTheDocument();
        expect(screen.queryByText('Not Recommended')).not.toBeInTheDocument();

        act(() => screen.getByLabelText('System Wide').click());
        expect(screen.getByText('Not Recommended')).toBeInTheDocument();

        act(() => screen.getByLabelText('System Wide').click());
        expect(screen.queryByText('Not Recommended')).not.toBeInTheDocument();
    });

    it('hides the System Wide checkbox entirely in cloud mode', () => {
        render(<GithubAppCreateModal {...baseProps({ isCloud: true })} />);
        expect(screen.queryByLabelText('System Wide')).not.toBeInTheDocument();
    });

    it('keeps the Self-hosted/Enterprise fields collapsed by default, with the documented defaults once expanded', () => {
        render(<GithubAppCreateModal {...baseProps()} />);

        expect(screen.queryByLabelText('HTML Url')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('API Url')).not.toBeInTheDocument();

        act(() => screen.getByRole('button', { name: /Self-hosted \/ Enterprise GitHub/ }).click());

        expect(screen.getByLabelText('HTML Url')).toHaveValue('https://github.com');
        expect(screen.getByLabelText('API Url')).toHaveValue('https://api.github.com');
        expect(screen.getByLabelText('Custom Git User')).toHaveValue('git');
        expect(screen.getByLabelText('Custom Git Port')).toHaveValue(22);
    });

    it('calls onClose when the X button or the backdrop is clicked', () => {
        const onCloseX = vi.fn();
        const { unmount } = render(<GithubAppCreateModal {...baseProps({ onClose: onCloseX })} />);
        act(() => screen.getByRole('button', { name: '✕' }).click());
        expect(onCloseX).toHaveBeenCalledTimes(1);
        unmount();

        const onCloseBackdrop = vi.fn();
        const { container } = render(<GithubAppCreateModal {...baseProps({ onClose: onCloseBackdrop })} />);
        act(() => container.querySelector('.backdrop-blur-xs').click());
        expect(onCloseBackdrop).toHaveBeenCalledTimes(1);
    });

    it('submits via post to storeUrl', () => {
        render(<GithubAppCreateModal {...baseProps()} />);

        act(() => screen.getByRole('button', { name: 'Continue' }).click());
        expect(postSpy).toHaveBeenCalledWith('/source/github', undefined);
    });
});
