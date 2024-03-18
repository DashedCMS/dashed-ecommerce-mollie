<?php

namespace Dashed\DashedEcommerceMollie\Commands;

use Dashed\DashedEcommerceMollie\Classes\Mollie;
use Illuminate\Console\Command;

class SyncMolliePaymentMethodsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dashed:sync-mollie-payment-methods';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync Mollie payment methods';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        Mollie::syncPaymentMethods();
    }
}
