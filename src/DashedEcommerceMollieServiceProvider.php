<?php

namespace Dashed\DashedEcommerceMollie;

use Filament\PluginServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Dashed\DashedEcommerceMollie\Classes\Mollie;
use Dashed\DashedEcommerceMollie\Commands\SyncMolliePaymentMethodsCommand;
use Dashed\DashedEcommerceMollie\Filament\Pages\Settings\MollieSettingsPage;
use Spatie\LaravelPackageTools\Package;

class DashedEcommerceMollieServiceProvider extends PluginServiceProvider
{
    public static string $name = 'dashed-ecommerce-mollie';

    public function bootingPackage()
    {
        $this->app->booted(function () {
            $schedule = app(Schedule::class);
            $schedule->command(SyncMolliePaymentMethodsCommand::class)->daily();
        });
    }

    public function configurePackage(Package $package): void
    {
        cms()->builder(
            'settingPages',
            array_merge(cms()->builder('settingPages'), [
                'mollie' => [
                    'name' => 'Mollie',
                    'description' => 'Link Mollie aan je webshop',
                    'icon' => 'cash',
                    'page' => MollieSettingsPage::class,
                ],
            ])
        );

        ecommerce()->builder(
            'paymentServiceProviders',
            array_merge(ecommerce()->builder('paymentServiceProviders'), [
                'mollie' => [
                    'name' => 'Mollie',
                    'class' => Mollie::class,
                ],
            ])
        );

        $package
            ->name('dashed-ecommerce-mollie')
            ->hasCommands([
                SyncMolliePaymentMethodsCommand::class,
            ]);
    }

    protected function getPages(): array
    {
        return array_merge(parent::getPages(), [
            MollieSettingsPage::class,
        ]);
    }
}
