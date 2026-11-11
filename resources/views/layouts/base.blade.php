<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="no-js">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f3f0f8">
    <meta name="description" content="@yield('description', 'Voca brings a little more human to AI calling. Build your business’s next voice, on your terms.')">
    <title>@yield('title', 'Voca — Make every hello count.')</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="@yield('body-class')">
    <a class="skip-link" href="#main-content">Skip to content</a>
    @yield('body')
</body>

</html>
