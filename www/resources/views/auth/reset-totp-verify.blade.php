@extends('layouts.config')

@section('title', 'Reset Two-Factor Authentication')

@section('content')
<div class="max-w-md mx-auto">
    <div class="text-center mb-8">
        <h2 class="text-xl font-semibold mb-2">Reset Two-Factor Authentication</h2>
        <p class="text-gray-400 text-sm">Enter your current 2FA code to continue</p>
    </div>

    <div class="bg-gray-800 rounded-lg p-6">
        @if($errors->any())
            <div class="mb-4 p-3 bg-red-900 border-l-4 border-red-500 text-red-200 rounded text-sm" role="alert" aria-live="polite">
                <ul class="list-disc list-inside space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="p-4 bg-yellow-900/30 border border-yellow-700/50 rounded-lg mb-6">
            <div class="flex gap-3">
                <svg class="w-5 h-5 text-yellow-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <div class="text-sm">
                    <p class="font-medium text-yellow-200">Verify your identity</p>
                    <p class="text-yellow-300/80 mt-1">Enter a code from your authenticator app to confirm you want to reset your 2FA.</p>
                </div>
            </div>
        </div>

        <form action="{{ route('settings.security.reset-totp') }}" method="POST">
            @csrf

            <div class="mb-4">
                <label for="current_password" class="block text-sm font-medium text-gray-300 mb-2">Current Password</label>
                <input type="password"
                       id="current_password"
                       name="current_password"
                       required
                       autocomplete="current-password"
                       class="w-full px-4 py-3 bg-gray-700 text-white border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                       placeholder="Enter your password">
            </div>

            <div class="mb-6">
                <label for="code" class="block text-sm font-medium text-gray-300 mb-2">Current 2FA Code</label>
                <input type="text"
                       id="code"
                       name="code"
                       inputmode="numeric"
                       pattern="[0-9]*"
                       maxlength="6"
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
                    Continue
                </button>
            </div>
        </form>
    </div>
</div>

<style>
    [x-cloak] { display: none !important; }
</style>
@endsection
