<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Stone ERP — Slab Inventory Assistant</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/chat-app.js'])
    @else
        <style>
            body { font-family: Instrument Sans, system-ui, sans-serif; padding: 2rem; max-width: 40rem; margin: auto; }
        </style>
    @endif
</head>
<body class="min-h-screen bg-slate-100 font-sans text-slate-900 antialiased">
    @if (! file_exists(public_path('build/manifest.json')) && ! file_exists(public_path('hot')))
        <p><strong>Assets not built.</strong> Run <code>npm install</code> then <code>npm run dev</code> or <code>npm run build</code>, then reload.</p>
    @else
        <div id="app" class="h-screen min-h-0"></div>
    @endif
</body>
</html>
