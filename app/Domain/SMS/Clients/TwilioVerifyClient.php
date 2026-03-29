<?php

declare(strict_types=1);

namespace App\Domain\SMS\Clients;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * HTTP client for the Twilio Verify v2 API.
 *
 * Twilio Verify owns the entire OTP lifecycle: code generation, storage,
 * delivery, and expiry. We only call start (send) and check (verify).
 *
 * @see https://www.twilio.com/docs/verify/api
 */
class TwilioVerifyClient
{
    private readonly string $accountSid;

    private readonly string $authToken;

    private readonly string $serviceSid;

    private const BASE_URL = 'https://verify.twilio.com/v2';

    public function __construct()
    {
        /** @var array{account_sid?: string, auth_token?: string, verify_service_sid?: string} $config */
        $config = config('sms.providers.twilio', []);

        $this->accountSid = (string) ($config['account_sid'] ?? '');
        $this->authToken  = (string) ($config['auth_token'] ?? '');
        $this->serviceSid = (string) ($config['verify_service_sid'] ?? '');
    }

    /**
     * Start a verification — Twilio generates and sends the OTP via SMS.
     *
     * @throws RuntimeException if configuration is missing or the API call fails
     */
    public function startVerification(string $to): void
    {
        $this->assertConfigured();

        $response = Http::withBasicAuth($this->accountSid, $this->authToken)
            ->asForm()
            ->timeout(15)
            ->post(self::BASE_URL . "/Services/{$this->serviceSid}/Verifications", [
                'To'      => $to,
                'Channel' => 'sms',
            ]);

        if (! $response->successful()) {
            Log::error('TwilioVerify: start verification failed', [
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 500),
                'to'     => $to,
            ]);

            throw new RuntimeException('Failed to send verification code. Please try again.');
        }

        Log::info('TwilioVerify: verification started', ['to' => $to]);
    }

    /**
     * Check a verification code entered by the user.
     *
     * Returns true if Twilio confirms the code is correct and not expired.
     */
    public function checkVerification(string $to, string $code): bool
    {
        $this->assertConfigured();

        $response = Http::withBasicAuth($this->accountSid, $this->authToken)
            ->asForm()
            ->timeout(15)
            ->post(self::BASE_URL . "/Services/{$this->serviceSid}/VerificationChecks", [
                'To'   => $to,
                'Code' => $code,
            ]);

        if (! $response->successful()) {
            Log::warning('TwilioVerify: check failed', [
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 200),
                'to'     => $to,
            ]);

            return false;
        }

        /** @var array{status?: string} $data */
        $data = $response->json() ?? [];

        $approved = ($data['status'] ?? '') === 'approved';

        Log::info('TwilioVerify: verification check', [
            'to'       => $to,
            'approved' => $approved,
        ]);

        return $approved;
    }

    public function isConfigured(): bool
    {
        return $this->accountSid !== ''
            && $this->authToken !== ''
            && $this->serviceSid !== '';
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Twilio Verify is not configured. Set TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, and TWILIO_VERIFY_SERVICE_SID in .env'
            );
        }
    }
}
