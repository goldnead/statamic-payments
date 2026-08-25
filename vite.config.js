import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import statamic from '@statamic/cms/vite-plugin';
import tailwindcss from '@tailwindcss/vite';

/**
 * The Statamic plugin rewrites `import … from 'vue'` to `window.Vue` and leaves
 * `@statamic/cms/*` to the host. Without it the bundle ships a second Vue
 * instance, and then provide/inject silently returns null: the page renders,
 * the publish context is gone, and nothing says why.
 *
 * The three values below must byte-match `$vite` in the ServiceProvider.
 */
export default defineConfig({
    plugins: [
        statamic(),
        tailwindcss(),
        laravel({
            hotFile: 'dist/hot',
            publicDirectory: 'dist',
            input: ['resources/js/cp.js', 'resources/css/cp.css'],
        }),
    ],
});
