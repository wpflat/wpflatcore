import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig( {
	root: 'ui',
	plugins: [ vue() ],
	define: { 'process.env.NODE_ENV': '"production"' },
	build: {
		outDir: '../build',
		emptyOutDir: true,
		cssCodeSplit: false,
		chunkSizeWarningLimit: 2000,
		lib: {
			entry: 'main.js',
			name: 'WPFlatCore',
			formats: [ 'iife' ],
			fileName: () => 'wpflatcore.js',
			cssFileName: 'wpflatcore',
		},
	},
} );
