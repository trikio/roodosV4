<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roodos - Marketplace de Autos</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <header class="bg-white border-b border-gray-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex items-center justify-between gap-4">
                <!-- Logo -->
                <a href="/" class="flex-shrink-0 hover:opacity-80">
                    <img src="https://autos.roodos.cl/assets/img/roodos.png" alt="Roodos" class="h-8 w-auto">
                </a>

                <!-- Search Bar -->
                <div class="flex-1 max-w-md">
                    <div class="relative">
                        <input 
                            type="text" 
                            placeholder="Buscar autos..." 
                            class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:outline-none focus:ring-2 focus:ring-[#008bea]"
                        >
                        <button class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Links -->
                <div class="hidden md:flex items-center gap-4">
                    <a href="/casas" class="text-[#008bea] hover:underline">Casas</a>
                    <a href="/autos" class="text-[#008bea] hover:underline">Autos</a>
                </div>

                <!-- Mobile Menu Button -->
                <div class="block md:hidden">
                    <button id="mobile-menu-btn" class="text-[#008bea] hover:opacity-80">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16m-7 6h7" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </header>

    <!-- Mobile Menu -->
    <div id="mobile-menu" class="hidden fixed top-16 right-0 bg-white w-48 p-4 rounded-lg shadow-lg z-50">
        <button id="close-menu-btn" class="absolute top-2 right-2 text-gray-500 hover:text-gray-700">&times;</button>
        <nav class="space-y-4">
            <a href="/casas" class="block text-[#008bea] hover:underline text-lg">Casas</a>
            <a href="/autos" class="block text-[#008bea] hover:underline text-lg">Autos</a>
        </nav>
    </div>

    <!-- Main Content -->
    <main>
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="bg-gray-900 text-gray-300 py-12 mt-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-4 gap-8 mb-8">
                <div>
                    <h3 class="text-white font-bold mb-4">Sobre Roodos</h3>
                    <ul class="space-y-2 text-sm">
                        <li><a href="#" class="hover:text-white transition">Acerca de nosotros</a></li>
                        <li><a href="#" class="hover:text-white transition">Blog</a></li>
                        <li><a href="#" class="hover:text-white transition">Prensa</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-white font-bold mb-4">Comunidad</h3>
                    <ul class="space-y-2 text-sm">
                        <li><a href="#" class="hover:text-white transition">Foro</a></li>
                        <li><a href="#" class="hover:text-white transition">Testimonios</a></li>
                        <li><a href="#" class="hover:text-white transition">Eventos</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-white font-bold mb-4">Soporte</h3>
                    <ul class="space-y-2 text-sm">
                        <li><a href="#" class="hover:text-white transition">Centro de ayuda</a></li>
                        <li><a href="#" class="hover:text-white transition">Contacto</a></li>
                        <li><a href="#" class="hover:text-white transition">Seguridad</a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-white font-bold mb-4">Legal</h3>
                    <ul class="space-y-2 text-sm">
                        <li><a href="#" class="hover:text-white transition">Términos</a></li>
                        <li><a href="#" class="hover:text-white transition">Privacidad</a></li>
                        <li><a href="#" class="hover:text-white transition">Cookies</a></li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-gray-800 pt-8 text-center">
                <p>&copy; 2026 Roodos. Todos los derechos reservados.</p>
            </div>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const menuBtn = document.getElementById('mobile-menu-btn');
            const menu = document.getElementById('mobile-menu');
            const closeMenuBtn = document.getElementById('close-menu-btn');

            menuBtn.addEventListener('click', () => {
                menu.classList.remove('hidden');
            });

            closeMenuBtn.addEventListener('click', () => {
                menu.classList.add('hidden');
            });
        });
    </script>
</body>
</html>
