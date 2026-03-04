<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roodos - Marketplace de Autos y Casas</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <header class="bg-white border-b border-gray-200 sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex items-center justify-between gap-4">
                <!-- Logo -->
                <div class="flex items-center gap-2 text-2xl font-bold text-blue-600 flex-shrink-0">
                    <div class="w-10 h-10 bg-blue-600 rounded-lg flex items-center justify-center">
                        <span class="text-white font-bold">R</span>
                    </div>
                    <span>roodos</span>
                </div>

                <!-- Search Bar -->
                <div class="flex-1 max-w-md">
                    <div class="relative">
                        <input 
                            type="text" 
                            placeholder="Buscar autos..." 
                            class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                        <button class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Button -->
                <button class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium flex-shrink-0">
                    Entrar
                </button>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="bg-gray-900 text-gray-300 py-12 mt-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-4 gap-8 mb-8">
                <div>
                    <h3 class="text-white font-bold mb-4">Sobre Roodos</h3>
                    <ul class="space-y-2">
                        <li><a href="#" class="hover:text-white">Acerca de nosotros</a></li>
                        <li><a href="#" class="hover:text-white">Blog</a></li>
                        <li><a href="#" class="hover:text-white">Prensa</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-white font-bold mb-4">Comunidad</h3>
                    <ul class="space-y-2">
                        <li><a href="#" class="hover:text-white">Foro</a></li>
                        <li><a href="#" class="hover:text-white">Testimonios</a></li>
                        <li><a href="#" class="hover:text-white">Eventos</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-white font-bold mb-4">Soporte</h3>
                    <ul class="space-y-2">
                        <li><a href="#" class="hover:text-white">Centro de ayuda</a></li>
                        <li><a href="#" class="hover:text-white">Contacto</a></li>
                        <li><a href="#" class="hover:text-white">Seguridad</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-white font-bold mb-4">Legal</h3>
                    <ul class="space-y-2">
                        <li><a href="#" class="hover:text-white">Términos</a></li>
                        <li><a href="#" class="hover:text-white">Privacidad</a></li>
                        <li><a href="#" class="hover:text-white">Cookies</a></li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-gray-800 pt-8 text-center">
                <p>&copy; 2026 Roodos. Todos los derechos reservados.</p>
            </div>
        </div>
    </footer>
</body>
</html>
