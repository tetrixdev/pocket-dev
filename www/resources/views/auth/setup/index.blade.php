@extends('layouts.auth')

@section('title', 'Welcome to PocketDev')

@section('content')
    <div x-data="{ showSkipForm: false }">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold mb-2">Welcome to PocketDev</h1>
            <p class="text-gray-400">Set up your admin account to secure your instance</p>
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
            {{-- Primary option: email/password setup --}}
            <div>
                <a href="{{ route('setup.credentials') }}"
                   class="flex items-center justify-center gap-3 w-full px-4 py-3 bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors font-medium">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                    <span>Set up authentication</span>
                </a>
                <p class="text-center text-sm text-gray-500 mt-2">
                    Email + password with two-factor authentication
                </p>
            </div>

            <div class="relative">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-gray-700"></div>
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="px-2 bg-gray-800 text-gray-500">or</span>
                </div>
            </div>

            {{-- Secondary option: skip authentication --}}
            <div>
                <button type="button"
                        @click="showSkipForm = !showSkipForm"
                        class="flex items-center justify-center gap-3 w-full px-4 py-3 bg-gray-700 hover:bg-gray-600 rounded-lg transition-colors">
                    <svg class="w-5 h-5 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <span class="font-medium">Skip authentication</span>
                    <svg class="w-4 h-4 transition-transform" :class="showSkipForm && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>

                <div x-show="showSkipForm" x-collapse class="mt-4">
                    <div class="p-4 bg-yellow-900/30 border border-yellow-700/50 rounded-lg mb-4">
                        <div class="flex gap-3">
                            <svg class="w-5 h-5 text-yellow-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                            <div class="text-sm">
                                <p class="font-medium text-yellow-200">Security Warning</p>
                                <p class="text-yellow-300/80 mt-1">
                                    Skipping authentication means anyone with access to this URL can use PocketDev.
                                    Only do this if you have other security measures in place (VPN, Tailscale, firewall, etc.).
                                </p>
                            </div>
                        </div>
                    </div>

                    <form action="{{ route('setup.skip') }}" method="POST" class="space-y-4"
                          x-data="{ submitting: false }" @submit="submitting = true">
                        @csrf

                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox"
                                   name="dont_ask_again"
                                   value="1"
                                   class="mt-1 w-4 h-4 bg-gray-700 border-gray-600 rounded text-blue-600 focus:ring-blue-500 focus:ring-offset-gray-800">
                            <span class="text-sm text-gray-300">
                                Don't show this screen again
                                <span class="block text-xs mt-0.5 text-gray-500">
                                    Confirms you accept the risk and have secured PocketDev by other means.
                                </span>
                            </span>
                        </label>

                        <button type="submit"
                                :disabled="submitting"
                                :class="{ 'opacity-50 cursor-not-allowed': submitting }"
                                class="w-full px-4 py-2 bg-yellow-600 hover:bg-yellow-700 text-white rounded-lg font-medium transition-colors">
                            Continue without authentication
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <p class="mt-6 text-center text-sm text-gray-500">
            Authentication can be configured or changed anytime in:<br>
            Settings &rarr; Security
        </p>
    </div>
@endsection
