import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '');
    const hmrHost = (env.APP_URL || 'http://localhost').replace(/^https?:\/\//, '').split(':')[0];
    const vitePort = parseInt(env.VITE_PORT || '5173');

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.js'],
                refresh: true,
            }),
            tailwindcss(),
        ],
        server: {
            host: '0.0.0.0',
            port: vitePort,
            hmr: {
                host: hmrHost,
            },
        },
    };
});
