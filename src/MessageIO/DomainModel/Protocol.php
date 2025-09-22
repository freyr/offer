<?php

declare(strict_types=1);

namespace Freyr\Offer\MessageIO\DomainModel;

enum Protocol: string
{
    case ALLEGRO = 'allegro';
    case OLX_LEGACY = 'olxlegacy';
    case OLX = 'olx';
}
