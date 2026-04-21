@extends('layouts.config')

@section('title', 'Change Password')

@section('content')
<div class="max-w-md mx-auto">
    <div class="text-center mb-8">
        <h2 class="text-xl font-semibold mb-2">Change Password</h2>
        <p class="text-gray-400 text-sm">Enter your current password and choose a new one</p>
    </div>

    <div class="bg-gray-800 rounded-lg p-6">
        @if($errors->any())
            <div class="mb-4 p-3 bg-red-900 border-l-4 border-red-500 text-red-200 rounded text-sm">
                @foreach($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        <form action="{{ route('settings.security.change-password') }}" method="POST" class="space-y-4">
            @csrf

            <div>
                <label for="current_password" class="block text-sm font-medium text-gray-300 mb-2">Current Password</label>
                <input type="password"
                       id="current_password"
                       name="current_password"
                       required
                       autofocus
                       autocomplete="current-password"
                       class="w-full px-4 py-3 bg-gray-700 text-white border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-300 mb-2">New Password</label>
                <input type="password"
                       id="password"
                       name="password"
                       required
                       minlength="8"
                       autocomplete="new-password"
                       class="w-full px-4 py-3 bg-gray-700 text-white border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <p class="mt-1 text-xs text-gray-500">Minimum 8 characters</p>
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-300 mb-2">Confirm New Password</label>
                <input type="password"
                       id="password_confirmation"
                       name="password_confirmation"
                       required
                       minlength="8"
                       autocomplete="new-password"
                       class="w-full px-4 py-3 bg-gray-700 text-white border border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            <div class="flex gap-3 pt-4">
                <a href="{{ route('settings.security') }}"
                   class="flex-1 px-4 py-2 text-center text-gray-300 hover:text-white border border-gray-600 rounded-lg transition-colors">
                    Cancel
                </a>
                <button type="submit"
                        class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                    Change Password
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
