@extends('layouts.auth')

@section('title', 'Recovery Codes - PocketDev')

@section('content')
    <div x-data="{ copied: false, confirmed: false, submitting: false }">
        <div class="text-center mb-8">
            <h1 class="text-2xl font-bold mb-2">Save your recovery codes</h1>
            <p class="text-gray-400">Keep these codes safe. You can use them to access your account if you lose your authenticator.</p>
        </div>

        <x-wizard-steps :current="3" :labels="['Account', 'Two-factor', 'Recovery codes']" />

        <div class="bg-gray-800 rounded-lg p-6 space-y-6">
            {{-- Recovery codes --}}
            <div class="relative">
                <div class="grid grid-cols-2 gap-2 p-4 bg-gray-700 rounded-lg font-mono text-sm">
                    @foreach($recoveryCodes as $code)
                        <div class="px-2 py-1 bg-gray-800 rounded text-center">{{ $code }}</div>
                    @endforeach
                </div>

                <button
                    type="button"
                    @click="navigator.clipboard.writeText(@js($recoveryCodes).join('\n')); copied = true; setTimeout(() => copied = false, 2000)"
                    class="absolute top-2 right-2 p-2 text-gray-400 hover:text-white transition-colors"
                    title="Copy all codes"
                >
                    <template x-if="!copied">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                        </svg>
                    </template>
                    <template x-if="copied">
                        <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    </template>
                </button>
            </div>

            <div class="p-4 bg-yellow-900/30 border border-yellow-700/50 rounded-lg">
                <div class="flex gap-3">
                    <svg class="w-5 h-5 text-yellow-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <div class="text-sm text-yellow-200">
                        <p class="font-medium">Each code can only be used once.</p>
                        <p class="text-yellow-300/80 mt-1">Store these codes in a password manager or secure location. Without these, you may lose access to your account.</p>
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('setup.recovery') }}" @submit="submitting = true">
                @csrf

                <label class="flex items-start gap-3 cursor-pointer mb-4">
                    <input
                        type="checkbox"
                        x-model="confirmed"
                        class="mt-1 w-4 h-4 rounded border-gray-600 bg-gray-700 text-blue-600 focus:ring-blue-500"
                    >
                    <span class="text-sm text-gray-300">I have saved these recovery codes in a safe place</span>
                </label>

                <button
                    type="submit"
                    :disabled="!confirmed || submitting"
                    :class="{ 'opacity-50 cursor-not-allowed': !confirmed || submitting }"
                    class="w-full px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors disabled:hover:bg-blue-600"
                >
                    <span x-show="!submitting">Complete Setup</span>
                    <span x-show="submitting" x-cloak>Creating account…</span>
                </button>
            </form>
        </div>
    </div>
@endsection
