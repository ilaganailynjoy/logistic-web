<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <a href="{{ route('rider-applications.index') }}" class="p-2 rounded-xl bg-white border border-gray-100 text-gray-500 hover:text-teal hover:border-teal transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Application Details</h1>
                </div>
            </div>
            <x-status-badge :status="$application->status" />
        </div>
    </x-slot>

    @php $capacities = \App\Models\LogisticsSetting::vehicleCapacities(); @endphp

    <div x-data="{ approveOpen: false, rejectOpen: false, revertOpen: false }" class="max-w-2xl">
        {{-- Application Info Card --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 sm:p-8 mb-6">
            <h3 class="text-base font-bold text-gray-900 mb-5">Applicant Information</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Name</p>
                    <p class="text-sm font-medium text-gray-900">{{ $application->name }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Email</p>
                    <p class="text-sm font-medium text-gray-900">{{ $application->email }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Phone</p>
                    <p class="text-sm font-medium text-gray-900">{{ $application->phone }}</p>
                </div>
                <div class="md:col-span-2">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Address</p>
                    <p class="text-sm font-medium text-gray-900">{{ $application->address ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Vehicle Type</p>
                    <p class="text-sm font-medium text-gray-900">{{ ucfirst($application->vehicle_type) }} · up to {{ $capacities[$application->vehicle_type] ?? '—' }} kg</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">License Plate</p>
                    <p class="text-sm font-medium text-gray-900 font-mono">{{ $application->license_plate }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Driver's License No.</p>
                    <p class="text-sm font-medium text-gray-900 font-mono">{{ $application->license_number ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Vehicle Registration No.</p>
                    <p class="text-sm font-medium text-gray-900 font-mono">{{ $application->vehicle_registration ?? '—' }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Status</p>
                    <x-status-badge :status="$application->status" />
                </div>
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Applied Date</p>
                    <p class="text-sm font-medium text-gray-900">{{ $application->created_at->format('M d, Y') }}</p>
                </div>
                @if ($application->reviewed_at)
                    <div>
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Reviewed At</p>
                        <p class="text-sm font-medium text-gray-900">{{ $application->reviewed_at->format('M d, Y h:i A') }}</p>
                    </div>
                @endif
            </div>
            @if ($application->notes && $application->status === 'rejected')
                <div class="mt-6 p-4 bg-red-50 border border-red-100 rounded-xl">
                    <p class="text-xs font-medium text-red-500 uppercase tracking-wider mb-1">Rejection Reason</p>
                    <p class="text-sm text-red-700">{{ $application->notes }}</p>
                </div>
            @endif

            @if($application->documents)
                <div class="mt-6 p-4 bg-gray-50 rounded-xl">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">Supporting Documents</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach($application->documents as $key => $path)
                            <a href="{{ asset($path) }}" target="_blank"
                               class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-gray-200 hover:border-teal hover:text-teal-dark rounded-lg text-xs font-semibold text-gray-600 transition">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                {{ str_replace('_', ' ', Str::title($key)) }}
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Actions --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 sm:p-8">
            @if ($application->status === 'pending')
                <h3 class="text-base font-bold text-gray-900 mb-2">Review Application</h3>
                <p class="text-sm text-gray-500 mb-5">Approving creates a verified rider account. Rejecting requires a reason.</p>
                <div class="flex flex-wrap gap-3">
                    <button type="button" @click="approveOpen = true"
                            class="bg-emerald-500 hover:bg-emerald-600 text-white px-6 py-2.5 rounded-xl text-sm font-semibold transition">
                        Approve Application
                    </button>
                    <button type="button" @click="rejectOpen = true"
                            class="bg-white border border-red-200 hover:bg-red-50 text-red-600 px-6 py-2.5 rounded-xl text-sm font-semibold transition">
                        Reject Application
                    </button>
                </div>
            @elseif(in_array($application->status, ['approved', 'rejected']))
                <h3 class="text-base font-bold text-gray-900 mb-2">Change Decision</h3>
                <p class="text-sm text-gray-500 mb-5">
                    This application was already reviewed. You can return it to pending to correct an accidental decision — the change is recorded in the history below.
                    @if($application->status === 'approved')
                        Returning it to pending will also remove the linked rider account.
                    @endif
                </p>
                <button type="button" @click="revertOpen = true"
                        class="bg-white border border-amber-300 hover:bg-amber-50 text-amber-700 px-6 py-2.5 rounded-xl text-sm font-semibold transition">
                    Return to Pending
                </button>
            @else
                <p class="text-sm text-gray-500">No actions available for this application.</p>
            @endif
        </div>

        {{-- Audit Trail --}}
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 sm:p-8 mt-6">
            <h3 class="text-base font-bold text-gray-900 mb-5">Application History</h3>

            @forelse($application->logs as $log)
                <div class="flex gap-4 {{ !$loop->last ? 'pb-5 mb-5 border-b border-gray-100' : '' }}">
                    <div class="flex-shrink-0 w-9 h-9 rounded-full bg-teal-light text-teal-dark flex items-center justify-center text-xs font-bold">
                        {{ strtoupper(substr($log->previous_status ?? 'new', 0, 1)) }}→{{ strtoupper(substr($log->new_status, 0, 1)) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-gray-900">
                            {{ ucfirst($log->previous_status ?? 'submitted') }} → {{ ucfirst($log->new_status) }}
                        </p>
                        <p class="text-xs text-gray-400 mt-0.5">{{ $log->created_at->format('F j, Y · h:i A') }}</p>
                        <p class="text-xs text-gray-500 mt-1">Changed by: {{ $log->changer?->name ?? 'System' }}</p>
                        @if($log->reason)
                            <div class="mt-2 p-3 bg-gray-50 rounded-lg">
                                <p class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-0.5">Reason</p>
                                <p class="text-sm text-gray-700">{{ $log->reason }}</p>
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                <p class="text-sm text-gray-500">No status changes recorded yet.</p>
            @endforelse
        </div>

        {{-- Approve Modal --}}
        <div x-show="approveOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" x-transition.opacity>
            <div class="absolute inset-0 bg-black/40" @click="approveOpen = false"></div>
            <form action="{{ route('rider-applications.approve', $application) }}" method="POST" class="relative bg-white rounded-2xl shadow-xl w-full max-w-md" x-transition>
                @csrf
                <div class="px-6 py-4 border-b border-gray-100">
                    <h4 class="font-bold text-gray-900">Approve Rider Application?</h4>
                </div>
                <div class="px-6 py-5 space-y-2 text-sm">
                    <p><span class="text-gray-500">Applicant:</span> <strong class="text-gray-900">{{ $application->name }}</strong></p>
                    <p><span class="text-gray-500">Email:</span> {{ $application->email }}</p>
                    <p><span class="text-gray-500">Phone:</span> {{ $application->phone }}</p>
                    <p><span class="text-gray-500">Vehicle:</span> {{ ucfirst($application->vehicle_type) }} · {{ $application->license_plate }}</p>
                    <p class="pt-2 text-gray-500">A verified rider account will be created with status <strong>Available</strong>.</p>
                </div>
                <div class="px-6 py-4 bg-gray-50 rounded-b-2xl flex justify-end gap-3">
                    <button type="button" @click="approveOpen = false" class="px-5 py-2.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-semibold rounded-xl transition">Cancel</button>
                    <button type="submit" class="px-5 py-2.5 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-bold rounded-xl transition">Confirm Approval</button>
                </div>
            </form>
        </div>

        {{-- Reject Modal --}}
        <div x-show="rejectOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" x-transition.opacity>
            <div class="absolute inset-0 bg-black/40" @click="rejectOpen = false"></div>
            <form action="{{ route('rider-applications.reject', $application) }}" method="POST" class="relative bg-white rounded-2xl shadow-xl w-full max-w-md" x-transition>
                @csrf
                <input type="hidden" name="reason_holder" value="">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h4 class="font-bold text-gray-900">Reject Rider Application?</h4>
                    <p class="text-sm text-gray-500 mt-0.5">Applicant: <strong class="text-gray-800">{{ $application->name }}</strong></p>
                </div>
                <div class="px-6 py-5">
                    <label for="reject-reason" class="block text-sm font-medium text-gray-700 mb-1">Rejection Reason <span class="text-red-500">*</span></label>
                    <textarea id="reject-reason" name="reason" required minlength="3" maxlength="500" rows="3"
                              placeholder="e.g. Invalid vehicle documents"
                              class="block w-full rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm"></textarea>
                    @error('reason')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="px-6 py-4 bg-gray-50 rounded-b-2xl flex justify-end gap-3">
                    <button type="button" @click="rejectOpen = false" class="px-5 py-2.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-semibold rounded-xl transition">Cancel</button>
                    <button type="submit" class="px-5 py-2.5 bg-red-500 hover:bg-red-600 text-white text-sm font-bold rounded-xl transition">Confirm Rejection</button>
                </div>
            </form>
        </div>

        {{-- Revert Modal --}}
        <div x-show="revertOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" x-transition.opacity>
            <div class="absolute inset-0 bg-black/40" @click="revertOpen = false"></div>
            <form action="{{ route('rider-applications.revert', $application) }}" method="POST" class="relative bg-white rounded-2xl shadow-xl w-full max-w-md" x-transition>
                @csrf
                <div class="px-6 py-4 border-b border-gray-100">
                    <h4 class="font-bold text-gray-900">Return Application to Pending?</h4>
                    <p class="text-sm text-gray-500 mt-0.5">
                        Applicant: <strong class="text-gray-800">{{ $application->name }}</strong>.
                        This will reverse the current decision ({{ ucfirst($application->status) }} → Pending).
                        @if($application->status === 'approved')
                            The linked rider account will be removed.
                        @endif
                    </p>
                </div>
                <div class="px-6 py-5">
                    <label for="revert-reason" class="block text-sm font-medium text-gray-700 mb-1">Reason / Note</label>
                    <textarea id="revert-reason" name="reason" rows="2" minlength="3" maxlength="500"
                              placeholder="e.g. Accidentally rejected"
                              class="block w-full rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm"></textarea>
                    @error('reason')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                <div class="px-6 py-4 bg-gray-50 rounded-b-2xl flex justify-end gap-3">
                    <button type="button" @click="revertOpen = false" class="px-5 py-2.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-semibold rounded-xl transition">Cancel</button>
                    <button type="submit" class="px-5 py-2.5 bg-amber-500 hover:bg-amber-600 text-white text-sm font-bold rounded-xl transition">Confirm Return to Pending</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
