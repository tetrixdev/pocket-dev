@extends('layouts.config')

@section('title', 'Add Password')

@section('content')
<div class="max-w-md mx-auto">
    <div class="mb-6">
        <a href="{{ route('settings.security') }}" class="text-sm text-gray-400 hover:text-white">
            &larr; Back to Security Settings
        </a>
    </div>

    <div class="text-center mb-8">
        <h2 class="text-xl font-semibold mb-2">Add a Password</h2>
        <p class="text-gray-400 text-sm">Set up email/password login as a backup authentication method</p>
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

    <form method="POST" action="{{ route('settings.security.add-password') }}" class="bg-gray-800 rounded-lg p-6 space-y-4">
        @csrf

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
                class="w-full px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors"
            >
                Continue to 2FA Setup
            </button>
        </div>

        <p class="text-center text-sm text-gray-500">
            You'll need to set up two-factor authentication for password login
        </p>
    </form>
</div>
@endsection
