import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    // 127.0.0.1 explícito: en Windows Vite tiende a escuchar solo en ::1 y el
    // navegador (o artisan serve, que va por IPv4) no puede abrir el HMR.
    server: { host: '127.0.0.1', port: 5173, strictPort: true, hmr: { host: '127.0.0.1' } },
    plugins: [
        laravel({ input: ['resources/css/app.css', 'resources/js/app.jsx'], refresh: true }),
        react(),
        tailwindcss(),
    ],
});
