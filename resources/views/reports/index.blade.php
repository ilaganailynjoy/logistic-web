<x-app-layout>
    @php
        $tabs = [
            'delivery' => 'Delivery Report',
            'center' => 'Center Report',
            'area' => 'Service Area Report',
            'rider' => 'Rider Report',
            'financial' => 'Financial Report',
        ];
        $tab = in_array(request('tab', 'delivery'), array_keys($tabs)) ? request('tab') : 'delivery';
        $tabUrl = fn ($t) => route('reports.index', collect(request()->query())->except('tab')->merge(['tab' => $t])->all());
        $f = $filters;
    @endphp

    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Reports</h1>
            <p class="text-sm text-gray-500 mt-1">Operational and financial analytics</p>
        </div>

        {{-- Tabs --}}
        <div class="flex flex-wrap gap-2">
            @foreach($tabs as $key => $label)
                <a href="{{ $tabUrl($key) }}"
                   class="px-4 py-2 rounded-full text-sm font-medium transition whitespace-nowrap {{ $tab === $key ? 'bg-teal text-white shadow-sm' : 'bg-white border border-gray-200 text-gray-600 hover:border-teal hover:text-teal-dark' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        {{-- Filters --}}
        <form method="GET" action="{{ route('reports.index') }}" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <div class="flex flex-col lg:flex-row lg:items-end gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">From</label>
                    <input type="date" name="date_from" value="{{ $f['dateFrom'] ?? request('date_from') }}" class="rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">To</label>
                    <input type="date" name="date_to" value="{{ $f['dateTo'] ?? request('date_to') }}" class="rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm">
                </div>
                @if($tab !== 'center')
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Center</label>
                    <select name="center_id" class="rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm">
                        <option value="0">All Centers</option>
                        @foreach($centers as $c)
                            <option value="{{ $c->id }}" @selected((int) ($f['centerId'] ?? 0) === $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                @if($tab === 'delivery' || $tab === 'rider')
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Rider</label>
                    <select name="rider_id" class="rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm">
                        <option value="0">All Riders</option>
                        @foreach($riders as $r)
                            <option value="{{ $r->id }}" @selected((int) ($f['riderId'] ?? 0) === $r->id)>{{ $r->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                @if($tab === 'delivery' || $tab === 'area')
                <div>
                    <label class="block text-xs font-medium text-gray-500 mb-1">Service Area</label>
                    <select name="service_area_id" class="rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm">
                        <option value="0">All Areas</option>
                        @foreach($serviceAreas as $a)
                            <option value="{{ $a->id }}" @selected((int) ($f['serviceAreaId'] ?? 0) === $a->id)>{{ $a->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                <button type="submit" class="bg-gray-900 hover:bg-gray-800 text-white font-semibold px-5 py-2.5 rounded-xl transition text-sm">Apply</button>
            </div>
        </form>

        {{-- Delivery Report --}}
        @if($tab === 'delivery')
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                @php
                    $cards = [
                        ['Total', $deliveryStats['total'] ?? 0],
                        ['Received', $deliveryStats['received'] ?? 0],
                        ['Scanned', $deliveryStats['scanned'] ?? 0],
                        ['Sorted', $deliveryStats['sorted'] ?? 0],
                        ['Waiting', $deliveryStats['waiting_for_rider'] ?? 0],
                        ['Assigned', $deliveryStats['assigned'] ?? 0],
                        ['Picked Up', $deliveryStats['picked_up'] ?? 0],
                        ['Out for Delivery', $deliveryStats['out_for_delivery'] ?? 0],
                        ['Delivered', $deliveryStats['delivered'] ?? 0],
                        ['Failed', $deliveryStats['failed'] ?? 0],
                        ['Cancelled', $deliveryStats['cancelled'] ?? 0],
                    ];
                @endphp
                @foreach($cards as [$label, $val])
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">{{ $label }}</p>
                        <p class="text-2xl font-bold text-gray-900">{{ $val }}</p>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Center Report --}}
        @if($tab === 'center')
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50/50">
                        <tr>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Center</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">City</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Deliveries</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Service Areas</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Riders</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($centerStats as $c)
                            <tr class="hover:bg-teal-light/40 transition">
                                <td class="px-6 py-4 text-sm font-semibold text-gray-900">{{ $c['name'] }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $c['city'] }}</td>
                                <td class="px-6 py-4 text-right text-sm text-gray-900">{{ $c['deliveries_count'] ?? 0 }}</td>
                                <td class="px-6 py-4 text-right text-sm text-gray-500">{{ $c['service_areas_count'] ?? 0 }}</td>
                                <td class="px-6 py-4 text-right text-sm text-gray-500">{{ $c['riders_count'] ?? 0 }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-12 text-center text-sm text-gray-500">No centers found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Service Area Report --}}
        @if($tab === 'area')
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50/50">
                        <tr>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Area</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Center</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Deliveries</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Riders</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($areaStats as $a)
                            <tr class="hover:bg-teal-light/40 transition">
                                <td class="px-6 py-4 text-sm font-semibold text-gray-900">{{ $a['name'] }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ \App\Models\ServiceArea::find($a['id'])?->logisticsCenter?->name ?? '—' }}</td>
                                <td class="px-6 py-4 text-right text-sm text-gray-900">{{ $a['deliveries_count'] ?? 0 }}</td>
                                <td class="px-6 py-4 text-right text-sm text-gray-500">{{ $a['riders_count'] ?? 0 }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-6 py-12 text-center text-sm text-gray-500">No service areas found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Rider Report --}}
        @if($tab === 'rider')
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50/50">
                        <tr>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Rider</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Vehicle</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Total</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Active</th>
                            <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Delivered</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($riderStats as $r)
                            <tr class="hover:bg-teal-light/40 transition">
                                <td class="px-6 py-4 text-sm font-semibold text-gray-900">{{ $r['name'] }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ ucfirst($r['vehicle_type'] ?? '—') }}</td>
                                <td class="px-6 py-4 text-right text-sm text-gray-900">{{ $r['total_deliveries'] ?? 0 }}</td>
                                <td class="px-6 py-4 text-right text-sm text-gray-500">{{ $r['active_deliveries'] ?? 0 }}</td>
                                <td class="px-6 py-4 text-right text-sm text-gray-500">{{ $r['completed_deliveries'] ?? 0 }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-12 text-center text-sm text-gray-500">No riders found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Financial Report --}}
        @if($tab === 'financial')
            @php
                $fs = $financialStats;
                $currency = fn ($v) => '₱' . number_format((float) ($v ?? 0), 2);
            @endphp
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Transactions</p>
                    <p class="text-3xl font-bold text-gray-900">{{ number_format((int) ($fs['total_transactions'] ?? 0)) }}</p>
                </div>
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Total Amount</p>
                    <p class="text-3xl font-bold text-gray-900">{{ $currency($fs['total_amount'] ?? 0) }}</p>
                </div>
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Rider Fees</p>
                    <p class="text-3xl font-bold text-gray-900">{{ $currency($fs['total_rider_fees'] ?? 0) }}</p>
                </div>
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Admin Commissions</p>
                    <p class="text-3xl font-bold text-teal-dark">{{ $currency($fs['total_commissions'] ?? 0) }}</p>
                </div>
            </div>
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-base font-bold text-gray-900 mb-4">Completed Transactions Breakdown</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                    <div><p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Completed Amount</p><p class="text-lg font-bold text-gray-900">{{ $currency($fs['completed_amount'] ?? 0) }}</p></div>
                    <div><p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Completed Rider Fees</p><p class="text-lg font-bold text-gray-900">{{ $currency($fs['completed_rider_fees'] ?? 0) }}</p></div>
                    <div><p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-1">Completed Commissions</p><p class="text-lg font-bold text-teal-dark">{{ $currency($fs['completed_commissions'] ?? 0) }}</p></div>
                </div>
            </div>
        @endif
    </div>
</x-app-layout>
