<?php

namespace Dashed\DashedEcommerceMollie;

use Dashed\DashedEcommerceMollie\Classes\Mollie;
use Dashed\DashedEcommerceMollie\Commands\SyncMolliePaymentMethodsCommand;
use Dashed\DashedEcommerceMollie\Filament\Pages\Settings\MollieSettingsPage;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DashedEcommerceMollieServiceProvider extends PackageServiceProvider
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
                    'icon' => 'banknotes',
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
}
