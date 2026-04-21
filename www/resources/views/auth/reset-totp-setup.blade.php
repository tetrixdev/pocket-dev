@extends('layouts.config')

@section('title', 'Set Up New 2FA')

@section('content')
<div class="max-w-md mx-auto">
    <div class="text-center mb-8">
        <h2 class="text-xl font-semibold mb-2">Set Up New 2FA</h2>
        <p class="text-gray-400 text-sm">Scan this QR code with your authenticator app</p>
    </div>

    <div class="bg-gray-800 rounded-lg p-6 space-y-6">
        @if($errors->has('code'))
            <div class="p-3 bg-red-900 border-l-4 border-red-500 text-red-200 rounded text-sm">
                {{ $errors->first('code') }}
            </div>
        @endif

        {{-- QR Code --}}
        <div class="flex justify-center">
            <div class="bg-white p-4 rounded-lg">
                {!! $qrCodeSvg !!}
            </div>
        </div>

        {{-- Manual Entry --}}
        <div>
            <p class="text-sm text-gray-400 text-center mb-2">Or enter this code manually:</p>
            <div class="relative">
                <code class="block w-full px-4 py-3 bg-gray-700 text-center text-lg tracking-widest rounded-lg font-mono">
                    {{ $secret }}
                </code>
                <button type="button"
                        onclick="navigator.clipboard.writeText({{ \Illuminate\Support\Js::from($secret) }})"
                        class="absolute right-2 top-1/2 -translate-y-1/2 p-2 text-gray-400 hover:text-white transition-colors"
                        title="Copy to clipboard">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                </button>
            </div>
        </div>

        {{-- Verify Form --}}
        <form action="{{ route('settings.security.reset-totp.new') }}" method="POST">
            @csrf

            <div class="mb-6">
                <label for="code" class="block text-sm font-medium text-gray-300 mb-2">Enter code from your app</label>
                <input type="text"
                       id="code"
                       name="code"
                       inputmode="numeric"
                       pattern="[0-9]*"
                       maxlength="6"
                       autofocus
                       autocomplete="one-time-code"
                       class="w-full px-4 py-3 bg-gray-700 text-white text-center text-2xl tracking-widest border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       placeholder="000000">
            </div>

            <div class="flex gap-3">
                <a href="{{ route('settings.security') }}"
                   class="flex-1 px-4 py-2 text-center text-gray-300 hover:text-white border border-gray-600 rounded-lg transition-colors">
                    Cancel
                </a>
                <button type="submit"
                        class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                    Verify & Save
                </button>
            </div>
        </form>
    </div>

    <p class="mt-4 text-center text-sm text-gray-500">
        Your old 2FA will be replaced. Previous recovery codes will be invalidated.
    </p>
</div>

<style>
    [x-cloak] { display: none !important; }
</style>
@endsection
