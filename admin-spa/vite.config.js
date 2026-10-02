import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// The administrator SPA is built into the public/admin bundle directory that
// the application serves at the administrator path.
export default defineConfig({
    plugins: [vue()],
    base: '/admin-assets/',
    build: {
        outDir: '../public/admin-assets',
        emptyOutDir: true,
        manifest: false,
    },
    server: {
        port: 5174,
    },
});
