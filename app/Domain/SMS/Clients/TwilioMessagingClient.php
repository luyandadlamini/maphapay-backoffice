<?php

declare(strict_types=1);

namespace App\Domain\SMS\Clients;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Twilio Programmable SMS (Messages API) — for transactional SMS, distinct from Verify OTP.
 *
 * @see https://www.twilio.com/docs/sms/api/message-resource
 */
class TwilioMessagingClient
{
    private const BASE_URL = 'https://api.twilio.com/2010-04-01';

    private readonly string $accountSid;

    private readonly string $authToken;

    private readonly string $messagingServiceSid;

    private readonly string $fromNumber;

    public function __construct()
    {
        /** @var array{account_sid?: string, auth_token?: string, messaging_service_sid?: string, from_number?: string} $config */
        $config = config('sms.providers.twilio', []);

        $this->accountSid = (string) ($config['account_sid'] ?? '');
        $this->authToken = (string) ($config['auth_token'] ?? '');
        $this->messagingServiceSid = (string) ($config['messaging_service_sid'] ?? '');
        $this->fromNumber = (string) ($config['from_number'] ?? '');
    }

    public function isConfigured(): bool
    {
        if ($this->accountSid === '' || $this->authToken === '') {
            return false;
        }

        return $this->messagingServiceSid !== '' || $this->fromNumber !== '';
    }

    /**
     * @return array{message_id: string, parts: int}
     */
    public function sendSms(string $to, string $from, string $message, bool $testMode = false): array
    {
        $this->assertConfigured();

        $form = array_merge(
            [
                'To'   => $to,
                'Body' => $message,
            ],
            $this->resolveFromParameter($from),
        );

        if ($testMode) {
            Log::info('TwilioMessaging: test_mode flag set (Twilio still sends unless using magic numbers)');
        }

        $response = Http::withBasicAuth($this->accountSid, $this->authToken)
            ->asForm()
            ->timeout(30)
            ->post(self::BASE_URL . "/Accounts/{$this->accountSid}/Messages.json", $form);

        if (! $response->successful()) {
            Log::error('TwilioMessaging: send failed', [
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 500),
                'to'     => $to,
            ]);

            throw new RuntimeException('Twilio SMS send failed: HTTP ' . $response->status());
        }

        /** @var array{sid?: string, num_segments?: string|int} $data */
        $data = $response->json() ?? [];
        $sid = (string) ($data['sid'] ?? '');
        if ($sid === '') {
            throw new RuntimeException('Twilio SMS response missing message SID');
        }

        $parts = max(1, (int) ($data['num_segments'] ?? 1));

        Log::info('TwilioMessaging: message sent', [
            'sid'   => $sid,
            'parts' => $parts,
            'to'    => $to,
        ]);

        return [
            'message_id' => $sid,
            'parts'      => $parts,
        ];
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Twilio SMS is not configured. Set TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, and '
                . 'TWILIO_MESSAGING_SERVICE_SID or TWILIO_FROM_NUMBER (E.164) in .env'
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function resolveFromParameter(string $from): array
    {
        if ($this->messagingServiceSid !== '') {
            return ['MessagingServiceSid' => $this->messagingServiceSid];
        }

        if ($this->fromNumber !== '') {
            return ['From' => $this->fromNumber];
        }

        if (preg_match('/^\+[1-9]\d{4,14}$/', $from) === 1) {
            return ['From' => $from];
        }

        throw new RuntimeException(
            'Twilio SMS needs TWILIO_MESSAGING_SERVICE_SID or TWILIO_FROM_NUMBER, or an E.164 From on the request.'
        );
    }
}
