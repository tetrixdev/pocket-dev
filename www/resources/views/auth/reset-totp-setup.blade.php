@extends('layouts.config')

@section('title', 'Set Up New 2FA')

@section('content')
<div class="max-w-md mx-auto" x-data="{ showSecret: false, copied: false }">
    <div class="text-center mb-8">
        <h2 class="text-xl font-semibold mb-2">Set Up New 2FA</h2>
        <p class="text-gray-400 text-sm">Scan this QR code with your authenticator app</p>
    </div>

    <div class="bg-gray-800 rounded-lg p-6 space-y-6">
        @if($errors->has('code'))
            <div class="p-3 bg-red-900 border-l-4 border-red-500 text-red-200 rounded text-sm" role="alert" aria-live="polite">
                {{ $errors->first('code') }}
            </div>
        @endif

        {{-- QR Code --}}
        <div class="flex justify-center">
            <div class="bg-white p-4 rounded-lg">
                {!! $qrCodeSvg !!}
            </div>
        </div>

        {{-- Manual Entry Secret (hidden by default, matching setup/totp pattern) --}}
        <div class="text-center">
            <button
                type="button"
                @click="showSecret = !showSecret"
                class="text-sm text-blue-400 hover:text-blue-300"
            >
                <span x-text="showSecret ? 'Hide' : 'Show'"></span> manual entry code
            </button>
            <div x-show="showSecret" x-cloak class="mt-3">
                <div class="relative">
                    <code
                        class="block px-4 py-2 bg-gray-700 rounded font-mono text-sm break-all cursor-pointer"
                        @click="navigator.clipboard.writeText(@js($secret)); copied = true; setTimeout(() => copied = false, 2000)"
                        title="Click to copy"
                    >{{ $secret }}</code>
                    <span
                        x-show="copied"
                        x-transition
                        class="absolute -top-8 left-1/2 -translate-x-1/2 px-2 py-1 bg-green-600 text-white text-xs rounded"
                    >Copied!</span>
                </div>
                <p class="text-xs text-gray-500 mt-2">Click to copy</p>
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
                       pattern="[0-9]{6}"
                       maxlength="6"
                       autofocus
                       autocomplete="one-time-code"
                       class="w-full px-4 py-3 bg-gray-700 text-white text-center text-2xl tracking-widest border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       placeholder="000000">
            </div>

            {{-- Hidden default submit so Enter key fires Verify & Save, not Cancel --}}
            <button type="submit" class="hidden" tabindex="-1" aria-hidden="true">Verify & Save</button>

            <div class="flex gap-3">
                <button type="submit"
                        formaction="{{ route('settings.security.reset-totp.cancel') }}"
                        class="flex-1 px-4 py-2 text-center text-gray-300 hover:text-white border border-gray-600 rounded-lg transition-colors">
                    Cancel
                </button>
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
@endsection
