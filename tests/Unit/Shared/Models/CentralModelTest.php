<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Models;

use App\Domain\Shared\Models\CentralModel;
use Tests\TestCase;

class CentralModelTest extends TestCase
{
    public function test_central_model_subclass_is_pinned_to_central_connection(): void
    {
        $model = new class () extends CentralModel {
            protected $table = 'users';
        };

        $this->assertSame('central', $model->getConnectionName());
    }
}
