<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'JEUDY') }}</title>
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('Logo_APP.PNG') }}?v=20260303b">
        <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('Logo_APP.PNG') }}?v=20260303b">
        <link rel="icon" sizes="any" href="{{ asset('favicon.ico') }}?v=20260303b">
        <link rel="apple-touch-icon" href="{{ asset('Logo_APP.PNG') }}?v=20260303b">
        <link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v=20260303b">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=montserrat:400,500,600,700,800,900&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @vite(['resources/js/app.jsx', "resources/js/Pages/{$page['component']}.jsx"])
        @inertiaHead
    </head>
    <body class="antialiased">
        @inertia
    </body>
</html>
