<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-2xl font-bold text-gray-900 leading-tight">Sorting Center</h2>
                <p class="mt-1 text-sm text-gray-500">Receive, scan, sort, and dispatch parcels — assignment happens on the delivery record</p>
            </div>
            <a href="{{ route('deliveries.scan-page') }}"
               class="shrink-0 text-sm font-semibold text-white bg-teal hover:bg-teal-dark rounded-xl px-4 py-2 transition-colors">
                Scan Parcel
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-6xl mx-auto px-4 sm:px-6">
            {{-- Summary counts --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-2 mb-4">
                @php
                    $cards = [
                        ['Incoming', $counts['incoming'], 'bg-amber-50 text-amber-800'],
                        ['Received', $counts['received'], 'bg-blue-50 text-blue-800'],
                        ['Scanned', $counts['scanned'], 'bg-indigo-50 text-indigo-800'],
                        ['Sorted', $counts['sorted'], 'bg-purple-50 text-purple-800'],
                        ['Dispatched', $counts['dispatched'], 'bg-gray-100 text-gray-800'],
                        ['Assigned', $counts['assigned'], 'bg-teal-light text-teal-dark'],
                        ['Returned', $counts['returned'], 'bg-red-50 text-red-800'],
                        ['Re-attempt', $counts['reattempt'], 'bg-orange-50 text-orange-800'],
                    ];
                @endphp
                @foreach($cards as [$label, $value, $classes])
                    <div class="rounded-xl border border-gray-100 bg-white px-3 py-3 text-center shadow-sm">
                        <p class="text-xl font-extrabold text-gray-900">{{ $value }}</p>
                        <p class="mt-0.5 text-[11px] font-semibold"><span class="inline-block rounded-full px-2 py-0.5 {{ $classes }}">{{ $label }}</span></p>
                    </div>
                @endforeach
            </div>

            {{-- Tabs + search + filters --}}
            <div class="flex flex-wrap items-center gap-2 mb-4">
                @php
                    $tabs = ['all' => 'All', 'incoming' => 'Incoming', 'received' => 'Received', 'scanned' => 'Scanned', 'sorted' => 'Sorted', 'dispatched' => 'Dispatched', 'returned' => 'Returned', 'reattempt' => 'Re-attempt'];
                @endphp
                @foreach($tabs as $key => $label)
                    <a href="{{ route('sorting-center.index', array_filter(['tab' => $key === 'all' ? null : $key, 'search' => $search ?: null, 'center_id' => $centerId ?: null, 'service_area_id' => $serviceAreaId ?: null])) }}"
                       class="text-[13px] font-semibold rounded-xl px-3 py-1.5 transition-colors {{ $tab === $key ? 'bg-teal text-white' : 'bg-white text-gray-600 border border-gray-200 hover:border-teal' }}">
                        {{ $label }}
                    </a>
                @endforeach
                <form method="GET" action="{{ route('sorting-center.index') }}" class="ms-auto flex flex-wrap items-center gap-2">
                    @if($tab !== 'all')
                        <input type="hidden" name="tab" value="{{ $tab }}">
                    @endif
                    <input type="search" name="search" value="{{ $search }}" placeholder="Tracking, sender, recipient…"
                           class="text-sm rounded-xl px-3 py-1.5 bg-white text-gray-700 border border-gray-200 focus:border-teal focus:outline-none w-52">
                    @if(!$staffCenterId)
                        <select name="center_id" onchange="this.form.submit()" aria-label="Filter by center"
                                class="text-sm rounded-xl px-2 py-1.5 bg-white text-gray-700 border border-gray-200 focus:border-teal focus:outline-none max-w-44">
                            <option value="">All centers</option>
                            @foreach($centers as $center)
                                <option value="{{ $center->id }}" @selected($centerId === $center->id)>{{ $center->name }}</option>
                            @endforeach
                        </select>
                    @endif
                    <select name="service_area_id" onchange="this.form.submit()" aria-label="Filter by service area"
                            class="text-sm rounded-xl px-2 py-1.5 bg-white text-gray-700 border border-gray-200 focus:border-teal focus:outline-none max-w-44">
                        <option value="">All areas</option>
                        @foreach($serviceAreas as $area)
                            <option value="{{ $area->id }}" @selected($serviceAreaId === $area->id)>{{ $area->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="text-sm font-semibold text-teal hover:text-teal-dark">Search</button>
                </form>
            </div>

            {{-- Parcel table --}}
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wide text-gray-400 border-b border-gray-100">
                                <th class="px-4 py-3 font-semibold">Tracking</th>
                                <th class="px-4 py-3 font-semibold">Route</th>
                                <th class="px-4 py-3 font-semibold">Parcel</th>
                                <th class="px-4 py-3 font-semibold">Delivery</th>
                                <th class="px-4 py-3 font-semibold">Center / Area</th>
                                <th class="px-4 py-3 font-semibold">Rider</th>
                                <th class="px-4 py-3 font-semibold text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            @forelse($deliveries as $delivery)
                                <tr class="hover:bg-gray-50/60">
                                    <td class="px-4 py-3 font-bold text-gray-900 whitespace-nowrap">
                                        {{ $delivery->tracking_number }}
                                        @if($delivery->returned_at)
                                            <span class="ml-1 text-[10px] font-bold uppercase tracking-wide text-red-700 bg-red-50 rounded-full px-2 py-0.5">Returned</span>
                                        @endif
                                        @if($delivery->reattempt_requested_at)
                                            <span class="ml-1 text-[10px] font-bold uppercase tracking-wide text-orange-700 bg-orange-50 rounded-full px-2 py-0.5">Re-attempt</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-[13px] text-gray-600">
                                        {{ $delivery->sender_name }} <span aria-hidden="true">→</span> {{ $delivery->recipient_name }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="text-[11px] font-bold uppercase tracking-wide text-gray-700 bg-gray-100 rounded-full px-2 py-0.5">{{ str_replace('_', ' ', $delivery->parcel_status) }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="text-[11px] font-bold uppercase tracking-wide text-teal-dark bg-teal-light/60 rounded-full px-2 py-0.5">{{ str_replace('_', ' ', $delivery->status) }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-[13px] text-gray-600">
                                        {{ $delivery->logisticsCenter?->name ?? '—' }}
                                        <span class="text-gray-400">/</span>
                                        {{ $delivery->serviceArea?->name ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-[13px] text-gray-600">{{ $delivery->rider?->name ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center justify-end gap-1.5 flex-wrap">
                                            <a href="{{ route('deliveries.show', $delivery) }}"
                                               class="text-[12px] font-semibold text-teal hover:text-teal-dark border border-teal/30 hover:border-teal rounded-lg px-2.5 py-1">Open</a>
                                            @if($delivery->parcel_status === 'pending_arrival')
                                                <form method="POST" action="{{ route('deliveries.receive', $delivery) }}" class="inline">
                                                    @csrf
                                                    <button type="submit" class="text-[12px] font-semibold text-white bg-teal hover:bg-teal-dark rounded-lg px-2.5 py-1">Receive</button>
                                                </form>
                                            @endif
                                            @if(in_array($delivery->parcel_status, ['received', 'scanned'], true))
                                                <details class="relative inline">
                                                    <summary class="cursor-pointer list-none text-[12px] font-semibold text-white bg-purple-600 hover:bg-purple-700 rounded-lg px-2.5 py-1">Sort</summary>
                                                    <form method="POST" action="{{ route('deliveries.sort', $delivery) }}"
                                                          class="absolute right-0 z-10 mt-1 w-56 bg-white rounded-xl shadow-lg ring-1 ring-gray-200 p-3 flex flex-col gap-2">
                                                        @csrf
                                                        <label class="text-[11px] font-semibold text-gray-500">Destination center
                                                            <select name="destination_center_id" required
                                                                    class="mt-1 w-full text-[13px] rounded-lg border border-gray-200 px-2 py-1.5">
                                                                @foreach($centers as $center)
                                                                    <option value="{{ $center->id }}" @selected($delivery->destination_center_id === $center->id)>{{ $center->name }}</option>
                                                                @endforeach
                                                            </select>
                                                        </label>
                                                        <label class="text-[11px] font-semibold text-gray-500">Service area
                                                            <select name="service_area_id" required
                                                                    class="mt-1 w-full text-[13px] rounded-lg border border-gray-200 px-2 py-1.5">
                                                                @foreach($serviceAreas as $area)
                                                                    <option value="{{ $area->id }}" @selected($delivery->service_area_id === $area->id)>{{ $area->name }}</option>
                                                                @endforeach
                                                            </select>
                                                        </label>
                                                        <button type="submit" class="text-[12px] font-bold text-white bg-purple-600 hover:bg-purple-700 rounded-lg px-2.5 py-1.5">Confirm sort</button>
                                                    </form>
                                                </details>
                                            @endif
                                            @if($delivery->parcel_status === 'sorted')
                                                <form method="POST" action="{{ route('deliveries.dispatch', $delivery) }}" class="inline">
                                                    @csrf
                                                    <button type="submit" class="text-[12px] font-semibold text-white bg-gray-700 hover:bg-gray-900 rounded-lg px-2.5 py-1">Dispatch</button>
                                                </form>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-12 text-center">
                                        <p class="text-4xl mb-3" aria-hidden="true">📦</p>
                                        <p class="text-sm font-semibold text-gray-900">No parcels here</p>
                                        <p class="text-[13px] text-gray-500 mt-1">Try another tab or clear the search.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if($deliveries->hasPages())
                <div class="mt-4">
                    {{ $deliveries->links() }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
