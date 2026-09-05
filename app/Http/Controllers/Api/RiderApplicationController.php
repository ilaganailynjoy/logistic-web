<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RiderApplication;
use App\Models\RiderApplicationDocument;
use App\Models\Notification;
use App\Models\VehicleType;
use App\Rules\PhilippinePhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RiderApplicationController extends Controller
{
    public function vehicleTypes(): JsonResponse
    {
        return response()->json([
            'vehicle_types' => VehicleType::where('is_active', true)->orderBy('sort_order')->get(['name', 'label', 'capacity_kg'])->map(fn ($t) => [
                'name' => $t->name,
                'label' => $t->label,
                'capacity_kg' => (float) $t->capacity_kg,
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['vehicle_type' => strtolower(trim((string) $request->input('vehicle_type')))]);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:riders,email',
            'phone' => ['required', new PhilippinePhone, 'unique:rider_applications,phone'],
            'address' => 'required|string|max:500',
            'vehicle_type' => 'required|in:' . implode(',', array_keys(VehicleType::activeLabels())),
            'license_plate' => 'required|string|max:20',
            'license_number' => 'required|string|max:100',
            'vehicle_registration' => 'required|string|max:100',
            'documents.valid_id' => 'required|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
            'documents.drivers_license' => 'required|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
            'documents.vehicle_registration' => 'required|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
            'documents.proof_of_address' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
            'documents.deed_of_sale' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
            'documents.sales_invoice' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
            'documents.owner_valid_id' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
            'documents.authorization_letter' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
            'documents.encumbrance_certificate' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
            'documents.other' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
            'rider_type' => 'nullable|in:full_time,part_time',
            'vehicle_ownership' => 'nullable|in:own,borrowed,second_hand,financing',
        ]);

        $data['phone'] = PhilippinePhone::normalize($data['phone']);
        $data['status'] = 'pending';
        $data['documents'] = [];
        $data['submitted_via'] = 'mobile';
        $data['rider_type'] = $data['rider_type'] ?? 'full_time';
        $data['vehicle_ownership'] = $data['vehicle_ownership'] ?? null;

        $application = DB::transaction(function () use ($data, $request) {
            $app = RiderApplication::create($data);
            foreach (array_keys(RiderApplicationDocument::TYPES) as $type) {
                $file = $request->file("documents.$type");
                if (!$file || !$file->isValid()) continue;
                $filename = Str::uuid() . '.' . strtolower($file->getClientOriginalExtension());
                $storedPath = $file->storeAs("rider-documents/{$app->id}", $filename);
                RiderApplicationDocument::create([
                    'rider_application_id' => $app->id,
                    'document_type' => $type,
                    'original_filename' => $file->getClientOriginalName(),
                    'stored_path' => $storedPath,
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                ]);
            }
            return $app;
        });

        Notification::create([
            'type' => 'new_rider_application',
            'title' => 'New Rider Application (Mobile)',
            'message' => "A new rider application from {$application->name} via mobile is waiting for review.",
            'icon' => '🧑‍✈️',
            'priority' => 'high',
            'link' => route('rider-applications.show', $application),
        ]);

        return response()->json([
            'message' => 'Application submitted successfully.',
            'application' => [
                'id' => $application->id,
                'status' => $application->status,
                'submitted_via' => $application->submitted_via,
            ],
        ], 201);
    }

    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $app = RiderApplication::where('email', $validated['email'])->latest('id')->first();

        if (!$app) {
            return response()->json(['application' => null, 'message' => 'No application found.'], 404);
        }

        $app->load(['supportingDocuments', 'logs']);

        return response()->json([
            'application' => [
                'id' => $app->id,
                'name' => $app->name,
                'email' => $app->email,
                'phone' => $app->phone,
                'status' => $app->status,
                'submitted_via' => $app->submitted_via,
                'rider_type' => $app->rider_type,
                'vehicle_ownership' => $app->vehicle_ownership,
                'created_at' => $app->created_at->toISOString(),
                'reviewed_at' => $app->reviewed_at?->toISOString(),
                'notes' => $app->notes,
                'documents' => $app->supportingDocuments->map(fn ($d) => [
                    'type' => $d->document_type,
                    'name' => $d->original_filename,
                ])->values(),
                'logs' => $app->logs->map(fn ($l) => [
                    'from' => $l->previous_status,
                    'to' => $l->new_status,
                    'reason' => $l->reason,
                    'at' => $l->created_at->toISOString(),
                ])->values(),
            ],
        ]);
    }
}
