<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Security\Audit\AuditLog;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST /logout: ends the current session server-side (invalidate, not just
 * forget the cookie) and rotates the CSRF token. S1-04's navigation posts
 * to `route('logout')`.
 */
final class LogoutController extends Controller
{
    public function __invoke(Request $request, AuthFactory $auth, AuditLog $audit): RedirectResponse
    {
        $guard = $auth->guard('web');
        $userId = $guard->id();

        $guard->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (is_string($userId)) {
            $audit->record('auth.logged_out', "user:{$userId}", AuditLog::OUTCOME_OK, 'user', $userId, ['ip_address' => $request->ip()]);
        }

        return redirect()->route('login');
    }
}
