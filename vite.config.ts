import { defineConfig } from 'vite';
import { fileURLToPath, URL } from 'node:url';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({ plugins: [vue(), tailwindcss()], build: { outDir: 'public/build', emptyOutDir: true }, resolve: { alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) } } });
