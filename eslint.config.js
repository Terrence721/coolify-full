import js from '@eslint/js';
import react from 'eslint-plugin-react';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import globals from 'globals';
import prettierConfig from 'eslint-config-prettier';

export default [
    {
        ignores: ['node_modules/**', 'public/build/**', 'vendor/**', 'storage/**'],
    },
    js.configs.recommended,
    {
        files: ['resources/js/**/*.{js,jsx}'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            parserOptions: {
                ecmaFeatures: { jsx: true },
            },
            globals: {
                // Deliberately not adding `route` or similar globals here: this codebase has
                // no Ziggy installed, and a real bug (Project/Resource/Create.jsx calling a
                // route() global that doesn't exist) shipped exactly because nothing flagged
                // the undefined reference - no-undef should catch this class of bug, not
                // suppress it.
                ...globals.browser,
                ...globals.node,
            },
        },
        plugins: {
            react,
            'react-hooks': reactHooks,
            'react-refresh': reactRefresh,
        },
        settings: {
            react: { version: '19' },
        },
        rules: {
            ...react.configs.recommended.rules,
            ...reactHooks.configs.recommended.rules,
            'react/react-in-jsx-scope': 'off',
            'react/prop-types': 'off',
            'react-refresh/only-export-components': 'warn',
            'no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],
        },
    },
    {
        files: ['resources/js/**/*.test.{js,jsx}'],
        languageOptions: {
            globals: { ...globals.node },
        },
    },
    prettierConfig,
];
