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
                display: ['"Plus Jakarta Sans"', ...defaultTheme.fontFamily.sans],
                foodo: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                fa: {
                    'navy': '#0c1222',
                    'navy-light': '#131b2e',
                    'slate': '#1e293b',
                    'surface': '#f8fafc',
                    'accent': '#3b82f6',
                    'teal': '#14b8a6',
                    'gold': '#f59e0b',
                    'border': 'rgba(255,255,255,0.08)',
                },
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
            animation: {
                'fade-in': 'fadeIn 0.6s ease-out forwards',
                'fade-in-up': 'fadeInUp 0.6s ease-out forwards',
                'fade-in-down': 'fadeInDown 0.5s ease-out forwards',
                'slide-in-left': 'slideInLeft 0.6s ease-out forwards',
                'slide-in-right': 'slideInRight 0.6s ease-out forwards',
                'glow-pulse': 'glowPulse 3s ease-in-out infinite',
                'float': 'float 6s ease-in-out infinite',
            },
            keyframes: {
                fadeIn: {
                    '0%': { opacity: '0' },
                    '100%': { opacity: '1' },
                },
                fadeInUp: {
                    '0%': { opacity: '0', transform: 'translateY(24px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                fadeInDown: {
                    '0%': { opacity: '0', transform: 'translateY(-16px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                slideInLeft: {
                    '0%': { opacity: '0', transform: 'translateX(-24px)' },
                    '100%': { opacity: '1', transform: 'translateX(0)' },
                },
                slideInRight: {
                    '0%': { opacity: '0', transform: 'translateX(24px)' },
                    '100%': { opacity: '1', transform: 'translateX(0)' },
                },
                glowPulse: {
                    '0%, 100%': { opacity: '0.4' },
                    '50%': { opacity: '0.8' },
                },
                float: {
                    '0%, 100%': { transform: 'translateY(0)' },
                    '50%': { transform: 'translateY(-12px)' },
                },
            },
        },
    },

    plugins: [forms, typography],
};
