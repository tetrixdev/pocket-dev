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

class SecuritySettingsController extends Controller
{
    use RendersSensitiveViews;

    private const PENDING_KEY = 'pending_2fa_commit';

    /** Seconds a stashed recovery-code commit stays valid before requiring restart. */
    private const PENDING_TTL = 600;

    public function __construct(
        protected AppSettingsService $settings,
        protected TotpEnrollmentService $totp,
    ) {}

    public function index(Request $request): View
    {
        return view('auth.security', [
            'user' => Auth::user(),
            'isAuthBypassPermanent' => $this->settings->isAuthBypassPermanent(),
            'isAuthBypassSession' => (bool) $request->session()->get('auth_bypass_session', false),
        ]);
    }

    /**
     * Read the pending recovery-code commit from session, enforcing a TTL so
     * abandoned-and-forgotten stashes can't be picked up later.
     */
    private function readPendingCommit(Request $request): ?array
    {
        $pending = $request->session()->get(self::PENDING_KEY);
        if (!$pending || empty($pending['codes']) || empty($pending['flow'])) {
            return null;
        }

        $stagedAt = (int) ($pending['staged_at'] ?? 0);
        if ($stagedAt <= 0 || (time() - $stagedAt) > self::PENDING_TTL) {
            $request->session()->forget(self::PENDING_KEY);
            return null;
        }

        return $pending;
    }

    // =========================================================================
    // Add password (for passwordless/OAuth accounts): step 1 = password, step 2 = TOTP.
    // =========================================================================

    public function showAddPassword(): View|RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->hasPassword()) {
            return redirect()->route('settings.security')->withErrors([
                'security' => 'You already have a password set.',
            ]);
        }

        return view('auth.add-password');
    }

    public function storeAddPasswordStep(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->hasPassword()) {
            return redirect()->route('settings.security')->withErrors([
                'security' => 'You already have a password set.',
            ]);
        }

        $validated = $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        $request->session()->put('add_password.password', Hash::make($validated['password']));
        $request->session()->put('add_password.totp_secret', $this->totp->generateSecret());

        return redirect()->route('settings.security.add-password.totp');
    }

    public function showAddPasswordTotp(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->hasPassword()) {
            return redirect()->route('settings.security');
        }

        if (!$request->session()->has('add_password.totp_secret') || !$request->session()->has('add_password.password')) {
            return redirect()->route('settings.security.add-password');
        }

        $secret = $request->session()->get('add_password.totp_secret');

        return $this->sensitiveView('auth.add-password-totp', [
            'secret' => $secret,
            'qrCodeSvg' => $this->totp->qrCodeSvg($user->email, $secret),
            'email' => $user->email,
        ]);
    }

    public function verifyAddPasswordTotp(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->hasPassword()) {
            return redirect()->route('settings.security');
        }

        if (!$request->session()->has('add_password.totp_secret') || !$request->session()->has('add_password.password')) {
            return redirect()->route('settings.security.add-password');
        }

        $validated = $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $secret = $request->session()->get('add_password.totp_secret');
        $password = $request->session()->get('add_password.password');

        if (!$this->totp->verifyCode($secret, $validated['code'])) {
            return back()->withErrors(['code' => 'Invalid verification code. Please try again.']);
        }

        $request->session()->forget(['add_password.password', 'add_password.totp_secret']);

        $request->session()->put(self::PENDING_KEY, [
            'flow' => 'add_password',
            'codes' => $this->totp->generateRecoveryCodes(),
            'new_secret' => $secret,
            'new_password' => $password,
            'staged_at' => time(),
        ]);

        return redirect()->route('settings.security.recovery-codes');
    }

    // =========================================================================
    // Recovery codes preview + acknowledgment commit
    // =========================================================================

    public function showRecoveryCodes(Request $request): Response|RedirectResponse
    {
        $pending = $this->readPendingCommit($request);

        if (!$pending) {
            return redirect()->route('settings.security');
        }

        return $this->sensitiveView('auth.recovery-codes', [
            'recoveryCodes' => $pending['codes'],
        ]);
    }

    public function acknowledgeRecoveryCodes(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $pending = $this->readPendingCommit($request);

        if (!$pending) {
            return redirect()->route('settings.security');
        }

        DB::transaction(function () use ($user, $pending) {
            $updates = [
                'two_factor_recovery_codes' => $this->totp->encryptRecoveryCodes($pending['codes']),
            ];

            if ($pending['flow'] === 'add_password' || $pending['flow'] === 'reset_totp') {
                $updates['two_factor_secret'] = $this->totp->encryptSecret($pending['new_secret']);
                $updates['two_factor_confirmed_at'] = now();
            }

            if ($pending['flow'] === 'add_password' && !empty($pending['new_password'])) {
                $updates['password'] = $pending['new_password'];
                // Rotate remember_token alongside a freshly set password so any
                // prior "remember me" cookie loses validity.
                $updates['remember_token'] = Str::random(60);
            }

            $user->forceFill($updates)->save();
        });

        $request->session()->forget(self::PENDING_KEY);

        if ($pending['flow'] === 'add_password') {
            $request->session()->regenerate();
            $this->invalidateOtherSessions($request, $user);
        }

        return redirect()->route('settings.security')
            ->with('success', 'Two-factor authentication updated successfully.');
    }

    // =========================================================================
    // Regenerate recovery codes (requires password)
    // =========================================================================

    public function regenerateRecovery(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (!$user->hasTwoFactorEnabled()) {
            return back()->withErrors([
                'security' => 'Two-factor authentication is not enabled.',
            ]);
        }

        $request->validate([
            'current_password' => ['required', 'current_password'],
        ]);

        $request->session()->put(self::PENDING_KEY, [
            'flow' => 'regenerate',
            'codes' => $this->totp->generateRecoveryCodes(),
            'staged_at' => time(),
        ]);

        return redirect()->route('settings.security.recovery-codes');
    }

    // =========================================================================
    // Reset TOTP: step 1 = verify current password + TOTP, step 2 = new QR + new code.
    // =========================================================================

    public function showResetTotp(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (!$user->hasTwoFactorEnabled()) {
            return redirect()->route('settings.security')->withErrors([
                'security' => 'Two-factor authentication is not enabled.',
            ]);
        }

        return view('auth.reset-totp-verify');
    }

    public function verifyCurrentTotp(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (!$user->hasTwoFactorEnabled()) {
            return redirect()->route('settings.security');
        }

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'code' => 'required|string|size:6',
        ]);

        $currentSecret = $this->totp->decryptSecret($user->two_factor_secret);

        if (!$this->totp->verifyCode($currentSecret, $validated['code'])) {
            return back()->withErrors(['code' => 'Invalid verification code. Please try again.']);
        }

        $request->session()->put('reset_totp.verified', true);

        return redirect()->route('settings.security.reset-totp.new');
    }

    public function showResetTotpNew(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (!$user->hasTwoFactorEnabled() || !$request->session()->get('reset_totp.verified')) {
            return redirect()->route('settings.security.reset-totp');
        }

        $secret = $request->session()->get('reset_totp.new_secret');
        if (!$secret) {
            $secret = $this->totp->generateSecret();
            $request->session()->put('reset_totp.new_secret', $secret);
        }

        return $this->sensitiveView('auth.reset-totp-setup', [
            'secret' => $secret,
            'qrCodeSvg' => $this->totp->qrCodeSvg($user->email, $secret),
            'email' => $user->email,
        ]);
    }

    public function confirmResetTotpNew(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (!$user->hasTwoFactorEnabled() || !$request->session()->get('reset_totp.verified')) {
            return redirect()->route('settings.security.reset-totp');
        }

        $validated = $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $newSecret = $request->session()->get('reset_totp.new_secret');
        if (!$newSecret) {
            return redirect()->route('settings.security.reset-totp.new');
        }

        if (!$this->totp->verifyCode($newSecret, $validated['code'])) {
            return back()->withErrors(['code' => 'Invalid verification code. Please try again.']);
        }

        $request->session()->forget(['reset_totp.verified', 'reset_totp.new_secret']);

        $request->session()->put(self::PENDING_KEY, [
            'flow' => 'reset_totp',
            'codes' => $this->totp->generateRecoveryCodes(),
            'new_secret' => $newSecret,
            'staged_at' => time(),
        ]);

        return redirect()->route('settings.security.recovery-codes');
    }

    public function cancelResetTotp(Request $request): RedirectResponse
    {
        $request->session()->forget(['reset_totp.verified', 'reset_totp.new_secret']);
        return redirect()->route('settings.security');
    }

    // =========================================================================
    // Session hygiene
    // =========================================================================

    /**
     * Log out all other sessions.
     * Only works on the database session driver.
     */
    public function logoutOtherSessions(Request $request): RedirectResponse
    {
        if (config('session.driver') !== 'database') {
            return back()->withErrors([
                'security' => 'Logging out other sessions requires the database session driver.',
            ]);
        }

        $request->validate([
            'current_password' => ['required', 'current_password'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        $this->invalidateOtherSessions($request, $user);

        return back()->with('success', 'All other sessions have been logged out.');
    }

    // =========================================================================
    // Change password
    // =========================================================================

    public function showChangePassword(): View
    {
        return view('auth.change-password');
    }

    /**
     * Change the user's password.
     * Rotates the current session and invalidates all other sessions (on database driver).
     */
    public function changePassword(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => 'required|string|min:8|confirmed',
        ]);

        DB::transaction(function () use ($user, $validated) {
            // Rotate remember_token alongside the password so any "remember me" cookie
            // issued before the change stops working. The User model's 'hashed' cast
            // handles bcrypt hashing automatically.
            $user->forceFill([
                'password' => $validated['password'],
                'remember_token' => Str::random(60),
            ])->save();
        });

        // Drop any half-completed Fortify login state (the 2FA challenge staging keys).
        $request->session()->forget(['login.id', 'login.remember']);

        // Rotate current session to prevent fixation, then drop all other sessions for this
        // user so a stolen cookie can't outlive a password change.
        $request->session()->regenerate();
        $this->invalidateOtherSessions($request, $user);

        return redirect()->route('settings.security')->with('success', 'Password changed successfully.');
    }

    // =========================================================================
    // Disable authentication
    // =========================================================================

    /**
     * Disable authentication entirely (delete the user and enable permanent bypass).
     * Requires password + current TOTP since this removes the auth system.
     */
    public function disableAuth(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $rules = [
            'current_password' => ['required', 'current_password'],
            'confirm' => ['required', 'accepted'],
        ];

        if ($user->hasTwoFactorEnabled()) {
            $rules['code'] = ['required', 'string', 'size:6'];
        }

        $validated = $request->validate($rules);

        if ($user->hasTwoFactorEnabled()) {
            $currentSecret = $this->totp->decryptSecret($user->two_factor_secret);
            if (!$this->totp->verifyCode($currentSecret, $validated['code'])) {
                return back()->withErrors(['code' => 'Invalid verification code.']);
            }
        }

        DB::transaction(function () use ($user) {
            $user->delete();
            $this->settings->setAuthBypassPermanent(true);
        });

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('chat.index');
    }

    /**
     * Delete all other database sessions for this user, leaving only the
     * current session alive. No-ops silently on non-database drivers.
     */
    private function invalidateOtherSessions(Request $request, User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table('sessions')
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }
    }
}
