<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Cards\CardDisputeResource\Pages;

use App\Domain\CardSubscriptions\Enums\CardDisputeStatus;
use App\Domain\CardSubscriptions\Models\CardTransaction;
use App\Filament\Admin\Resources\Cards\CardDisputeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCardDispute extends CreateRecord
{
    protected static string $resource = CardDisputeResource::class;

    public function mount(): void
    {
        parent::mount();

        $txId = request()->query('card_transaction_id');
        if (is_string($txId) && $txId !== '') {
            $this->form->fill([
                'card_transaction_id' => $txId,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        /** @var CardTransaction|null $tx */
        $tx = CardTransaction::query()->find($data['card_transaction_id'] ?? null);
        if ($tx instanceof CardTransaction) {
            $data['user_id'] = (string) $tx->user_id;
        }
        $data['status'] = CardDisputeStatus::Submitted;
        $data['submitted_at'] = now();

        return $data;
    }
}
