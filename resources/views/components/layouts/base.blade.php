@props(['title' => null, 'density' => 'comfortable'])
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-density="{{ $density }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' · ' : '' }}KBT RUVIA BioTech</title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2311b5ab' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M2 12h4l2.5-6 4 12 3-9 1.5 3H22'/%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body {{ $attributes }}>
{{ $slot }}
</body>
</html>
