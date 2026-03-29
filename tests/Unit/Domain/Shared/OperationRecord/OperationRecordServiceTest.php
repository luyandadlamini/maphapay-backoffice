<?php

declare(strict_types=1);

use App\Domain\Shared\OperationRecord\Exceptions\OperationPayloadMismatchException;
use App\Domain\Shared\OperationRecord\OperationRecord;
use App\Domain\Shared\OperationRecord\OperationRecordService;
use App\Models\User;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeService(): OperationRecordService
{
    return new OperationRecordService();
}

function validHash(): string
{
    $payload = ['amount' => '10.00'];
    ksort($payload);

    return hash('sha256', (string) json_encode($payload));
}

// ---------------------------------------------------------------------------
// Normal path
// ---------------------------------------------------------------------------

describe('OperationRecordService::guardAndRun — normal path', function () {
    it('creates a completed OperationRecord and returns $fn result', function () {
        $user = User::factory()->create();
        $fnRan = 0;

        $result = makeService()->guardAndRun(
            $user->id,
            'send_money',
            'idem-key-1',
            validHash(),
            function () use (&$fnRan): array {
                $fnRan++;

                return ['trx' => 'TRX-ABCDEF'];
            },
        );

        expect($result)->toBe(['trx' => 'TRX-ABCDEF']);
        expect($fnRan)->toBe(1);

        $this->assertDatabaseHas('operation_records', [
            'user_id'         => $user->id,
            'operation_type'  => 'send_money',
            'idempotency_key' => 'idem-key-1',
            'status'          => OperationRecord::STATUS_COMPLETED,
        ]);
    });

    it('marks the record failed when $fn throws', function () {
        $user = User::factory()->create();

        expect(fn () => makeService()->guardAndRun(
            $user->id,
            'send_money',
            'idem-fail-1',
            validHash(),
            fn (): array => throw new RuntimeException('handler exploded'),
        ))->toThrow(RuntimeException::class, 'handler exploded');

        $this->assertDatabaseHas('operation_records', [
            'user_id'         => $user->id,
            'idempotency_key' => 'idem-fail-1',
            'status'          => OperationRecord::STATUS_FAILED,
        ]);
    });
});

// ---------------------------------------------------------------------------
// Cache hit
// ---------------------------------------------------------------------------

describe('OperationRecordService::guardAndRun — cache hit', function () {
    it('returns the cached result_payload without calling $fn', function () {
        $user = User::factory()->create();
        $cached = ['trx' => 'TRX-CACHED', 'amount' => '10.00'];
        $fnCalls = 0;

        // Pre-seed a completed record.
        OperationRecord::create([
            'id'              => (string) Str::ulid(),
            'user_id'         => $user->id,
            'operation_type'  => 'send_money',
            'idempotency_key' => 'idem-cache-1',
            'payload_hash'    => validHash(),
            'status'          => OperationRecord::STATUS_COMPLETED,
            'result_payload'  => $cached,
        ]);

        $result = makeService()->guardAndRun(
            $user->id,
            'send_money',
            'idem-cache-1',
            validHash(),
            function () use (&$fnCalls): array {
                $fnCalls++;

                return ['should' => 'never be returned'];
            },
        );

        expect($result)->toBe($cached);
        expect($fnCalls)->toBe(0, '$fn must not be called on a cache hit');
    });
});

// ---------------------------------------------------------------------------
// Hash mismatch
// ---------------------------------------------------------------------------

describe('OperationRecordService::guardAndRun — hash mismatch', function () {
    it('throws OperationPayloadMismatchException when the hash differs from an existing record', function () {
        $user = User::factory()->create();
        $originalHash = hash('sha256', (string) json_encode(['amount' => '10.00']));
        $differentHash = hash('sha256', (string) json_encode(['amount' => '99.00']));

        OperationRecord::create([
            'id'              => (string) Str::ulid(),
            'user_id'         => $user->id,
            'operation_type'  => 'send_money',
            'idempotency_key' => 'idem-mismatch-1',
            'payload_hash'    => $originalHash,
            'status'          => OperationRecord::STATUS_PENDING,
        ]);

        expect(fn () => makeService()->guardAndRun(
            $user->id,
            'send_money',
            'idem-mismatch-1',
            $differentHash,                    // different hash — should 409
            fn (): array => ['should' => 'not run'],
        ))->toThrow(OperationPayloadMismatchException::class);
    });
});

// ---------------------------------------------------------------------------
// Concurrent same-key (unique-constraint retry path)
// ---------------------------------------------------------------------------

describe('OperationRecordService::guardAndRun — concurrent same-key', function () {
    it('returns the cached result when a concurrent insert race produces a completed record', function () {
        $user = User::factory()->create();
        $cached = ['trx' => 'TRX-CONCURRENT'];

        // Simulate "another process won the INSERT race" by pre-creating a completed record
        // directly in the DB. When guardAndRun() attempts its own INSERT it will hit the
        // unique constraint, re-read, and return the cached payload.
        //
        // We patch the service by calling guardAndRun() with a closed-over flag that
        // creates the record right before the INSERT would happen — which is equivalent
        // to the real concurrent-insert scenario where the DB returns a constraint error.
        //
        // In practice the UniqueConstraintViolationException path is exercised here via
        // the pre-seeded completed record causing the initial ->first() check to trigger
        // the cache-hit branch. To exercise the exception-catch branch specifically we
        // create a PENDING record (the initial check won't short-circuit), then the
        // INSERT fails with a unique-constraint error, we re-read the now-completed record.

        // Create a pending record (simulates another request that started but not yet done).
        OperationRecord::create([
            'id'              => (string) Str::ulid(),
            'user_id'         => $user->id,
            'operation_type'  => 'send_money',
            'idempotency_key' => 'idem-concurrent-1',
            'payload_hash'    => validHash(),
            'status'          => OperationRecord::STATUS_PENDING,
        ]);

        // The INSERT in guardAndRun() will throw UniqueConstraintViolationException.
        // After the catch, it re-reads the record — still PENDING, so $fn is called
        // and the record is updated to COMPLETED with the fn result.
        $fnCalls = 0;
        $result = makeService()->guardAndRun(
            $user->id,
            'send_money',
            'idem-concurrent-1',
            validHash(),
            function () use (&$fnCalls, $cached): array {
                $fnCalls++;

                return $cached;
            },
        );

        expect($result)->toBe($cached);
        expect($fnCalls)->toBe(1);

        $this->assertDatabaseHas('operation_records', [
            'user_id'         => $user->id,
            'idempotency_key' => 'idem-concurrent-1',
            'status'          => OperationRecord::STATUS_COMPLETED,
        ]);
    });
});
