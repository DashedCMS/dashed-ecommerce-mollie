<?php

namespace Dashed\DashedEcommerceMollie;

use Filament\Panel;
use Filament\Contracts\Plugin;
use Dashed\DashedEcommerceMollie\Filament\Pages\Settings\MollieSettingsPage;

class DashedEcommerceMolliePlugin implements Plugin
{
    public function getId(): string
    {
        return 'dashed-ecommerce-mollie';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->pages([
                MollieSettingsPage::class,
            ]);
    }

    public function boot(Panel $panel): void
    {

    }
}
