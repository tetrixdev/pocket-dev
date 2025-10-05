import './bootstrap';

// Import Alpine.js
import Alpine from 'alpinejs';

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

// Make Alpine and hljs available globally
window.Alpine = Alpine;
window.hljs = hljs;

// Start Alpine after all imports and setup
Alpine.start();
