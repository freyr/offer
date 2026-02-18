<?php

declare(strict_types=1);

namespace Freyr\Offer\Saga\Order\DomainModel;

use Freyr\Identity\Id;

readonly class PlaceOrder
{
    public function __construct(
        public Id $orderId,
        public int $amount,
        public string $currency,
        public Id $productId,
    ) {
    }
}
