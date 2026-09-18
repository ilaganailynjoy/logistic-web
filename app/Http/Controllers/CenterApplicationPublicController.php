<?php

namespace App\Http\Controllers;

use App\Models\LogisticsCenterApplicationDocument;
use App\Models\Province;
use App\Services\CenterApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Public Logistics web flows: "Open a Logistics Center" application and
 * "Check a Logistics Center Application Status". Both share the exact same
 * validation and persistence logic as the mobile API via
 * CenterApplicationService, so the two surfaces stay consistent.
 */
class CenterApplicationPublicController extends Controller
{
    public function create(): View
    {
        $provinces = Province::orderBy('name')->get(['id', 'name']);

        // Pre-render the cascading address options so that old() input from a
        // failed submission (or the initial GET) stays fully populated without
        // any client-side fetching. The page only fetches new options after the
        // applicant actually changes a selection.
        $municipalities = collect();
        $barangays = collect();

        if ($provinceName = old('province')) {
            $province = Province::where('name', $provinceName)->first();

            if ($province) {
                $municipalities = $province->municipalities()->orderBy('name')->get(['id', 'name']);

                if ($municipalityName = old('municipality')) {
                    $municipality = $municipalities->firstWhere('name', $municipalityName);

                    if ($municipality) {
                        $barangays = $municipality->barangays()->orderBy('name')->get(['id', 'name']);
                    }
                }
            }
        }

        return view('center-application-apply', [
            'documentLabels' => LogisticsCenterApplicationDocument::TYPES,
            'provinces' => $provinces,
            'municipalities' => $this->addressOptions($municipalities, old('municipality')),
            'barangays' => $this->addressOptions($barangays, old('barangay')),
        ]);
    }

    /**
     * Turn an Eloquent collection into option data for a cascading address
     * <select>. Values that are not in the local PSGC dataset (for example text
     * submitted before the address picker existed) are kept as a fallback option
     * so a failed submission round-trips exactly what the applicant entered.
     */
    private function addressOptions(Collection $items, ?string $selected): array
    {
        $options = $items
            ->map(fn ($item) => ['id' => $item->id, 'name' => $item->name])
            ->values()
            ->all();

        if ($selected !== null && $selected !== '' && ! collect($options)->contains(fn ($o) => $o['name'] === $selected)) {
            $options[] = ['id' => null, 'name' => $selected];
        }

        return $options;
    }

    public function store(Request $request, CenterApplicationService $service): RedirectResponse
    {
        $application = $service->create($request, 'web');
        $service->notifyNewApplication($application);

        return redirect()
            ->route('center-application.apply')
            ->with('success', 'Your Logistics Center application has been submitted successfully. Use the "Check a Logistics Center Application Status" page with the email you registered to track its progress.');
    }

    public function status(Request $request): View
    {
        return view('center-application-status', [
            'searched' => false,
        ]);
    }

    public function check(Request $request, CenterApplicationService $service): View
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        return view('center-application-status', [
            'searched' => true,
            'application' => $service->status($validated['email']),
            'email' => $validated['email'],
        ]);
    }
}