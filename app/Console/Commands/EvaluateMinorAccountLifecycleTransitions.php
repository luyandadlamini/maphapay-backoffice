<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Services\MinorAccountLifecycleService;
use Illuminate\Console\Command;

class EvaluateMinorAccountLifecycleTransitions extends Command
{
    protected $signature = 'minor-accounts:lifecycle-evaluate {--account=}';

    protected $description = 'Evaluate, schedule, and execute minor account lifecycle transitions';

    public function __construct(
        private readonly MinorAccountLifecycleService $lifecycleService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = Account::query()->where('type', 'minor');
        $specificAccount = $this->option('account');
        if (is_string($specificAccount) && $specificAccount !== '') {
            $query->where('uuid', $specificAccount);
        }

        $scheduled = 0;
        $completed = 0;
        $blocked = 0;
        $exceptionsOpened = 0;

        $query->orderBy('id')->chunkById(100, function ($accounts) use (&$scheduled, &$completed, &$blocked, &$exceptionsOpened): void {
            foreach ($accounts as $account) {
                $result = $this->lifecycleService->evaluateAccount($account, 'scheduler');
                $scheduled += $result['scheduled'];
                $completed += $result['completed'];
                $blocked += $result['blocked'];
                $exceptionsOpened += $result['exceptions_opened'];
            }
        });

        $this->info(sprintf(
            'Minor lifecycle evaluation complete. scheduled=%d completed=%d blocked=%d exceptions_opened=%d',
            $scheduled,
            $completed,
            $blocked,
            $exceptionsOpened,
        ));

        return $exceptionsOpened > 0 ? self::FAILURE : self::SUCCESS;
    }
}
