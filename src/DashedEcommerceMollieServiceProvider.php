<?php

namespace Dashed\DashedEcommerceMollie;

use Spatie\LaravelPackageTools\Package;
use Illuminate\Console\Scheduling\Schedule;
use Dashed\DashedEcommerceMollie\Classes\Mollie;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Dashed\DashedEcommerceMollie\Commands\SyncMolliePaymentMethodsCommand;
use Dashed\DashedEcommerceMollie\Filament\Pages\Settings\MollieSettingsPage;

class DashedEcommerceMollieServiceProvider extends PackageServiceProvider
{
    public static string $name = 'dashed-ecommerce-mollie';

    public function bootingPackage()
    {
        $this->app->booted(function () {
            $schedule = app(Schedule::class);
            $schedule->command(SyncMolliePaymentMethodsCommand::class)->daily();
        });

        cms()->registerSettingsDocs(
            page: \Dashed\DashedEcommerceMollie\Filament\Pages\Settings\MollieSettingsPage::class,
            title: 'Mollie instellingen',
            intro: 'Verbind de webshop met Mollie om iDEAL, creditcards, Bancontact en andere Europese betaalmethodes aan te bieden. Mollie verzorgt de betaalafhandeling en keert het geld uit naar je bankrekening. Deze instellingen zijn per site, zodat elke webshop zijn eigen Mollie account kan gebruiken.',
            sections: [
                [
                    'heading' => 'Wat kun je hier instellen?',
                    'body' => 'Per site leg je je Mollie API key vast, optioneel een partner ID en je kiest of je in testmodus werkt. De beschikbare betaalmethodes worden automatisch dagelijks gesynchroniseerd zodra er een geldige koppeling is.',
                ],
                [
                    'heading' => 'Hoe zet je Mollie op?',
                    'body' => <<<MARKDOWN
1. Maak een account aan op [mollie.com](https://www.mollie.com).
2. Doorloop de verificatie van je bedrijfsgegevens en bankrekening.
3. Ga in het Mollie dashboard naar **Ontwikkelaars > API keys**.
4. Kopieer je **Test API key** (begint met `test_`) en plak die hieronder, zet testmodus aan.
5. Doe een complete testbestelling in je webshop van begin tot eind.
6. Werkt alles? Vervang de test key dan door je **Live API key** (begint met `live_`) en zet testmodus uit.
MARKDOWN,
                ],
            ],
            fields: [
                'Partner ID' => 'Optioneel Partner ID dat door Mollie wordt meegegeven aan partners en integrators. Laat leeg als je geen Mollie partner bent.',
                'API sleutel' => 'De API sleutel van je Mollie account. In testmodus gebruik je een sleutel die begint met test_, in live modus een die begint met live_. Zonder geldige sleutel werken er geen betalingen in de webshop.',
                'Testmodus' => 'Aan betekent dat betalingen worden afgehandeld in de Mollie sandbox en er geen echt geld wordt overgemaakt. Zet dit pas uit als je een testbestelling van begin tot eind succesvol hebt doorlopen.',
            ],
            tips: [
                'Begin altijd met een test API key voordat je live gaat.',
                'Een ongeldige of verlopen sleutel zorgt dat alle betaalmethodes uit de checkout verdwijnen. Controleer de sleutel als klanten plotseling niet meer kunnen afrekenen.',
                'Zorg dat je Mollie account volledig is geverifieerd, anders kan Mollie geen geld uitkeren.',
            ],
        );
    }

    public function configurePackage(Package $package): void
    {
        cms()->registerSettingsPage(MollieSettingsPage::class, 'Mollie', 'banknotes', 'Link Mollie aan je webshop');

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

        cms()->builder('plugins', [
            new DashedEcommerceMolliePlugin(),
        ]);
    }
}
