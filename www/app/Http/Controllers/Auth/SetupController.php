<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AppSettingsService;
use App\Services\TotpEnrollmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SetupController extends Controller
{
    use RendersSensitiveViews;

    public function __construct(
        protected AppSettingsService $settings,
        protected TotpEnrollmentService $totp,
    ) {}

    /**
     * Show the setup index page (email option + skip option).
     */
    public function index(Request $request): View|RedirectResponse
    {
        if ($redirect = $this->redirectIfAlreadySetUp($request)) {
            return $redirect;
        }

        return view('auth.setup.index');
    }

    /**
     * Skip authentication setup (for users behind VPN/other security).
     */
    public function skipAuth(Request $request): RedirectResponse
    {
        // Refuse if users exist OR a bypass is already active — the skip flow is
        // only valid as a first-run choice. Otherwise an unauthenticated visitor
        // could re-stamp the bypass via replay.
        if ($redirect = $this->redirectIfAlreadySetUp($request)) {
            return $redirect;
        }

        $request->validate([
            'dont_ask_again' => 'nullable|boolean',
        ]);

        if ($request->boolean('dont_ask_again')) {
            $this->settings->setAuthBypassPermanent(true);
        } else {
            $request->session()->put('auth_bypass_session', true);
        }

        return redirect()->route('chat.index');
    }

    /**
     * Show the email/password credentials form.
     */
    public function showCredentials(): View|RedirectResponse
    {
        if ($redirect = $this->redirectIfUsersExist()) {
            return $redirect;
        }

        return view('auth.setup.credentials');
    }

    /**
     * Store credentials in session and redirect to TOTP setup.
     */
    public function storeCredentials(Request $request): RedirectResponse
    {
        if ($redirect = $this->redirectIfUsersExist()) {
            return $redirect;
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email:rfc|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $request->session()->put('setup.pending_user', [
            'name' => $validated['name'],
            'email' => Str::lower($validated['email']),
            'password' => Hash::make($validated['password']),
        ]);

        $request->session()->put('setup.totp_secret', $this->totp->generateSecret());
        $request->session()->forget('setup.recovery_codes');

        return redirect()->route('setup.totp');
    }

    /**
     * Show the TOTP setup page with QR code.
     */
    public function showTotp(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectIfUsersExist()) {
            return $redirect;
        }

        if (!$request->session()->has('setup.pending_user') || !$request->session()->has('setup.totp_secret')) {
            return redirect()->route('setup.credentials');
        }

        $pendingUser = $request->session()->get('setup.pending_user');
        $secret = $request->session()->get('setup.totp_secret');

        return $this->sensitiveView('auth.setup.totp', [
            'secret' => $secret,
            'qrCodeSvg' => $this->totp->qrCodeSvg($pendingUser['email'], $secret),
            'email' => $pendingUser['email'],
        ]);
    }

    /**
     * Verify the TOTP code and proceed to recovery codes.
     */
    public function verifyTotp(Request $request): RedirectResponse
    {
        if ($redirect = $this->redirectIfUsersExist()) {
            return $redirect;
        }

        if (!$request->session()->has('setup.pending_user') || !$request->session()->has('setup.totp_secret')) {
            return redirect()->route('setup.credentials');
        }

        $validated = $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $secret = $request->session()->get('setup.totp_secret');

        if (!$this->totp->verifyCode($secret, $validated['code'])) {
            return back()->withErrors(['code' => 'Invalid verification code. Please try again.']);
        }

        $request->session()->put('setup.recovery_codes', $this->totp->generateRecoveryCodes());

        return redirect()->route('setup.recovery');
    }

    /**
     * Show the recovery codes page.
     */
    public function showRecovery(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectIfUsersExist()) {
            return $redirect;
        }

        if (!$request->session()->has('setup.pending_user') ||
            !$request->session()->has('setup.totp_secret') ||
            !$request->session()->has('setup.recovery_codes')) {
            return redirect()->route('setup.credentials');
        }

        return $this->sensitiveView('auth.setup.recovery', [
            'recoveryCodes' => $request->session()->get('setup.recovery_codes'),
        ]);
    }

    /**
     * Confirm recovery codes saved and create the user.
     */
    public function confirmRecovery(Request $request): RedirectResponse
    {
        if ($redirect = $this->redirectIfUsersExist()) {
            return $redirect;
        }

        if (!$request->session()->has('setup.pending_user') ||
            !$request->session()->has('setup.totp_secret') ||
            !$request->session()->has('setup.recovery_codes')) {
            return redirect()->route('setup.credentials');
        }

        $request->validate([
            'confirmed' => 'required|accepted',
        ]);

        $pendingUser = $request->session()->get('setup.pending_user');
        $secret = $request->session()->get('setup.totp_secret');
        $recoveryCodes = $request->session()->get('setup.recovery_codes');

        $user = DB::transaction(function () use ($pendingUser, $secret, $recoveryCodes) {
            $user = User::create([
                'name' => $pendingUser['name'],
                'email' => $pendingUser['email'],
                'password' => $pendingUser['password'],
            ]);

            $user->forceFill([
                'two_factor_secret' => $this->totp->encryptSecret($secret),
                'two_factor_recovery_codes' => $this->totp->encryptRecoveryCodes($recoveryCodes),
                'two_factor_confirmed_at' => now(),
            ])->save();

            return $user;
        });

        $request->session()->forget(['setup.pending_user', 'setup.totp_secret', 'setup.recovery_codes']);
        $this->settings->clearAuthBypass();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('chat.index');
    }

    private function redirectIfAlreadySetUp(Request $request): ?RedirectResponse
    {
        if ($redirect = $this->redirectIfUsersExist()) {
            return $redirect;
        }

        if ($this->settings->isAuthBypassPermanent()) {
            return redirect()->route('chat.index');
        }

        if ($request->session()->get('auth_bypass_session')) {
            return redirect()->route('chat.index');
        }

        return null;
    }

    private function redirectIfUsersExist(): ?RedirectResponse
    {
        return User::count() > 0 ? redirect()->route('login') : null;
    }
}
