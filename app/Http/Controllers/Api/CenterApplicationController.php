<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LogisticsCenterApplication;
use App\Models\LogisticsCenterApplicationDocument;
use App\Models\Notification;
use App\Rules\PhilippinePhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Promoter / operator "Open a Logistics Center" application flow, the
 * center-application counterpart to the rider apply flow. Applications land
 * in logistics_center_applications for admin review on the Logistics Web.
 */
class CenterApplicationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_name' => 'required|string|max:255',
            'owner_name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:logistics_center_applications,email',
            'phone' => ['required', new PhilippinePhone, 'unique:logistics_center_applications,phone'],
            'house_number' => 'nullable|string|max:50',
            'street' => 'nullable|string|max:255',
            'barangay' => 'nullable|string|max:255',
            'municipality' => 'nullable|string|max:255',
            'province' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'documents.valid_id' => 'required|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
            'documents.business_registration' => 'required|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
            'documents.barangay_clearance' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
            'documents.mayors_permit' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
            'documents.lease_contract' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
            'documents.other' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,doc,docx|max:5120',
        ]);

        $data['phone'] = PhilippinePhone::normalize($data['phone']);
        $data['status'] = 'pending';
        $data['submitted_via'] = 'mobile';
        $data['address'] = trim((string) ($data['address'] ?? '')) ?: trim(collect([
            $data['house_number'] ?? '',
            $data['street'] ?? '',
            $data['barangay'] ?? '',
            $data['municipality'] ?? '',
            $data['province'] ?? '',
        ])->implode(', '), ' ,');

        $application = DB::transaction(function () use ($data, $request) {
            $app = LogisticsCenterApplication::create($data);
            foreach (array_keys(LogisticsCenterApplicationDocument::TYPES) as $type) {
                $file = $request->file("documents.$type");
                if (! $file || ! $file->isValid()) continue;
                $filename = Str::uuid() . '.' . strtolower($file->getClientOriginalExtension());
                $storedPath = $file->storeAs("center-documents/{$app->id}", $filename);
                LogisticsCenterApplicationDocument::create([
                    'logistics_center_application_id' => $app->id,
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
            'type' => 'new_center_application',
            'title' => 'New Center Application',
            'message' => "A new Logistics Center application from {$application->business_name} is waiting for review.",
            'icon' => '🏬',
            'priority' => 'high',
            'link' => route('center-applications.show', $application),
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

        $app = LogisticsCenterApplication::where('email', $validated['email'])->latest('id')->first();

        if (! $app) {
            return response()->json(['application' => null, 'message' => 'No application found.'], 404);
        }

        return response()->json([
            'application' => [
                'id' => $app->id,
                'business_name' => $app->business_name,
                'owner_name' => $app->owner_name,
                'email' => $app->email,
                'status' => $app->status,
                'submitted_via' => $app->submitted_via,
                'created_at' => $app->created_at?->toISOString(),
                'reviewed_at' => $app->reviewed_at?->toISOString(),
                'provisioned_at' => $app->provisioned_at?->toISOString(),
                'notes' => $app->notes,
                'documents' => $app->supportingDocuments->map(fn ($d) => [
                    'type' => $d->document_type,
                    'name' => $d->original_filename,
                ])->values(),
            ],
        ]);
    }
}