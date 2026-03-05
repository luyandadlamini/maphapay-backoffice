<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="csrf-token" content="{{ csrf_token() }}"/>
    <title>{{ $title ?? 'Foodo Insights' }} — Command Center</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    @vite(['resources/css/foodo.css'])
    @stack('head')
</head>
<body class="flex min-h-screen font-foodo text-foodo-text overflow-hidden foodo-bg">
    {{-- Sidebar --}}
    <x-foodo.sidebar :active="$activePage ?? 'dashboard'" />

    {{-- Main --}}
    <main class="ml-64 flex-1 h-screen overflow-y-auto foodo-scrollbar p-6 lg:p-8 {{ $mainClass ?? '' }}">
        {{ $slot }}
    </main>

    @stack('scripts')
</body>
</html>
