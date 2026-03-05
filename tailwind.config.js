import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';
import safelist from './tailwind.safelist.js';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],
    
    safelist: safelist,

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
                foodo: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                foodo: {
                    'primary-dark': '#7C2D12',
                    'primary': '#C2410C',
                    'primary-vibrant': '#EA580C',
                    'accent': '#7E22CE',
                    'olive': '#3F6212',
                    'lime': '#65A30D',
                    'surface-dark': '#1C1917',
                    'surface-bg': '#F5F5F4',
                    'surface-subtle': '#FFF7ED',
                    'text': '#292524',
                    'text-muted': '#78716C',
                },
            },
        },
    },

    plugins: [forms, typography],
};
