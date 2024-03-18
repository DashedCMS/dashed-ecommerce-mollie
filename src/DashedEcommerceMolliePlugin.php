<?php

namespace Dashed\DashedEcommerceMollie;

use Dashed\DashedEcommerceMollie\Filament\Pages\Settings\MollieSettingsPage;
use Filament\Contracts\Plugin;
use Filament\Panel;

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
