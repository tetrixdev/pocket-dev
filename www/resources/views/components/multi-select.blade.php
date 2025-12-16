@props([
    'options' => 'options',      // Alpine variable name for available options array
    'selected' => 'selected',    // Alpine variable name for selected items array
    'label' => null,
    'placeholder' => 'Select items...',
    'allSelectedText' => 'All selected',
    'noneSelectedText' => 'None selected',
    'onChange' => null,          // Optional callback function name to call on change
])

<div
    x-data="{
        open: false,
        search: '',
        get filteredOptions() {
            const searchLower = this.search.toLowerCase();
            return {{ $options }}.filter(opt =>
                opt.toLowerCase().includes(searchLower) &&
                !{{ $selected }}.includes(opt)
            );
        },
        get displayText() {
            if ({{ $selected }}.length === 0) return '{{ $noneSelectedText }}';
            if ({{ $selected }}.length === {{ $options }}.length) return '{{ $allSelectedText }}';
            return {{ $selected }}.length + ' selected';
        },
        toggle(item) {
            const idx = {{ $selected }}.indexOf(item);
            if (idx === -1) {
                {{ $selected }}.push(item);
            } else {
                {{ $selected }}.splice(idx, 1);
            }
            this.triggerChange();
        },
        remove(item) {
            const idx = {{ $selected }}.indexOf(item);
            if (idx !== -1) {
                {{ $selected }}.splice(idx, 1);
            }
            this.triggerChange();
        },
        selectAll() {
            {{ $selected }}.splice(0, {{ $selected }}.length, ...{{ $options }});
            this.triggerChange();
        },
        clearAll() {
            {{ $selected }}.splice(0, {{ $selected }}.length);
            this.triggerChange();
        },
        triggerChange() {
            @if($onChange)
            {{ $onChange }}();
            @endif
        }
    }"
    @click.away="open = false"
    {{ $attributes->merge(['class' => 'relative']) }}
>
    @if($label)
        <label class="block text-sm font-medium text-gray-300 mb-2">{{ $label }}</label>
    @endif

    {{-- Selected Items as Tags --}}
    <div class="min-h-[38px] w-full bg-gray-700 border border-gray-600 rounded-lg p-2 cursor-pointer"
         @click="open = !open">
        <div class="flex flex-wrap gap-1">
            <template x-if="{{ $selected }}.length === 0">
                <span class="text-gray-400 text-sm">{{ $placeholder }}</span>
            </template>
            <template x-for="item in {{ $selected }}" :key="item">
                <span class="inline-flex items-center gap-1 bg-blue-600 text-white text-xs px-2 py-1 rounded">
                    <span x-text="item"></span>
                    <button type="button" @click.stop="remove(item)" class="hover:text-red-300">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </span>
            </template>
        </div>
    </div>

    {{-- Dropdown --}}
    <div x-show="open"
         x-transition:enter="transition ease-out duration-100"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-75"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         class="absolute z-50 mt-1 w-full bg-gray-800 border border-gray-600 rounded-lg shadow-lg">

        {{-- Search Input --}}
        <div class="p-2 border-b border-gray-700">
            <input type="text"
                   x-model="search"
                   placeholder="Search..."
                   class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-1.5 text-sm text-white placeholder-gray-400 focus:outline-none focus:border-blue-500"
                   @click.stop>
        </div>

        {{-- Quick Actions --}}
        <div class="flex gap-2 p-2 border-b border-gray-700 text-xs">
            <button type="button" @click.stop="selectAll()" class="text-blue-400 hover:underline">Select All</button>
            <span class="text-gray-600">|</span>
            <button type="button" @click.stop="clearAll()" class="text-blue-400 hover:underline">Clear All</button>
        </div>

        {{-- Options List --}}
        <div class="max-h-48 overflow-y-auto p-2">
            <template x-for="option in filteredOptions" :key="option">
                <div @click.stop="toggle(option)"
                     class="px-3 py-1.5 text-sm text-gray-300 hover:bg-gray-700 rounded cursor-pointer">
                    <span x-text="option"></span>
                </div>
            </template>
            <template x-if="filteredOptions.length === 0 && search">
                <div class="px-3 py-1.5 text-sm text-gray-500">No matches found</div>
            </template>
            <template x-if="filteredOptions.length === 0 && !search && {{ $selected }}.length === {{ $options }}.length">
                <div class="px-3 py-1.5 text-sm text-gray-500">All items selected</div>
            </template>
        </div>
    </div>
</div>
