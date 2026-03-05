import { Chart, registerables } from 'chart.js';
Chart.register(...registerables);

document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('salesChart');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const gradient = ctx.createLinearGradient(0, 0, 0, 400);
    gradient.addColorStop(0, 'rgba(194, 65, 12, 0.15)');
    gradient.addColorStop(1, 'rgba(194, 65, 12, 0.0)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: ['W50', 'W51', 'W52', 'W1', 'W2', 'W3', 'W4', 'W5', 'W6', 'W7', 'W8', 'W9'],
            datasets: [
                {
                    label: 'Actual Sales',
                    data: [420000, 430000, 410000, 400000, 390000, 380000, 440000, 435000, 410000, 377309, null, null],
                    borderColor: '#C2410C',
                    backgroundColor: gradient,
                    borderWidth: 3,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#C2410C',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    tension: 0.4,
                    fill: true,
                },
                {
                    label: 'Forecast',
                    data: [null, null, null, null, null, null, null, null, null, 377309, 395000, 410000],
                    borderColor: '#A8A29E',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    pointRadius: 0,
                    tension: 0.4,
                    fill: false,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1C1917',
                    padding: 12,
                    titleFont: { size: 12, weight: 'bold', family: 'Inter' },
                    bodyFont: { size: 12, family: 'Inter' },
                    cornerRadius: 8,
                    displayColors: false,
                    callbacks: {
                        label: (context) => {
                            let label = context.dataset.label || '';
                            if (label) label += ': ';
                            if (context.parsed.y !== null) {
                                label += new Intl.NumberFormat('de-DE', {
                                    style: 'currency',
                                    currency: 'EUR',
                                    maximumFractionDigits: 0,
                                }).format(context.parsed.y);
                            }
                            return label;
                        },
                    },
                },
            },
            scales: {
                y: {
                    beginAtZero: false,
                    grid: { color: '#E7E5E4', drawBorder: false },
                    ticks: {
                        color: '#78716C',
                        font: { size: 10, weight: '600', family: 'Inter' },
                        callback: (value) => '€' + value / 1000 + 'k',
                    },
                },
                x: {
                    grid: { display: false },
                    ticks: { color: '#78716C', font: { size: 10, weight: '600', family: 'Inter' } },
                },
            },
        },
    });
});
