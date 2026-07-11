@extends('layouts.auth')

@section('title', 'Login - PocketDev')

@section('content')
    <div class="text-center mb-8">
        <h1 class="text-3xl font-bold mb-2">PocketDev</h1>
        <p class="text-gray-400">Sign in to your account</p>
    </div>

    @if(session('status'))
        <div class="mb-6 p-4 bg-green-900 border-l-4 border-green-500 text-green-200 rounded" role="status">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div class="mb-6 p-4 bg-red-900 border-l-4 border-red-500 text-red-200 rounded" role="alert" aria-live="polite">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="bg-gray-800 rounded-lg p-6 space-y-4">
        <form method="POST" action="{{ route('login') }}" class="space-y-4"
              x-data="{ submitting: false }" @submit="submitting = true">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium mb-2">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="{{ old('email') }}"
                    required
                    autofocus
                    autocomplete="username"
                    class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    placeholder="you@example.com"
                >
            </div>

            <div>
                <label for="password" class="block text-sm font-medium mb-2">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                    placeholder="Your password"
                >
            </div>

            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input
                        type="checkbox"
                        name="remember"
                        class="w-4 h-4 rounded border-gray-600 bg-gray-700 text-blue-600 focus:ring-blue-500"
                    >
                    <span class="text-sm text-gray-300">Remember me</span>
                </label>
            </div>

            <button
                type="submit"
                :disabled="submitting"
                :class="{ 'opacity-50 cursor-not-allowed': submitting }"
                class="w-full px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors"
            >
                <span x-show="!submitting">Sign in</span>
                <span x-show="submitting" x-cloak>Signing in…</span>
            </button>
        </form>

        <p class="text-center text-xs text-gray-500 pt-2 border-t border-gray-700">
            Lost access to your authenticator? Use a recovery code on the next screen.
        </p>
    </div>
@endsection
