<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

use App\Domain\SMS\Clients\TwilioVerifyClient;
use App\Domain\SMS\Services\SmsService;
use App\Models\User;
use App\Models\UserOtp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OtpService
{
    private const OTP_LENGTH = 6;

    private const OTP_TTL_MINUTES = 10;

    private const RESEND_COOLDOWN_SECONDS = 120;

    public function __construct(
        private readonly SmsService $smsService,
        private readonly TwilioVerifyClient $twilioVerify,
    ) {
    }

    public function generateAndSend(User $user, string $type, string $channel = 'sms'): string
    {
        if ($channel === 'sms' && $this->isTwilioProvider()) {
            $to = $user->dial_code . $user->mobile;
            $this->twilioVerify->startVerification($to);

            Log::info('OtpService: Twilio OTP dispatched', [
                'user_id' => $user->id,
                'type'    => $type,
                'to'      => $to,
            ]);

            return '';
        }

        $otp = $this->generateOtp();
        $this->store($user, $type, $otp);
        $this->deliver($user, $otp, $type, $channel);

        return $otp;
    }

    public function verify(User $user, string $type, string $plainOtp): bool
    {
        if ($this->isTwilioProvider()) {
            $to = $user->dial_code . $user->mobile;

            return $this->twilioVerify->checkVerification($to, $plainOtp);
        }

        $record = $this->getActiveOtp($user, $type);

        if ($record === null) {
            return false;
        }

        if ($record->isExpired()) {
            return false;
        }

        if (! Hash::check($plainOtp, $record->otp_hash)) {
            return false;
        }

        $record->update(['verified_at' => now()]);

        return true;
    }

    /**
     * @return array{can_resend: bool, remaining_seconds: int}
     */
    public function canResend(User $user, string $type): array
    {
        // Twilio manages its own rate limiting — we report always ready.
        // Twilio enforces max 5 sends per phone per service by default.
        if ($this->isTwilioProvider()) {
            return ['can_resend' => true, 'remaining_seconds' => 0];
        }

        $record = UserOtp::where('user_id', $user->id)
            ->where('type', $type)
            ->whereNull('verified_at')
            ->orderByDesc('created_at')
            ->first();

        if ($record === null) {
            return ['can_resend' => true, 'remaining_seconds' => 0];
        }

        $elapsed = now()->diffInSeconds($record->created_at);
        $remaining = max(0, self::RESEND_COOLDOWN_SECONDS - $elapsed);

        return [
            'can_resend'        => $remaining === 0,
            'remaining_seconds' => (int) $remaining,
        ];
    }

    public function resend(User $user, string $type, string $channel = 'sms'): string
    {
        // For Twilio: skip the local cooldown check — Twilio handles rate limiting.
        if (! $this->isTwilioProvider()) {
            $check = $this->canResend($user, $type);
            if (! $check['can_resend']) {
                throw new RuntimeException(
                    "Please wait {$check['remaining_seconds']} seconds before requesting a new code."
                );
            }
        }

        return $this->generateAndSend($user, $type, $channel);
    }

    private function isTwilioProvider(): bool
    {
        return config('sms.otp_provider') === 'twilio'
            && $this->twilioVerify->isConfigured();
    }

    private function generateOtp(): string
    {
        return (string) random_int(10 ** (self::OTP_LENGTH - 1), 999999);
    }

    private function store(User $user, string $type, string $plainOtp): UserOtp
    {
        UserOtp::where('user_id', $user->id)
            ->where('type', $type)
            ->whereNull('verified_at')
            ->delete();

        return UserOtp::create([
            'user_id'    => $user->id,
            'type'       => $type,
            'otp_hash'   => Hash::make($plainOtp),
            'expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
        ]);
    }

    private function getActiveOtp(User $user, string $type): ?UserOtp
    {
        return UserOtp::where('user_id', $user->id)
            ->where('type', $type)
            ->whereNull('verified_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->first();
    }

    private function deliver(User $user, string $plainOtp, string $type, string $channel): void
    {
        if ($channel === 'sms') {
            $this->deliverViaSms($user, $plainOtp, $type);
        }
    }

    private function deliverViaSms(User $user, string $plainOtp, string $type): void
    {
        $message = $this->smsMessageForType($type, $plainOtp);

        try {
            $to   = $user->dial_code . $user->mobile;
            $from = (string) config('sms.defaults.from', 'FinAegis');

            $this->smsService->send($to, $from, $message);

            Log::info('OtpService: SMS OTP sent', [
                'user_id' => $user->id,
                'type'    => $type,
                'to'      => $to,
            ]);
        } catch (Throwable $e) {
            Log::error('OtpService: Failed to send SMS OTP', [
                'user_id' => $user->id,
                'type'    => $type,
                'error'   => $e->getMessage(),
            ]);

            throw new RuntimeException('Could not deliver the verification code right now. Please try again.');
        }
    }

    private function smsMessageForType(string $type, string $plainOtp): string
    {
        return match ($type) {
            UserOtp::TYPE_MOBILE_VERIFICATION => "Your FinAegis verification code is: {$plainOtp}. Valid for 10 minutes.",
            UserOtp::TYPE_PIN_RESET           => "Your FinAegis PIN reset code is: {$plainOtp}. Valid for 10 minutes. If you did not request this, please ignore.",
            UserOtp::TYPE_LOGIN               => "Your FinAegis login code is: {$plainOtp}. Valid for 10 minutes.",
            default                           => "Your FinAegis code is: {$plainOtp}. Valid for 10 minutes.",
        };
    }
}
