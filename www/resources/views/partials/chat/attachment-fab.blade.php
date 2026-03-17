{{-- Floating Action Button for File Attachments (Mobile) --}}
<div x-data="{
    showModal: false,
    get attachments() { return Alpine.store('attachments'); },
    get hasAnyFiles() { return this.attachments.files.length > 0; },
    get isUploading() { return this.attachments.isUploading; },
    openFilePicker() {
        this.$refs.fileInput.click();
    },
    handleFileSelect(event) {
        const files = event.target.files;
        for (const file of files) {
            this.attachments.addFile(file);
        }
        event.target.value = ''; // Reset input for re-selection
    },
    handleFabClick() {
        if (this.hasAnyFiles) {
            this.showModal = true;
        } else {
            this.openFilePicker();
        }
    },
    confirmClearAll() {
        if (confirm('Remove all attachments?')) {
            this.attachments.clear();
            if (this.attachments.files.length === 0) {
                this.showModal = false;
            }
        }
    }
}"
     class="md:hidden">

    {{-- Hidden File Input --}}
    <input type="file"
           x-ref="fileInput"
           @change="handleFileSelect($event)"
           multiple
           class="hidden"
           accept="*/*">

    {{-- FAB Button (same size as scroll-to-bottom: w-10 h-10) --}}
    <button @click="handleFabClick()"
            class="fixed z-40 w-10 h-10 rounded-full shadow-lg flex items-center justify-center transition-all duration-200 right-4"
            :class="hasAnyFiles ? 'bg-blue-600 hover:bg-blue-500' : 'bg-emerald-600 hover:bg-emerald-500'"
            :style="'bottom: ' + (parseInt(mobileInputHeight) + 16) + 'px'"
            title="Attach files">

        {{-- Main Icon (spinner when uploading) --}}
        <x-spinner x-show="isUploading" class="text-white text-base" x-cloak />
        <i x-show="!isUploading" class="fa-solid fa-paperclip text-white text-base"></i>

        {{-- Badge --}}
        <span class="absolute -bottom-1 -right-1 min-w-4 h-4 px-0.5 rounded-full text-[10px] font-bold flex items-center justify-center"
              :class="hasAnyFiles ? 'bg-white text-blue-600' : 'bg-white text-emerald-600'"
              x-text="attachments.badgeText"></span>
    </button>

    {{-- Attachments Modal --}}
    <div x-show="showModal"
         x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @keydown.escape.window="showModal = false"
         class="fixed inset-0 z-50 flex items-end justify-center p-4 bg-black/50">

        {{-- Modal Content --}}
        <div @click.outside="showModal = false"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-4"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 translate-y-4"
             class="w-full max-w-md bg-gray-800 rounded-t-xl shadow-xl max-h-[70vh] flex flex-col">

            {{-- Header --}}
            <div class="flex items-center justify-between p-4 border-b border-gray-700">
                <h3 class="text-lg font-semibold text-white">
                    Attachments
                    <span x-show="attachments.count > 0" class="text-gray-400 font-normal">(<span x-text="attachments.count"></span>)</span>
                </h3>
                <button type="button" @click="showModal = false" class="text-gray-400 hover:text-white p-1">
                    <i class="fa-solid fa-times text-xl"></i>
                </button>
            </div>

            {{-- File List --}}
            <div class="flex-1 overflow-y-auto p-4 space-y-3">
                <template x-for="file in attachments.files" :key="file.id">
                    <div class="flex items-center justify-between p-3 bg-gray-700 rounded-lg">
                        <div class="flex-1 min-w-0 mr-3">
                            <p class="text-sm text-gray-200 truncate" x-text="file.filename"></p>
                            <p class="text-xs text-gray-400" x-text="file.sizeFormatted"></p>
                            <p x-show="file.error" class="text-xs text-red-400 mt-1" x-text="file.error"></p>
                        </div>
                        <div class="flex items-center gap-3">
                            <template x-if="file.uploading">
                                <x-spinner class="text-blue-400" />
                            </template>
                            <template x-if="!file.uploading && !file.error">
                                <i class="fa-solid fa-check text-green-400"></i>
                            </template>
                            <template x-if="file.error">
                                <i class="fa-solid fa-exclamation-triangle text-red-400"></i>
                            </template>
                            <button type="button" @click="attachments.removeFile(file.id)"
                                    :disabled="file.uploading"
                                    :class="file.uploading ? 'text-gray-600 cursor-not-allowed' : 'text-gray-400 hover:text-red-400 cursor-pointer'"
                                    class="p-1 transition-colors">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </template>

                {{-- Empty State --}}
                <template x-if="attachments.files.length === 0">
                    <div class="text-center py-8">
                        <i class="fa-solid fa-paperclip text-4xl text-gray-600 mb-3"></i>
                        <p class="text-gray-400">No files attached</p>
                        <p class="text-gray-500 text-sm mt-1">Tap the button below to add files</p>
                    </div>
                </template>
            </div>

            {{-- Actions --}}
            <div class="p-4 border-t border-gray-700">
                <div class="flex gap-2">
                    <button type="button" @click="openFilePicker()"
                            class="flex-1 px-4 py-3 bg-gray-700 hover:bg-gray-600 text-white rounded-lg font-medium transition-colors">
                        <i class="fa-solid fa-plus mr-2"></i>Add Files
                    </button>
                    <button type="button" @click="confirmClearAll()"
                            x-show="attachments.files.length > 0"
                            :disabled="isUploading"
                            :class="isUploading ? 'bg-gray-600 cursor-not-allowed' : 'bg-red-600/80 hover:bg-red-500 cursor-pointer'"
                            class="px-4 py-3 text-white rounded-lg font-medium transition-colors">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
