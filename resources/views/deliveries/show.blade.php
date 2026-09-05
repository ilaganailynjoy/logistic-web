<x-app-layout>
    @php
        $dotColors = [
            'waiting_for_rider' => 'bg-amber-500',
            'assigned'          => 'bg-blue-500',
            'picked_up'         => 'bg-indigo-500',
            'out_for_delivery'  => 'bg-purple-500',
            'delivered'         => 'bg-emerald-500',
            'failed'            => 'bg-red-500',
            'cancelled'         => 'bg-gray-400',
            'archived'          => 'bg-gray-300',
            'restored'          => 'bg-teal-500',
        ];
        $stepOrder = ['assigned', 'picked_up', 'out_for_delivery', 'delivered'];
        $stepLabels = ['assigned' => 'Assigned', 'picked_up' => 'Picked Up', 'out_for_delivery' => 'Out for Delivery', 'delivered' => 'Delivered'];
        $currentIndex = array_search($delivery->status, $stepOrder);
        $isTerminalBad = in_array($delivery->status, ['failed', 'cancelled']);
        $weight = (float) ($delivery->weight ?? 0);
        $capacities = \App\Models\LogisticsSetting::vehicleCapacities();
    @endphp

    <div class="flex items-center gap-4 mb-6">
        <a href="{{ route('deliveries.index') }}"
           class="p-2 bg-white border border-gray-200 rounded-xl text-gray-500 hover:text-teal-dark hover:border-teal transition shadow-sm">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
        </a>
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold text-gray-900">Delivery Details</h1>
            <x-status-badge :status="$delivery->status" />
            @if($delivery->archived_at)
                <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-full bg-gray-800 text-white">Archived</span>
            @endif
        </div>
    </div>

    {{-- Failure / Cancellation banner --}}
    @if($isTerminalBad && ($delivery->failure_reason || $delivery->cancellation_reason))
        <div class="mb-6 flex items-start gap-3 rounded-2xl border p-5 {{ $delivery->status === 'failed' ? 'bg-red-50 border-red-200' : 'bg-gray-50 border-gray-200' }}">
            <svg class="h-5 w-5 flex-shrink-0 {{ $delivery->status === 'failed' ? 'text-red-600' : 'text-gray-500' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <div>
                <p class="text-sm font-bold {{ $delivery->status === 'failed' ? 'text-red-700' : 'text-gray-700' }}">
                    {{ $delivery->status === 'failed' ? 'Delivery Failed' : 'Delivery Cancelled' }}
                    @if($delivery->status === 'failed' && $delivery->failed_at)<span class="font-normal">· {{ $delivery->failed_at->format('M d, Y h:i A') }}</span>@endif
                    @if($delivery->status === 'cancelled' && $delivery->cancelled_at)<span class="font-normal">· {{ $delivery->cancelled_at->format('M d, Y h:i A') }}</span>@endif
                </p>
                <p class="text-sm {{ $delivery->status === 'failed' ? 'text-red-600' : 'text-gray-600' }} mt-0.5">
                    <span class="font-semibold">Reason:</span> {{ $delivery->failure_reason ?? $delivery->cancellation_reason }}
                </p>
            </div>
        </div>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
            <div>
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Tracking Number</p>
                <p class="text-2xl font-mono font-bold text-gray-900">{{ $delivery->tracking_number }}</p>
                <p class="text-sm text-gray-500 mt-2">
                    Created {{ $delivery->created_at->format('M d, Y h:i A') }}
                    @if($delivery->creator) · by {{ $delivery->creator->name }}@endif
                </p>
            </div>
        </div>

        {{-- Delivery Progress --}}
        <div class="mt-8 pt-6 border-t border-gray-100">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-5">Delivery Progress</p>
            <div class="relative">
                @if(!$isTerminalBad)
                    <div class="absolute top-5 left-0 right-0 h-1 bg-gray-200 rounded-full"></div>
                    <div class="absolute top-5 left-0 h-1 bg-emerald-500 rounded-full transition-all duration-700" style="width: {{ ($currentIndex !== false ? $currentIndex : 0) / (count($stepOrder) - 1) * 100 }}%"></div>
                @endif
                <div class="relative flex justify-between">
                    <div class="flex flex-col items-center flex-1 text-center">
                        <div class="h-10 w-10 rounded-full flex items-center justify-center bg-green-500 text-white">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                        </div>
                        <p class="mt-3 text-xs font-medium text-green-600">Created</p>
                    </div>
                    @foreach($stepOrder as $i => $stepKey)
                        @php
                            $done = $currentIndex !== false && $i < $currentIndex;
                            $current = $currentIndex === $i;
                            $future = !$done && !$current;
                        @endphp
                        <div class="flex flex-col items-center flex-1 text-center">
                            <div class="relative flex items-center justify-center">
                                @if($current && !$isTerminalBad)
                                    <span class="absolute h-3 w-3 rounded-full bg-teal animate-ping"></span>
                                @endif
                                <div class="relative h-10 w-10 rounded-full flex items-center justify-center {{ $done ? 'bg-green-500 text-white' : ($current ? 'bg-teal text-white shadow-lg shadow-teal/30' : 'bg-gray-200 text-gray-400') }}">
                                    @if($done)
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                    @elseif($current)
                                        <span class="h-3 w-3 rounded-full bg-white"></span>
                                    @else
                                        <span class="h-2.5 w-2.5 rounded-full bg-gray-400"></span>
                                    @endif
                                </div>
                            </div>
                            <p class="mt-3 text-xs font-medium {{ $current ? 'text-teal-dark' : ($done ? 'text-green-600' : 'text-gray-400') }}">{{ $stepLabels[$stepKey] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            @if($isTerminalBad)
                <div class="mt-4 flex justify-center">
                    <span class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-bold {{ $delivery->status === 'failed' ? 'bg-red-100 text-red-700 ring-1 ring-red-200' : 'bg-gray-100 text-gray-600 ring-1 ring-gray-200' }}">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $delivery->status === 'failed' ? 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z' : 'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636'}}"/></svg>
                        Final Status: {{ ucfirst(str_replace('_', ' ', $delivery->status)) }} — not completed
                    </span>
                </div>
            @endif
        </div>
    </div>

    {{-- Info grid --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <div class="bg-gray-50 rounded-xl p-5">
            <div class="flex items-center gap-2 mb-4">
                <div class="h-2 w-1 rounded-full bg-teal"></div>
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Sender Information</h3>
            </div>
            <dl class="space-y-3">
                <div><dt class="text-xs text-gray-500">Name</dt><dd class="text-sm font-medium text-gray-900">{{ $delivery->sender_name }}</dd></div>
                <div><dt class="text-xs text-gray-500">Phone</dt><dd class="text-sm font-medium text-gray-900">{{ $delivery->sender_phone }}</dd></div>
                <div><dt class="text-xs text-gray-500">Pickup Address</dt><dd class="text-sm font-medium text-gray-900">{{ $delivery->sender_address }}</dd></div>
            </dl>
        </div>

        <div class="bg-gray-50 rounded-xl p-5">
            <div class="flex items-center gap-2 mb-4">
                <div class="h-2 w-1 rounded-full bg-teal"></div>
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Recipient Information</h3>
            </div>
            <dl class="space-y-3">
                <div><dt class="text-xs text-gray-500">Name</dt><dd class="text-sm font-medium text-gray-900">{{ $delivery->recipient_name }}</dd></div>
                <div><dt class="text-xs text-gray-500">Phone</dt><dd class="text-sm font-medium text-gray-900">{{ $delivery->recipient_phone }}</dd></div>
                <div><dt class="text-xs text-gray-500">Delivery Address</dt><dd class="text-sm font-medium text-gray-900">{{ $delivery->recipient_address }}</dd></div>
            </dl>
        </div>

        <div class="bg-gray-50 rounded-xl p-5">
            <div class="flex items-center gap-2 mb-4">
                <div class="h-2 w-1 rounded-full bg-teal"></div>
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Package Details</h3>
            </div>
            <dl class="space-y-3">
                <div><dt class="text-xs text-gray-500">Package Type</dt><dd class="text-sm font-medium text-gray-900">{{ $delivery->package_type ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Description</dt><dd class="text-sm font-medium text-gray-900">{{ $delivery->package_description ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Weight</dt><dd class="text-sm font-medium text-gray-900">{{ $delivery->weight ? $delivery->weight . ' kg' : '—' }}</dd></div>
                <div>
                    <dt class="text-xs text-gray-500">Priority</dt>
                    <dd class="mt-0.5">
                        @php $prio = $delivery->priority ?? 'normal'; @endphp
                        <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded-full {{ match($prio) { 'urgent' => 'bg-red-100 text-red-700', 'high' => 'bg-amber-100 text-amber-700', default => 'bg-gray-100 text-gray-600' } }}">{{ ucfirst($prio) }}</span>
                    </dd>
                </div>
                <div><dt class="text-xs text-gray-500">Notes</dt><dd class="text-sm font-medium text-gray-900">{{ $delivery->notes ?? '—' }}</dd></div>
            </dl>
        </div>

        <div class="bg-gray-50 rounded-xl p-5">
            <div class="flex items-center gap-2 mb-4">
                <div class="h-2 w-1 rounded-full bg-teal"></div>
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Rider &amp; Contact</h3>
            </div>
            @if($delivery->rider)
                <dl class="space-y-3">
                    <div><dt class="text-xs text-gray-500">Name</dt><dd class="text-sm font-medium text-gray-900">{{ $delivery->rider->name }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Contact Number</dt><dd class="text-sm font-medium text-gray-900">{{ $delivery->rider->phone }}</dd></div>
                    <div><dt class="text-xs text-gray-500">Vehicle</dt><dd class="text-sm font-medium text-gray-900">{{ ucfirst($delivery->rider->vehicle_type) }} · {{ $delivery->rider->license_plate }}</dd></div>
                </dl>
            @else
                <p class="text-sm text-gray-500">No rider assigned yet.</p>
            @endif
        </div>

        <div class="bg-gray-50 rounded-xl p-5 md:col-span-2">
            <div class="flex items-center gap-2 mb-4">
                <div class="h-2 w-1 rounded-full bg-teal"></div>
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide">Timestamps</h3>
            </div>
            <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
                <div><dt class="text-xs text-gray-500">Created At</dt><dd class="text-sm font-medium text-gray-900">{{ $delivery->created_at->format('M d, Y h:i A') }}</dd></div>
                <div><dt class="text-xs text-gray-500">Estimated Delivery</dt><dd class="text-sm font-medium text-gray-900">{{ $delivery->estimated_delivery_at ? $delivery->estimated_delivery_at->format('M d, Y h:i A') : '—' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Actual Pickup</dt><dd class="text-sm font-medium text-gray-900">{{ $delivery->picked_up_at ? $delivery->picked_up_at->format('M d, Y h:i A') : '—' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Actual Delivery</dt><dd class="text-sm font-medium text-gray-900">{{ $delivery->delivered_at ? $delivery->delivered_at->format('M d, Y h:i A') : '—' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Created By</dt><dd class="text-sm font-medium text-gray-900">{{ $delivery->creator?->name ?? '—' }}</dd></div>
            </dl>
        </div>
    </div>

    {{-- Parcel Processing (Receive -> Scan -> Sort) --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
        <div class="flex items-center gap-2 mb-5">
            <div class="h-2 w-1 rounded-full bg-teal"></div>
            <h2 class="text-lg font-semibold text-gray-900">Parcel Processing</h2>
            <span class="inline-flex items-center px-2.5 py-0.5 text-xs font-semibold rounded-full bg-teal-light text-teal-dark">
                {{ ucwords(str_replace('_', ' ', $delivery->parcel_status ?? 'pending_arrival')) }}
            </span>
        </div>

        @php
            $parcelSteps = [
                'pending_arrival' => ['label' => 'Pending Arrival', 'index' => 0],
                'received'        => ['label' => 'Received', 'index' => 1],
                'scanned'         => ['label' => 'Scanned', 'index' => 2],
                'sorted'          => ['label' => 'Sorted', 'index' => 3],
            ];
            $currentParcelIndex = $parcelSteps[$delivery->parcel_status ?? 'pending_arrival']['index'];
        @endphp

        <div class="relative mb-6">
            <div class="absolute top-5 left-8 right-8 h-1 bg-gray-200 rounded-full"></div>
            <div class="absolute top-5 left-8 h-1 bg-teal rounded-full transition-all duration-700" style="width: {{ ($currentParcelIndex / 3) * 100 }}%"></div>
            <div class="relative flex justify-between">
                @foreach($parcelSteps as $key => $step)
                    @php
                        $done = $currentParcelIndex > $step['index'];
                        $current = $currentParcelIndex === $step['index'];
                    @endphp
                    <div class="flex flex-col items-center flex-1 text-center">
                        <div class="relative flex items-center justify-center">
                            <div class="h-10 w-10 rounded-full flex items-center justify-center {{ $done ? 'bg-teal text-white' : ($current ? 'bg-teal text-white shadow-lg shadow-teal/30' : 'bg-gray-200 text-gray-400') }}">
                                @if($done)
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                @else
                                    <span class="h-3 w-3 rounded-full {{ $current ? 'bg-white' : 'bg-gray-400' }}"></span>
                                @endif
                            </div>
                        </div>
                        <p class="mt-3 text-xs font-medium {{ $current ? 'text-teal-dark' : ($done ? 'text-teal' : 'text-gray-400') }}">{{ $step['label'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        @if(!$delivery->archived_at)
            <div class="flex flex-wrap gap-3">
                @if(in_array($delivery->parcel_status, ['pending_arrival', null]))
                    <form action="{{ route('deliveries.receive', $delivery) }}" method="POST" class="w-full max-w-xl">
                        @csrf
                        <label for="receive_center_id" class="block text-sm font-medium text-gray-700 mb-1">Receive at Logistics Center</label>
                        <div class="flex flex-col sm:flex-row gap-2">
                            <select name="center_id" id="receive_center_id" required
                                    class="flex-1 rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm">
                                <option value="">— Select center —</option>
                                @foreach($centers as $center)
                                    <option value="{{ $center->id }}" @selected($delivery->center_id === $center->id)>{{ $center->name }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="bg-teal hover:bg-teal-dark text-white font-semibold py-2 px-5 rounded-xl transition shadow-sm text-sm whitespace-nowrap">
                                Mark as Received
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500">Log the parcel arriving at the handling logistics center.</p>
                    </form>
                @endif

                @if($delivery->parcel_status === 'received')
                    <form action="{{ route('deliveries.scan', $delivery) }}" method="POST">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="bg-indigo-500 hover:bg-indigo-600 text-white font-semibold px-5 py-2.5 rounded-xl transition shadow-sm text-sm">
                            Scan &amp; Verify Parcel
                        </button>
                    </form>
                @endif

                @if(in_array($delivery->parcel_status, ['received', 'scanned']))
                    <form action="{{ route('deliveries.sort', $delivery) }}" method="POST" class="w-full">
                        @csrf
                        <fieldset class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-gray-50 rounded-xl p-4">
                            <legend class="text-sm font-medium text-gray-700 px-2">Sort to Destination</legend>
                            <div>
                                <label for="destination_center" class="block text-sm font-medium text-gray-700 mb-1">Destination Center</label>
                                <select name="destination_center_id" id="destination_center" required
                                        class="w-full rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm">
                                    <option value="">— Select destination —</option>
                                    @foreach($centers as $center)
                                        <option value="{{ $center->id }}" @selected($delivery->destination_center_id === $center->id)>{{ $center->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="service_area" class="block text-sm font-medium text-gray-700 mb-1">Service Area</label>
                                <select name="service_area_id" id="service_area" required
                                        class="w-full rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm">
                                    <option value="">— Select service area —</option>
                                    @foreach($serviceAreas as $area)
                                        <option value="{{ $area->id }}" @selected($delivery->service_area_id === $area->id)>{{ $area->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="md:col-span-2 bg-purple-500 hover:bg-purple-600 text-white font-semibold py-2.5 px-5 rounded-xl transition shadow-sm text-sm">
                                Mark as Sorted
                            </button>
                        </fieldset>
                    </form>
                @endif

                @if(in_array($delivery->parcel_status, ['received', 'scanned', 'sorted']) && $delivery->logisticsCenter)
                    <div class="w-full flex flex-wrap items-center gap-x-6 gap-y-1 text-xs text-gray-500 bg-gray-50 rounded-xl px-4 py-3">
                        @if($delivery->logisticsCenter)<span><strong class="text-gray-700">Handling Center:</strong> {{ $delivery->logisticsCenter->name }}</span>@endif
                        @if($delivery->received_at)<span><strong class="text-gray-700">Received:</strong> {{ $delivery->received_at->format('M d, Y h:i A') }}</span>@endif
                        @if($delivery->scanned_at)<span><strong class="text-gray-700">Scanned:</strong> {{ $delivery->scanned_at->format('M d, Y h:i A') }}</span>@endif
                        @if($delivery->destinationCenter)<span><strong class="text-gray-700">Destination:</strong> {{ $delivery->destinationCenter->name }}</span>@endif
                        @if($delivery->serviceArea)<span><strong class="text-gray-700">Service Area:</strong> {{ $delivery->serviceArea->name }}</span>@endif
                        @if($delivery->sorted_at)<span><strong class="text-gray-700">Sorted:</strong> {{ $delivery->sorted_at->format('M d, Y h:i A') }}</span>@endif
                    </div>
                @endif
            </div>
        @endif
    </div>

    {{-- Proof of Delivery --}}
    @if($delivery->proofs->count())
        <div class="bg-white rounded-2xl shadow-sm border border-emerald-100 p-6 mb-6">
            <div class="flex items-center gap-2 mb-5">
                <div class="h-2 w-1 rounded-full bg-emerald-500"></div>
                <h2 class="text-lg font-semibold text-gray-900">Proof of Delivery</h2>
                <span class="inline-flex items-center px-2 py-0.5 text-[11px] font-bold rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200">Confirmed</span>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($delivery->proofs as $proof)
                    <div class="bg-gray-50 rounded-xl p-4 border border-gray-100">
                        <p class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-1">{{ strtoupper($proof->type) }} confirmation</p>
                        @if($proof->type === 'signature' && $proof->signature_name)
                            <p class="text-sm font-semibold text-gray-900">Signed by: {{ $proof->signature_name }}</p>
                        @elseif($proof->type === 'otp' && $proof->otp)
                            <p class="text-sm font-mono font-semibold text-gray-900">OTP verified</p>
                        @elseif($proof->file_path && file_exists(public_path('storage/' . $proof->file_path)))
                            <img src="{{ asset('storage/' . $proof->file_path) }}" alt="Proof" class="mt-1 rounded-lg max-h-40 object-cover w-full">
                        @elseif($proof->file_path)
                            <p class="text-sm text-gray-700">{{ $proof->file_path }}</p>
                        @endif
                        @if($proof->rider)
                            <p class="text-xs text-gray-500 mt-2">Uploaded by {{ $proof->rider->name }}</p>
                        @endif
                        <p class="text-xs text-gray-400 mt-1">{{ $proof->created_at->format('M d, Y h:i A') }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @elseif($delivery->status === 'delivered')
        <div class="mb-6 flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-2xl p-5">
            <svg class="h-5 w-5 flex-shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <div>
                <p class="text-sm font-bold text-amber-700">No proof of delivery on record</p>
                <p class="text-sm text-amber-600 mt-0.5">This delivery was marked delivered without a recorded confirmation.</p>
            </div>
        </div>
    @endif

    {{-- Timeline --}}
    @if($delivery->statusLogs->count())
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 mb-6">
            <div class="flex items-center gap-2 mb-6">
                <div class="h-2 w-1 rounded-full bg-teal"></div>
                <h2 class="text-lg font-semibold text-gray-900">Status Timeline</h2>
            </div>
            <div class="relative">
                <div class="absolute left-2 top-1 bottom-1 w-0.5 bg-gray-200"></div>
                <div class="space-y-4">
                    @foreach($delivery->statusLogs->sortBy('created_at') as $log)
                        <div class="relative pl-10">
                            <div class="absolute left-1.5 top-1.5 h-3 w-3 rounded-full border-2 border-white {{ $dotColors[$log->status] ?? 'bg-gray-400' }} shadow"></div>
                            <div class="bg-gray-50 rounded-xl p-4">
                                <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                                    <span class="text-sm font-semibold text-gray-900">
                                        {{ \Illuminate\Support\Str::title(str_replace('_', ' ', $log->status)) }}
                                        @if(in_array($log->status, ['failed', 'cancelled']))<span class="ml-1 text-xs font-normal text-red-500">(final)</span>@endif
                                    </span>
                                    <span class="text-xs text-gray-500">{{ $log->created_at->format('M d, Y · h:i A') }}</span>
                                </div>
                                @if($log->notes)
                                    <p class="text-sm text-gray-600">{{ $log->notes }}</p>
                                @endif
                                @if($log->changer)
                                    <p class="text-xs text-gray-400 mt-1">Changed by: {{ $log->changer->name }}</p>
                                @endif
                                @if($log->location)
                                    <p class="text-xs text-gray-500 mt-1">📍 {{ $log->location }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- Actions --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6"
         x-data="{
             failOpen: false,
             cancelOpen: false,
             archiveOpen: false,
             overrideOpen: false,
             failReason: '',
             cancelReason: '',
             otherFail: '',
             otherCancel: ''
         }">

        <div class="flex items-center gap-2 mb-5">
            <div class="h-2 w-1 rounded-full bg-teal"></div>
            <h2 class="text-lg font-semibold text-gray-900">Actions</h2>
        </div>

        @if(!$delivery->archived_at)
            <div class="flex flex-wrap gap-3">
                @if($delivery->status === 'waiting_for_rider')
                    <form action="{{ route('deliveries.assign-rider', $delivery) }}" method="POST" class="w-full max-w-xl">
                        @csrf
                        <label for="rider_id" class="block text-sm font-medium text-gray-700 mb-1">Assign Rider</label>
                        <div class="flex flex-col sm:flex-row gap-2">
                            <select name="rider_id" id="rider_id" required
                                    class="flex-1 rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm">
                                <option value="">— Select a rider —</option>
                                @if($riderEligibility->where('eligible', true)->isNotEmpty())
                                    <optgroup label="✓ Available — Online">
                                        @foreach($riderEligibility->where('eligible', true) as $item)
                                            <option value="{{ $item['rider']->id }}">
                                                ✓ Online — {{ $item['rider']->name }} — {{ ucfirst($item['rider']->vehicle_type) }} — up to {{ $item['capacity'] }} kg
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endif
                                @if($riderEligibility->where('eligible', false)->isNotEmpty())
                                    <optgroup label="✕ Unavailable">
                                        @foreach($riderEligibility->where('eligible', false) as $item)
                                            <option value="" disabled>
                                                @if(!$item['is_online'])
                                                    ○ Offline — {{ $item['rider']->name }} — {{ ucfirst($item['rider']->vehicle_type) }} — {{ $item['reason'] }}
                                                @else
                                                    ✕ {{ $item['rider']->name }} — {{ ucfirst($item['rider']->vehicle_type) }} — {{ $item['reason'] }}
                                                @endif
                                            </option>
                                        @endforeach
                                    </optgroup>
                                @endif
                            </select>
                            <button type="submit" class="bg-teal hover:bg-teal-dark text-white font-semibold py-2 px-5 rounded-xl transition shadow-sm text-sm whitespace-nowrap">
                                Assign Rider
                            </button>
                        </div>
                        @if($riderEligibility->where('eligible', true)->isEmpty())
                            <p class="mt-2 text-xs text-amber-600">No eligible riders right now. Riders must be approved, active, online, have a verified vehicle, no active delivery, and sufficient capacity.</p>
                        @endif
                        @if($errors->has('rider_id'))
                            <p class="text-red-500 text-xs mt-1">{{ $errors->first('rider_id') }}</p>
                        @endif
                    </form>

                    <button type="button" @click="cancelOpen = true"
                            class="inline-flex items-center gap-2 self-start bg-white border border-gray-200 hover:border-red-300 hover:text-red-600 text-gray-600 font-semibold px-4 py-2.5 rounded-xl transition text-sm">
                        Cancel Delivery
                    </button>
                @endif

                @if($delivery->status === 'assigned')
                    <form action="{{ route('deliveries.update-status', $delivery) }}" method="POST">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="picked_up">
                        <button type="submit" class="bg-indigo-500 hover:bg-indigo-600 text-white font-semibold px-5 py-2.5 rounded-xl transition shadow-sm">
                            Confirm Pickup
                        </button>
                    </form>
                    <button type="button" @click="cancelOpen = true"
                            class="inline-flex items-center gap-2 bg-white border border-gray-200 hover:border-red-300 hover:text-red-600 text-gray-600 font-semibold px-4 py-2.5 rounded-xl transition text-sm">
                        Cancel Delivery
                    </button>
                @endif

                @if($delivery->status === 'picked_up')
                    <form action="{{ route('deliveries.update-status', $delivery) }}" method="POST">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="out_for_delivery">
                        <button type="submit" class="bg-purple-500 hover:bg-purple-600 text-white font-semibold px-5 py-2.5 rounded-xl transition shadow-sm">
                            Mark as Out for Delivery
                        </button>
                    </form>
                    <button type="button" @click="failOpen = true"
                            class="inline-flex items-center gap-2 bg-white border border-red-200 hover:border-red-300 hover:bg-red-50 text-red-600 font-semibold px-4 py-2.5 rounded-xl transition text-sm">
                        Mark as Failed
                    </button>
                @endif

                @if($delivery->status === 'out_for_delivery')
                    <form action="{{ route('deliveries.update-status', $delivery) }}" method="POST">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="status" value="delivered">
                        <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white font-semibold px-5 py-2.5 rounded-xl transition shadow-sm">
                            Mark as Delivered
                        </button>
                    </form>
                    <button type="button" @click="failOpen = true"
                            class="inline-flex items-center gap-2 bg-white border border-red-200 hover:border-red-300 hover:bg-red-50 text-red-600 font-semibold px-4 py-2.5 rounded-xl transition text-sm">
                        Mark as Failed
                    </button>
                @endif

                @if(in_array($delivery->status, ['delivered', 'failed', 'cancelled']))
                    <button type="button" @click="overrideOpen = true"
                            class="inline-flex items-center gap-2 bg-white border border-amber-300 hover:bg-amber-50 text-amber-700 font-semibold px-4 py-2.5 rounded-xl transition text-sm">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        Override Status
                    </button>
                @endif

                <button type="button" @click="archiveOpen = true"
                        class="inline-flex items-center gap-2 sm:ml-auto bg-white border border-gray-200 hover:border-gray-300 text-gray-600 font-semibold px-4 py-2.5 rounded-xl transition text-sm">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                    Archive
                </button>
            </div>
        @else
            <div class="flex flex-wrap items-center gap-4">
                <p class="text-sm text-gray-500">
                    Archived {{ $delivery->archived_at?->format('M d, Y h:i A') }}
                    @if($delivery->archiver) by {{ $delivery->archiver->name }}@endif
                    @if($delivery->archive_note) · “{{ $delivery->archive_note }}”@endif
                </p>
                <form action="{{ route('deliveries.restore', $delivery) }}" method="POST" x-data
                      x-on:submit.prevent="$el.submit()">
                    @csrf
                    <button type="submit" class="bg-teal hover:bg-teal-dark text-white font-semibold px-4 py-2 rounded-xl transition text-sm">
                        Restore Delivery
                    </button>
                </form>
            </div>
        @endif

        @if($errors->any())
            <div class="mt-4 bg-red-50 border border-red-200 rounded-xl px-4 py-3 text-sm text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        {{-- Fail Modal --}}
        <div x-show="failOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" x-transition.opacity>
            <div class="absolute inset-0 bg-black/40" @click="failOpen = false"></div>
            <form action="{{ route('deliveries.update-status', $delivery) }}" method="POST" class="relative bg-white rounded-2xl shadow-xl w-full max-w-md" x-transition>
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="failed">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h4 class="font-bold text-gray-900">Mark Delivery as Failed?</h4>
                    <p class="text-sm text-gray-500 mt-0.5">{{ $delivery->tracking_number }} will be marked failed and removed from active routes.</p>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Failure Reason <span class="text-red-500">*</span></label>
                        <select name="reason" x-model="failReason" required class="block w-full rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm">
                            <option value="">— Select a reason —</option>
                            @foreach(config('logistics.failure_reasons') as $reason)
                                <option value="{{ $reason }}">{{ $reason }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div x-show="failReason === 'Other'" x-cloak>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Please explain <span class="text-red-500">*</span></label>
                        <textarea name="other_reason" rows="3" class="block w-full rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm"></textarea>
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 rounded-b-2xl flex justify-end gap-3">
                    <button type="button" @click="failOpen = false" class="px-5 py-2.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-semibold rounded-xl transition">Cancel</button>
                    <button type="submit" class="px-5 py-2.5 bg-red-500 hover:bg-red-600 text-white text-sm font-bold rounded-xl transition">Confirm Failure</button>
                </div>
            </form>
        </div>

        {{-- Cancel Modal --}}
        <div x-show="cancelOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" x-transition.opacity>
            <div class="absolute inset-0 bg-black/40" @click="cancelOpen = false"></div>
            <form action="{{ route('deliveries.update-status', $delivery) }}" method="POST" class="relative bg-white rounded-2xl shadow-xl w-full max-w-md" x-transition>
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="cancelled">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h4 class="font-bold text-gray-900">Cancel Delivery?</h4>
                    <p class="text-sm text-gray-500 mt-0.5">This delivery will be cancelled and kept for records only.</p>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Cancellation Reason <span class="text-red-500">*</span></label>
                        <select name="reason" x-model="cancelReason" required class="block w-full rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm">
                            <option value="">— Select a reason —</option>
                            @foreach(config('logistics.cancellation_reasons') as $reason)
                                <option value="{{ $reason }}">{{ $reason }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div x-show="cancelReason === 'Other'" x-cloak>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Please explain <span class="text-red-500">*</span></label>
                        <textarea name="other_reason" rows="3" class="block w-full rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm"></textarea>
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 rounded-b-2xl flex justify-end gap-3">
                    <button type="button" @click="cancelOpen = false" class="px-5 py-2.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-semibold rounded-xl transition">Keep Delivery</button>
                    <button type="submit" class="px-5 py-2.5 bg-gray-800 hover:bg-black text-white text-sm font-bold rounded-xl transition">Cancel Delivery</button>
                </div>
            </form>
        </div>

        {{-- Override Modal --}}
        <div x-show="overrideOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" x-transition.opacity>
            <div class="absolute inset-0 bg-black/40" @click="overrideOpen = false"></div>
            <form action="{{ route('deliveries.update-status', $delivery) }}" method="POST" class="relative bg-white rounded-2xl shadow-xl w-full max-w-md" x-transition>
                @csrf
                @method('PATCH')
                <input type="hidden" name="override" value="1">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h4 class="font-bold text-gray-900">Override Delivery Status?</h4>
                    <p class="text-sm text-gray-500 mt-0.5">
                        This is a Logistics Manager override. The change from
                        <strong>{{ str_replace('_', ' ', $delivery->status) }}</strong> will be permanently recorded in the audit timeline together with your reason.
                    </p>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">New Status <span class="text-red-500">*</span></label>
                        <select name="status" required class="block w-full rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm">
                            <option value="">— Select target status —</option>
                            @foreach(['waiting_for_rider', 'assigned', 'picked_up', 'out_for_delivery'] as $target)
                                @if($target !== $delivery->status)
                                    <option value="{{ $target }}">{{ ucfirst(str_replace('_', ' ', $target)) }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Override Reason <span class="text-red-500">*</span></label>
                        <textarea name="override_reason" required minlength="3" rows="3" placeholder="Explain why this override is necessary..."
                                  class="block w-full rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm"></textarea>
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 rounded-b-2xl flex justify-end gap-3">
                    <button type="button" @click="overrideOpen = false" class="px-5 py-2.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-semibold rounded-xl transition">Cancel</button>
                    <button type="submit" class="px-5 py-2.5 bg-amber-500 hover:bg-amber-600 text-white text-sm font-bold rounded-xl transition">Apply Override</button>
                </div>
            </form>
        </div>

        {{-- Archive Modal --}}
        <div x-show="archiveOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" x-transition.opacity>
            <div class="absolute inset-0 bg-black/40" @click="archiveOpen = false"></div>
            <form action="{{ route('deliveries.archive', $delivery) }}" method="POST" class="relative bg-white rounded-2xl shadow-xl w-full max-w-md" x-transition>
                @csrf
                <div class="px-6 py-4 border-b border-gray-100">
                    <h4 class="font-bold text-gray-900">Archive Delivery?</h4>
                    <p class="text-sm text-gray-500 mt-0.5">This delivery will be removed from active deliveries but preserved in the archive for records and auditing.</p>
                </div>
                <div class="px-6 py-5">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Archive note (optional)</label>
                    <input type="text" name="archive_note" maxlength="255" placeholder="e.g. Duplicate of Order #1042"
                           class="block w-full rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm">
                </div>
                <div class="px-6 py-4 bg-gray-50 rounded-b-2xl flex justify-end gap-3">
                    <button type="button" @click="archiveOpen = false" class="px-5 py-2.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-semibold rounded-xl transition">Cancel</button>
                    <button type="submit" class="px-5 py-2.5 bg-gray-800 hover:bg-black text-white text-sm font-bold rounded-xl transition">Archive Delivery</button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
