<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Wallets;

use App\Filament\Admin\Pages\Wallets\Concerns\HasMockWalletActions;
use Filament\Pages\Page;

/**
 * Parent page for a wallet provider dashboard. Immediately redirects to the
 * Overview sub-page. Provides Fund / Check balance header actions to every
 * sub-navigation page via the HasMockWalletActions trait.
 */
abstract class AbstractWalletProviderPage extends Page
{
    use HasMockWalletActions;

    protected static ?string $navigationGroup = 'E-Wallets';

    protected static string $view = 'filament.admin.pages.wallets.redirect';

    /** Provider key as persisted in the database (e.g. 'mtn_momo'). */
    public static string $providerKey = '';

    /** Display label shown in the sidebar and the page header. */
    public static string $providerLabel = '';

    /** Path segment used in the mock-wallets routes (e.g. 'mtn-momo'). */
    public static string $mockEndpointPath = '';

    public function mount(): void
    {
        $slug = static::getSlug();
        redirect()->to("/admin/{$slug}/overview")->send();
    }

    public static function getNavigationLabel(): string
    {
        return static::$providerLabel;
    }

    public function getTitle(): string
    {
        return static::$providerLabel;
    }
}
