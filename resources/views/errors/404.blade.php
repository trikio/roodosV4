<!DOCTYPE html>
<html lang="es">
<head>
    @php($pageTitle = 'Página no encontrada')
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $pageTitle }}</title>
    <meta name="title" content="{{ $pageTitle }}">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-gradient-to-b from-slate-100 to-white text-slate-900">
    <main class="min-h-screen flex items-center justify-center px-6">
        <section class="w-full max-w-2xl rounded-2xl border border-slate-200 bg-white shadow-xl p-10 text-center">
            <p class="text-sm font-semibold tracking-[0.18em] text-slate-500 mb-4">ERROR 404</p>
            <h1 class="text-4xl font-bold mb-4">{{ $pageTitle }}</h1>
            <p class="text-slate-600 mb-8">
                No existe una página configurada para esta URL en el país solicitado.
            </p>
            <a
                href="/"
                class="inline-flex items-center justify-center rounded-lg bg-[#008bea] px-6 py-3 font-semibold text-white hover:bg-[#007acc] transition"
            >
                Volver al inicio
            </a>
        </section>
    </main>
</body>
</html>
