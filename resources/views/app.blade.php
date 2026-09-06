<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ config('app.name', 'Smart Access Control') }}</title>

    {{-- Assembled in SpaController; see the note there on why it is not baked
         into the bundle. --}}
    <script>
        window.__ACCESS_CONFIG__ = @json($runtimeConfig);
    </script>

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/main.tsx'])
</head>
<body class="h-full">
    <div id="root"></div>
    <noscript>
        The Smart Access Control dashboard needs JavaScript. Door hardware keeps
        working regardless — this page is only the management view.
    </noscript>
</body>
</html>
