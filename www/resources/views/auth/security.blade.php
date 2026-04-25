@extends('layouts.config')

@section('title', 'Security Settings')

@section('content')
@php
    $isAuthBypassed = $isAuthBypassPermanent || $isAuthBypassSession;
    $totpEnabled = $user && $user->hasTwoFactorEnabled();
@endphp
<div class="space-y-6" x-data="{ showDisableAuth: false, showRegenerate: false, showLogoutSessions: false }">
    <div>
        <h2 class="text-xl font-semibold mb-1">Security Settings</h2>
        <p class="text-gray-400 text-sm">Manage your authentication methods</p>
    </div>

    @if(session('success'))
        <div class="p-4 bg-green-900 border-l-4 border-green-500 text-green-200 rounded" role="status">
            {{ session('success') }}
        </div>
    @endif

    {{-- Authentication Bypass Warning --}}
    @if($isAuthBypassed && !$user)
        <div class="bg-yellow-900/30 border border-yellow-700/50 rounded-lg p-6">
            <div class="flex gap-4">
                <svg class="w-6 h-6 text-yellow-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <div class="flex-1">
                    <h3 class="text-lg font-medium text-yellow-200 mb-2">Authentication Disabled</h3>
                    <p class="text-yellow-300/80 text-sm mb-4">
                        PocketDev is currently running without authentication. Anyone with access to this URL can use it.
                        @if($isAuthBypassPermanent)
                            <span class="block mt-1 text-yellow-400/60">You chose "Don't show this again" during setup.</span>
                        @elseif($isAuthBypassSession)
                            <span class="block mt-1 text-yellow-400/60">This bypass is temporary and will expire when your session ends.</span>
                        @endif
                    </p>
                    <a href="{{ route('setup.index') }}"
                       class="inline-flex items-center gap-2 px-4 py-2 bg-yellow-600 hover:bg-yellow-700 text-white rounded-lg font-medium transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                        Set up authentication now
                    </a>
                </div>
            </div>
        </div>
    @endif

    @if($user)
        {{-- Password & 2FA --}}
        <div class="bg-gray-800 rounded-lg p-6">
            <h3 class="text-lg font-medium mb-4">Password & Two-Factor Authentication</h3>

            <div class="space-y-4">
                @if($user->hasPassword())
                    <div class="flex items-center justify-between py-3">
                        <div>
                            <p class="font-medium">Password</p>
                            <p class="text-sm text-green-400">Set</p>
                        </div>
                        <a href="{{ route('settings.security.change-password') }}" class="px-3 py-1.5 text-sm text-blue-400 hover:text-blue-300 border border-blue-400/30 hover:border-blue-300/50 rounded transition-colors">
                            Change password
                        </a>
                    </div>

                    <div class="flex items-center justify-between py-3 border-t border-gray-700">
                        <div>
                            <p class="font-medium">Two-Factor Authentication</p>
                            @if($totpEnabled)
                                <p class="text-sm text-green-400">Enabled</p>
                            @else
                                <p class="text-sm text-yellow-400">Required but not set up</p>
                            @endif
                        </div>
                        @if($totpEnabled)
                            <div class="flex gap-2">
                                <a href="{{ route('settings.security.reset-totp') }}" class="px-3 py-1.5 text-sm text-yellow-400 hover:text-yellow-300 border border-yellow-400/30 hover:border-yellow-300/50 rounded transition-colors">
                                    Reset 2FA
                                </a>
                                <button type="button"
                                        @click="showRegenerate = true"
                                        class="px-3 py-1.5 text-sm text-blue-400 hover:text-blue-300 border border-blue-400/30 hover:border-blue-300/50 rounded transition-colors">
                                    Regenerate codes
                                </button>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="text-center py-4">
                        <p class="text-gray-400 mb-4">No password is set. Add a password to enable email login.</p>
                        <a href="{{ route('settings.security.add-password') }}" class="inline-flex px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                            Add Password
                        </a>
                    </div>
                @endif
            </div>
        </div>

        {{-- Sessions --}}
        <div class="bg-gray-800 rounded-lg p-6">
            <h3 class="text-lg font-medium mb-4">Sessions</h3>

            <div class="flex items-center justify-between">
                <div>
                    <p class="font-medium">Other Browser Sessions</p>
                    <p class="text-sm text-gray-400">Log out of all other devices and browsers</p>
                </div>
                <button type="button"
                        @click="showLogoutSessions = true"
                        class="px-3 py-1.5 text-sm text-red-400 hover:text-red-300 border border-red-400/30 hover:border-red-300/50 rounded transition-colors">
                    Logout other sessions
                </button>
            </div>
        </div>

        {{-- Danger Zone --}}
        <div class="bg-red-900/20 border border-red-700/50 rounded-lg p-6">
            <h3 class="text-lg font-medium text-red-400 mb-4">Danger Zone</h3>

            <div class="flex items-center justify-between flex-wrap gap-3">
                <div>
                    <p class="font-medium">Disable Authentication</p>
                    <p class="text-sm text-gray-400">Remove your account and run PocketDev without login</p>
                </div>
                <button type="button"
                        @click="showDisableAuth = true"
                        class="px-3 py-1.5 text-sm text-red-400 hover:text-red-300 border border-red-400/30 hover:border-red-300/50 rounded transition-colors">
                    Disable authentication
                </button>
            </div>
        </div>

        {{-- Regenerate Recovery Codes Modal --}}
        <template x-teleport="body">
            <div x-show="showRegenerate" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
                 role="dialog" aria-modal="true" aria-labelledby="regenerate-title"
                 @keydown.escape.window="showRegenerate = false">
                <div class="absolute inset-0 bg-black/70" @click="showRegenerate = false"></div>
                <div class="relative bg-gray-800 rounded-lg p-6 max-w-md w-full">
                    <h3 id="regenerate-title" class="text-lg font-medium text-blue-300 mb-2">Regenerate recovery codes?</h3>
                    <p class="text-gray-400 text-sm mb-4">
                        New codes will replace your current set. Your existing codes will continue to work until you confirm the new ones on the next screen.
                    </p>
                    <form action="{{ route('settings.security.regenerate-recovery') }}" method="POST"
                          x-data="{ submitting: false }" @submit="submitting = true">
                        @csrf
                        <div class="mb-4">
                            <label for="regenerate_password" class="block text-sm font-medium text-gray-300 mb-1">
                                Enter your password to continue
                            </label>
                            <input type="password" name="current_password" id="regenerate_password" required
                                   autocomplete="current-password"
                                   class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-lg text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div class="flex gap-3 justify-end">
                            <button type="button" @click="showRegenerate = false" class="px-4 py-2 text-gray-300 hover:text-white transition-colors">
                                Cancel
                            </button>
                            <button type="submit"
                                    :disabled="submitting"
                                    :class="{ 'opacity-50 cursor-not-allowed': submitting }"
                                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                                Continue
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </template>

        {{-- Logout Other Sessions Modal --}}
        <template x-teleport="body">
            <div x-show="showLogoutSessions" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
                 role="dialog" aria-modal="true" aria-labelledby="logout-sessions-title"
                 @keydown.escape.window="showLogoutSessions = false">
                <div class="absolute inset-0 bg-black/70" @click="showLogoutSessions = false"></div>
                <div class="relative bg-gray-800 rounded-lg p-6 max-w-md w-full">
                    <h3 id="logout-sessions-title" class="text-lg font-medium text-red-300 mb-2">Logout other sessions?</h3>
                    <p class="text-gray-400 text-sm mb-4">
                        This will log out all other devices and browsers. Your current session will not be affected.
                    </p>
                    <form action="{{ route('settings.security.logout-other-sessions') }}" method="POST"
                          x-data="{ submitting: false }" @submit="submitting = true">
                        @csrf
                        <div class="mb-4">
                            <label for="logout_sessions_password" class="block text-sm font-medium text-gray-300 mb-1">
                                Enter your password to continue
                            </label>
                            <input type="password" name="current_password" id="logout_sessions_password" required
                                   autocomplete="current-password"
                                   class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-lg text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
                        </div>
                        <div class="flex gap-3 justify-end">
                            <button type="button" @click="showLogoutSessions = false" class="px-4 py-2 text-gray-300 hover:text-white transition-colors">
                                Cancel
                            </button>
                            <button type="submit"
                                    :disabled="submitting"
                                    :class="{ 'opacity-50 cursor-not-allowed': submitting }"
                                    class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium transition-colors">
                                Logout other sessions
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </template>

        {{-- Disable Auth Confirmation Modal --}}
        <template x-teleport="body">
            <div x-show="showDisableAuth" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
                 role="dialog" aria-modal="true" aria-labelledby="disable-auth-title"
                 @keydown.escape.window="showDisableAuth = false">
                <div class="absolute inset-0 bg-black/70" @click="showDisableAuth = false"></div>
                <div class="relative bg-gray-800 rounded-lg p-6 max-w-md w-full">
                    <h3 id="disable-auth-title" class="text-lg font-medium text-red-400 mb-2">Disable Authentication?</h3>
                    <p class="text-gray-400 text-sm mb-4">
                        This will permanently delete your account and allow anyone with access to this URL to use PocketDev without logging in.
                    </p>
                    <div class="p-4 bg-yellow-900/30 border border-yellow-700/50 rounded-lg mb-4">
                        <p class="text-yellow-300/80 text-sm">
                            <strong>Only do this if:</strong> You have other security measures in place (VPN, Tailscale, firewall, etc.) or you're running PocketDev locally.
                        </p>
                    </div>
                    <form action="{{ route('settings.security.disable-auth') }}" method="POST"
                          x-data="{ confirmed: false, submitting: false }" @submit="submitting = true">
                        @csrf
                        @method('DELETE')

                        <div class="mb-4">
                            <label for="disable_password" class="block text-sm font-medium text-gray-300 mb-1">
                                Current password
                            </label>
                            <input type="password" name="current_password" id="disable_password" required
                                   autocomplete="current-password"
                                   class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-lg text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent">
                        </div>

                        @if($totpEnabled)
                            <div class="mb-4">
                                <label for="disable_code" class="block text-sm font-medium text-gray-300 mb-1">
                                    Current 2FA code
                                </label>
                                <input type="text" name="code" id="disable_code" required
                                       inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                                       autocomplete="one-time-code"
                                       class="w-full px-3 py-2 bg-gray-700 border border-gray-600 rounded-lg text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent text-center font-mono tracking-widest"
                                       placeholder="000000">
                            </div>
                        @endif

                        <label class="flex items-start gap-3 cursor-pointer mb-4 text-sm text-gray-300">
                            <input type="checkbox" name="confirm" value="1" x-model="confirmed" required
                                   class="mt-1 w-4 h-4 rounded border-gray-600 bg-gray-700 text-red-600 focus:ring-red-500">
                            <span>I understand this deletes my account and cannot be undone.</span>
                        </label>

                        <div class="flex gap-3 justify-end">
                            <button type="button" @click="showDisableAuth = false" class="px-4 py-2 text-gray-300 hover:text-white transition-colors">
                                Cancel
                            </button>
                            <button type="submit"
                                    :disabled="!confirmed || submitting"
                                    :class="{ 'opacity-50 cursor-not-allowed': !confirmed || submitting }"
                                    class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium transition-colors">
                                Yes, disable authentication
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </template>
    @elseif(!$isAuthBypassed)
        {{-- No user logged in and auth is NOT bypassed - show setup prompt --}}
        <div class="bg-gray-800 rounded-lg p-6 text-center">
            <svg class="w-12 h-12 text-gray-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
            </svg>
            <h3 class="text-lg font-medium mb-2">No Account Created</h3>
            <p class="text-gray-400 text-sm mb-4">
                Set up an admin account to secure your PocketDev instance.
            </p>
            <a href="{{ route('setup.index') }}"
               class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                </svg>
                Create Admin Account
            </a>
        </div>
    @endif
</div>
@endsection
