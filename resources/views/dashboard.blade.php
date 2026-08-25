<x-app-layout>
    @php
        $statusMeta = [
            'waiting_for_rider' => ['Waiting for Rider', '#F59E0B'],
            'assigned'          => ['Rider Assigned', '#3B82F6'],
            'picked_up'         => ['Picked Up', '#6366F1'],
            'out_for_delivery'  => ['Out for Delivery', '#A855F7'],
            'delivered'         => ['Delivered', '#10B981'],
            'failed'            => ['Failed', '#EF4444'],
            'cancelled'         => ['Cancelled', '#9CA3AF'],
        ];
        $chartTotal = $status_counts->sum();
        $segments = [];
        $cumulative = 0;
        foreach ($statusMeta as $key => [$label, $color]) {
            $count = (int) ($status_counts[$key] ?? 0);
            if ($count === 0 || $chartTotal === 0) { continue; }
            $pct = $count / $chartTotal * 100;
            $segments[] = ['label' => $label, 'color' => $color, 'count' => $count, 'dash' => $pct, 'offset' => 25 - $cumulative];
            $cumulative += $pct;
        }
    @endphp

    <div class="space-y-6">
        {{-- Header --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">Dashboard Overview</h1>
                <p class="mt-1 text-sm text-gray-500">{{ now()->format('l, F j, Y') }}</p>
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

        {{-- Stats --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
            <a href="{{ route('deliveries.index') }}" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 flex items-center gap-4 hover:shadow-lg hover:border-teal/30 transition-all duration-200">
                <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-teal-light text-teal-dark flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-500 truncate">Total Deliveries</p>
                    <p class="mt-0.5 text-2xl font-bold text-gray-900">{{ $total_deliveries }}</p>
                    <p class="text-xs font-medium text-emerald-500 mt-0.5">All time</p>
                </div>
            </a>

            <a href="{{ route('deliveries.index', ['status' => 'waiting_for_rider']) }}" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 flex items-center gap-4 hover:shadow-lg hover:border-amber-300 transition-all duration-200">
                <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-500 truncate">Waiting for Rider</p>
                    <p class="mt-0.5 text-2xl font-bold text-gray-900">{{ $waiting_for_rider }}</p>
                    <p class="text-xs font-medium text-amber-500 mt-0.5">Needs dispatch</p>
                </div>
            </a>

            <a href="{{ route('deliveries.index', ['status' => 'out_for_delivery']) }}" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 flex items-center gap-4 hover:shadow-lg hover:border-purple-300 transition-all duration-200">
                <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-purple-100 text-purple-600 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-500 truncate">Out for Delivery</p>
                    <p class="mt-0.5 text-2xl font-bold text-gray-900">{{ $out_for_delivery }}</p>
                    <p class="text-xs font-medium text-purple-500 mt-0.5">Currently delivering</p>
                </div>
            </a>

            <a href="{{ route('deliveries.index', ['status' => 'delivered']) }}" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 flex items-center gap-4 hover:shadow-lg hover:border-emerald-300 transition-all duration-200">
                <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-500 truncate">Delivered</p>
                    <p class="mt-0.5 text-2xl font-bold text-gray-900">{{ $delivered }}</p>
                    <p class="text-xs font-medium text-emerald-500 mt-0.5">Completed</p>
                </div>
            </a>

            <a href="{{ route('riders.index') }}" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 flex items-center gap-4 hover:shadow-lg hover:border-blue-300 transition-all duration-200">
                <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-500 truncate">Total Riders</p>
                    <p class="mt-0.5 text-2xl font-bold text-gray-900">{{ $total_riders }}</p>
                    <p class="text-xs font-medium text-blue-500 mt-0.5">Registered</p>
                </div>
            </a>

            <a href="{{ route('riders.index', ['status' => 'available']) }}" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 flex items-center gap-4 hover:shadow-lg hover:border-emerald-300 transition-all duration-200">
                <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-500 truncate">Available Riders</p>
                    <p class="mt-0.5 text-2xl font-bold text-gray-900">{{ $available_riders }}</p>
                    <p class="text-xs font-medium text-emerald-500 mt-0.5">Ready now</p>
                </div>
            </a>

            <a href="{{ route('rider-applications.index', ['status' => 'pending']) }}" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 flex items-center gap-4 hover:shadow-lg hover:border-teal/30 transition-all duration-200">
                <div class="flex-shrink-0 w-12 h-12 rounded-xl bg-teal-light text-teal-dark flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-500 truncate">Pending Applications</p>
                    <p class="mt-0.5 text-2xl font-bold text-gray-900">{{ $pending_applications }}</p>
                    <p class="text-xs font-medium text-teal-dark mt-0.5">Review needed →</p>
                </div>
            </a>

            <a href="{{ route('deliveries.index', ['status' => 'failed']) }}" class="bg-white rounded-2xl border {{ $failed > 0 ? 'border-red-200 ring-1 ring-red-100' : 'border-gray-100' }} shadow-sm p-5 flex items-center gap-4 hover:shadow-lg hover:border-red-300 transition-all duration-200">
                <div class="flex-shrink-0 w-12 h-12 rounded-xl {{ $failed > 0 ? 'bg-red-100 text-red-600 animate-pulse' : 'bg-red-50 text-red-400' }} flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-500 truncate">Failed Deliveries</p>
                    <p class="mt-0.5 text-2xl font-bold {{ $failed > 0 ? 'text-red-600' : 'text-gray-900' }}">{{ $failed }}</p>
                    <p class="text-xs font-medium {{ $failed > 0 ? 'text-red-500' : 'text-gray-400' }} mt-0.5">{{ $failed > 0 ? 'Requires attention' : 'No issues' }}</p>
                </div>
            </a>
        </div>

        {{-- Delivery Status Donut --}}
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 sm:p-6">
            <h3 class="text-base font-semibold text-gray-900 mb-4">Delivery Status</h3>

            <div class="flex flex-col sm:flex-row items-center gap-6 sm:gap-10">
                <div class="relative flex-shrink-0 w-[180px] h-[180px]">
                    @if($chartTotal > 0)
                        <svg viewBox="0 0 42 42" class="w-full h-full -rotate-90">
                            <circle cx="21" cy="21" r="15.9155" fill="none" stroke="#F3F4F6" stroke-width="5"/>
                            @foreach($segments as $seg)
                                <circle cx="21" cy="21" r="15.9155" fill="none"
                                        stroke="{{ $seg['color'] }}"
                                        stroke-width="5"
                                        stroke-dasharray="{{ $seg['dash'] }} {{ 100 - $seg['dash'] }}"
                                        stroke-dashoffset="{{ $seg['offset'] }}">
                                    <title>{{ $seg['label'] }}: {{ $seg['count'] }}</title>
                                </circle>
                            @endforeach
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                            <span class="text-3xl font-bold text-gray-900">{{ $chartTotal }}</span>
                            <span class="text-xs text-gray-400">Total deliveries</span>
                        </div>
                    @else
                        <svg viewBox="0 0 42 42" class="w-full h-full">
                            <circle cx="21" cy="21" r="15.9155" fill="none" stroke="#F3F4F6" stroke-width="5"/>
                        </svg>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <span class="text-sm text-gray-400">No data</span>
                        </div>
                    @endif
                </div>

                <div class="flex-1 w-full grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-1">
                    @foreach($statusMeta as $key => [$label, $color])
                        @php $count = (int) ($status_counts[$key] ?? 0); @endphp
                        <a href="{{ route('deliveries.index', ['status' => $key]) }}" class="flex items-center gap-3 py-2 px-2 -mx-2 rounded-lg hover:bg-gray-50 transition-colors group">
                            <span class="h-3 w-3 rounded-full flex-shrink-0" style="background: {{ $color }}"></span>
                            <span class="text-sm text-gray-600 group-hover:text-gray-900 flex-1 truncate">{{ $label }}</span>
                            <span class="text-sm font-bold text-gray-900">{{ $count }}</span>
                            <span class="text-xs text-gray-400 w-12 text-right">{{ $chartTotal > 0 ? round($count / $chartTotal * 100) . '%' : '0%' }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Content grid --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
            {{-- Recent Deliveries --}}
            <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="flex items-center justify-between px-5 sm:px-6 py-4 border-b border-gray-100">
                    <h3 class="text-base font-semibold text-gray-900">Recent Deliveries</h3>
                    <a href="{{ route('deliveries.index') }}" class="text-sm font-semibold text-teal hover:text-teal-dark transition">
                        View All →
                    </a>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-100">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Tracking #</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Sender</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Recipient</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Rider</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($recent_deliveries as $delivery)
                                <tr class="hover:bg-gray-50 transition-colors duration-150">
                                    <td class="px-4 py-3.5 text-sm font-mono font-medium text-gray-900 whitespace-nowrap">
                                        <a href="{{ route('deliveries.show', $delivery) }}" class="hover:text-teal-dark transition-colors">{{ $delivery->tracking_number }}</a>
                                    </td>
                                    <td class="px-4 py-3.5 text-sm text-gray-600">{{ $delivery->sender_name }}</td>
                                    <td class="px-4 py-3.5 text-sm text-gray-600">{{ $delivery->recipient_name }}</td>
                                    <td class="px-4 py-3.5 text-sm whitespace-nowrap">
                                        @if($delivery->rider)
                                            <span class="inline-flex items-center gap-1.5 text-gray-700">
                                                <span class="h-6 w-6 rounded-full bg-teal-light text-teal-dark text-[10px] font-bold flex items-center justify-center">{{ strtoupper(substr($delivery->rider->name, 0, 1)) }}</span>
                                                {{ $delivery->rider->name }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold rounded-full bg-gray-100 text-gray-500 ring-1 ring-inset ring-gray-200">Unassigned</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <x-status-badge :status="$delivery->status" />
                                    </td>
                                    <td class="px-4 py-3.5 text-sm text-gray-500 whitespace-nowrap">{{ $delivery->created_at->format('M d, Y') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-12">
                                        <div class="flex flex-col items-center text-center">
                                            <div class="w-14 h-14 rounded-2xl bg-teal-light text-teal-dark flex items-center justify-center mb-3">
                                                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                                            </div>
                                            <p class="text-sm font-semibold text-gray-700">No recent deliveries yet</p>
                                            <p class="text-sm text-gray-500 mt-1">Deliveries will appear here as orders come in.</p>
                                            <a href="{{ route('deliveries.index') }}" class="mt-4 inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold text-white bg-teal hover:bg-teal-dark transition">
                                                Go to Deliveries
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Recent Applications --}}
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="flex items-center justify-between px-5 sm:px-6 py-4 border-b border-gray-100">
                    <h3 class="text-base font-semibold text-gray-900">Recent Applications</h3>
                    <a href="{{ route('rider-applications.index') }}" class="text-sm font-semibold text-teal hover:text-teal-dark transition">
                        View All →
                    </a>
                </div>

                @forelse ($recent_applications as $application)
                    <a href="{{ route('rider-applications.show', $application) }}" class="flex items-center gap-3 px-5 sm:px-6 py-4 {{ !$loop->last ? 'border-b border-gray-100' : '' }} hover:bg-gray-50 transition-colors duration-150">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-teal-light text-teal-dark font-bold text-sm flex items-center justify-center">
                            {{ strtoupper(substr($application->name, 0, 1)) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-gray-900 truncate">{{ $application->name }}</p>
                            <p class="text-xs text-gray-500 truncate">{{ $application->email }} · {{ $application->created_at->format('M d, Y') }}</p>
                        </div>
                        <x-status-badge :status="$application->status" />
                    </a>
                @empty
                    <div class="flex flex-col items-center text-center px-5 py-12">
                        <div class="w-14 h-14 rounded-2xl bg-gray-100 text-gray-400 flex items-center justify-center mb-3">
                            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        </div>
                        <p class="text-sm font-semibold text-gray-700">No applications yet</p>
                        <p class="text-sm text-gray-500 mt-1">Rider applications will appear here.</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
