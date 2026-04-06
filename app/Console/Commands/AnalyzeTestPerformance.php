<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class AnalyzeTestPerformance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:analyze-performance 
                            {--top=10 : Number of slowest tests to show}
                            {--threshold=1 : Threshold in seconds for slow tests}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Analyze test performance metrics and identify slow tests';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $metricsFile = storage_path('logs/test-metrics.json');

        if (! file_exists($metricsFile)) {
            $this->error('No test metrics found. Run tests with performance tracking enabled.');

            return 1;
        }

        $content = file_get_contents($metricsFile);
        if ($content === false) {
            $this->error('Failed to read test metrics file.');

            return 1;
        }

        $metrics = collect(json_decode($content, true));

        if ($metrics->isEmpty()) {
            $this->error('No test metrics data available.');

            return 1;
        }

        $this->info('Test Performance Analysis');
        $this->info('========================');
        $this->newLine();

        // Overall statistics
        $this->displayOverallStats($metrics);

        // Slowest tests
        $this->displaySlowestTests($metrics);

        // Tests exceeding threshold
        $this->displaySlowTests($metrics);

        // Memory usage
        $this->displayMemoryUsage($metrics);

        return 0;
    }

    /**
     * Display overall statistics.
     */
    protected function displayOverallStats(Collection $metrics): void
    {
        $totalTests = $metrics->count();
        $totalTime = $metrics->sum('execution_time');
        $avgTime = $metrics->avg('execution_time');

        $this->info('Overall Statistics:');
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Tests', $totalTests],
                ['Total Execution Time', sprintf('%.2f seconds', $totalTime)],
                ['Average Test Time', sprintf('%.3f seconds', $avgTime)],
                ['Fastest Test', sprintf('%.3f seconds', $metrics->min('execution_time'))],
                ['Slowest Test', sprintf('%.3f seconds', $metrics->max('execution_time'))],
            ]
        );
        $this->newLine();
    }

    /**
     * Display slowest tests.
     */
    protected function displaySlowestTests(Collection $metrics): void
    {
        $top = (int) $this->option('top');

        $slowest = $metrics
            ->sortByDesc('execution_time')
            ->take($top)
            ->map(function ($metric) {
                return [
                    'Test'   => $this->truncateTestName($metric['test']),
                    'Time'   => sprintf('%.3f s', $metric['execution_time']),
                    'Memory' => sprintf('%.1f MB', $metric['memory_peak'] ?? 0),
                    'Date'   => substr($metric['timestamp'], 0, 19),
                ];
            });

        $this->info("Top {$top} Slowest Tests:");
        $this->table(['Test', 'Time', 'Memory', 'Date'], $slowest);
        $this->newLine();
    }

    /**
     * Display tests exceeding threshold.
     */
    protected function displaySlowTests(Collection $metrics): void
    {
        $threshold = (float) $this->option('threshold');

        $slowTests = $metrics
            ->filter(fn ($metric) => $metric['execution_time'] > $threshold)
            ->sortByDesc('execution_time');

        if ($slowTests->isEmpty()) {
            $this->info("No tests exceed the {$threshold}s threshold.");

            return;
        }

        $this->warn("Tests Exceeding {$threshold}s Threshold:");

        $grouped = $slowTests
            ->groupBy(function ($metric) {
                // Group by test class
                $parts = explode('::', $metric['test']);

                return $parts[0] ?? 'Unknown';
            })
            ->map(function ($group) {
                return [
                    'count'      => $group->count(),
                    'total_time' => $group->sum('execution_time'),
                    'avg_time'   => $group->avg('execution_time'),
                ];
            })
            ->sortByDesc('total_time');

        $this->table(
            ['Test Class', 'Count', 'Total Time', 'Avg Time'],
            $grouped->map(function ($stats, $class) {
                return [
                    $this->truncateClassName($class),
                    $stats['count'],
                    sprintf('%.2f s', $stats['total_time']),
                    sprintf('%.3f s', $stats['avg_time']),
                ];
            })
        );
        $this->newLine();
    }

    /**
     * Display memory usage statistics.
     */
    protected function displayMemoryUsage(Collection $metrics): void
    {
        $memoryStats = $metrics
            ->filter(fn ($metric) => isset($metric['memory_peak']))
            ->sortByDesc('memory_peak')
            ->take(5);

        if ($memoryStats->isEmpty()) {
            return;
        }

        $this->info('Top Memory Consumers:');
        $this->table(
            ['Test', 'Memory'],
            $memoryStats->map(function ($metric) {
                return [
                    $this->truncateTestName($metric['test']),
                    sprintf('%.1f MB', $metric['memory_peak']),
                ];
            })
        );
    }

    /**
     * Truncate test name for display.
     */
    protected function truncateTestName(string $name): string
    {
        return strlen($name) > 60
            ? '...' . substr($name, -57)
            : $name;
    }

    /**
     * Truncate class name for display.
     */
    protected function truncateClassName(string $name): string
    {
        return strlen($name) > 40
            ? '...' . substr($name, -37)
            : $name;
    }
}
