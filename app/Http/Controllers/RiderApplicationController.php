<?php

namespace App\Http\Controllers;

use App\Models\Rider;
use App\Models\RiderApplication;
use App\Models\RiderApplicationDocument;
use App\Models\RiderApplicationLog;
use App\Models\Notification;
use App\Models\VehicleType;
use App\Rules\PhilippinePhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RiderApplicationController extends Controller
{
    public function index(Request $request): View
    {
        $perPage = in_array((int) $request->query('per_page'), [10, 25, 50]) ? (int) $request->query('per_page') : 10;
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));

        $sortMap = [
            'name' => 'name',
            'status' => 'status',
            'date' => 'created_at',
        ];
        $sort = $sortMap[(string) $request->query('sort', '')] ?? 'created_at';
        $dir = strtolower($request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = RiderApplication::query();

        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
            $query->where(function ($w) use ($like) {
                $w->where('name', 'like', $like)
                  ->orWhere('email', 'like', $like)
                  ->orWhere('phone', 'like', $like)
                  ->orWhere('vehicle_type', 'like', $like);
            });
        }

        if ($status !== '' && in_array($status, ['pending', 'approved', 'rejected'])) {
            $query->where('status', $status);
        }

        if ($dateFrom !== '' && strtotime($dateFrom)) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo !== '' && strtotime($dateTo)) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $applications = $query->orderBy($sort, $dir)->orderBy('id', $dir)->paginate($perPage)->withQueryString();

        return view('rider-applications.index', [
            'applications' => $applications,
        ]);
    }

    public function show(RiderApplication $riderApplication): View
    {
        $riderApplication->load(['logs.changer', 'supportingDocuments']);

        return view('rider-applications.show', [
            'application' => $riderApplication,
        ]);
    }

    public function create(): View
    {
        return view('rider-applications.create', [
            'vehicleTypes' => VehicleType::activeLabels(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'vehicle_type' => strtolower(trim((string) $request->input('vehicle_type'))),
        ]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:riders,email',
            'phone' => ['required', new PhilippinePhone, 'unique:rider_applications,phone'],
            'address' => 'required|string|max:500',
            'vehicle_type' => 'required|in:' . implode(',', array_keys(VehicleType::activeLabels())),
            'license_plate' => 'required|string|max:20',
            'license_number' => 'required|string|max:100',
            'vehicle_registration' => 'required|string|max:100',
            'documents' => 'required|array',
            'documents.valid_id' => 'required|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
            'documents.drivers_license' => 'required|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
            'documents.vehicle_registration' => 'required|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
            'documents.proof_of_address' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
            'documents.other' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
        ], [
            'documents.required' => 'Please upload the required supporting documents.',
            'documents.valid_id.required' => 'Please upload a copy of your valid ID.',
            'documents.drivers_license.required' => 'Please upload a photo of your driver license.',
            'documents.vehicle_registration.required' => 'Please upload your vehicle registration document.',
        ]);

        $data['phone'] = PhilippinePhone::normalize($data['phone']);
        $data['status'] = 'pending';
        $data['documents'] = []; // legacy JSON column kept empty for new applications

        $application = DB::transaction(function () use ($data, $request) {
            $application = RiderApplication::create($data);

            foreach (array_keys(\App\Models\RiderApplicationDocument::TYPES) as $type) {
                $file = $request->file("documents.$type");

                if (!$file || !$file->isValid()) {
                    continue;
                }

                $filename = Str::uuid() . '.' . strtolower($file->getClientOriginalExtension());
                $storedPath = $file->storeAs("rider-documents/{$application->id}", $filename);

                \App\Models\RiderApplicationDocument::create([
                    'rider_application_id' => $application->id,
                    'document_type' => $type,
                    'original_filename' => $file->getClientOriginalName(),
                    'stored_path' => $storedPath,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                ]);
            }

            return $application;
        });

        Notification::create([
            'type' => 'new_rider_application',
            'title' => 'New Rider Application',
            'message' => "A new rider application from {$application->name} is waiting for review.",
            'icon' => '🧑‍✈️',
            'priority' => 'high',
            'link' => route('rider-applications.show', $application),
        ]);

        return redirect()->route('rider-applications.create')->with('success', 'Application submitted successfully. We will review it shortly.');
    }

    /**
     * Stream a supporting document inline (images / PDFs) for preview.
     * Access is limited to authenticated Logistics staff and the file must
     * belong to the given application.
     */
    public function viewDocument(RiderApplication $riderApplication, RiderApplicationDocument $document): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return $this->serveDocument($riderApplication, $document, 'inline');
    }

    /**
     * Force a supporting document download.
     */
    public function downloadDocument(RiderApplication $riderApplication, RiderApplicationDocument $document): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        return $this->serveDocument($riderApplication, $document, 'attachment');
    }

    private function serveDocument(RiderApplication $riderApplication, RiderApplicationDocument $document, string $disposition): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless($document->rider_application_id === $riderApplication->id, 404);
        abort_unless($document->fileExists(), 404, 'Document file is missing.');

        return response()->file($document->absolutePath(), [
            'Content-Type' => $document->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => $disposition . '; filename="' . basename($document->original_filename) . '"',
        ]);
    }

    public function approve(Request $request, RiderApplication $riderApplication): RedirectResponse
    {
        if ($riderApplication->status !== 'pending') {
            return back()->with('error', "Only pending applications can be approved. This one is currently \"{$riderApplication->status}\".");
        }

        DB::transaction(function () use ($riderApplication) {
            Rider::create([
                'name' => $riderApplication->name,
                'email' => $riderApplication->email,
                'phone' => $riderApplication->phone,
                'vehicle_type' => $riderApplication->vehicle_type,
                'license_plate' => $riderApplication->license_plate,
                'status' => 'available',
                'is_verified' => true,
                'approved_at' => now(),
            ]);

            $riderApplication->update([
                'status' => 'approved',
                'reviewed_at' => now(),
            ]);

            $this->logChange($riderApplication, 'pending', 'approved', null);
        });

        Notification::create([
            'type' => 'application_update',
            'title' => 'Application Approved',
            'message' => "Rider application from {$riderApplication->name} has been approved.",
            'icon' => '📄',
            'priority' => 'normal',
            'link' => route('rider-applications.show', $riderApplication),
        ]);

        return redirect()->back()->with('success', "Rider application from {$riderApplication->name} approved successfully. A rider account has been created.");
    }

    public function reject(Request $request, RiderApplication $riderApplication): RedirectResponse
    {
        if ($riderApplication->status !== 'pending') {
            return back()->with('error', "Only pending applications can be rejected. This one is currently \"{$riderApplication->status}\".");
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:3|max:500',
        ], [
            'reason.required' => 'A rejection reason is required.',
        ]);

        $riderApplication->update([
            'status' => 'rejected',
            'reviewed_at' => now(),
            'notes' => $validated['reason'],
        ]);

        $this->logChange($riderApplication, 'pending', 'rejected', $validated['reason']);

        Notification::create([
            'type' => 'application_update',
            'title' => 'Application Rejected',
            'message' => "Rider application from {$riderApplication->name} has been rejected.",
            'icon' => '📄',
            'priority' => 'normal',
            'link' => route('rider-applications.show', $riderApplication),
        ]);

        return redirect()->back()->with('success', "Rider application from {$riderApplication->name} rejected successfully.");
    }

    public function revertToPending(Request $request, RiderApplication $riderApplication): RedirectResponse
    {
        if (!in_array($riderApplication->status, ['approved', 'rejected'])) {
            return back()->with('error', 'Only reviewed applications can be returned to pending.');
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|min:3|max:500',
        ], [
            'reason.min' => 'If provided, the reason must be at least 3 characters.',
        ]);

        $previous = $riderApplication->status;

        DB::transaction(function () use ($riderApplication, $previous, $validated) {
            if ($previous === 'approved') {
                Rider::where('email', $riderApplication->email)->delete();
            }

            $riderApplication->update(['status' => 'pending']);

            $this->logChange($riderApplication, $previous, 'pending', $validated['reason'] ?? 'Returned to pending by administrator');
        });

        return redirect()->back()->with('success', "Application from {$riderApplication->name} was returned to pending" . ($previous === 'approved' ? ' and the linked rider account was removed.' : '.'));
    }

    private function logChange(RiderApplication $application, string $previous, string $new, ?string $reason): void
    {
        RiderApplicationLog::create([
            'rider_application_id' => $application->id,
            'previous_status' => $previous,
            'new_status' => $new,
            'changed_by' => Auth::id(),
            'reason' => $reason,
        ]);
    }
}
