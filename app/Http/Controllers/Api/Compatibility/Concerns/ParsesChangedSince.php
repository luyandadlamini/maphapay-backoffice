<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Concerns;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

trait ParsesChangedSince
{
    protected function parseChangedSince(Request $request): ?CarbonImmutable
    {
        $value = $request->query('changed_since');

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'changed_since' => ['The changed_since cursor must be a valid ISO-8601 timestamp.'],
            ]);
        }
    }

    /**
     * @param iterable<CarbonInterface|string|null> $timestamps
     */
    protected function nextSyncToken(iterable $timestamps): string
    {
        $latest = null;

        foreach ($timestamps as $timestamp) {
            if ($timestamp === null) {
                continue;
            }

            $candidate = $timestamp instanceof CarbonInterface
                ? CarbonImmutable::instance($timestamp)
                : CarbonImmutable::parse($timestamp);

            if ($latest === null || $candidate->greaterThan($latest)) {
                $latest = $candidate;
            }
        }

        return ($latest ?? now()->toImmutable())->toIso8601String();
    }
}
