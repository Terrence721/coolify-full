import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useTeamChannel } from './useTeamChannel';

// Regression coverage for the real, silent bug fixed in Phase 78 (see todo.md's Livewire →
// React/Inertia migration section, Phase 78 entry): most broadcast events don't override
// broadcastAs(), so their wire event name is the fully-qualified class name and this hook
// prefixes it with `.App\Events\`. A handful of events (ServerValidated is the one that
// actually broke) DO override broadcastAs() to a bare name - for those, the caller passes a
// leading-dot name and the hook must use it exactly as given, not re-prefix it. Getting this
// wrong means the listener silently never fires, since the wire event name never matches -
// exactly what happened before the fix.

const channelMock = { listen: vi.fn(), stopListening: vi.fn() };
const echoMock = { private: vi.fn(() => channelMock), leave: vi.fn() };
let pageProps = {};

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: pageProps }),
}));

vi.mock('../echo', () => ({
    getEcho: () => echoMock,
}));

describe('useTeamChannel', () => {
    beforeEach(() => {
        channelMock.listen.mockClear();
        channelMock.stopListening.mockClear();
        echoMock.private.mockClear();
        echoMock.leave.mockClear();
        pageProps = { currentTeam: { id: 42 }, echo: { host: 'localhost', key: 'coolify', port: 6001 } };
    });

    it('prefixes a bare event name with .App\\Events\\', () => {
        renderHook(() => useTeamChannel(['ProxyStatusChangedUI'], vi.fn()));

        expect(channelMock.listen).toHaveBeenCalledWith('.App\\Events\\ProxyStatusChangedUI', expect.any(Function));
    });

    it('uses a leading-dot event name exactly as given, without re-prefixing it (the ServerValidated bug)', () => {
        renderHook(() => useTeamChannel(['.ServerValidated'], vi.fn()));

        expect(channelMock.listen).toHaveBeenCalledWith('.ServerValidated', expect.any(Function));
        expect(channelMock.listen).not.toHaveBeenCalledWith('.App\\Events\\.ServerValidated', expect.any(Function));
    });

    it("subscribes to the current team's private channel", () => {
        renderHook(() => useTeamChannel(['ProxyStatusChangedUI'], vi.fn()));

        expect(echoMock.private).toHaveBeenCalledWith('team.42');
    });

    it('does not subscribe when there is no current team', () => {
        pageProps = { currentTeam: null, echo: { host: 'localhost', key: 'coolify', port: 6001 } };

        renderHook(() => useTeamChannel(['ProxyStatusChangedUI'], vi.fn()));

        expect(echoMock.private).not.toHaveBeenCalled();
    });

    it('invokes the onEvent callback with the event name and payload when the listener fires', () => {
        const onEvent = vi.fn();
        renderHook(() => useTeamChannel(['ProxyStatusChangedUI'], onEvent));

        const handler = channelMock.listen.mock.calls[0][1];
        handler({ status: 'running' });

        expect(onEvent).toHaveBeenCalledWith('ProxyStatusChangedUI', { status: 'running' });
    });

    it('stops listening and leaves the channel on unmount', () => {
        const { unmount } = renderHook(() => useTeamChannel(['ProxyStatusChangedUI', '.ServerValidated'], vi.fn()));

        unmount();

        expect(channelMock.stopListening).toHaveBeenCalledWith('.App\\Events\\ProxyStatusChangedUI');
        expect(channelMock.stopListening).toHaveBeenCalledWith('.ServerValidated');
        expect(echoMock.leave).toHaveBeenCalledWith('team.42');
    });
});
