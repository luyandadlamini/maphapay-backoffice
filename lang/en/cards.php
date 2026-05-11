<?php

declare(strict_types=1);

return [
    'push' => [
        'subscription_activated' => [
            'title' => 'Card subscription active',
            'body'  => 'Your :plan subscription is now active.',
        ],
        'payment_success' => [
            'title' => 'Card payment successful',
            'body'  => 'We\'ve billed your :plan subscription. Next charge :date.',
        ],
        'payment_failed' => [
            'title' => 'Card payment failed',
            'body'  => 'Add money to your wallet by :grace_end. Your wallet still works for local payments.',
        ],
        'subscription_suspended' => [
            'title' => 'Card access paused',
            'body'  => 'Your subscription is overdue. Pay now to restore card access.',
        ],
        'subscription_cancelled' => [
            'title' => 'Card subscription ended',
            'body'  => 'Your wallet still works. Choose a plan to use cards again.',
        ],
        'subscription_restored' => [
            'title' => 'Card access restored',
            'body'  => 'Payment received. Your cards are active again.',
        ],
        'virtual_created' => [
            'title' => 'Virtual card created',
            'body'  => 'Your card ending in :last4 is ready to use.',
        ],
        'transaction_approved' => [
            'title' => 'Card payment',
            'body'  => ':merchant: :amount',
        ],
        'transaction_declined' => [
            'title' => 'Card declined',
            'body'  => ':merchant: :reason',
        ],
        'fee_subscription' => [
            'title' => 'Card fee charged',
            'body'  => 'A subscription-related fee of :amount SZL was applied.',
        ],
        'fee_physical' => [
            'title' => 'Physical card fee',
            'body'  => 'A fee of :amount SZL was charged for your physical card order.',
        ],
        'minor_request_approved' => [
            'title' => 'Card request approved',
            'body'  => 'Your guardian approved your request.',
        ],
        'minor_request_denied' => [
            'title' => 'Card request denied',
            'body'  => 'Your guardian denied this request: :reason',
        ],
        'physical_order_update' => [
            'title' => 'Physical card order update',
            'body'  => 'Your order status is now :status.',
        ],
        'physical_activated' => [
            'title' => 'Card activated',
            'body'  => 'Your physical card is ready.',
        ],
        'risk_alert' => [
            'title' => 'Card temporarily restricted',
            'body'  => 'We paused your card after unusual activity. Tap to learn more.',
        ],
    ],
];
