@extends('layouts.marketplace')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
    <div class="bg-white rounded-xl shadow p-8">
        <h1 class="text-3xl font-bold text-gray-900 mb-8">Contact Us</h1>

        <form class="space-y-4" autocomplete="on">
            <div class="grid grid-cols-1 md:grid-cols-[180px_1fr] items-center gap-2 md:gap-4">
                <label for="contact-email" class="text-2xl md:text-3xl text-gray-900">Email</label>
                <input id="contact-email" name="email" type="email" placeholder="Email" class="w-full px-3 py-2 border border-gray-300 rounded text-gray-900 focus:outline-none focus:border-[#008bea]">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-[180px_1fr] items-center gap-2 md:gap-4">
                <label for="contact-name" class="text-2xl md:text-3xl text-gray-900">Name</label>
                <input id="contact-name" name="name" type="text" placeholder="Name" class="w-full px-3 py-2 border border-gray-300 rounded text-gray-900 focus:outline-none focus:border-[#008bea]">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-[180px_1fr] items-center gap-2 md:gap-4">
                <label for="contact-website" class="text-2xl md:text-3xl text-gray-900">Website</label>
                <input id="contact-website" name="website" type="url" placeholder="Website" class="w-full px-3 py-2 border border-gray-300 rounded text-gray-900 focus:outline-none focus:border-[#008bea]">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-[180px_1fr] items-center gap-2 md:gap-4">
                <label for="contact-country" class="text-2xl md:text-3xl text-gray-900">Country</label>
                <input id="contact-country" name="country" type="text" placeholder="Country" class="w-full px-3 py-2 border border-gray-300 rounded text-gray-900 focus:outline-none focus:border-[#008bea]">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-[180px_1fr] items-center gap-2 md:gap-4">
                <label for="contact-subject" class="text-2xl md:text-3xl text-gray-900">Subject</label>
                <select id="contact-subject" name="subject" class="w-full px-3 py-2 border border-gray-300 rounded text-gray-900 focus:outline-none focus:border-[#008bea] bg-white">
                    <option selected>XML Feed</option>
                    <option>JSON Feed</option>
                    <option>API</option>
                    <option>Integración Trovit</option>
                    <option>Integración Mitula</option>
                    <option>Otro</option>
                </select>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-[180px_1fr] items-start gap-2 md:gap-4">
                <label for="contact-message" class="text-2xl md:text-3xl text-gray-900 pt-1">Message</label>
                <textarea id="contact-message" name="message" rows="10" placeholder="Textarea" class="w-full px-3 py-2 border border-gray-300 rounded text-gray-900 focus:outline-none focus:border-[#008bea]"></textarea>
            </div>

            <div class="md:pl-[196px]">
                <div class="w-full max-w-[420px] border border-gray-300 rounded p-3 bg-white">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-6 h-6 border-2 border-gray-500 rounded-sm"></div>
                            <span class="text-gray-800">No soy un robot</span>
                        </div>
                        <div class="text-xs text-gray-500 text-right">
                            <div class="font-semibold">reCAPTCHA</div>
                            <div>Privacidad · Términos</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="md:pl-[196px]">
                <button type="button" class="bg-[#008bea] hover:bg-[#007acc] text-white px-6 py-2 rounded font-medium">
                    Send
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
