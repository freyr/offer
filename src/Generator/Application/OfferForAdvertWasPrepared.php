<?php

namespace Freyr\Offer\Generator\Application;

use Freyr\Identity\Id;

readonly class OfferForAdvertWasPrepared
{

    public function __construct(public Id $offerId)
    {
    }
}