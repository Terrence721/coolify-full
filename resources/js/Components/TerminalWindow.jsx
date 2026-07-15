import { useEffect, useRef, useState } from 'react';
import { TerminalSession } from '../terminalSession';
import {
    TERMINAL_SESSION_DANGER_SECONDS,
    TERMINAL_SESSION_WARNING_SECONDS,
    formatTerminalSessionRemainingTime,
} from '../terminal-session-timer';

function terminalSessionRemainingLabel(remainingSeconds) {
    if (remainingSeconds === null) {
        return '';
    }

    return `Session expires in ${formatTerminalSessionRemainingTime(remainingSeconds)}`;
}

function terminalSessionTimerClass(remainingSeconds) {
    if (remainingSeconds === null) {
        return 'text-neutral-300 bg-black/70 border-white/10';
    }
    if (remainingSeconds <= TERMINAL_SESSION_DANGER_SECONDS) {
        return 'text-red-200 bg-red-950/80 border-red-500/40';
    }
    if (remainingSeconds <= TERMINAL_SESSION_WARNING_SECONDS) {
        return 'text-yellow-200 bg-yellow-950/80 border-yellow-500/40';
    }

    return 'text-neutral-300 bg-black/70 border-white/10';
}

/**
 * React port of resources/views/livewire/project/shared/terminal.blade.php, driven by
 * terminalSession.js (the framework-agnostic port of terminal.js's Alpine component) instead
 * of Livewire's $wire event bus. Shared by Terminal/Index.jsx (the standalone /terminal page),
 * Project/Shared/Command.jsx, and Server/Command.jsx (the resource-scoped terminal pages).
 *
 * Known simplification: the original's `wire:poll.keep-alive.30s="keepTerminalPageAlive"` kept
 * the Livewire component's server-side connection alive during a long SSH session. Inertia pages
 * have no equivalent persistent server-side component, so there is nothing to poll here.
 */
export default function TerminalWindow({ terminalConfig, pendingCommand, noShell }) {
    const wrapperRef = useRef(null);
    const terminalElRef = useRef(null);
    const sessionRef = useRef(null);
    const [state, setState] = useState({
        fullscreen: false,
        terminalActive: false,
        mobileToolbarCollapsed: false,
        terminalSessionRemainingSeconds: null,
    });

    useEffect(() => {
        const session = new TerminalSession({
            terminalConfig,
            onError: (message) => {
                if (typeof window.toast === 'function') {
                    window.toast('Error', { type: 'danger', description: message });
                }
            },
            onStateChange: (nextState) => setState(nextState),
        });
        sessionRef.current = session;
        session.mount(wrapperRef.current, terminalElRef.current);

        return () => {
            session.unmount();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (pendingCommand && sessionRef.current) {
            sessionRef.current.sendCommandWhenReady(pendingCommand.command);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pendingCommand?.key]);

    return (
        <div id="terminal-container">
            {noShell && (
                <div className="flex pt-4 items-center justify-center w-full py-4 mx-auto">
                    <div className="p-4 w-full rounded-sm border dark:bg-coolgray-100 dark:border-coolgray-300">
                        <div className="flex flex-col items-center justify-center space-y-4">
                            <svg className="w-12 h-12 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2"
                                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <div className="text-center">
                                <h3 className="text-lg font-medium">Terminal Not Available</h3>
                                <p className="mt-2 text-sm text-neutral-300">
                                    No shell (bash/sh) is available in this container. Please ensure either bash or sh is installed to use the terminal.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            )}
            <div
                ref={wrapperRef}
                className={
                    state.fullscreen
                        ? 'fixed inset-0 z-9999 m-0 h-dvh w-screen max-w-none overflow-hidden rounded-none bg-black! p-0'
                        : 'relative w-full h-full py-4 mx-auto max-h-127.5'
                }
            >
                {state.terminalActive && (
                    <div className="mb-2 flex justify-start">
                        <div
                            className={`inline-flex rounded-sm border px-2 py-1 text-xs font-medium ${terminalSessionTimerClass(state.terminalSessionRemainingSeconds)}`}
                        >
                            {terminalSessionRemainingLabel(state.terminalSessionRemainingSeconds)}
                        </div>
                    </div>
                )}
                <div
                    id="terminal"
                    ref={terminalElRef}
                    className={
                        (state.fullscreen
                            ? (state.mobileToolbarCollapsed
                                ? 'h-[calc(100dvh-3.5rem)] mb-14 px-2 py-1 bg-black'
                                : 'h-[calc(100dvh-6rem)] mb-24 px-2 py-1 bg-black')
                            : 'h-127.5 max-h-[calc(100dvh-10rem)] overflow-hidden px-2 py-1 rounded-sm bg-black') +
                        (state.terminalActive ? '' : ' hidden')
                    }
                />
                {state.terminalActive && (
                    <div
                        className={`sm:hidden ${state.fullscreen ? 'absolute inset-x-0 bottom-0 z-9999 px-2 pb-2' : 'relative mt-2'}`}
                        data-terminal-mobile-toolbar
                    >
                        <div className="mx-auto max-w-3xl rounded-lg border border-white/10 bg-black/90 p-1.5 text-white shadow-lg backdrop-blur">
                            <div className="flex items-center justify-between gap-2">
                                <span className="px-2 text-[11px] font-medium uppercase tracking-wide text-neutral-400">Terminal keys</span>
                                <button
                                    type="button"
                                    className="rounded px-2 py-1 text-xs text-neutral-300 hover:bg-white/10 hover:text-white"
                                    onClick={() => sessionRef.current?.toggleMobileToolbar()}
                                    aria-label="Toggle mobile terminal toolbar"
                                >
                                    {state.mobileToolbarCollapsed ? 'Show' : 'Hide'}
                                </button>
                            </div>
                            {!state.mobileToolbarCollapsed && (
                                <div className="mt-1 grid grid-cols-6 gap-1">
                                    <button type="button" className="terminal-mobile-key" onClick={() => sessionRef.current?.sendTerminalControl('arrowUp')} aria-label="Previous command">↑</button>
                                    <button type="button" className="terminal-mobile-key" onClick={() => sessionRef.current?.sendTerminalControl('arrowDown')} aria-label="Next command">↓</button>
                                    <button type="button" className="terminal-mobile-key" onClick={() => sessionRef.current?.sendTerminalControl('arrowLeft')} aria-label="Move cursor left">←</button>
                                    <button type="button" className="terminal-mobile-key" onClick={() => sessionRef.current?.sendTerminalControl('arrowRight')} aria-label="Move cursor right">→</button>
                                    <button type="button" className="terminal-mobile-key" onClick={() => sessionRef.current?.sendTerminalControl('tab')}>Tab</button>
                                    <button type="button" className="terminal-mobile-key" onClick={() => sessionRef.current?.sendTerminalControl('escape')}>Esc</button>
                                </div>
                            )}
                        </div>
                    </div>
                )}
                {state.fullscreen && (
                    <button title="Minimize" className="fixed bg-black/40 top-4 right-6 text-white" onClick={() => sessionRef.current?.makeFullscreen()}>
                        <svg className="w-5 h-5 text-gray-500 hover:text-white bg-black/80" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 14h4m0 0v4m0-4l-6 6m14-10h-4m0 0V6m0 4l6-6" />
                        </svg>
                    </button>
                )}
                {!state.fullscreen && state.terminalActive && (
                    <button title="Fullscreen" className="absolute right-5 top-6 text-white" onClick={() => sessionRef.current?.makeFullscreen()}>
                        <svg className="w-5 h-5 text-gray-500 hover:text-white bg-black/80" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <g fill="none">
                                <path d="M24 0v24H0V0h24ZM12.593 23.258l-.011.002l-.071.035l-.02.004l-.014-.004l-.071-.035c-.01-.004-.019-.001-.024.005l-.004.01l-.017.428l.005.02l.01.013l.104.074l.015.004l.012-.004l.104-.074l.012-.016l.004-.017l-.017-.427c-.002-.01-.009-.017-.017-.018Zm.265-.113l-.013.002l-.185.093l-.01.01l-.003.011l.018.43l.005.012l.008.007l.201.093c.012.004.023 0 .029-.008l.004-.014l-.034-.614c-.003-.012-.01-.02-.02-.022Zm-.715.002a.023.023 0 0 0-.027.006l-.006.014l-.034.614c0 .012.007.02.017.024l.015-.002l.201-.093l.01-.008l.004-.011l.017-.43l-.003-.012l-.01-.01l-.184-.092Z" />
                                <path fill="currentColor" d="M9.793 12.793a1 1 0 0 1 1.497 1.32l-.083.094L6.414 19H9a1 1 0 0 1 .117 1.993L9 21H4a1 1 0 0 1-.993-.883L3 20v-5a1 1 0 0 1 1.993-.117L5 15v2.586l4.793-4.793ZM20 3a1 1 0 0 1 .993.883L21 4v5a1 1 0 0 1-1.993.117L19 9V6.414l-4.793 4.793a1 1 0 0 1-1.497-1.32l.083-.094L17.586 5H15a1 1 0 0 1-.117-1.993L15 3h5Z" />
                            </g>
                        </svg>
                    </button>
                )}
            </div>
        </div>
    );
}
