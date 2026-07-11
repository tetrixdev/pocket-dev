@extends('layouts.auth')

@section('title', 'Two-Factor Authentication - PocketDev')

@section('content')
    <div x-data="{ useRecovery: false, submitting: false }">
        <div class="text-center mb-8">
            <h1 class="text-2xl font-bold mb-2">Two-factor authentication</h1>
            <p class="text-gray-400" x-text="useRecovery ? 'Enter one of your recovery codes' : 'Enter the code from your authenticator app'"></p>
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

        <div class="bg-gray-800 rounded-lg p-6">
            {{-- TOTP Code Form --}}
            <form x-show="!useRecovery" method="POST" action="{{ route('two-factor.login') }}" class="space-y-4"
                  @submit="submitting = true">
                @csrf

                <div>
                    <label for="code" class="block text-sm font-medium mb-2">Authentication Code</label>
                    <input
                        type="text"
                        id="code"
                        name="code"
                        :required="!useRecovery"
                        autofocus
                        autocomplete="one-time-code"
                        inputmode="numeric"
                        pattern="[0-9]{6}"
                        maxlength="6"
                        class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent text-center text-xl tracking-widest font-mono"
                        placeholder="000000"
                    >
                </div>

                <button
                    type="submit"
                    :disabled="submitting"
                    :class="{ 'opacity-50 cursor-not-allowed': submitting }"
                    class="w-full px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors"
                >
                    <span x-show="!submitting">Verify</span>
                    <span x-show="submitting" x-cloak>Verifying…</span>
                </button>
            </form>

            {{-- Recovery Code Form --}}
            <form x-show="useRecovery" x-cloak method="POST" action="{{ route('two-factor.login') }}" class="space-y-4"
                  @submit="submitting = true">
                @csrf

                <div>
                    <label for="recovery_code" class="block text-sm font-medium mb-2">Recovery Code</label>
                    <input
                        type="text"
                        id="recovery_code"
                        name="recovery_code"
                        :required="useRecovery"
                        autocomplete="off"
                        autocapitalize="off"
                        autocorrect="off"
                        spellcheck="false"
                        class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent text-center font-mono"
                        placeholder="xxxxxxxxxx-xxxxxxxxxx"
                    >
                </div>

                <button
                    type="submit"
                    :disabled="submitting"
                    :class="{ 'opacity-50 cursor-not-allowed': submitting }"
                    class="w-full px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors"
                >
                    <span x-show="!submitting">Verify</span>
                    <span x-show="submitting" x-cloak>Verifying…</span>
                </button>
            </form>

            {{-- Toggle Link --}}
            <div class="mt-6 text-center">
                <button
                    type="button"
                    @click="useRecovery = !useRecovery"
                    class="text-sm text-blue-400 hover:text-blue-300"
                >
                    <span x-text="useRecovery ? 'Use authenticator app' : 'Use a recovery code'"></span>
                </button>
            </div>
        </div>

        <div class="mt-4 text-center">
            <form method="POST" action="{{ route('two-factor.cancel') }}">
                @csrf
                <button type="submit" class="text-sm text-gray-400 hover:text-white underline">
                    Cancel and sign out
                </button>
            </form>
        </div>
    </div>
@endsection
