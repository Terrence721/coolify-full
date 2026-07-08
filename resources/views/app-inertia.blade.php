<!DOCTYPE html>
<html data-theme="dark" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<script>
    (function () {
        const t = localStorage.theme || 'dark';
        const d = t === 'dark' || (t === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.classList[d ? 'add' : 'remove']('dark');
        document.documentElement.setAttribute('data-theme', d ? 'dark' : 'light');
    })();
</script>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name', 'Coolify') }}</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/inertia-app.jsx'])
    @inertiaHead
</head>

<body class="dark:text-inherit text-black">
    @inertia
</body>

</html>
