<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\ProjectorHealthDashboard;
use App\Filament\Admin\Resources\AmlScreeningResource\Pages\ListAmlScreenings;
use App\Filament\Admin\Resources\BannerResource\Pages\ListBanners;
use App\Filament\Admin\Resources\DataSubjectRequestResource\Pages\ListDataSubjectRequests;
use App\Filament\Admin\Resources\FeatureFlagResource\Pages\ListFeatureFlags;
use App\Filament\Admin\Resources\FilingScheduleResource\Pages\ListFilingSchedules;
use App\Filament\Admin\Resources\GroupSavingsResource\Pages\ListGroupSavings;
use App\Filament\Admin\Resources\ReferralResource\Pages\ListReferrals;
use App\Filament\Admin\Resources\SocialMoneyResource\Pages\ListSocialMoney;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel->boot();

    $this->user = User::factory()->create();
    $this->user->assignRole('super-admin');
    $this->actingAs($this->user);
});

it('renders GroupSavingsResource index page', function () {
    livewire(ListGroupSavings::class)->assertSuccessful();
});

it('renders SocialMoneyResource index page', function () {
    livewire(ListSocialMoney::class)->assertSuccessful();
});

it('renders BannerResource index page', function () {
    livewire(ListBanners::class)->assertSuccessful();
});

it('renders DataSubjectRequestResource index page', function () {
    livewire(ListDataSubjectRequests::class)->assertSuccessful();
});

it('renders AmlScreeningResource index page', function () {
    livewire(ListAmlScreenings::class)->assertSuccessful();
});

it('renders FilingScheduleResource index page', function () {
    livewire(ListFilingSchedules::class)->assertSuccessful();
});

it('renders FeatureFlagResource index page', function () {
    livewire(ListFeatureFlags::class)->assertSuccessful();
});

it('renders ReferralResource index page', function () {
    livewire(ListReferrals::class)->assertSuccessful();
});

it('renders ProjectorHealthDashboard index page', function () {
    livewire(ProjectorHealthDashboard::class)->assertSuccessful();
});
