import { defineConfig, loadEnv } from "vite";
import laravel from "laravel-vite-plugin";
import react from "@vitejs/plugin-react";

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '')
    const viteHost = env.VITE_HOST || null;
    const vitePort = Number(env.VITE_PORT || 5173);

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
                    ...(env.APP_URL ? [env.APP_URL] : []),
                    ...(viteHost ? [`http://${viteHost}:${vitePort}`, `https://${viteHost}:${vitePort}`] : []),
                ],
            },
            origin: viteHost ? `http://${viteHost}:${vitePort}` : undefined,
            hmr: viteHost
                ? { host: viteHost, clientPort: vitePort }
                : true,
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
