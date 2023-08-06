<?php

namespace Dashed\DashedEcommerceMollie\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Dashed\DashedEcommerceMollie\DashedEcommerceMollie
 */
class DashedEcommerceMollie extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'dashed-ecommerce-mollie';
    }
}
