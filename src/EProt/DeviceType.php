<?php

declare(strict_types=1);

namespace Freyr\Offer\EProt;

enum DeviceType: string
{
    case SINGLE = 'single';
    case MULTISPLIT = 'multisplit';
}
