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
        $this->authToken = (string) ($config['auth_token'] ?? '');
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
            /** @var array{code?: int|string, message?: string} $err */
            $err = $response->json() ?? [];
            $twilioCode = isset($err['code']) ? (int) $err['code'] : 0;
            $twilioMsg = isset($err['message']) ? (string) $err['message'] : '';

            Log::error('TwilioVerify: start verification failed', [
                'status'      => $response->status(),
                'twilio_code' => $twilioCode,
                'message'     => mb_substr($twilioMsg, 0, 300),
                'body'        => mb_substr($response->body(), 0, 500),
                'to'          => $to,
            ]);

            throw new RuntimeException($this->friendlyStartError($response->status(), $twilioCode, $twilioMsg));
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

    private function friendlyStartError(int $httpStatus, int $twilioCode, string $twilioMessage): string
    {
        // https://www.twilio.com/docs/api/errors — common Verify / SMS failures
        if (in_array($twilioCode, [60200, 60203, 60212, 60213, 21211, 21614], true)) {
            return 'This phone number cannot receive a verification SMS. Use the full number with country code, '
                . 'without a leading 0 after the country code (e.g. +26876123456). On a Twilio trial, the number must be verified in the Twilio console.';
        }

        if ($twilioCode === 20404 || ($httpStatus === 404 && str_contains(strtolower($twilioMessage), 'service'))) {
            return 'Twilio Verify service was not found. Check TWILIO_VERIFY_SERVICE_SID matches your Verify service in the Twilio console.';
        }

        if ($httpStatus === 401 || $twilioCode === 20003) {
            return 'Twilio rejected the request (invalid credentials). Check TWILIO_ACCOUNT_SID and TWILIO_AUTH_TOKEN.';
        }

        if ($twilioMessage !== '') {
            Log::debug('TwilioVerify: raw error message for client', ['message' => $twilioMessage]);
        }

        return 'Failed to send verification code. Please try again.';
    }
}
