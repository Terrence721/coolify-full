import { defineConfig, loadEnv } from "vite";
import laravel from "laravel-vite-plugin";
import react from "@vitejs/plugin-react";
import fs from "node:fs";

// Escapes every regex metacharacter, not just dots - VITE_HOST is interpolated into a RegExp
// below to allow any port on the LAN host in Vite's dev-server CORS allowlist, so an
// unescaped metacharacter there (*, +, (, etc.) could make the resulting pattern match more
// than intended, not just fail to match the literal host.
function escapeRegExp(string) {
    return string.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '')
    const viteHost = env.VITE_HOST || null;
    const vitePort = Number(env.VITE_PORT || 5173);

    // Opt-in: only present once ./docker/https-proxy/generate-cert.sh has been run (see issue
    // #84). Serving Vite's own assets over HTTPS - regardless of which scheme the main app page
    // uses - avoids a real mixed-content block: an HTTPS page refuses to load an HTTP <script>,
    // but either scheme can load an HTTPS one. Left conditional so the default plain-HTTP dev
    // workflow (the vast majority of sessions, no cert generated) never sees a self-signed-cert
    // browser warning it didn't ask for.
    const certPath = "docker/https-proxy/certs/dev.crt";
    const keyPath = "docker/https-proxy/certs/dev.key";
    const httpsCert = fs.existsSync(certPath) && fs.existsSync(keyPath)
        ? { cert: fs.readFileSync(certPath), key: fs.readFileSync(keyPath) }
        : null;
    const viteScheme = httpsCert ? "https" : "http";

    return {
        server: {
            watch: {
                // Docker Desktop on Windows doesn't reliably forward native
                // filesystem change events from the bind-mounted volume into
                // the container, so chokidar's default fs.watch-based
                // detection never fires for host-side edits. Polling works
                // around that at the cost of a bit of CPU.
                usePolling: true,
                interval: 300,
                ignored: [
                    "**/dev_*_data/**",
                    "**/storage/**",
                    "**/node_modules/**",
                ],
            },
            host: "0.0.0.0",
            allowedHosts: true,
            cors: {
                origin: [
                    /^https?:\/\/localhost(:\d+)?$/,
                    /^https?:\/\/127\.0\.0\.1(:\d+)?$/,
                    /^https?:\/\/\[::1\](:\d+)?$/,
                    // Any *.localhost subdomain is guaranteed to resolve to loopback (RFC
                    // 6761) - browsers and the OS handle this natively, no DNS/hosts-file
                    // entry needed - so it's as safe to trust unconditionally as bare
                    // localhost above.
                    /^https?:\/\/[a-zA-Z0-9-]+\.localhost(:\d+)?$/,
                    ...(env.APP_URL ? [env.APP_URL] : []),
                    // Any port on the LAN host, not just Vite's own port - the app itself is
                    // served from APP_PORT (8000 by default), a different port than Vite
                    // (5173), and it's the app's origin the browser actually sends when
                    // fetching Vite's assets from another device on the LAN.
                    ...(viteHost ? [new RegExp(`^https?://${escapeRegExp(viteHost)}(:\\d+)?$`)] : []),
                ],
            },
            origin: viteHost ? `${viteScheme}://${viteHost}:${vitePort}` : undefined,
            hmr: viteHost
                ? { host: viteHost, clientPort: vitePort, protocol: httpsCert ? "wss" : "ws" }
                : true,
            https: httpsCert,
        },
        plugins: [
            laravel({
                input: [
                    "resources/css/app.css",
                    "resources/js/app.js",
                    "resources/js/inertia-app.jsx",
                ],
                refresh: true,
            }),
            react(),
        ],
    }
});
