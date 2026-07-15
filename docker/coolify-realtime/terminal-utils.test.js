import test from 'node:test';
import assert from 'node:assert/strict';
import {
    MAX_TERMINAL_SESSION_TIMEOUT_SECONDS,
    extractSshArgs,
    extractTargetHost,
    getTerminalSessionTimeout,
    isAuthorizedTargetHost,
    normalizeHostForAuthorization,
    parseCommandMessage,
} from './terminal-utils.js';

test('extractTargetHost normalizes quoted IPv4 hosts from generated ssh commands', () => {
    const sshArgs = extractSshArgs(
        "timeout 3600 ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o ServerAliveInterval=20 -o ConnectTimeout=10 'root'@'10.0.0.5' 'bash -se' << \\\\$abc\necho hi\nabc"
    );

    assert.equal(extractTargetHost(sshArgs), '10.0.0.5');
});

test('extractSshArgs strips shell quotes from port and user host arguments before spawning ssh', () => {
    const sshArgs = extractSshArgs(
        "timeout 3600 ssh -p '22' -o StrictHostKeyChecking=no 'root'@'10.0.0.5' 'bash -se' << \\\\$abc\necho hi\nabc"
    );

    assert.deepEqual(sshArgs.slice(0, 5), ['-p', '22', '-o', 'StrictHostKeyChecking=no', 'root@10.0.0.5']);
});

test('extractSshArgs preserves proxy command as a single normalized ssh option value', () => {
    const sshArgs = extractSshArgs(
        "timeout 3600 ssh -o ProxyCommand='cloudflared access ssh --hostname %h' -o StrictHostKeyChecking=no 'root'@'example.com' 'bash -se' << \\\\$abc\necho hi\nabc"
    );

    assert.equal(sshArgs[1], 'ProxyCommand=cloudflared access ssh --hostname %h');
    assert.equal(sshArgs[4], 'root@example.com');
});

test('isAuthorizedTargetHost matches normalized hosts against plain allowlist values', () => {
    assert.equal(isAuthorizedTargetHost("'10.0.0.5'", ['10.0.0.5']), true);
    assert.equal(isAuthorizedTargetHost('"host.docker.internal"', ['host.docker.internal']), true);
});

test('normalizeHostForAuthorization unwraps bracketed IPv6 hosts', () => {
    assert.equal(normalizeHostForAuthorization("'[2001:db8::10]'"), '2001:db8::10');
    assert.equal(isAuthorizedTargetHost("'[2001:db8::10]'", ['2001:db8::10']), true);
});

test('isAuthorizedTargetHost rejects hosts that are not in the allowlist', () => {
    assert.equal(isAuthorizedTargetHost("'10.0.0.9'", ['10.0.0.5']), false);
});


test('getTerminalSessionTimeout always enforces the maximum terminal session lifetime', () => {
    assert.equal(getTerminalSessionTimeout(null), MAX_TERMINAL_SESSION_TIMEOUT_SECONDS);
    assert.equal(getTerminalSessionTimeout(60), MAX_TERMINAL_SESSION_TIMEOUT_SECONDS);
    assert.equal(getTerminalSessionTimeout(MAX_TERMINAL_SESSION_TIMEOUT_SECONDS + 60), MAX_TERMINAL_SESSION_TIMEOUT_SECONDS);
});

// Regression test for a bug found via a real end-to-end browser session (2026-07-15):
// handleCommand() in terminal-server.js used to index `command[0]` on the raw websocket
// message value, which is already a plain string — `command[0]` silently truncated every
// SSH command down to its first character before parsing, so extractTargetHost() always
// received an empty sshArgs array and every real terminal connection was rejected with
// "Invalid SSH command: No target host found". parseCommandMessage() is the exact function
// handleCommand() calls with the raw, untouched message value, so this test covers the
// integration point the previous unit tests (which called extractSshArgs()/extractTargetHost()
// directly with an already-correct string) never exercised.
test('parseCommandMessage extracts the target host from the full raw command string handleCommand() receives', () => {
    const rawCommand =
        "ssh -o ControlMaster=auto -o ControlPath=/var/www/html/storage/app/ssh/mux/mux_localhost -o ControlPersist=3600 -i /var/www/html/storage/app/ssh/keys/ssh_key@ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PasswordAuthentication=no -o ConnectTimeout=10 -o ServerAliveInterval=20 -o RequestTTY=no -o LogLevel=ERROR -p '22' 'root'@'coolify-testing-host' 'bash -se' << \\abc\nPATH=$PATH:/usr/local/sbin && sh\nabc";

    const { targetHost, sshArgs } = parseCommandMessage(rawCommand);

    assert.equal(targetHost, 'coolify-testing-host');
    assert.ok(sshArgs.length > 1, 'sshArgs should contain the full parsed option list, not be empty');
});

test('parseCommandMessage would have caught the command[0] truncation bug', () => {
    const rawCommand = "ssh -o StrictHostKeyChecking=no 'root'@'10.0.0.5' 'bash -se' << \\abc\necho hi\nabc";

    // The bug indexed the raw string itself (a single character, e.g. rawCommand[0] === 's')
    // instead of passing the whole string through — asserting against that shape directly
    // documents exactly what regressed and would fail again if reintroduced.
    assert.notEqual(parseCommandMessage(rawCommand).targetHost, parseCommandMessage(rawCommand[0]).targetHost);
    assert.equal(parseCommandMessage(rawCommand).targetHost, '10.0.0.5');
    assert.equal(parseCommandMessage(rawCommand[0]).targetHost, null);
});
