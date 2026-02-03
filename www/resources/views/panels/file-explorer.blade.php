{{-- File Explorer Panel --}}
{{-- Uses inline x-data for compatibility with dynamic loading via x-html + Alpine.initTree() --}}
<div class="h-full bg-gray-900 text-gray-200 overflow-auto p-4"
     x-data="{
         rootPath: @js($rootPath),
         expanded: @js($expanded),
         selected: @js($selected),
         loadedPaths: @js($loadedPaths),
         loadingPaths: [],
         panelStateId: @js($panelStateId),
         syncTimeout: null,

         async toggle(path, depth) {
             const idx = this.expanded.indexOf(path);
             if (idx === -1) {
                 // Expanding - check if we need to load children
                 if (!this.loadedPaths.includes(path)) {
                     await this.loadChildren(path, depth);
                 }
                 this.expanded.push(path);
             } else {
                 // Collapsing
                 this.expanded.splice(idx, 1);
             }
             // Sync state to server (debounced)
             this.syncState();
         },

         async loadChildren(path, depth) {
             if (this.loadingPaths.includes(path)) return;

             this.loadingPaths.push(path);

             try {
                 const response = await fetch(`/api/panel/${this.panelStateId}/action`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'Accept': 'application/json',
                     },
                     body: JSON.stringify({
                         action: 'loadChildren',
                         params: { path, depth }
                     })
                 });

                 if (!response.ok) {
                     throw new Error('Failed to load children');
                 }

                 const result = await response.json();

                 if (result.ok && result.html) {
                     // Find the children container and insert HTML
                     const container = document.querySelector(`[data-children-for='${path}']`);
                     if (container) {
                         container.innerHTML = result.html;
                         // Initialize Alpine on new content
                         Alpine.initTree(container);
                     }

                     // Update loaded paths
                     if (!this.loadedPaths.includes(path)) {
                         this.loadedPaths.push(path);
                     }
                 }
             } catch (err) {
                 console.error('Failed to load children:', err);
             } finally {
                 const idx = this.loadingPaths.indexOf(path);
                 if (idx !== -1) {
                     this.loadingPaths.splice(idx, 1);
                 }
             }
         },

         // Debounced state sync to server
         syncState() {
             if (this.syncTimeout) {
                 clearTimeout(this.syncTimeout);
             }
             this.syncTimeout = setTimeout(() => {
                 this.doSyncState();
             }, 300);
         },

         async doSyncState() {
             if (!this.panelStateId) return;

             try {
                 await fetch(`/api/panel/${this.panelStateId}/state`, {
                     method: 'POST',
                     headers: {
                         'Content-Type': 'application/json',
                         'Accept': 'application/json',
                     },
                     body: JSON.stringify({
                         state: {
                             expanded: this.expanded,
                             selected: this.selected,
                             loadedPaths: this.loadedPaths
                         },
                         merge: true
                     })
                 });
             } catch (err) {
                 console.error('Failed to sync panel state:', err);
             }
         },

         select(path) {
             this.selected = path;
             this.syncState();
         },

         isExpanded(path) {
             return this.expanded.includes(path);
         },

         isLoading(path) {
             return this.loadingPaths.includes(path);
         },

         refresh() {
             window.location.reload();
         }
     }">

    <div class="flex items-center gap-2 mb-4 pb-2 border-b border-gray-700">
        <i class="fa-solid fa-folder-tree text-blue-400"></i>
        <span class="font-medium text-sm truncate" x-text="rootPath"></span>
        <button @click="refresh()"
                class="ml-auto p-1.5 hover:bg-gray-700 rounded transition-colors"
                title="Refresh">
            <i class="fa-solid fa-rotate text-gray-400 hover:text-white text-sm"></i>
        </button>
    </div>

    <div class="space-y-0.5">
        @foreach($tree as $item)
            @include('panels.partials.file-tree-item', ['item' => $item, 'depth' => 0])
        @endforeach
    </div>
</div>
