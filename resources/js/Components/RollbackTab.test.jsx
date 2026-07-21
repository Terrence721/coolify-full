import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import RollbackTab from './RollbackTab';

// Regression coverage for the logic that survived the ESLint cleanup pass (issue #33): a dead
// `currentTag` state variable was removed there, with the note that the "current image" badge
// already worked via each image's own `isCurrent` flag - this suite locks that claim in place,
// plus the tag-classification regexes (commit SHA vs. PR vs. plain tag) that decide whether an
// image is rollback-eligible at all.

let flashImages = [];
const postSpy = vi.fn((url, data, options) => {
    if (options?.onSuccess) {
        options.onSuccess({ props: { flash: { rollbackImages: flashImages } } });
    }
});
const patchSpy = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url, data, options) => postSpy(url, data, options),
        patch: (url, data, options) => patchSpy(url, data, options),
    },
}));

function typeInto(element, value) {
    const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
    setter.call(element, value);
    element.dispatchEvent(new Event('input', { bubbles: true }));
}

function baseProps(overrides = {}) {
    return {
        rollback: {
            dockerImagesToKeep: 5,
            serverRetentionDisabled: false,
            canDeploy: true,
        },
        rollbackUrls: {
            loadImages: '/rollback/load-images',
            saveSettings: '/rollback/save-settings',
            deploy: '/rollback/deploy',
        },
        ...overrides,
    };
}

describe('RollbackTab', () => {
    beforeEach(() => {
        postSpy.mockClear();
        patchSpy.mockClear();
        flashImages = [];
    });

    it('shows a loading message until the initial image fetch resolves', () => {
        postSpy.mockImplementationOnce(() => {
            // Deliberately don't call onSuccess yet - simulates the request still in flight.
        });

        render(<RollbackTab {...baseProps()} />);

        expect(screen.getByText('Loading available docker images...')).toBeInTheDocument();
    });

    it('shows an empty-state message when no local images are found', () => {
        flashImages = [];
        render(<RollbackTab {...baseProps()} />);

        expect(screen.getByText('No images found locally.')).toBeInTheDocument();
    });

    it('classifies a commit-SHA tag correctly', () => {
        flashImages = [{ tag: 'abc1234', createdAt: '2026-01-01', isCurrent: false }];
        render(<RollbackTab {...baseProps()} />);

        expect(screen.getByText('SHA: abc1234')).toBeInTheDocument();
    });

    it('classifies a PR tag correctly', () => {
        flashImages = [{ tag: 'pr-42', createdAt: '2026-01-01', isCurrent: false }];
        render(<RollbackTab {...baseProps()} />);

        expect(screen.getByText('PR: pr-42')).toBeInTheDocument();
    });

    it('classifies a plain tag (neither commit SHA nor PR) as just a tag', () => {
        flashImages = [{ tag: 'latest', createdAt: '2026-01-01', isCurrent: false }];
        render(<RollbackTab {...baseProps()} />);

        expect(screen.getByText('Tag: latest')).toBeInTheDocument();
    });

    it('shows the LIVE badge and disables rollback for the currently running image', () => {
        flashImages = [{ tag: 'abc1234', createdAt: '2026-01-01', isCurrent: true }];
        render(<RollbackTab {...baseProps()} />);

        expect(screen.getByText(/LIVE/)).toBeInTheDocument();
        const button = screen.getByRole('button', { name: 'Rollback' });
        expect(button).toBeDisabled();
        expect(button).toHaveAttribute('title', 'This image is currently running.');
    });

    it('disables rollback for a non-rollbackable tag, with an explanatory title', () => {
        flashImages = [{ tag: 'latest', createdAt: '2026-01-01', isCurrent: false }];
        render(<RollbackTab {...baseProps()} />);

        const button = screen.getByRole('button', { name: 'Rollback' });
        expect(button).toBeDisabled();
        expect(button).toHaveAttribute(
            'title',
            "Rollback not available for 'latest' tag. Only commit-based tags support rollback. Re-deploy to create a rollback-enabled image.",
        );
    });

    it('enables rollback for a rollbackable, non-current image and deploys the selected tag on click', () => {
        flashImages = [{ tag: 'abc1234', createdAt: '2026-01-01', isCurrent: false }];
        render(<RollbackTab {...baseProps()} />);

        const button = screen.getByRole('button', { name: 'Rollback' });
        expect(button).not.toBeDisabled();

        act(() => button.click());

        expect(postSpy).toHaveBeenCalledWith('/rollback/deploy', { tag: 'abc1234' }, expect.objectContaining({ preserveScroll: true }));
    });

    it('hides the rollback button entirely when the user cannot deploy', () => {
        flashImages = [{ tag: 'abc1234', createdAt: '2026-01-01', isCurrent: false }];
        render(<RollbackTab {...baseProps({ rollback: { dockerImagesToKeep: 5, serverRetentionDisabled: false, canDeploy: false } })} />);

        expect(screen.queryByRole('button', { name: 'Rollback' })).not.toBeInTheDocument();
    });

    it('reloads images with a toast when "Reload Available Images" is clicked', () => {
        render(<RollbackTab {...baseProps()} />);
        postSpy.mockClear();

        act(() => screen.getByText('Reload Available Images').click());

        expect(postSpy).toHaveBeenCalledWith('/rollback/load-images', { showToast: true }, expect.objectContaining({ preserveScroll: true }));
    });

    it('shows the retention-disabled warning and disables the keep-images input/save button', () => {
        render(<RollbackTab {...baseProps({ rollback: { dockerImagesToKeep: 5, serverRetentionDisabled: true, canDeploy: true } })} />);

        expect(screen.getByText(/Image retention is disabled at the server level/)).toBeInTheDocument();
        expect(screen.getByLabelText('Images to keep for rollback')).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });

    it('saves the entered value when the settings form is submitted', () => {
        render(<RollbackTab {...baseProps()} />);

        const input = screen.getByLabelText('Images to keep for rollback');
        act(() => typeInto(input, '10'));
        act(() => screen.getByRole('button', { name: 'Save' }).click());

        expect(patchSpy).toHaveBeenCalledWith(
            '/rollback/save-settings',
            { dockerImagesToKeep: '10' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
