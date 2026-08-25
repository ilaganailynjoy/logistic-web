<x-app-layout>
    @php
        $currentStatus = request('status');
        $tabs = [
            ['label' => 'All', 'status' => null],
            ['label' => 'Pending', 'status' => 'pending'],
            ['label' => 'Approved', 'status' => 'approved'],
            ['label' => 'Rejected', 'status' => 'rejected'],
        ];
        $tabUrl = fn ($status) => route('rider-applications.index', collect(request()->query())->except('page')->merge(['status' => $status])->filter(fn ($v) => $v !== null && $v !== '')->all());
        $hasFilters = trim((string) request('search')) !== '' || $currentStatus || request('date_from') || request('date_to');
    @endphp

    <div x-data="{ approveOpen: false, rejectOpen: false, revertOpen: false, url: '', name: '', info: '' }">

    {{-- Status Filter Tabs --}}
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach($tabs as $tab)
            <a href="{{ $tabUrl($tab['status']) }}"
               class="px-4 py-2 rounded-xl text-sm font-semibold transition {{ ($currentStatus === $tab['status']) || (!$currentStatus && !$tab['status']) ? 'bg-teal text-white shadow-sm' : 'bg-white border border-gray-200 text-gray-600 hover:text-teal hover:border-teal' }}">
                {{ $tab['label'] }}
            </a>
        @endforeach
    </div>

    {{-- Search + Applied date toolbar --}}
    <div class="flex flex-col lg:flex-row lg:items-start gap-3 mb-6">
        <form method="GET" action="{{ route('rider-applications.index') }}" class="flex flex-col sm:flex-row gap-3 flex-1">
            <div class="relative flex-1 min-w-[220px]">
                <span class="absolute inset-y-0 left-3 flex items-center text-gray-400">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </span>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Search name, email, phone, or vehicle..."
                       class="w-full bg-white border border-gray-200 rounded-xl pl-10 pr-4 py-2 text-sm focus:border-teal focus:ring-teal shadow-sm placeholder-gray-400">
            </div>

            <input type="date" name="date_from" value="{{ request('date_from') }}" max="{{ request('date_to') }}" title="Applied from"
                   class="bg-white border border-gray-200 rounded-xl px-3 py-2 text-sm text-gray-600 focus:border-teal focus:ring-teal shadow-sm">
            <span class="hidden sm:flex items-center text-xs text-gray-400">to</span>
            <input type="date" name="date_to" value="{{ request('date_to') }}" min="{{ request('date_from') }}" title="Applied to"
                   class="bg-white border border-gray-200 rounded-xl px-3 py-2 text-sm text-gray-600 focus:border-teal focus:ring-teal shadow-sm">

            <button type="submit" class="inline-flex items-center justify-center px-5 py-2 bg-teal hover:bg-teal-dark text-white text-sm font-semibold rounded-xl transition shadow-sm whitespace-nowrap">
                Apply
            </button>
            @if($hasFilters)
                <a href="{{ route('rider-applications.index') }}" title="Clear all filters"
                   class="inline-flex items-center justify-center px-4 py-2 bg-white border border-gray-200 hover:bg-gray-50 text-gray-500 text-sm font-medium rounded-xl transition whitespace-nowrap">
                    Clear
                </a>
            @endif
        </form>

        <x-per-page route="rider-applications.index"/>
    </div>

    {{-- Applications Table --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100">
                <thead class="bg-gray-50/50">
                    <tr>
                        <x-th-sort field="name" label="Name"/>
                        <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Email</th>
                        <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Phone</th>
                        <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Vehicle</th>
                        <x-th-sort field="status" label="Status"/>
                        <x-th-sort field="date" label="Applied Date"/>
                        <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($applications as $application)
                        <tr class="hover:bg-teal-light/40 transition">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center gap-3">
                                    <div class="h-9 w-9 rounded-full bg-teal flex items-center justify-center text-white text-sm font-bold">
                                        {{ strtoupper(substr($application->name, 0, 1)) }}
                                    </div>
                                    <span class="text-sm font-semibold text-gray-900">{{ $application->name }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $application->email }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $application->phone }}</td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-lg bg-teal-light text-teal-dark">
                                    {{ ucfirst($application->vehicle_type) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <x-status-badge :status="$application->status" />
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ $application->created_at->format('M d, Y') }}</td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('rider-applications.show', $application) }}" class="text-sm font-semibold text-teal hover:text-teal-dark">View</a>
                                    @if ($application->status === 'pending')
                                        <button type="button"
                                                data-url="{{ route('rider-applications.approve', $application) }}"
                                                data-name="{{ $application->name }}"
                                                data-info="{{ $application->email }} · {{ ucfirst($application->vehicle_type) }} · {{ $application->license_plate }}"
                                                @click="url = $el.dataset.url; name = $el.dataset.name; info = $el.dataset.info; approveOpen = true"
                                                class="bg-emerald-500 hover:bg-emerald-600 text-white px-3 py-1.5 rounded-lg text-xs font-semibold transition">
                                            Approve
                                        </button>
                                        <button type="button"
                                                data-url="{{ route('rider-applications.reject', $application) }}"
                                                data-name="{{ $application->name }}"
                                                @click="url = $el.dataset.url; name = $el.dataset.name; rejectOpen = true"
                                                class="bg-red-500 hover:bg-red-600 text-white px-3 py-1.5 rounded-lg text-xs font-semibold transition">
                                            Reject
                                        </button>
                                    @elseif(in_array($application->status, ['approved', 'rejected']))
                                        <button type="button"
                                                data-url="{{ route('rider-applications.revert', $application) }}"
                                                data-name="{{ $application->name }}"
                                                @click="url = $el.dataset.url; name = $el.dataset.name; revertOpen = true"
                                                class="text-sm font-semibold text-amber-600 hover:text-amber-700">Return to Pending</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-16 text-center">
                                <div class="flex flex-col items-center">
                                    <div class="h-12 w-12 rounded-full bg-teal-light flex items-center justify-center mb-3">
                                        <svg class="h-6 w-6 text-teal" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                        </svg>
                                    </div>
                                    <p class="text-base font-semibold text-gray-700">No results found</p>
                                    @if(trim((string) request('search')) !== '')
                                        <p class="mt-1 text-sm text-gray-500">We couldn't find any applications matching
                                            <span class="font-semibold text-gray-800">"{{ request('search') }}"</span>.
                                        </p>
                                    @else
                                        <p class="mt-1 text-sm text-gray-500">No applications match the selected filters.</p>
                                    @endif
                                    @if($hasFilters)
                                        <a href="{{ route('rider-applications.index') }}" class="mt-4 inline-flex items-center px-4 py-2 bg-white border border-gray-200 hover:border-teal hover:text-teal-dark rounded-xl text-sm font-semibold text-gray-600 transition">
                                            Clear Search &amp; Filters
                                        </a>
                                    @else
                                        <p class="mt-1 text-sm text-gray-500">Applications submitted through the public form will appear here.</p>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($applications->count() > 0)
            <div class="px-6 py-3 border-t border-gray-100 bg-gray-50/50 flex flex-col sm:flex-row items-center justify-between gap-2">
                <p class="text-xs text-gray-500">
                    Showing <span class="font-semibold text-gray-700">{{ $applications->firstItem() }}–{{ $applications->lastItem() }}</span>
                    of <span class="font-semibold text-gray-700">{{ $applications->total() }}</span> applications
                </p>
                <p class="text-xs text-gray-400">Page {{ $applications->currentPage() }} of {{ $applications->lastPage() }}</p>
            </div>
        @endif
    </div>

    {{-- Pagination --}}
    <div class="mt-6">
        {{ $applications->onEachSide(2)->links() }}
    </div>

    {{-- Approve Modal --}}
    <div x-show="approveOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" x-transition.opacity>
        <div class="absolute inset-0 bg-black/40" @click="approveOpen = false"></div>
        <form :action="url" method="POST" class="relative bg-white rounded-2xl shadow-xl w-full max-w-md" x-transition>
            @csrf
            <div class="px-6 py-4 border-b border-gray-100">
                <h4 class="font-bold text-gray-900">Approve Rider Application?</h4>
            </div>
            <div class="px-6 py-5 text-sm space-y-1.5">
                <p><span class="text-gray-500">Applicant:</span> <strong x-text="name" class="text-gray-900"></strong></p>
                <p><span class="text-gray-500">Details:</span> <span x-text="info" class="text-gray-700"></span></p>
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
        <form :action="url" method="POST" class="relative bg-white rounded-2xl shadow-xl w-full max-w-md" x-transition>
            @csrf
            <div class="px-6 py-4 border-b border-gray-100">
                <h4 class="font-bold text-gray-900">Reject Rider Application?</h4>
                <p class="text-sm text-gray-500 mt-0.5">Applicant: <strong class="text-gray-800" x-text="name"></strong></p>
            </div>
            <div class="px-6 py-5">
                <label class="block text-sm font-medium text-gray-700 mb-1">Rejection Reason <span class="text-red-500">*</span></label>
                <textarea name="reason" required minlength="3" maxlength="500" rows="3" placeholder="e.g. Invalid vehicle documents"
                          class="block w-full rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm"></textarea>
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
        <form :action="url" method="POST" class="relative bg-white rounded-2xl shadow-xl w-full max-w-md" x-transition>
            @csrf
            <div class="px-6 py-4 border-b border-gray-100">
                <h4 class="font-bold text-gray-900">Return Application to Pending?</h4>
                <p class="text-sm text-gray-500 mt-0.5">
                    Applicant: <strong class="text-gray-800" x-text="name"></strong>. This reverses the current decision and is recorded in the application history.
                    If it was previously approved, the linked rider account will be removed.
                </p>
            </div>
            <div class="px-6 py-5">
                <label class="block text-sm font-medium text-gray-700 mb-1">Reason / Note</label>
                <textarea name="reason" rows="2" minlength="3" maxlength="500" placeholder="e.g. Accidentally rejected"
                          class="block w-full rounded-xl border-gray-300 focus:border-teal focus:ring-teal text-sm"></textarea>
            </div>
            <div class="px-6 py-4 bg-gray-50 rounded-b-2xl flex justify-end gap-3">
                <button type="button" @click="revertOpen = false" class="px-5 py-2.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-sm font-semibold rounded-xl transition">Cancel</button>
                <button type="submit" class="px-5 py-2.5 bg-amber-500 hover:bg-amber-600 text-white text-sm font-bold rounded-xl transition">Confirm Return to Pending</button>
            </div>
        </form>
    </div>
</div>
</x-app-layout>
