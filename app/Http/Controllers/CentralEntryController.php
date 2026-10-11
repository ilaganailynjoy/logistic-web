<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * INVOIZ central entry point.
 *
 * There is no public landing page: guests go straight to the Logistics
 * login, and authenticated users never pick a platform — their existing
 * account role decides the destination, server-side. Accounts with no
 * logistics destination (buyer/seller without a configured platform) are
 * signed out back to login with an explanation instead of stranding them
 * on a missing page. Platform access itself stays enforced by the
 * existing route middleware — this controller only chooses where to send
 * people.
 */
class CentralEntryController extends Controller
{
    public function index(): RedirectResponse
    {
        $user = auth()->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $destination = self::destinationFor($user);

        if ($destination === null) {
            Auth::guard('web')->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();

            return redirect()->route('login')->with(
                'error',
                'This area is restricted to authorized logistics staff and riders.'
            );
        }

        return redirect($destination);
    }

    /**
     * Rider transit page: attempts the existing Rider App deep link with
     * the existing web fallback. Not a platform choice — riders only.
     * Protected by the existing rider.web middleware on the route.
     */
    public function riderEntry(): View
    {
        return view('rider.entry', [
            'deeplink' => config('invoiz.platforms.rider_deeplink', 'invoizrider://login'),
        ]);
    }

    /**
     * Resolve the post-login / central-entry destination for a user.
     *
     * Returns a URL (relative for internal routes, absolute for configured
     * external platforms), or null when the user has no logistics
     * destination (buyer/seller without a configured platform) and should
     * be sent back to login.
     */
    public static function destinationFor(User $user): ?string
    {
        if ($user->isAdmin() || $user->isStaff()) {
            return route('dashboard', absolute: false);
        }

        if ($user->role === 'rider') {
            return route('rider.entry', absolute: false);
        }

        if ($user->role === 'buyer') {
            return self::externalPlatformUrl('buyer_url');
        }

        if ($user->role === 'seller') {
            return self::externalPlatformUrl('seller_url');
        }

        return null;
    }

    /**
     * Buyer/Seller applications live outside this repository. Their URL is
     * a deployment-time integration point — never a fake local dashboard.
     */
    private static function externalPlatformUrl(string $key): ?string
    {
        $url = config("invoiz.platforms.{$key}");

        return is_string($url) && $url !== '' ? $url : null;
    }
}
