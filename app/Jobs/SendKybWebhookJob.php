<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Account\Services\CompanyKybWebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendKybWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly CompanyKybWebhookService $webhookService,
        private readonly string $documentId,
        private readonly string $oldStatus,
        private readonly string $newStatus,
        private readonly ?string $verifiedBy = null,
    ) {}

    public function handle(): void
    {
        $document = \App\Domain\Account\Models\AccountProfileCompanyDocument::query()
            ->with('companyProfile')
            ->find($this->documentId);

        if (!$document) {
            return;
        }

        $this->webhookService->sendDocumentStatusChange(
            $document,
            $this->oldStatus,
            $this->newStatus,
            $this->verifiedBy
        );
    }
}