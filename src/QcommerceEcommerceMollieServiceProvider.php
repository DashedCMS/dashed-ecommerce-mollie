<?php

namespace Qubiqx\QcommerceEcommerceMollie;

use Filament\PluginServiceProvider;
use Spatie\LaravelPackageTools\Package;
use Illuminate\Console\Scheduling\Schedule;
use Qubiqx\QcommerceEcommerceMollie\Classes\Mollie;
use Qubiqx\QcommerceEcommerceMollie\Commands\SyncMolliePaymentMethodsCommand;
use Qubiqx\QcommerceEcommerceMollie\Filament\Pages\Settings\MollieSettingsPage;

class QcommerceEcommerceMollieServiceProvider extends PluginServiceProvider
{
    public static string $name = 'qcommerce-ecommerce-mollie';

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
            ->name('qcommerce-ecommerce-mollie')
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
