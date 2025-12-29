import './bootstrap';

// Import Alpine.js and plugins
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

// Register Alpine plugins
Alpine.plugin(collapse);

// Import HTMX
import 'htmx.org';

// Import Highlight.js
import hljs from 'highlight.js/lib/core';
import nginx from 'highlight.js/lib/languages/nginx';
import markdown from 'highlight.js/lib/languages/markdown';
import json from 'highlight.js/lib/languages/json';
import 'highlight.js/styles/atom-one-dark.css';

// Register languages for syntax highlighting
hljs.registerLanguage('nginx', nginx);
hljs.registerLanguage('markdown', markdown);
hljs.registerLanguage('json', json);

// Make Alpine and hljs available globally BEFORE Alpine starts
window.Alpine = Alpine;
window.hljs = hljs;

// Global Debug Logger Store
Alpine.store('debug', {
    logs: [],
    showPanel: false,
    maxLogs: 100,

    log(message, data = null) {
        const timestamp = new Date().toISOString().substr(11, 12);
        const entry = { timestamp, message, data };
        console.log(`[DEBUG ${timestamp}] ${message}`, data ?? '');
        this.logs.push(entry);
        if (this.logs.length > this.maxLogs) {
            this.logs.shift();
        }
    },

    clear() {
        this.logs = [];
    },

    copy() {
        const text = this.logs.map(log => {
            const dataStr = log.data !== null ? ' ' + (typeof log.data === 'object' ? JSON.stringify(log.data) : log.data) : '';
            return `[${log.timestamp}] ${log.message}${dataStr}`;
        }).join('\n');
        navigator.clipboard.writeText(text);
    },

    toggle() {
        this.showPanel = !this.showPanel;
    }
});

// Global helper function for easy access
window.debugLog = (message, data = null) => {
    Alpine.store('debug').log(message, data);
};

// Start Alpine after all imports and setup
Alpine.start();
