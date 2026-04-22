<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Models\AccountProfileCompany;
use App\Domain\Account\Models\AccountProfileCompanyDocument;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class CompanyKybWebhookService
{
    private const MAX_RETRIES = 3;

    private const TIMEOUT = 30;

    public function __construct(
        private readonly string $webhookUrl,
        private readonly ?string $webhookSecret = null,
    ) {
    }

    public static function forCompany(AccountProfileCompany $company): ?self
    {
        if (empty($company->webhook_url)) {
            return null;
        }

        return new self($company->webhook_url, $company->webhook_secret);
    }

    public function sendDocumentStatusChange(
        AccountProfileCompanyDocument $document,
        string $oldStatus,
        string $newStatus,
        ?string $verifiedBy = null
    ): void {
        $payload = [
            'event'     => 'kyb.document.status_changed',
            'timestamp' => now()->toISOString(),
            'data'      => [
                'company_uuid'     => $document->companyProfile?->account_uuid,
                'company_name'     => $document->companyProfile?->company_name,
                'document_id'      => $document->id,
                'document_type'    => $document->document_type,
                'old_status'       => $oldStatus,
                'new_status'       => $newStatus,
                'verified_by'      => $verifiedBy,
                'rejection_reason' => $document->rejection_reason,
            ],
        ];

        $this->sendWebhook($payload);
    }

    private function sendWebhook(array $payload): void
    {
        if (empty($this->webhookUrl)) {
            return;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent'   => 'MaphaPay-KYB/1.0',
        ];

        if ($this->webhookSecret) {
            $headers['X-KYB-Signature'] = $this->generateSignature($payload);
        }

        try {
            Http::timeout(self::TIMEOUT)
                ->withHeaders($headers)
                ->retry(self::MAX_RETRIES, 1000)
                ->post($this->webhookUrl, $payload);

            Log::info('KYB webhook sent successfully', [
                'event' => $payload['event'],
                'url'   => $this->webhookUrl,
            ]);
        } catch (Throwable $e) {
            Log::error('KYB webhook failed', [
                'event' => $payload['event'],
                'url'   => $this->webhookUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function generateSignature(array $payload): string
    {
        $json = json_encode($payload);

        return 'sha256=' . hash_hmac('sha256', $json, $this->webhookSecret);
    }
}
