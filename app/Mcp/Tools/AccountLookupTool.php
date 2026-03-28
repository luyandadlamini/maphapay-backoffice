<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Look up a user by email address and return their account details, frozen status, and all asset balances with formatted amounts.')]
class AccountLookupTool extends Tool
{
    public function handle(Request $request): Response
    {
        $email = $request->get('email');

        if (! $email) {
            return Response::text((string) json_encode(['error' => 'email is required'], JSON_PRETTY_PRINT));
        }

        $user = DB::selectOne(
            'SELECT uuid, name, email, email_verified_at, created_at FROM users WHERE email = ? LIMIT 1',
            [$email]
        );

        if (! $user) {
            return Response::text((string) json_encode(['error' => "No user found with email: {$email}"], JSON_PRETTY_PRINT));
        }

        $accounts = DB::select(
            'SELECT uuid, name, frozen, created_at FROM accounts WHERE user_uuid = ? ORDER BY created_at ASC',
            [$user->uuid]
        );

        $result = [
            'user'     => $user,
            'accounts' => [],
        ];

        foreach ($accounts as $account) {
            $balances = DB::select(
                'SELECT ab.asset_code, ab.balance, a.precision, a.symbol, a.name AS asset_name
                 FROM account_balances ab
                 LEFT JOIN assets a ON a.code = ab.asset_code
                 WHERE ab.account_uuid = ?
                 ORDER BY ab.asset_code ASC',
                [$account->uuid]
            );

            $formattedBalances = array_map(function ($b) {
                $divisor = 10 ** ($b->precision ?? 2);
                $formatted = number_format($b->balance / $divisor, $b->precision ?? 2, '.', '');

                return [
                    'asset'     => $b->asset_code,
                    'name'      => $b->asset_name,
                    'symbol'    => $b->symbol,
                    'raw'       => $b->balance,
                    'formatted' => "{$b->symbol} {$formatted}",
                ];
            }, $balances);

            $result['accounts'][] = [
                'uuid'     => $account->uuid,
                'name'     => $account->name,
                'frozen'   => (bool) $account->frozen,
                'created'  => $account->created_at,
                'balances' => $formattedBalances,
            ];
        }

        return Response::text((string) json_encode($result, JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()
                ->description('The email address of the user to look up')
                ->required(),
        ];
    }
}
