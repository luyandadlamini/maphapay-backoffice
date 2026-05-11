<?php

declare(strict_types=1);

return [
    'push' => [
        'subscription_activated' => [
            'title' => 'Likhokholo lelikhadi liyasebenta',
            'body'  => 'Likusito lakho le-:plan liyasebenta manje.',
        ],
        'payment_success' => [
            'title' => 'Kukhokhwa kwekukhadi kuphumelele',
            'body'  => 'Sikhokhile likusito lakho le-:plan. Kukhokhwa lokulandzelako :date.',
        ],
        'payment_failed' => [
            'title' => 'Kukhokhwa kwekukhadi kwehlulekile',
            'body'  => 'Faka imali ku-wallet yakho ngu-:grace_end. I-wallet isasebenta.',
        ],
        'subscription_suspended' => [
            'title' => 'Likhwama lelikhadi lima',
            'body'  => 'Likusito lakho liphelelwe sikhatsi. Khokha manje kute ubuyise emuva.',
        ],
        'subscription_cancelled' => [
            'title' => 'Likhokholo lelikhadi liphelile',
            'body'  => 'I-wallet isasebenta. Khetsa likusito kute usebentise emakhadini.',
        ],
        'subscription_restored' => [
            'title' => 'Likhwama lelikhadi libuyile',
            'body'  => 'Siyamukela kukhokhwa. Emakhadini akho ayasebenta.',
        ],
        'virtual_created' => [
            'title' => 'Likhadi le-digital lenziwe',
            'body'  => 'Likhadi lakho leliphela nge-:last4 liyakulungele.',
        ],
        'transaction_approved' => [
            'title' => 'Sikhokho sekukhadi',
            'body'  => ':merchant: :amount',
        ],
        'transaction_declined' => [
            'title' => 'Likhadi lenqatjwe',
            'body'  => ':merchant: :reason',
        ],
        'fee_subscription' => [
            'title' => 'Imali yekukhadi',
            'body'  => 'Imali ye-:amount SZL yakhokhwa ngekusito.',
        ],
        'fee_physical' => [
            'title' => 'Imali yelikhadi lemnyama',
            'body'  => 'Imali ye-:amount SZL yakhokhwa nge-oda lakho lelikhadi lemnyama.',
        ],
        'minor_request_approved' => [
            'title' => 'sicelo sesitfunti sivunyelwe',
            'body'  => 'Umgcini wakho wemntfwana uyasivuma sicelo sakho.',
        ],
        'minor_request_denied' => [
            'title' => 'sicelo senqatjwe',
            'body'  => 'Umgcini wakho wenqatisa: :reason',
        ],
        'physical_order_update' => [
            'title' => 'Kubuyekezwa kwe-oda lelikhadi lemnyama',
            'body'  => 'Isimo se-oda sakho siyisi-:status.',
        ],
        'physical_activated' => [
            'title' => 'Likhadi liyasebenta',
            'body'  => 'Likhadi lakho lemnyama liyakulungele.',
        ],
        'risk_alert' => [
            'title' => 'Likhadi lima kwesikhashana',
            'body'  => 'Siphawule emkhatsini ongakajwayelekile. Chafata kute utolote.',
        ],
    ],
];
