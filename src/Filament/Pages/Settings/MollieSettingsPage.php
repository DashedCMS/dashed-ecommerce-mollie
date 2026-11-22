<?php

namespace Dashed\DashedEcommerceMollie\Filament\Pages\Settings;

use Filament\Pages\Page;
use Illuminate\Support\HtmlString;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Dashed\DashedEcommerceCore\Models\PaymentMethod;
use Filament\Schemas\Schema;
use Dashed\DashedCore\Classes\Sites;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
use Dashed\DashedCore\Models\Customsetting;
use Filament\Infolists\Components\TextEntry;
use Dashed\DashedEcommerceMollie\Classes\Mollie;
use Dashed\DashedCore\Traits\HasSettingsPermission;
use Dashed\DashedCore\Filament\Concerns\ShowsIntegrationHealthBanner;

class MollieSettingsPage extends Page
{
    use \Dashed\DashedCore\Filament\Concerns\TranslatesPageLabels;
    use HasSettingsPermission;
    use ShowsIntegrationHealthBanner;

    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $title = 'Mollie';

    protected string $view = 'dashed-core::settings.pages.default-settings';
    public array $data = [];

    public function mount(): void
    {
        $formData = [];
        $sites = Sites::getSites();
        foreach ($sites as $site) {
            $formData["mollie_partner_id_{$site['id']}"] = Customsetting::get('mollie_partner_id', $site['id']);
            $formData["mollie_api_key_{$site['id']}"] = Customsetting::get('mollie_api_key', $site['id']);
            $formData["mollie_test_mode_{$site['id']}"] = Customsetting::get('mollie_test_mode', $site['id'], false) ? true : false;
            $formData["mollie_send_order_lines_{$site['id']}"] = Customsetting::get('mollie_send_order_lines', $site['id'], false) ? true : false;
            $formData["mollie_connected_{$site['id']}"] = Customsetting::get('mollie_connected', $site['id']);
            $formData["mollie_connection_error_{$site['id']}"] = Customsetting::get('mollie_connection_error', $site['id']);
            $formData["mollie_apple_pay_domain_association_{$site['id']}"] = Customsetting::get('mollie_apple_pay_domain_association', $site['id']);
        }

        $this->form->fill($formData);
    }

    public function form(Schema $schema): Schema
    {
        $sites = Sites::getSites();
        $tabGroups = [];

        $tabs = [];
        foreach ($sites as $site) {
            $newSchema = [
                TextEntry::make('label')
                    ->state("Mollie voor {$site['name']}")
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 2,
                    ]),
                TextEntry::make("Mollie is " . (! Customsetting::get('mollie_connected', $site['id'], 0) ? 'niet' : '') . ' geconnect')
                    ->state(Customsetting::get('mollie_connection_error', $site['id'], ''))
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 2,
                    ]),
                TextInput::make("mollie_partner_id_{$site['id']}")
                    ->label(__('Mollie Partner ID'))
                    ->maxLength(255),
                TextInput::make("mollie_api_key_{$site['id']}")
                    ->label(__('Mollie API key'))
                    ->maxLength(255),
                Toggle::make("mollie_test_mode_{$site['id']}")
                    ->label(__('Testmodus activeren')),
                Toggle::make("mollie_send_order_lines_{$site['id']}")
                    ->label(__('Orderregels meesturen'))
                    ->helperText(__('Verplicht voor achteraf betalen (Klarna, in3, Riverty, Billie). De orderregels worden alleen meegestuurd als ze exact optellen tot het betaalbedrag.')),
                Section::make(__('Apple Pay'))
                    ->collapsible()
                    ->collapsed(fn () => self::applePayStatus($site['id']) === 'active')
                    ->columnSpan([
                        'default' => 1,
                        'lg' => 2,
                    ])
                    ->schema([
                        TextEntry::make("apple_pay_status_{$site['id']}")
                            ->label(__('Status'))
                            ->state(fn () => match (self::applePayStatus($site['id'])) {
                                'active' => __('Apple Pay staat aan in de webshop.'),
                                'inactive' => __('Apple Pay is opgehaald bij Mollie maar staat nog uit. Zet hem aan onder Betaalmethodes.'),
                                default => __('Apple Pay is nog niet opgehaald bij Mollie. Volg de stappen hieronder.'),
                            }),
                        TextEntry::make("apple_pay_steps_{$site['id']}")
                            ->label(__('Zo zet je Apple Pay aan'))
                            ->html()
                            ->state(fn () => self::applePaySteps()),
                        Textarea::make("mollie_apple_pay_domain_association_{$site['id']}")
                            ->label(__('Domeinverificatiebestand (optioneel)'))
                            ->helperText(__('Laat leeg: de webshop haalt het bestand automatisch bij Mollie op. Alleen invullen als Mollie je een eigen bestand geeft.'))
                            ->rows(3),
                    ]),
            ];

            $tabs[] = Tab::make($site['id'])
                ->label(ucfirst($site['name']))
                ->schema($newSchema)
                ->columns([
                    'default' => 1,
                    'lg' => 2,
                ]);
        }
        $tabGroups[] = Tabs::make('Sites')
            ->tabs($tabs);

        return $schema->schema($tabGroups)
            ->statePath('data');
    }

    protected static function applePayStatus(string $siteId): string
    {
        $method = PaymentMethod::where('site_id', $siteId)
            ->where('psp', 'mollie')
            ->where('psp_id', 'applepay')
            ->first();

        if (! $method) {
            return 'missing';
        }

        return $method->active ? 'active' : 'inactive';
    }

    protected static function applePaySteps(): HtmlString
    {
        $fileUrl = url('/.well-known/apple-developer-merchantid-domain-association');
        $domain = parse_url(url('/'), PHP_URL_HOST);

        $steps = [
            __('Zet Apple Pay aan in het Mollie-dashboard onder Instellingen → Betaalmethodes (bij het juiste websiteprofiel).'),
            __('Controleer dat het verificatiebestand bereikbaar is: <a href=":url" target="_blank" class="underline">:url</a>. Die URL moet een regel tekst tonen, geen foutpagina. De webshop regelt dit zelf.', ['url' => e($fileUrl)]),
            __('Voeg in het Mollie-dashboard bij Apple Pay het domein <strong>:domain</strong> toe (zonder https://, en ook de www-variant als de webshop die gebruikt). Mollie controleert dan het bestand uit stap 2.', ['domain' => e($domain)]),
            __('Klik hieronder op Opslaan. De webshop haalt dan de betaalmethodes opnieuw op bij Mollie (dit gebeurt ook elke nacht), en Apple Pay komt onder Betaalmethodes te staan.'),
            __('Zet Apple Pay onder Betaalmethodes op actief. In de checkout wordt Apple Pay alleen getoond op apparaten en browsers die het ondersteunen (Safari op iPhone, iPad en Mac).'),
        ];

        return new HtmlString('<ol class="list-decimal ps-5 space-y-1">'.collect($steps)->map(fn ($step) => "<li>{$step}</li>")->implode('').'</ol>');
    }

    public function submit()
    {
        $sites = Sites::getSites();

        foreach ($sites as $site) {
            Customsetting::set('mollie_partner_id', $this->form->getState()["mollie_partner_id_{$site['id']}"], $site['id']);
            Customsetting::set('mollie_api_key', $this->form->getState()["mollie_api_key_{$site['id']}"], $site['id']);
            Customsetting::set('mollie_test_mode', $this->form->getState()["mollie_test_mode_{$site['id']}"], $site['id']);
            Customsetting::set('mollie_send_order_lines', $this->form->getState()["mollie_send_order_lines_{$site['id']}"], $site['id']);
            Customsetting::set('mollie_apple_pay_domain_association', trim((string) $this->form->getState()["mollie_apple_pay_domain_association_{$site['id']}"]), $site['id']);
            Customsetting::set('mollie_connected', Mollie::isConnected($site['id']), $site['id']);

            if (Customsetting::get('mollie_connected', $site['id'])) {
                Mollie::syncPaymentMethods($site['id']);
            }
        }

        Notification::make()
            ->title(__('De Mollie instellingen zijn opgeslagen'))
            ->success()
            ->send();

        return redirect(MollieSettingsPage::getUrl());
    }
}
