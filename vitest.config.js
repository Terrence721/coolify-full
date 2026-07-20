import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

// Deliberately separate from vite.config.js: that file's `laravel()` plugin
// expects a real manifest/Vite dev server and has no role in an in-memory
// component test run, so it's left out here rather than reused.
export default defineConfig({
    plugins: [react()],
    test: {
        environment: "jsdom",
        setupFiles: ["./resources/js/testSetup.js"],
        include: ["resources/js/**/*.test.jsx"],
    },
});
