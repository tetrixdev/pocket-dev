import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Global fetch wrapper: auto-inject CSRF token and Accept: application/json
// on same-origin mutating requests. API routes live under /api and are web
// routes (CSRF-protected) — see docs/architecture/authentication.md.
(() => {
    const originalFetch = window.fetch.bind(window);
    const mutatingMethods = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

    const isSameOrigin = (url) => {
        try {
            const parsed = new URL(url, window.location.href);
            return parsed.origin === window.location.origin;
        } catch {
            return false;
        }
    };

    const readCsrfToken = () =>
        document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    window.fetch = (input, init = {}) => {
        const request = input instanceof Request ? input : null;
        const url = request ? request.url : input;
        const method = (init.method ?? request?.method ?? 'GET').toUpperCase();

        if (!isSameOrigin(url) || !mutatingMethods.has(method)) {
            return originalFetch(input, init);
        }

        const headers = new Headers(init.headers ?? request?.headers ?? {});

        if (!headers.has('Accept')) {
            headers.set('Accept', 'application/json');
        }

        if (!headers.has('X-CSRF-TOKEN')) {
            const token = readCsrfToken();
            if (token) {
                headers.set('X-CSRF-TOKEN', token);
            }
        }

        return originalFetch(input, { ...init, headers });
    };
})();
