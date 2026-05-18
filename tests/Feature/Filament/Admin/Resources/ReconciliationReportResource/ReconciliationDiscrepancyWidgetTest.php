<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Resources\ReconciliationReportResource;

use App\Domain\Account\Models\Account;
use App\Filament\Admin\Resources\ReconciliationReportResource\Widgets\ReconciliationDiscrepancyWidget;
use Tests\TestCase;

class ReconciliationDiscrepancyWidgetTest extends TestCase
{
    public function test_widget_instantiates_successfully(): void
    {
        Account::factory()->create();
        Account::factory()->state(['frozen' => true])->create();

        $widget = new ReconciliationDiscrepancyWidget();

        expect($widget)->toBeInstanceOf(ReconciliationDiscrepancyWidget::class);
    }
}
