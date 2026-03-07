<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roodos - Autos y Casas en Chile, Ecuador, Peru y Mexico</title>
    <meta name="title" content="Roodos Global">
    <meta name="description" content="Accede a los portales de autos y casas de Chile, Ecuador, Peru y Mexico.">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-gradient-to-b from-sky-100 via-cyan-50 to-blue-100 text-slate-900 flex flex-col">
    @php
        $markets = [
            ['code' => 'cl', 'name' => 'Chile', 'flag' => 'https://flagcdn.com/w80/cl.png'],
            ['code' => 'ec', 'name' => 'Ecuador', 'flag' => 'https://flagcdn.com/w80/ec.png'],
            ['code' => 'pe', 'name' => 'Peru', 'flag' => 'https://flagcdn.com/w80/pe.png'],
            ['code' => 'mx', 'name' => 'Mexico', 'flag' => 'https://flagcdn.com/w80/mx.png'],
        ];
    @endphp

    <main class="relative overflow-hidden flex-1 flex items-center">
        <div class="absolute -top-24 -left-24 h-80 w-80 rounded-full bg-sky-300/30 blur-3xl"></div>
        <div class="absolute -bottom-20 -right-20 h-80 w-80 rounded-full bg-cyan-300/30 blur-3xl"></div>

        <section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-10 sm:py-12 relative w-full">
            <div class="text-center mb-10 sm:mb-14">
                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight mb-4">
                    Bienvenido a Roodos
                </h1>
                <p class="text-base sm:text-lg text-slate-600 max-w-2xl mx-auto mb-6">
                    Elige un país para entrar a su home local.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                @foreach($markets as $market)
                <div class="rounded-2xl border border-slate-200 bg-white/90 backdrop-blur p-6 sm:p-7 shadow-sm">
                    <div class="mb-5 flex items-center gap-3">
                        <img
                            src="{{ $market['flag'] }}"
                            alt="Bandera de {{ $market['name'] }}"
                            class="w-10 h-7 object-cover rounded-sm border border-slate-200"
                            loading="lazy"
                        >
                        <p class="text-3xl font-black text-slate-900">{{ $market['name'] }}</p>
                    </div>
                    <div class="mb-5">
                        <p class="text-slate-600">Entrar al portal de {{ $market['name'] }}</p>
                    </div>

                    <a
                        href="https://roodos.{{ $market['code'] }}"
                        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-5 py-3 font-semibold text-[#008bea] hover:shadow-md transition"
                    >
                        Ir a Roodos {{ $market['name'] }}
                        <span aria-hidden="true">→</span>
                    </a>
                </div>
                @endforeach
            </div>
        </section>
    </main>

    <footer class="bg-gray-200 border-t border-gray-300 py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="flex flex-wrap items-center justify-center gap-x-8 gap-y-3 text-base text-gray-700">
                <a href="https://roodos.com" class="hover:text-gray-900">Home</a>
                <a href="https://roodos.com/envie-su-sitio" class="hover:text-gray-900">Envíe su sitio</a>
                <a href="https://roodos.com/sobre-nosotros" class="hover:text-gray-900">Sobre nosotros</a>
                <a href="https://roodos.com/terminos-de-uso" class="hover:text-gray-900">Terminos de uso</a>
                <a href="https://roodos.com/politica-de-privacidad" class="hover:text-gray-900">Política de privacidad</a>
                <a href="https://roodos.com/politica-de-cookies" class="hover:text-gray-900">Política de cookies</a>
                <a href="https://roodos.com/nuestras-redes" class="hover:text-gray-900">Nuestras Redes</a>
                <a href="https://roodos.com/contacta-con-nosotros" class="hover:text-gray-900">Contacta con nosotros</a>
            </nav>
        </div>
    </footer>
</body>
</html>
