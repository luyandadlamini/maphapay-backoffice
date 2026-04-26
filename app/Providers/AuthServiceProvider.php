<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorCardRequest;
use App\Domain\Account\Models\MinorChore;
use App\Domain\Account\Models\MinorReward;
use App\Domain\Account\Policies\ChorePolicy;
use App\Domain\Account\Policies\MinorCardPolicy;
use App\Domain\Account\Policies\RewardPolicy;
use App\Domain\Analytics\Models\RevenueTarget;
use App\Policies\AccountPolicy;
use App\Policies\RevenueTargetPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Account::class          => AccountPolicy::class,
        RevenueTarget::class    => RevenueTargetPolicy::class,
        MinorChore::class       => ChorePolicy::class,
        MinorReward::class      => RewardPolicy::class,
        MinorCardRequest::class => MinorCardPolicy::class,
    ];
}
