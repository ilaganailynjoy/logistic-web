<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <a href="{{ route('riders.index') }}" class="p-2 rounded-xl bg-white border border-gray-100 text-gray-500 hover:text-teal hover:border-teal transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Rider Details</h1>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <x-status-badge :status="$rider->status" />
                <a href="{{ route('riders.edit', $rider) }}" class="inline-flex items-center gap-2 bg-white border border-gray-200 text-gray-700 hover:text-teal hover:border-teal px-4 py-2 rounded-xl text-sm font-semibold transition">
                    Edit
                </a>
                <form action="{{ route('riders.destroy', $rider) }}" method="POST" x-data
                      x-on:submit.prevent="if (confirm('Are you sure you want to delete this rider?')) $el.submit()">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-xl text-sm font-semibold transition">
                        Delete
                    </button>
                </form>
            </div>
        </div>
    </x-slot>

    {{-- Profile Header Card --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-5">
            <div class="h-16 w-16 rounded-full bg-teal flex items-center justify-center text-2xl font-bold text-white flex-shrink-0">
                {{ strtoupper(substr($rider->name, 0, 1)) }}
            </div>
            <div>
                <h2 class="text-xl font-bold text-gray-900">{{ $rider->name }}</h2>
                <p class="mt-1 text-sm text-gray-500">{{ $rider->email }} &middot; {{ $rider->phone }}</p>
                <span class="mt-3 inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold rounded-lg bg-teal-light text-teal-dark">
                    {{ ucfirst($rider->vehicle_type) }} &middot; {{ $rider->license_plate }}
                </span>
            </div>
        </div>
    </div>

    {{-- Rider Info Grid --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
        <h3 class="text-base font-bold text-gray-900 mb-5">Rider Information</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Name</p>
                <p class="text-sm font-medium text-gray-900">{{ $rider->name }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Phone</p>
                <p class="text-sm font-medium text-gray-900">{{ $rider->phone }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Email</p>
                <p class="text-sm font-medium text-gray-900">{{ $rider->email }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Vehicle Verification</p>
                @if($rider->vehicle_verification === 'verified')
                    <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200">
                        <svg class="h-3 w-3 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        Verified
                    </span>
                    @if($rider->vehicle_verified_at)
                        <p class="text-xs text-gray-400 mt-1">{{ $rider->vehicle_verified_at->format('M d, Y') }}</p>
                    @endif
                @elseif($rider->vehicle_verification === 'rejected')
                    <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-full bg-red-50 text-red-700 ring-1 ring-inset ring-red-200">Rejected</span>
                @else
                    <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-full bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200">Pending</span>
                @endif
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Vehicle Type</p>
                <p class="text-sm font-medium text-gray-900">{{ ucfirst($rider->vehicle_type) }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Vehicle Details / Plate</p>
                <p class="text-sm font-medium text-gray-900 font-mono">{{ $rider->license_plate }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Vehicle Capacity</p>
                <p class="text-sm font-medium text-gray-900">
                    {{ $rider->vehicle_capacity_kg ? $rider->vehicle_capacity_kg . ' kg (custom)' : (\App\Models\LogisticsSetting::vehicleCapacities()[$rider->vehicle_type] ?? '—') . ' kg (default)' }}
                </p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Status</p>
                <x-status-badge :status="$rider->status" />
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Application Status</p>
                @if($application)
                    <a href="{{ route('rider-applications.show', $application) }}" class="inline-flex"><x-status-badge :status="$application->status" /></a>
                @else
                    <p class="text-sm text-gray-400">No application on file (added directly)</p>
                @endif
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Date Approved</p>
                <p class="text-sm font-medium text-gray-900">{{ $rider->approved_at?->format('M d, Y') ?? '—' }}</p>
            </div>
            <div class="lg:col-span-2">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Current Delivery</p>
                @if($currentDelivery)
                    <a href="{{ route('deliveries.show', $currentDelivery) }}" class="inline-flex items-center gap-2 text-sm font-mono font-semibold text-teal hover:text-teal-dark">
                        {{ $currentDelivery->tracking_number }}
                        <x-status-badge :status="$currentDelivery->status" />
                    </a>
                @else
                    <p class="text-sm text-gray-400">No active delivery right now</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Vehicle Verification Panel --}}
    @if($rider->vehicle_verification !== 'verified' || $rider->vehicle_verification === 'rejected')
        <div x-data="{ rejectOpen: false }" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
            <h3 class="text-base font-bold text-gray-900 mb-2">Vehicle Verification</h3>

            @if($rider->vehicle_verification === 'rejected')
                <div class="mb-4 p-4 bg-red-50 border border-red-100 rounded-xl">
                    <p class="text-xs font-semibold text-red-500 uppercase tracking-wider mb-1">Rejected</p>
                    <p class="text-sm text-red-700">{{ $rider->vehicle_verification_note ?? 'No reason recorded.' }}</p>
                    @if($application && $application->documents)
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach($application->documents as $key => $path)
                                <a href="{{ asset($path) }}" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-gray-200 hover:border-teal rounded-lg text-xs font-semibold text-gray-600 transition">
                                    View {{ str_replace('_', ' ', \Illuminate\Support\Str::title($key)) }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            @else
                <p class="text-sm text-gray-500 mb-4">
                    This rider's vehicle documents have not been verified yet — they cannot be assigned deliveries until verified.
                    @if($application && $application->documents)
                        Review the submitted documents:
                        @foreach($application->documents as $key => $path)
                            <a href="{{ asset($path) }}" target="_blank" class="inline-flex items-center px-2 py-0.5 mx-0.5 bg-gray-50 border border-gray-200 hover:border-teal rounded-md text-xs font-semibold text-teal">{{ \Illuminate\Support\Str::title(str_replace('_', ' ', $key)) }}</a>
                        @endforeach
                    @endif
                </p>
            @endif

            <div class="flex flex-wrap gap-3">
                <form action="{{ route('riders.verify-vehicle', $rider) }}" method="POST" x-data
                      x-on:submit.prevent="if (confirm('Verify vehicle for {{ $rider->name }}? They will become eligible for delivery assignments.')) $el.submit()">
                    @csrf
                    <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white px-5 py-2.5 rounded-xl text-sm font-semibold transition">
                        Confirm Verification
                    </button>
                </form>
                <button type="button" @click="rejectOpen = true"
                        class="bg-white border border-red-200 hover:bg-red-50 text-red-600 px-5 py-2.5 rounded-xl text-sm font-semibold transition">
                    Reject Vehicle
                </button>
            </div>

            {{-- Reject reason modal --}}
            <div x-show="rejectOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" x-transition.opacity>
                <div class="absolute inset-0 bg-black/40" @click="rejectOpen = false"></div>
                <form action="{{ route('riders.reject-vehicle', $rider) }}" method="POST" class="relative bg-white rounded-2xl shadow-xl w-full max-w-md" x-transition>
                    @csrf
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h4 class="font-bold text-gray-900">Reject Vehicle Information?</h4>
                        <p class="text-sm text-gray-500 mt-0.5">
                            Rider: <strong class="text-gray-800">{{ $rider->name }}</strong>.
                            The rider will be marked inactive and cannot receive deliveries.
                        </p>
                    </div>
                    <div class="px-6 py-5">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Reason <span class="text-red-500">*</span></label>
                        <textarea name="reason" required minlength="3" maxlength="500" rows="3" placeholder="e.g. Vehicle registration document is invalid."
                                  class="block w-full rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm"></textarea>
                    </div>
                    <div class="px-6 py-4 bg-gray-50 rounded-b-2xl flex justify-end gap-3">
                        <button type="button" @click="rejectOpen = false" class="px-5 py-2.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-semibold rounded-xl transition">Cancel</button>
                        <button type="submit" class="px-5 py-2.5 bg-red-500 hover:bg-red-600 text-white text-sm font-bold rounded-xl transition">Confirm Rejection</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Recent Deliveries --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100">
            <h3 class="text-base font-bold text-gray-900">Recent Deliveries</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100">
                <thead class="bg-gray-50/50">
                    <tr>
                        <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Tracking #</th>
                        <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Recipient</th>
                        <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rider->deliveries->take(10) as $delivery)
                        <tr class="hover:bg-teal-light/40 transition">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <a href="{{ route('deliveries.show', $delivery) }}" class="text-sm font-semibold text-teal hover:text-teal-dark">
                                    {{ $delivery->tracking_number }}
                                </a>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $delivery->recipient_name }}</td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <x-status-badge :status="$delivery->status" />
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $delivery->created_at->format('M d, Y') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-16 text-center">
                                <div class="flex flex-col items-center">
                                    <div class="h-12 w-12 rounded-full bg-teal-light flex items-center justify-center mb-3">
                                        <svg class="h-6 w-6 text-teal" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                                        </svg>
                                    </div>
                                    <p class="text-sm font-semibold text-gray-900">No deliveries found</p>
                                    <p class="mt-1 text-sm text-gray-500">This rider hasn't handled any deliveries yet.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>