<x-app-layout>
    <div class="space-y-6">
        {{-- Header --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Dashboard Overview</h1>
                <p class="mt-1 text-sm text-gray-500">{{ now()->format('l, F j, Y') }} · Financial figures shown are for <span class="font-semibold text-gray-700">Today</span></p>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('tracking.index') }}" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-gray-700 bg-white border border-gray-200 shadow-sm hover:bg-gray-50 hover:border-gray-300 transition">
                    <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    Track Package
                </a>
                <a href="{{ route('deliveries.index') }}" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-teal hover:bg-teal-dark shadow-sm shadow-teal/20 hover:shadow-teal/30 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                    View Deliveries
                </a>
            </div>
        </div>

        {{-- Deliveries summary --}}
        <section>
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-bold text-gray-900 uppercase tracking-wider text-gray-500">Deliveries</h2>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
                <a href="{{ route('deliveries.index') }}" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:shadow-lg hover:border-teal/30 transition-all duration-200">
                    <p class="text-xs font-medium text-gray-500">Total Deliveries</p>
                    <p class="mt-0.5 text-2xl font-bold text-gray-900">{{ $deliveries['total'] }}</p>
                    <p class="text-xs font-medium text-teal-dark mt-0.5">All time</p>
                </a>
                <a href="{{ route('deliveries.index', ['status' => 'waiting_for_rider']) }}" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:shadow-lg hover:border-amber-300 transition-all duration-200">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-gray-500">Waiting for Rider</p>
                        <span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span>
                    </div>
                    <p class="mt-0.5 text-2xl font-bold text-gray-900">{{ $deliveries['waiting_for_rider'] }}</p>
                    <p class="text-xs font-medium text-amber-500 mt-0.5">Needs dispatch</p>
                </a>
                <a href="{{ route('deliveries.index', ['status' => 'assigned']) }}" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:shadow-lg hover:border-blue-300 transition-all duration-200">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-gray-500">Assigned</p>
                        <span class="h-2.5 w-2.5 rounded-full bg-blue-500"></span>
                    </div>
                    <p class="mt-0.5 text-2xl font-bold text-gray-900">{{ $deliveries['assigned'] }}</p>
                    <p class="text-xs font-medium text-blue-500 mt-0.5">With rider</p>
                </a>
                <a href="{{ route('deliveries.index', ['status' => 'out_for_delivery']) }}" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:shadow-lg hover:border-purple-300 transition-all duration-200">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-gray-500">Out for Delivery</p>
                        <span class="h-2.5 w-2.5 rounded-full bg-purple-500"></span>
                    </div>
                    <p class="mt-0.5 text-2xl font-bold text-gray-900">{{ $deliveries['out_for_delivery'] }}</p>
                    <p class="text-xs font-medium text-purple-500 mt-0.5">Currently delivering</p>
                </a>
                <a href="{{ route('deliveries.index', ['status' => 'delivered']) }}" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 hover:shadow-lg hover:border-emerald-300 transition-all duration-200">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-gray-500">Delivered</p>
                        <span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                    </div>
                    <p class="mt-0.5 text-2xl font-bold text-gray-900">{{ $deliveries['delivered'] }}</p>
                    <p class="text-xs font-medium text-emerald-500 mt-0.5">Completed</p>
                </a>
                <a href="{{ route('deliveries.index', ['status' => 'delivery_failed']) }}" class="bg-white rounded-2xl border {{ $deliveries['failed'] > 0 ? 'border-red-200 ring-1 ring-red-100' : 'border-gray-100' }} shadow-sm p-5 hover:shadow-lg hover:border-red-300 transition-all duration-200">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-medium text-gray-500">Delivery Failed</p>
                        <span class="h-2.5 w-2.5 rounded-full {{ $deliveries['failed'] > 0 ? 'bg-red-500 animate-pulse' : 'bg-gray-300' }}"></span>
                    </div>
                    <p class="mt-0.5 text-2xl font-bold {{ $deliveries['failed'] > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $deliveries['failed'] }}</p>
                    <p class="text-xs font-medium {{ $deliveries['failed'] > 0 ? 'text-red-500' : 'text-gray-400' }} mt-0.5">{{ $deliveries['failed'] > 0 ? 'Requires attention' : 'No issues' }}</p>
                </a>
            </div>
        </section>

        {{-- Riders + Financial summary --}}
        <section class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Riders</p>
                <div class="mt-3 grid grid-cols-3 gap-4">
                    <a href="{{ route('riders.index') }}" class="rounded-xl border border-gray-100 p-3 hover:border-teal/30 transition">
                        <p class="text-xs text-gray-500">Total Riders</p>
                        <p class="text-2xl font-bold text-gray-900">{{ $riders['total'] }}</p>
                    </a>
                    <a href="{{ route('riders.index', ['online' => 'online']) }}" class="rounded-xl border border-emerald-100 bg-emerald-50/40 p-3 hover:border-emerald-300 transition">
                        <p class="text-xs text-emerald-600">Online</p>
                        <p class="text-2xl font-bold text-emerald-700">{{ $riders['online'] }}</p>
                        <p class="text-[11px] text-emerald-600 mt-0.5">Available now</p>
                    </a>
                    <a href="{{ route('riders.index', ['online' => 'offline']) }}" class="rounded-xl border border-gray-100 p-3 hover:border-gray-300 transition">
                        <p class="text-xs text-gray-500">Offline</p>
                        <p class="text-2xl font-bold text-gray-900">{{ $riders['offline'] }}</p>
                        <p class="text-[11px] text-gray-400 mt-0.5">Not for new deliveries</p>
                    </a>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Financial — Today</p>
                <div class="mt-3 grid grid-cols-3 gap-4">
                    <a href="{{ route('transactions.index') }}" class="rounded-xl border border-gray-100 p-3 hover:border-teal/30 transition">
                        <p class="text-xs text-gray-500">Today's Transactions</p>
                        <p class="text-2xl font-bold text-gray-900">{{ number_format($financialToday['count']) }}</p>
                    </a>
                    <a href="{{ route('transactions.index') }}" class="rounded-xl border border-gray-100 p-3 hover:border-teal/30 transition">
                        <p class="text-xs text-gray-500">Today's Rider Fees</p>
                        <p class="text-2xl font-bold text-teal-dark">{{ '₱' . number_format($financialToday['rider_fee'], 2) }}</p>
                    </a>
                    <a href="{{ route('transactions.index') }}" class="rounded-xl border border-gray-100 p-3 hover:border-teal/30 transition">
                        <p class="text-xs text-gray-500">Today's Admin Commission</p>
                        <p class="text-2xl font-bold text-teal-dark">{{ '₱' . number_format($financialToday['commission'], 2) }}</p>
                    </a>
                </div>
            </div>
        </section>

        {{-- Needs Attention --}}
        <section class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="px-5 sm:px-6 py-4 border-b border-gray-100 flex items-center gap-2">
                <span class="h-2.5 w-2.5 rounded-full bg-red-500"></span>
                <h2 class="text-base font-bold text-gray-900">Needs Attention</h2>
            </div>

            @php $attentionTotal = $needsAttention['waitingForRider'] + $needsAttention['failed'] + $needsAttention['processing']; @endphp

            @if($attentionTotal === 0)
                <div class="px-5 sm:px-6 py-10 text-center">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-emerald-50 text-emerald-500 mb-3">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <p class="text-sm font-semibold text-gray-700">All current deliveries are progressing normally.</p>
                    <p class="text-sm text-gray-500 mt-1">No items require Logistics attention right now.</p>
                </div>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-gray-100">
                    <a href="{{ route('deliveries.index', ['status' => 'waiting_for_rider']) }}" class="flex items-center justify-between gap-3 px-5 sm:px-6 py-5 hover:bg-amber-50/40 transition group {{ $needsAttention['waitingForRider'] === 0 ? 'opacity-60' : '' }}">
                        <div>
                            <p class="text-sm font-semibold text-gray-900">Waiting for rider</p>
                            <p class="text-xs text-gray-500 mt-0.5">Unassigned deliveries</p>
                        </div>
                        <div class="text-right">
                            <span class="inline-flex items-center justify-center min-w-[2.5rem] px-2 py-1 rounded-lg bg-amber-100 text-amber-700 font-bold">{{ $needsAttention['waitingForRider'] }}</span>
                            <p class="text-xs font-semibold text-teal group-hover:underline mt-1">View</p>
                        </div>
                    </a>
                    <a href="{{ route('deliveries.index', ['status' => 'delivery_failed']) }}" class="flex items-center justify-between gap-3 px-5 sm:px-6 py-5 hover:bg-red-50/40 transition group {{ $needsAttention['failed'] === 0 ? 'opacity-60' : '' }}">
                        <div>
                            <p class="text-sm font-semibold text-gray-900">Failed deliveries</p>
                            <p class="text-xs text-gray-500 mt-0.5">Failed out for delivery</p>
                        </div>
                        <div class="text-right">
                            <span class="inline-flex items-center justify-center min-w-[2.5rem] px-2 py-1 rounded-lg bg-red-100 text-red-700 font-bold">{{ $needsAttention['failed'] }}</span>
                            <p class="text-xs font-semibold text-teal group-hover:underline mt-1">View</p>
                        </div>
                    </a>
                    <a href="{{ route('deliveries.index', ['status' => 'waiting_for_rider']) }}" class="flex items-center justify-between gap-3 px-5 sm:px-6 py-5 hover:bg-teal-light/40 transition group {{ $needsAttention['processing'] === 0 ? 'opacity-60' : '' }}">
                        <div>
                            <p class="text-sm font-semibold text-gray-900">Requires processing</p>
                            <p class="text-xs text-gray-500 mt-0.5">Parcels at center not yet dispatched</p>
                        </div>
                        <div class="text-right">
                            <span class="inline-flex items-center justify-center min-w-[2.5rem] px-2 py-1 rounded-lg bg-teal-light text-teal-dark font-bold">{{ $needsAttention['processing'] }}</span>
                            <p class="text-xs font-semibold text-teal group-hover:underline mt-1">View</p>
                        </div>
                    </a>
                </div>
            @endif
        </section>

        {{-- Delivery Status Overview --}}
        <section class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-6">
            <h2 class="text-base font-bold text-gray-900">Delivery Status Overview</h2>
            <p class="text-sm text-gray-500 mt-1">Current deliveries by status</p>

            @php $chartTotal = $status_counts->sum(); @endphp
            @if($chartTotal === 0)
                <div class="mt-4 py-8 text-center text-sm text-gray-500">No deliveries yet. New deliveries will appear here.</div>
            @else
                <div class="mt-4 space-y-3">
                    @foreach($status_labels as $key => $label)
                        @php $count = (int) ($status_counts[$key] ?? 0); @endphp
                        @if($count > 0)
                            <a href="{{ route('deliveries.index', ['status' => $key]) }}" class="block group">
                                <div class="flex items-center justify-between text-sm mb-1">
                                    <span class="text-gray-600 group-hover:text-gray-900 font-medium">{{ $label }}</span>
                                    <span class="font-bold text-gray-900">{{ $count }} <span class="text-gray-400 font-normal text-xs">{{ $chartTotal > 0 ? round($count / $chartTotal * 100) . '%' : '' }}</span></span>
                                </div>
                                <div class="h-2 rounded-full bg-gray-100 overflow-hidden">
                                    <div class="h-full rounded-full bg-teal transition-all duration-500" style="width: {{ $chartTotal > 0 ? round($count / $chartTotal * 100, 1) : 0 }}%"></div>
                                </div>
                            </a>
                        @endif
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Rider availability --}}
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-bold text-gray-900">Rider Availability</h2>
                    <a href="{{ route('riders.index') }}" class="text-sm font-semibold text-teal hover:text-teal-dark">View Riders →</a>
                </div>
                <div class="flex items-center gap-6 mt-4">
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full bg-green-500"></span>
                        <span class="text-sm text-gray-600">Online — Available</span>
                        <span class="text-lg font-bold text-gray-900 ml-1">{{ $riders['online'] }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full bg-gray-300"></span>
                        <span class="text-sm text-gray-600">Offline — Not available for new deliveries</span>
                        <span class="text-lg font-bold text-gray-900 ml-1">{{ $riders['offline'] }}</span>
                    </div>
                </div>

                <div class="mt-5">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Currently Online</p>
                    @forelse ($onlineRiders as $rider)
                        <a href="{{ route('riders.show', $rider) }}" class="flex items-center gap-3 py-2.5 px-2 -mx-2 rounded-lg hover:bg-teal-light/40 transition group">
                            <span class="relative flex-shrink-0">
                                <span class="h-9 w-9 rounded-full bg-teal flex items-center justify-center text-white text-sm font-bold">{{ strtoupper(substr($rider->name, 0, 1)) }}</span>
                                <span class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full bg-green-500 border-2 border-white"></span>
                            </span>
                            <span class="flex-1 min-w-0">
                                <span class="block text-sm font-semibold text-gray-900 truncate">{{ $rider->name }}</span>
                                <span class="block text-xs text-gray-500 truncate">{{ $rider->serviceArea?->name ?? 'No service area' }}</span>
                            </span>
                            <span class="text-xs text-gray-500">{{ $rider->active_deliveries }} active</span>
                        </a>
                    @empty
                        <div class="py-6 text-center text-sm text-gray-500">No riders are currently available for new assignments.</div>
                    @endforelse
                </div>
            </div>

            {{-- Recent activity --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-6">
                <h2 class="text-base font-bold text-gray-900">Recent Activity</h2>
                <div class="mt-4">
                    @forelse ($activities as $activity)
                        <a href="{{ route('deliveries.index', ['search' => $activity['tracking']]) }}" class="flex items-start gap-3 py-2.5 px-1 -mx-1 rounded-lg hover:bg-gray-50 transition group">
                            <span class="mt-1 flex-shrink-0 h-2.5 w-2.5 rounded-full mt-1.5 {{ $activity['status'] === 'delivery_failed' ? 'bg-red-400' : ($activity['status'] === 'delivered' ? 'bg-emerald-400' : 'bg-teal') }}"></span>
                            <span class="flex-1 min-w-0">
                                <span class="block text-sm text-gray-700"><span class="font-mono font-semibold text-gray-900">{{ $activity['tracking'] }}</span> — {{ $activity['message'] }}</span>
                                <span class="block text-xs text-gray-400 mt-0.5">{{ $activity['created_at'] ? $activity['created_at']->diffForHumans() : '' }}{{ $activity['by'] ? ' · by ' . $activity['by'] : '' }}{{ $activity['recipient'] ? ' · ' . $activity['recipient'] : '' }}</span>
                            </span>
                        </a>
                    @empty
                        <div class="py-6 text-center text-sm text-gray-500">No activity recorded yet.</div>
                    @endforelse
                </div>
            </div>
        </section>

        {{-- Service area + rider workload --}}
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="px-5 sm:px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-base font-bold text-gray-900">Service Area Overview</h2>
                    <a href="{{ route('deliveries.index') }}" class="text-sm font-semibold text-teal hover:text-teal-dark">View All →</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Service Area</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Deliveries</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Active</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($serviceAreas as $area)
                                <tr class="hover:bg-teal-light/40 transition">
                                    <td class="px-5 py-3.5 text-sm font-medium text-gray-900">{{ $area['name'] }}</td>
                                    <td class="px-5 py-3.5 text-sm text-right text-gray-900">{{ $area['total'] }}</td>
                                    <td class="px-5 py-3.5 text-sm text-right text-teal-dark font-semibold">{{ $area['active'] }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-5 py-10 text-center text-sm text-gray-500">No service area data yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="px-5 sm:px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h2 class="text-base font-bold text-gray-900">Rider Workload</h2>
                    <a href="{{ route('riders.index') }}" class="text-sm font-semibold text-teal hover:text-teal-dark">View Riders →</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Rider</th>
                                <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Area</th>
                                <th class="px-5 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Active</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($riderWorkload as $rider)
                                <tr class="hover:bg-teal-light/40 transition">
                                    <td class="px-5 py-3.5 whitespace-nowrap">
                                        <div class="flex items-center gap-2.5">
                                            @if($rider->is_online)
                                                <span class="h-2 w-2 rounded-full bg-green-500 flex-shrink-0" title="Online — available"></span>
                                            @else
                                                <span class="h-2 w-2 rounded-full bg-gray-300 flex-shrink-0" title="Offline — not available for new deliveries"></span>
                                            @endif
                                            <a href="{{ route('riders.show', $rider) }}" class="text-sm font-medium text-gray-900 hover:text-teal-dark">{{ $rider->name }}</a>
                                        </div>
                                    </td>
                                    <td class="px-5 py-3.5 text-sm text-gray-500">{{ $rider->serviceArea?->name ?? '—' }}</td>
                                    <td class="px-5 py-3.5 text-sm text-right">
                                        @if($rider->active_deliveries > 0)
                                            <span class="inline-flex items-center justify-center min-w-[1.75rem] px-2 py-0.5 rounded-full bg-teal-light text-teal-dark text-xs font-bold">{{ $rider->active_deliveries }}</span>
                                        @else
                                            <span class="text-xs text-gray-400">No active delivery</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-5 py-10 text-center text-sm text-gray-500">No riders yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- Recent deliveries --}}
        <section class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="flex items-center justify-between px-5 sm:px-6 py-4 border-b border-gray-100">
                <h2 class="text-base font-bold text-gray-900">Recent Deliveries</h2>
                <a href="{{ route('deliveries.index') }}" class="text-sm font-semibold text-teal hover:text-teal-dark transition">View All →</a>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-100">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Tracking #</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Recipient</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Rider</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Updated</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($recent_deliveries as $delivery)
                            <tr class="hover:bg-gray-50 transition-colors duration-150">
                                <td class="px-4 py-3.5 text-sm font-mono font-medium text-gray-900 whitespace-nowrap">
                                    <a href="{{ route('deliveries.show', $delivery) }}" class="hover:text-teal-dark transition-colors">{{ $delivery->tracking_number }}</a>
                                </td>
                                <td class="px-4 py-3.5 text-sm text-gray-600">{{ $delivery->recipient_name }}</td>
                                <td class="px-4 py-3.5 text-sm text-gray-600 whitespace-nowrap">
                                    @if($delivery->rider)
                                        <span class="inline-flex items-center gap-1.5 text-gray-700">{{ $delivery->rider->name }}</span>
                                    @else
                                        <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded-full bg-gray-100 text-gray-500 ring-1 ring-inset ring-gray-200">Unassigned</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5">
                                    <x-status-badge :status="$delivery->status" />
                                </td>
                                <td class="px-4 py-3.5 text-sm text-gray-500 whitespace-nowrap">{{ $delivery->updated_at->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-12">
                                    <div class="flex flex-col items-center text-center">
                                        <div class="w-14 h-14 rounded-2xl bg-teal-light text-teal-dark flex items-center justify-center mb-3">
                                            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                                        </div>
                                        <p class="text-sm font-semibold text-gray-700">No recent deliveries yet</p>
                                        <p class="text-sm text-gray-500 mt-1">Deliveries will appear here as orders come in.</p>
                                        <a href="{{ route('deliveries.index') }}" class="mt-4 inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold text-white bg-teal hover:bg-teal-dark transition">Go to Deliveries</a>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-app-layout>
