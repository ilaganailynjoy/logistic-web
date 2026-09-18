<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * INVOIZ central entry point.
 *
 * Guests see the public landing page. Authenticated users never pick a
 * platform: their existing account role decides the destination,
 * server-side. Platform access itself stays enforced by the existing
 * route middleware — this controller only chooses where to send people.
 */
class CentralEntryController extends Controller
{
    public function index(): View|RedirectResponse
    {
        $user = auth()->user();

        if (! $user) {
            return view('landing');
        }

        $destination = self::destinationFor($user);

        if ($destination === null) {
            return view('landing');
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
     * external platforms), or null when the user should stay on the public
     * landing page (buyer/seller without a configured platform).
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
