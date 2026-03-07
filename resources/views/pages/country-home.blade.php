<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roodos {{ $countryName }} - Busca tu auto y tu casa</title>
    <meta name="title" content="Roodos {{ $countryName }}">
    <meta name="description" content="Bienvenido a Roodos {{ $countryName }}. Elige entre autos y casas.">
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-gradient-to-b from-sky-100 via-cyan-50 to-blue-100 text-slate-900 flex flex-col">
    <main class="relative overflow-hidden flex-1 flex items-center">
        <div class="absolute -top-24 -left-24 h-80 w-80 rounded-full bg-sky-300/30 blur-3xl"></div>
        <div class="absolute -bottom-20 -right-20 h-80 w-80 rounded-full bg-cyan-300/30 blur-3xl"></div>

        <section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-10 sm:py-12 relative w-full">
            <div class="text-center mb-10 sm:mb-14">

                <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight mb-4">
                    Bienvenido a Roodos {{ $countryName }}
                </h1>
                <p class="text-base sm:text-lg text-slate-600 max-w-2xl mx-auto mb-6">
                    Elige la categoría que quieres explorar y empieza a buscar en segundos.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <a
                    href="https://casas.roodos.{{ $country }}"
                    class="group rounded-2xl border border-slate-200 bg-white/90 backdrop-blur p-6 sm:p-7 shadow-sm hover:shadow-xl hover:-translate-y-1 transition duration-300"
                >
                    <div class="flex items-start justify-between gap-4 mb-5">
                        <img
                            src="{{ asset('assets/img/houses_logo.png') }}"
                            alt="Casas Roodos"
                            class="w-16 h-16 sm:w-20 sm:h-20 object-contain"
                            onerror="this.onerror=null;this.src='https://casas.roodos.cl/assets/img/houses_logo.png';"
                        >
                        <span class="inline-flex rounded-full bg-[#008bea]/10 text-[#008bea] px-3 py-1 text-xs font-bold uppercase tracking-wide">
                            Inmuebles
                        </span>
                    </div>
                    <p class="text-3xl font-black text-slate-900 mb-2">Casas</p>
                    <p class="text-slate-600 mb-5">Ver propiedades en {{ $countryName }}</p>
                    <span class="inline-flex items-center gap-2 text-[#008bea] font-semibold group-hover:gap-3 transition-all">
                        Entrar a Casas
                        <span aria-hidden="true">→</span>
                    </span>
                </a>

                <a
                    href="https://autos.roodos.{{ $country }}"
                    class="group rounded-2xl border border-slate-200 bg-white/90 backdrop-blur p-6 sm:p-7 shadow-sm hover:shadow-xl hover:-translate-y-1 transition duration-300"
                >
                    <div class="flex items-start justify-between gap-4 mb-5">
                        <img
                            src="{{ asset('assets/img/cars_logo.png') }}"
                            alt="Autos Roodos"
                            class="w-16 h-16 sm:w-20 sm:h-20 object-contain"
                            onerror="this.onerror=null;this.src='https://autos.roodos.cl/assets/img/cars_logo.png';"
                        >
                        <span class="inline-flex rounded-full bg-[#008bea]/10 text-[#008bea] px-3 py-1 text-xs font-bold uppercase tracking-wide">
                            Vehiculos
                        </span>
                    </div>
                    <p class="text-3xl font-black text-slate-900 mb-2">Autos</p>
                    <p class="text-slate-600 mb-5">Ver vehiculos en {{ $countryName }}</p>
                    <span class="inline-flex items-center gap-2 text-[#008bea] font-semibold group-hover:gap-3 transition-all">
                        Entrar a Autos
                        <span aria-hidden="true">→</span>
                    </span>
                </a>
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
