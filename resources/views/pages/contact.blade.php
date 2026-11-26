@extends('layouts.marketplace')

@section('page_title', 'Contacta con nosotros')
@section('meta_description', 'Escríbenos para publicar tu inventario en Roodos, reportar un aviso, publicidad, prensa o cualquier consulta.')

@section('content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <div class="bg-white rounded-xl shadow p-6 sm:p-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-2">@yield('page_title')</h1>
        <p class="text-gray-600 mb-8">
            ¿Tienes una automotora o un portal y quieres aparecer en Roodos? ¿Encontraste un aviso con datos malos?
            Escríbenos y te respondemos por email.
        </p>

        @if(session('contact_sent'))
            <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-green-800" role="status">
                <p class="font-semibold">¡Gracias! Recibimos tu mensaje.</p>
                <p class="text-sm mt-1">Te vamos a responder al email que nos dejaste.</p>
            </div>
        @else
            @if($errors->any())
                <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-red-800 mb-6" role="alert">
                    <p class="font-semibold">Revisa el formulario:</p>
                    <ul class="list-disc pl-5 text-sm mt-1">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ url('/contacta-con-nosotros') }}" class="space-y-5" autocomplete="on">
                @csrf
                <input type="hidden" name="form_token" value="{{ $formToken }}">
                {{-- Campo trampa: invisible para personas; los bots lo llenan. --}}
                <div class="absolute -left-[9999px]" aria-hidden="true">
                    <label for="contact-company-url">No llenar</label>
                    <input id="contact-company-url" name="company_url" type="text" tabindex="-1" autocomplete="off">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    <div>
                        <label for="contact-name" class="block text-sm font-medium text-gray-700 mb-1">Nombre</label>
                        <input id="contact-name" name="name" type="text" required maxlength="100" value="{{ old('name') }}"
                               autocomplete="name"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:border-[#008bea] focus:ring-1 focus:ring-[#008bea]">
                    </div>
                    <div>
                        <label for="contact-email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input id="contact-email" name="email" type="email" required maxlength="150" value="{{ old('email') }}"
                               autocomplete="email"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:border-[#008bea] focus:ring-1 focus:ring-[#008bea]">
                    </div>
                </div>

                <div>
                    <label for="contact-subject" class="block text-sm font-medium text-gray-700 mb-1">Motivo</label>
                    <select id="contact-subject" name="subject" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-gray-900 bg-white focus:outline-none focus:border-[#008bea] focus:ring-1 focus:ring-[#008bea]">
                        @foreach($subjects as $value => $label)
                            <option value="{{ $value }}" @selected(old('subject', request('motivo')) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="contact-website" class="block text-sm font-medium text-gray-700 mb-1">
                        Sitio web o link del aviso <span class="text-gray-400 font-normal">(opcional)</span>
                    </label>
                    <input id="contact-website" name="website" type="text" maxlength="200" value="{{ old('website') }}"
                           placeholder="https://"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:border-[#008bea] focus:ring-1 focus:ring-[#008bea]">
                </div>

                <div>
                    <label for="contact-message" class="block text-sm font-medium text-gray-700 mb-1">Mensaje</label>
                    <textarea id="contact-message" name="message" rows="7" required minlength="10" maxlength="5000"
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg text-gray-900 focus:outline-none focus:border-[#008bea] focus:ring-1 focus:ring-[#008bea]">{{ old('message') }}</textarea>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <button type="submit" class="bg-[#008bea] hover:bg-[#007acc] text-white px-6 py-2.5 rounded-lg font-medium">
                        Enviar mensaje
                    </button>
                    <p class="text-xs text-gray-500">
                        Usamos tus datos solo para responderte.
                        <a href="{{ url('/politica-de-privacidad') }}" class="underline hover:text-gray-700">Política de privacidad</a>.
                    </p>
                </div>
            </form>
        @endif
    </div>
</div>
@endsection
