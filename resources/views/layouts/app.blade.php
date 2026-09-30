<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="referrer" content="same-origin">
    <title>@yield('title', 'مدير ملفات SFTP')</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%230284c7' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect width='20' height='8' x='2' y='2' rx='2'/%3E%3Crect width='20' height='8' x='2' y='14' rx='2'/%3E%3Cpath d='M6 6h.01M6 18h.01'/%3E%3C/svg%3E">
    <script>
        // Apply theme before paint to avoid a flash.
        (function () {
            let t = null;
            try { t = localStorage.getItem('sftp.theme'); } catch (e) {}
            if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="@yield('body-class')">
    @yield('content')
</body>
</html>
