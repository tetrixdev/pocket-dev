@extends('layouts.auth')

@section('title', 'Create Account - PocketDev')

@section('content')
    <div class="text-center mb-8">
        <h1 class="text-2xl font-bold mb-2">Create your account</h1>
        <p class="text-gray-400">Enter your details to get started</p>
    </div>

    <x-wizard-steps :current="1" :labels="['Account', 'Two-factor', 'Recovery codes']" />

    @if($errors->any())
        <div class="mb-6 p-4 bg-red-900 border-l-4 border-red-500 text-red-200 rounded" role="alert" aria-live="polite">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('setup.credentials') }}" class="bg-gray-800 rounded-lg p-6 space-y-4"
          x-data="{ submitting: false }" @submit="submitting = true">
        @csrf

        <div>
            <label for="name" class="block text-sm font-medium mb-2">Name</label>
            <input
                type="text"
                id="name"
                name="name"
                value="{{ old('name') }}"
                required
                autofocus
                autocomplete="name"
                class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                placeholder="Your name"
            >
        </div>

        <div>
            <label for="email" class="block text-sm font-medium mb-2">Email</label>
            <input
                type="email"
                id="email"
                name="email"
                value="{{ old('email') }}"
                required
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
                minlength="8"
                autocomplete="new-password"
                aria-describedby="password-hint"
                class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                placeholder="Min. 8 characters"
            >
            <p id="password-hint" class="text-xs text-gray-500 mt-1">At least 8 characters</p>
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium mb-2">Confirm Password</label>
            <input
                type="password"
                id="password_confirmation"
                name="password_confirmation"
                required
                autocomplete="new-password"
                class="w-full px-3 py-2 bg-gray-700 text-white border border-gray-600 rounded focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                placeholder="Confirm your password"
            >
        </div>

        <div class="pt-2">
            <button
                type="submit"
                :disabled="submitting"
                :class="{ 'opacity-50 cursor-not-allowed': submitting }"
                class="w-full px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors"
            >
                <span x-show="!submitting">Continue</span>
                <span x-show="submitting" x-cloak>Saving…</span>
            </button>
        </div>

        <p class="text-center text-sm text-gray-500">
            Next, you'll set up two-factor authentication
        </p>
    </form>

    <div class="mt-4 text-center">
        <a href="{{ route('setup.index') }}" class="text-sm text-gray-400 hover:text-white">
            &larr; Back to other options
        </a>
    </div>
@endsection
