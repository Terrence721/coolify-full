import { Terminal } from '@xterm/xterm';
import '@xterm/xterm/css/xterm.css';
import { FitAddon } from '@xterm/addon-fit';
import { MAX_TERMINAL_SESSION_SECONDS } from './terminal-session-timer.js';

const terminalDebugEnabled = import.meta.env.DEV;

function logTerminal(level, message, ...context) {
    if (!terminalDebugEnabled) {
        return;
    }

    console[level](message, ...context);
}

/**
 * Framework-agnostic port of terminal.js's Alpine `terminalData()` component.
 * Livewire pages (ExecuteContainerCommand's nested Project\Shared\Terminal) keep using
 * terminal.js/Alpine unchanged; this class is the Inertia/React side's equivalent, driven by
 * plain callbacks (onError/onConnected/onDisconnected/onStateChange) instead of $wire/$watch.
 * The WebSocket protocol, reconnect backoff, heartbeat, and flow-control logic are unchanged.
 */
export class TerminalSession {
    constructor({ terminalConfig, onError, onTerminalConnected, onTerminalDisconnected, onStateChange } = {}) {
        this.terminalConfig = terminalConfig || {};
        this.onError = onError || (() => {});
        this.onTerminalConnected = onTerminalConnected || (() => {});
        this.onTerminalDisconnected = onTerminalDisconnected || (() => {});
        this.onStateChange = onStateChange || (() => {});

        this.fullscreen = false;
        this.terminalActive = false;
        this.message = '(connection closed)';
        this.term = null;
        this.fitAddon = null;
        this.socket = null;
        this.commandBuffer = '';
        this.pendingWrites = 0;
        this.paused = false;
        this.MAX_PENDING_WRITES = 5;
        this.keepAliveInterval = null;
        this.reconnectInterval = null;
        this.connectionState = 'disconnected';
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 10;
        this.baseReconnectDelay = 1000;
        this.maxReconnectDelay = 30000;
        this.connectionTimeout = 10000;
        this.connectionTimeoutId = null;
        this.lastPingTime = null;
        this.pingTimeout = 35000;
        this.pingTimeoutId = null;
        this.heartbeatMissed = 0;
        this.maxHeartbeatMisses = 3;
        this.pendingCommand = null;
        this.lastSentCommand = null;
        this.resizeObserver = null;
        this.resizeTimeout = null;
        this.isDocumentVisible = true;
        this.wasConnectedBeforeHidden = false;
        this.mobileToolbarCollapsed = false;
        this.terminalSessionStartedAt = null;
        this.terminalSessionRemainingSeconds = null;
        this.terminalSessionCountdownInterval = null;

        this.wrapperEl = null;
        this.terminalEl = null;
        this._onWindowResize = null;
        this._visibilityHandler = null;
    }

    emitState() {
        this.onStateChange({
            fullscreen: this.fullscreen,
            terminalActive: this.terminalActive,
            message: this.message,
            connectionState: this.connectionState,
            mobileToolbarCollapsed: this.mobileToolbarCollapsed,
            terminalSessionRemainingSeconds: this.terminalSessionRemainingSeconds,
        });
    }

    mount(wrapperEl, terminalEl) {
        this.wrapperEl = wrapperEl;
        this.terminalEl = terminalEl;

        this.setupTerminal();
        setTimeout(() => {
            this.initializeWebSocket();
        }, 100);
        this.setupTerminalEventListeners();

        this._onWindowResize = () => this.resizeTerminal();
        window.addEventListener('resize', this._onWindowResize);

        this._visibilityHandler = () => this.handleVisibilityChange();
        document.addEventListener('visibilitychange', this._visibilityHandler);

        if (window.ResizeObserver) {
            this.resizeObserver = new ResizeObserver(() => {
                clearTimeout(this.resizeTimeout);
                this.resizeTimeout = setTimeout(() => {
                    this.resizeTerminal();
                }, 50);
            });
            this.resizeObserver.observe(wrapperEl);
        }
    }

    unmount() {
        this.cleanup();
        if (this._onWindowResize) {
            window.removeEventListener('resize', this._onWindowResize);
        }
        if (this._visibilityHandler) {
            document.removeEventListener('visibilitychange', this._visibilityHandler);
        }
    }

    sendCommandWhenReady(command) {
        const message = { command };
        if (this.isWebSocketReady()) {
            this.sendMessage(message);
        } else {
            this.pendingCommand = message;
        }
    }

    cleanup() {
        this.checkIfProcessIsRunningAndKillIt();
        this.clearAllTimers();
        this.connectionState = 'disconnected';
        this.pendingCommand = null;
        this.resetTerminalSessionCountdown();
        if (this.socket) {
            this.socket.close(1000, 'Client cleanup');
        }
        if (this.resizeObserver) {
            this.resizeObserver.disconnect();
            this.resizeObserver = null;
        }
        if (this.resizeTimeout) {
            clearTimeout(this.resizeTimeout);
        }
    }

    clearAllTimers() {
        if (this.keepAliveInterval) {
            clearInterval(this.keepAliveInterval);
        }
        [this.reconnectInterval, this.connectionTimeoutId, this.pingTimeoutId, this.resizeTimeout]
            .forEach((timer) => timer && clearTimeout(timer));
        if (this.terminalSessionCountdownInterval) {
            clearInterval(this.terminalSessionCountdownInterval);
        }
        this.keepAliveInterval = null;
        this.reconnectInterval = null;
        this.connectionTimeoutId = null;
        this.pingTimeoutId = null;
        this.resizeTimeout = null;
        this.terminalSessionCountdownInterval = null;
    }

    resetTerminalSessionCountdown() {
        if (this.terminalSessionCountdownInterval) {
            clearInterval(this.terminalSessionCountdownInterval);
        }
        this.terminalSessionStartedAt = null;
        this.terminalSessionRemainingSeconds = null;
        this.terminalSessionCountdownInterval = null;
        this.emitState();
    }

    startTerminalSessionCountdown() {
        this.resetTerminalSessionCountdown();
        this.terminalSessionStartedAt = Date.now();
        this.updateTerminalSessionCountdown();
        this.terminalSessionCountdownInterval = setInterval(() => {
            this.updateTerminalSessionCountdown();
        }, 1000);
    }

    updateTerminalSessionCountdown() {
        if (!this.terminalSessionStartedAt) {
            this.terminalSessionRemainingSeconds = null;
            this.emitState();
            return;
        }

        const elapsedSeconds = (Date.now() - this.terminalSessionStartedAt) / 1000;
        this.terminalSessionRemainingSeconds = Math.max(0, MAX_TERMINAL_SESSION_SECONDS - elapsedSeconds);
        this.emitState();
    }

    resetTerminal() {
        if (this.term) {
            this.onError('Terminal websocket connection lost. Reconnecting...');
            try {
                const stamp = new Date().toLocaleTimeString();
                this.term.write(`\r\n\x1b[33m── Connection lost at ${stamp}, reconnecting... ──\x1b[0m\r\n`);
            } catch (_) {
                // ignore — terminal not ready to receive writes
            }
            this.pendingWrites = 0;
            this.paused = false;
            this.commandBuffer = '';
            this.pendingCommand = null;
            this.resetTerminalSessionCountdown();

            this.onTerminalDisconnected();

            setTimeout(() => {
                this.resizeTerminal();
                this.term.focus();
            });
        }
    }

    setupTerminal() {
        if (this.terminalEl) {
            this.term = new Terminal({
                cols: 80,
                rows: 30,
                fontFamily: '"Geist Mono", "SFMono-Regular", Menlo, Monaco, Consolas, "Liberation Mono", monospace, "Powerline Extra Symbols"',
                cursorBlink: true,
                rendererType: 'canvas',
                convertEol: true,
                disableStdin: false,
            });
            this.fitAddon = new FitAddon();
            this.term.loadAddon(this.fitAddon);
            setTimeout(() => {
                this.resizeTerminal();
            });
        }
    }

    initializeWebSocket() {
        if (this.socket && this.socket.readyState !== WebSocket.CLOSED) {
            logTerminal('log', '[Terminal] WebSocket already connecting/connected, skipping');
            return;
        }

        this.connectionState = 'connecting';
        this.emitState();
        this.clearAllTimers();

        const predefined = this.terminalConfig || {};
        const connectionString = {
            protocol: window.location.protocol === 'https:' ? 'wss' : 'ws',
            host: window.location.hostname,
            port: ':6002',
            path: '/terminal/ws',
        };

        if (!window.location.port) {
            connectionString.port = '';
        }
        if (predefined.host) {
            connectionString.host = predefined.host;
        }
        if (predefined.port) {
            connectionString.port = `:${predefined.port}`;
        }
        if (predefined.protocol) {
            connectionString.protocol = predefined.protocol;
        }

        const url = `${connectionString.protocol}://${connectionString.host}${connectionString.port}${connectionString.path}`;
        logTerminal('log', `[Terminal] Attempting connection to: ${url}`);

        try {
            this.socket = new WebSocket(url);

            const timeoutMs = this.reconnectAttempts === 0 ? 15000 : this.connectionTimeout;
            this.connectionTimeoutId = setTimeout(() => {
                if (this.connectionState === 'connecting') {
                    logTerminal('error', `[Terminal] Connection timeout after ${timeoutMs}ms`);
                    this.socket.close();
                    this.handleConnectionError('Connection timeout');
                }
            }, timeoutMs);

            this.socket.onopen = this.handleSocketOpen.bind(this);
            this.socket.onmessage = this.handleSocketMessage.bind(this);
            this.socket.onerror = this.handleSocketError.bind(this);
            this.socket.onclose = this.handleSocketClose.bind(this);
        } catch (error) {
            logTerminal('error', '[Terminal] Failed to create WebSocket:', error);
            this.handleConnectionError(`Failed to create WebSocket connection: ${error.message}`);
        }
    }

    handleSocketOpen() {
        logTerminal('log', '[Terminal] WebSocket connection established.');
        this.connectionState = 'connected';
        this.reconnectAttempts = 0;
        this.heartbeatMissed = 0;
        this.lastPingTime = Date.now();
        this.emitState();

        if (this.connectionTimeoutId) {
            clearTimeout(this.connectionTimeoutId);
            this.connectionTimeoutId = null;
        }

        if (this.pendingCommand) {
            this.sendMessage(this.pendingCommand);
            this.pendingCommand = null;
        } else if (this.lastSentCommand) {
            logTerminal('log', '[Terminal] Replaying last command after reconnect.');
            this.sendMessage(this.lastSentCommand);
        }

        if (!this.keepAliveInterval) {
            this.keepAliveInterval = setInterval(this.keepAlive.bind(this), 30000);
        }

        this.resetPingTimeout();
    }

    handleSocketError(error) {
        logTerminal('error', '[Terminal] WebSocket error:', error);
        logTerminal('error', '[Terminal] WebSocket state:', this.socket ? this.socket.readyState : 'No socket');
        logTerminal('error', '[Terminal] Connection attempt:', this.reconnectAttempts + 1);
        this.handleConnectionError('WebSocket error occurred');
    }

    handleSocketClose(event) {
        logTerminal('warn', `[Terminal] WebSocket connection closed. Code: ${event.code}, Reason: ${event.reason || 'No reason provided'}`);
        logTerminal('log', '[Terminal] Was clean close:', event.code === 1000);
        logTerminal('log', '[Terminal] Connection attempt:', this.reconnectAttempts + 1);

        this.connectionState = 'disconnected';
        this.clearAllTimers();
        this.resetTerminalSessionCountdown();
        this.emitState();

        if (event.code !== 1000) {
            if (this.reconnectAttempts > 0) {
                this.resetTerminal();
                this.message = '(connection closed)';
                this.terminalActive = false;
                this.emitState();
            }
            this.scheduleReconnect();
        }
    }

    handleConnectionError(reason) {
        logTerminal('error', `[Terminal] Connection error: ${reason} (attempt ${this.reconnectAttempts + 1})`);
        this.connectionState = 'disconnected';
        this.emitState();

        if (this.reconnectAttempts >= 2) {
            this.onError(`Terminal connection error: ${reason}`);
        }

        this.scheduleReconnect();
    }

    scheduleReconnect() {
        if (this.reconnectAttempts >= this.maxReconnectAttempts) {
            logTerminal('error', '[Terminal] Max reconnection attempts reached');
            this.message = '(connection failed - max retries exceeded)';
            this.emitState();
            return;
        }

        this.connectionState = 'reconnecting';
        this.emitState();

        const delay = Math.min(
            this.baseReconnectDelay * Math.pow(2, this.reconnectAttempts) + Math.random() * 1000,
            this.maxReconnectDelay
        );

        logTerminal('warn', `[Terminal] Scheduling reconnect attempt ${this.reconnectAttempts + 1} in ${delay}ms`);

        this.reconnectInterval = setTimeout(() => {
            this.reconnectAttempts++;
            this.initializeWebSocket();
        }, delay);
    }

    sendMessage(message) {
        if (this.socket && this.socket.readyState === WebSocket.OPEN) {
            this.socket.send(JSON.stringify(message));
            if (message && message.command) {
                this.lastSentCommand = message;
            }
        } else {
            logTerminal('warn', '[Terminal] WebSocket not ready, message not sent:', message);
        }
    }

    handleSocketMessage(event) {
        if (event.data === 'pong') {
            this.heartbeatMissed = 0;
            this.lastPingTime = Date.now();
            this.resetPingTimeout();
            return;
        }

        if (!this.term?._initialized && event.data !== 'pty-ready') {
            logTerminal('warn', '[Terminal] Received message before PTY initialization:', event.data);
        }

        if (event.data === 'pty-ready') {
            if (!this.term._initialized) {
                this.term.open(this.terminalEl);
                this.term._initialized = true;
            } else {
                try {
                    const stamp = new Date().toLocaleTimeString();
                    this.term.write(`\r\n\x1b[32m── Reconnected at ${stamp} ──\x1b[0m\r\n`);
                } catch (_) {
                    // ignore — fall through; xterm will render the new prompt anyway
                }
            }
            this.terminalActive = true;
            this.emitState();
            this.startTerminalSessionCountdown();
            this.term.focus();
            const viewport = document.querySelector('.xterm-viewport');
            if (viewport) {
                viewport.classList.add('scrollbar', 'rounded-sm');
            }

            this.resizeTerminal();
            setTimeout(() => this.resizeTerminal(), 200);
            setTimeout(() => this.term.focus(), 100);
            setTimeout(() => this.term.focus(), 500);

            this.onTerminalConnected();
        } else if (event.data === 'unprocessable') {
            if (this.term) {
                this.term.reset();
            }
            this.terminalActive = false;
            this.lastSentCommand = null;
            this.resetTerminalSessionCountdown();
            this.message = '(sorry, something went wrong, please try again)';
            this.emitState();

            this.onTerminalDisconnected();
        } else if (event.data === 'pty-exited') {
            this.fullscreen = false;
            this.mobileToolbarCollapsed = false;
            this.terminalActive = false;
            this.resetTerminalSessionCountdown();
            this.term.reset();
            this.commandBuffer = '';
            this.lastSentCommand = null;
            this.emitState();

            this.onTerminalDisconnected();
        } else if (
            typeof event.data === 'string' &&
            (event.data.startsWith('Unauthorized:') || event.data.startsWith('Invalid SSH command:'))
        ) {
            logTerminal('error', '[Terminal] Backend rejected terminal startup:', event.data);
            this.onError(event.data);
            this.terminalActive = false;
            this.resetTerminalSessionCountdown();
            this.emitState();
        } else {
            try {
                this.pendingWrites++;
                this.term.write(event.data, (err) => {
                    if (err) {
                        logTerminal('error', '[Terminal] Write error:', err);
                    }
                    this.flowControlCallback();
                });
            } catch (error) {
                logTerminal('error', '[Terminal] Write operation failed:', error);
                this.pendingWrites = Math.max(0, this.pendingWrites - 1);
            }
        }
    }

    flowControlCallback() {
        this.pendingWrites = Math.max(0, this.pendingWrites - 1);

        if (this.pendingWrites > this.MAX_PENDING_WRITES && !this.paused) {
            this.paused = true;
            this.sendMessage({ pause: true });
            return;
        }
        if (this.pendingWrites <= Math.floor(this.MAX_PENDING_WRITES / 2) && this.paused) {
            this.paused = false;
            this.sendMessage({ resume: true });
        }
    }

    setupTerminalEventListeners() {
        if (!this.term) {
            return;
        }

        this.term.onData((data) => {
            this.sendMessage({ message: data });
            if (data === '\r') {
                this.commandBuffer = '';
            } else {
                this.commandBuffer += data;
            }
        });

        this.term.attachCustomKeyEventHandler((arg) => {
            if (arg.ctrlKey && arg.code === 'KeyV' && arg.type === 'keydown') {
                return false;
            }

            if (arg.ctrlKey && arg.code === 'KeyC' && arg.type === 'keydown') {
                const selection = this.term.getSelection();
                if (selection) {
                    navigator.clipboard.writeText(selection);
                    return false;
                }
            }
            return true;
        });
    }

    sendTerminalInput(data) {
        if (!this.term || !this.terminalActive) {
            return;
        }

        this.term.focus();
        this.sendMessage({ message: data });
    }

    sendTerminalControl(sequence) {
        const terminalSequences = {
            arrowUp: '\x1b[A',
            arrowDown: '\x1b[B',
            arrowRight: '\x1b[C',
            arrowLeft: '\x1b[D',
            tab: '\t',
            escape: '\x1b',
            ctrlC: '\x03',
        };

        if (terminalSequences[sequence]) {
            this.sendTerminalInput(terminalSequences[sequence]);
        }
    }

    async pasteFromClipboard() {
        if (!navigator.clipboard?.readText) {
            this.onError('Clipboard paste is not available in this browser.');
            return;
        }

        try {
            const text = await navigator.clipboard.readText();
            if (text) {
                this.sendTerminalInput(text);
            }
        } catch (error) {
            logTerminal('warn', '[Terminal] Clipboard paste failed:', error);
            this.onError('Clipboard paste permission was denied.');
        }
    }

    async copyTerminalSelection() {
        const selection = this.term?.getSelection();
        if (!selection) {
            this.onError('Select terminal text before copying.');
            return;
        }

        try {
            await navigator.clipboard.writeText(selection);
        } catch (error) {
            logTerminal('warn', '[Terminal] Clipboard copy failed:', error);
            this.onError('Clipboard copy permission was denied.');
        }
    }

    keepAlive() {
        if (this.socket && this.socket.readyState === WebSocket.OPEN) {
            this.sendMessage({ ping: true });
        } else if (this.connectionState === 'disconnected') {
            this.initializeWebSocket();
        }
    }

    handleVisibilityChange() {
        const wasVisible = this.isDocumentVisible;
        this.isDocumentVisible = !document.hidden;

        if (!this.isDocumentVisible) {
            this.wasConnectedBeforeHidden = this.connectionState === 'connected';
            if (this.pingTimeoutId) {
                clearTimeout(this.pingTimeoutId);
                this.pingTimeoutId = null;
            }
            logTerminal('log', '[Terminal] Tab hidden, pausing heartbeat monitoring');
        } else if (wasVisible === false) {
            logTerminal('log', '[Terminal] Tab visible, resuming connection management');

            if (this.wasConnectedBeforeHidden && this.socket && this.socket.readyState === WebSocket.OPEN) {
                this.heartbeatMissed = 0;
                this.sendMessage({ ping: true });
                if (this.pingTimeoutId) {
                    clearTimeout(this.pingTimeoutId);
                }
                this.pingTimeoutId = setTimeout(() => {
                    logTerminal('warn', '[Terminal] Visibility-resume ping timed out, forcing reconnect.');
                    try {
                        this.socket.close(4000, 'Visibility-resume timeout');
                    } catch (_) {
                        // ignore — close handler will run on its own
                    }
                }, 5000);
            } else if (this.wasConnectedBeforeHidden && this.connectionState !== 'connected') {
                this.reconnectAttempts = 0;
                this.initializeWebSocket();
            }
        }
    }

    resetPingTimeout() {
        if (this.pingTimeoutId) {
            clearTimeout(this.pingTimeoutId);
        }

        this.pingTimeoutId = setTimeout(() => {
            this.heartbeatMissed++;
            logTerminal('warn', `[Terminal] Ping timeout - missed ${this.heartbeatMissed}/${this.maxHeartbeatMisses}`);

            if (this.heartbeatMissed >= this.maxHeartbeatMisses) {
                logTerminal('error', '[Terminal] Too many missed heartbeats, closing connection');
                this.socket.close(1001, 'Heartbeat timeout');
            }
        }, this.pingTimeout);
    }

    checkIfProcessIsRunningAndKillIt() {
        this.sendMessage({ checkActive: 'force' });
    }

    makeFullscreen() {
        this.fullscreen = !this.fullscreen;
        this.emitState();
        setTimeout(() => {
            this.resizeTerminal();
        }, 100);
    }

    toggleMobileToolbar() {
        this.mobileToolbarCollapsed = !this.mobileToolbarCollapsed;
        this.emitState();
        setTimeout(() => this.resizeTerminal());
    }

    resizeTerminal() {
        if (!this.terminalActive || !this.term || !this.fitAddon) {
            return;
        }

        try {
            this.fitAddon.fit();

            const terminalHeight = this.terminalEl?.clientHeight || this.wrapperEl?.clientHeight;
            const terminalWidth = this.terminalEl?.clientWidth || this.wrapperEl?.clientWidth;

            const horizontalPadding = 16;
            const verticalPadding = 8;
            const height = terminalHeight - verticalPadding;
            const width = terminalWidth - horizontalPadding;

            if (height <= 0 || width <= 0) {
                logTerminal('warn', '[Terminal] Invalid wrapper dimensions, retrying...', { height, width });
                setTimeout(() => this.resizeTerminal(), 100);
                return;
            }

            const charSize = this.term._core._renderService._charSizeService;

            if (!charSize.height || !charSize.width) {
                logTerminal('warn', '[Terminal] Character size not available, retrying...');
                setTimeout(() => this.resizeTerminal(), 100);
                return;
            }

            const rows = Math.floor(height / charSize.height) - 1;
            const cols = Math.floor(width / charSize.width) - 1;

            if (rows > 0 && cols > 0) {
                const currentCols = this.term.cols;
                const currentRows = this.term.rows;

                if (cols !== currentCols || rows !== currentRows) {
                    this.term.resize(cols, rows);
                    this.sendMessage({ resize: { cols, rows } });
                }
            } else {
                logTerminal('warn', '[Terminal] Invalid calculated dimensions:', { rows, cols, height, width, charSize });
            }
        } catch (error) {
            logTerminal('error', '[Terminal] Resize error:', error);
        }
    }

    isWebSocketReady() {
        return this.connectionState === 'connected' && this.socket && this.socket.readyState === WebSocket.OPEN;
    }
}
