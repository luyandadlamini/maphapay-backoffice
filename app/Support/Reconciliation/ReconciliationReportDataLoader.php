<?php

declare(strict_types=1);

namespace App\Support\Reconciliation;

use Illuminate\Support\Collection;

final class ReconciliationReportDataLoader
{
    public function __construct(
        private readonly ?string $reportDirectory = null,
    ) {
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function load(): Collection
    {
        $directory = $this->reportDirectory ?? storage_path('app/reconciliation');
        $files = glob($directory . '/reconciliation-*.json') ?: [];

        return collect($files)
            ->map(function (string $file): ?array {
                $content = json_decode((string) file_get_contents($file), true);

                if (! is_array($content)) {
                    return null;
                }

                $summary = is_array($content['summary'] ?? null) ? $content['summary'] : [];

                return array_merge($content, $summary);
            })
            ->filter(fn (?array $report): bool => $report !== null)
            ->sortByDesc('date')
            ->values();
    }
}
