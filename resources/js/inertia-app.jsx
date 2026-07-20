import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import AppLayout from './Layouts/AppLayout';

createInertiaApp({
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob(['./Pages/**/*.jsx', '!./Pages/**/*.test.jsx'])).then((module) => {
            module.default.layout = module.default.layout || ((page) => <AppLayout>{page}</AppLayout>);

            return module;
        }),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
