<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Lending;

use App\Domain\Lending\Models\Loan;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

it('resolves the borrower relation against the central user model', function (): void {
    $relation = (new Loan())->borrower();

    expect($relation)->toBeInstanceOf(BelongsTo::class)
        ->and($relation->getRelated())->toBeInstanceOf(User::class);
});
