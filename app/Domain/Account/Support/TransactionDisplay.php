<?php

declare(strict_types=1);

namespace App\Domain\Account\Support;

final class TransactionDisplay
{
    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    public static function buildForProjection(string $type, ?string $subtype, array $metadata): ?array
    {
        $existing = self::normalize(self::arrayValue($metadata, 'display'));
        if ($existing !== null) {
            return $existing;
        }

        $context = self::arrayValue($metadata, 'p2p_display');
        if ($context === null) {
            return null;
        }

        $senderLabel = self::fallbackLabel(self::stringValue($context, 'sender_label'));
        $recipientLabel = self::fallbackLabel(self::stringValue($context, 'recipient_label'));
        $notePreview = self::notePreview(
            self::stringValue($metadata, 'note') ?? self::stringValue($context, 'note_preview'),
        );

        return match ($subtype) {
            'send_money' => match ($type) {
                'transfer_out' => self::normalize([
                    'title' => "Sent to {$recipientLabel}",
                    'subtitle' => 'Peer transfer',
                    'counterparty_name' => $recipientLabel,
                    'counterparty_role' => 'recipient',
                    'note_preview' => $notePreview,
                    'reference_visible' => false,
                ]),
                'transfer_in' => self::normalize([
                    'title' => "Received from {$senderLabel}",
                    'subtitle' => 'Peer transfer',
                    'counterparty_name' => $senderLabel,
                    'counterparty_role' => 'sender',
                    'note_preview' => $notePreview,
                    'reference_visible' => false,
                ]),
                default => null,
            },
            'request_money_accept' => match ($type) {
                'transfer_out' => self::normalize([
                    'title' => "Paid {$recipientLabel}'s request",
                    'subtitle' => 'Request payment',
                    'counterparty_name' => $recipientLabel,
                    'counterparty_role' => 'requester',
                    'note_preview' => $notePreview,
                    'reference_visible' => false,
                ]),
                'transfer_in' => self::normalize([
                    'title' => "{$senderLabel} paid your request",
                    'subtitle' => 'Request payment',
                    'counterparty_name' => $senderLabel,
                    'counterparty_role' => 'payer',
                    'note_preview' => $notePreview,
                    'reference_visible' => false,
                ]),
                default => null,
            },
            'request_money' => match ($type) {
                'transfer_out' => self::normalize([
                    'title' => "Requested from {$recipientLabel}",
                    'subtitle' => 'Money request',
                    'counterparty_name' => $recipientLabel,
                    'counterparty_role' => 'recipient',
                    'note_preview' => $notePreview,
                    'reference_visible' => false,
                ]),
                'transfer_in' => self::normalize([
                    'title' => "Request from {$senderLabel}",
                    'subtitle' => 'Money request',
                    'counterparty_name' => $senderLabel,
                    'counterparty_role' => 'requester',
                    'note_preview' => $notePreview,
                    'reference_visible' => false,
                ]),
                default => null,
            },
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>|null  $display
     * @return array<string, mixed>|null
     */
    public static function normalize(?array $display): ?array
    {
        if ($display === null) {
            return null;
        }

        $title = self::stringValue($display, 'title');
        if ($title === null) {
            return null;
        }

        return [
            'title' => $title,
            'subtitle' => self::stringValue($display, 'subtitle'),
            'counterparty_name' => self::stringValue($display, 'counterparty_name'),
            'counterparty_role' => self::stringValue($display, 'counterparty_role'),
            'note_preview' => self::notePreview(self::stringValue($display, 'note_preview')),
            'reference_visible' => (bool) ($display['reference_visible'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>|null
     */
    private static function arrayValue(array $values, string $key): ?array
    {
        $value = $values[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function stringValue(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function fallbackLabel(?string $label): string
    {
        return $label !== null && $label !== '' ? $label : 'contact';
    }

    private static function notePreview(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $trimmed = trim($note);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, 120);
    }
}
