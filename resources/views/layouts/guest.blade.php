<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- SEO dynamique --}}
    <title>@yield('title', 'SimpleDevis - Devis & facturation pour indépendants')</title>

    <meta name="description" content="@yield('meta_description', 'Crée tes devis et factures facilement avec SimpleDevis. Solution simple pour freelances et indépendants.')">

    <meta name="robots" content="index, follow">

    {{-- Open Graph (réseaux sociaux) --}}
    <meta property="og:title" content="SimpleDevis - Devis & facturation">
    <meta property="og:description" content="Gère tes devis et factures simplement avec SimpleDevis.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://www.simpledevis.online">
    <meta property="og:image" content="https://www.simpledevis.online/preview.png">

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

    {{-- Scripts --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="bg-indigo-600 text-white text-center text-sm py-2 px-4">
    🚀 SimpleDevis vous prépare aux évolutions de la facturation électronique 2026 — 
    <a href="{{ route('reforme') }}" class="underline font-semibold">
        En savoir plus
    </a>
</div>
        {{ $slot }}
   </body>
</html>