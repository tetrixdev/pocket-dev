@extends('layouts.config')

@section('title', 'Set Up 2FA')

@section('content')
<div class="max-w-md mx-auto" x-data="{ showSecret: false, copied: false }">
    <div class="mb-6">
        <a href="{{ route('settings.security') }}" class="text-sm text-gray-400 hover:text-white">
            &larr; Back to Security Settings
        </a>
    </div>

    <div class="text-center mb-8">
        <h2 class="text-xl font-semibold mb-2">Set up two-factor authentication</h2>
        <p class="text-gray-400 text-sm">Scan this QR code with your authenticator app</p>
    </div>

    @if($errors->any())
        <div class="mb-6 p-4 bg-red-900 border-l-4 border-red-500 text-red-200 rounded" role="alert" aria-live="polite">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="bg-gray-800 rounded-lg p-6 space-y-6">
        {{-- QR Code --}}
        <div class="flex justify-center">
            <div class="bg-white p-4 rounded-lg">
                {!! $qrCodeSvg !!}
            </div>
        </div>

        {{-- Manual Entry Secret --}}
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

        {{-- Verification Form --}}
        <form method="POST" action="{{ route('settings.security.add-password.totp') }}">
            @csrf

            <div>
                <label for="code" class="block text-sm font-medium mb-2">Verification Code</label>
                <input
                    type="text"
                    id="code"
                    name="code"
                    required
                    autofocus
                    autocomplete="one-time-code"
                    inputmode="numeric"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent text-center text-xl tracking-widest font-mono"
                    placeholder="000000"
                >
                <p class="text-xs text-gray-500 mt-2 text-center">
                    Enter the 6-digit code from your authenticator app
                </p>
            </div>

            <div class="pt-4">
                <button
                    type="submit"
                    class="w-full px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors"
                >
                    Verify & Save
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
