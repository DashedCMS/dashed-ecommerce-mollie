<?php

namespace Qubiqx\QcommerceEcommerceMollie\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Qubiqx\QcommerceEcommerceMollie\QcommerceEcommerceMollie
 */
class QcommerceEcommerceMollie extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'qcommerce-ecommerce-mollie';
    }
}
