<?php

declare(strict_types=1);

namespace Freyr\Offer\Saga\Order\Application;

use Closure;
use Freyr\Identity\Id;
use Freyr\Offer\Saga\Order\DomainModel\PlaceOrder;
use Freyr\Offer\Saga\SagaEvent;

class PlaceOrderHandler
{
    /** @param Closure(SagaEvent): void $dispatch */
    public function __construct(
        private readonly Closure $dispatch,
    ) {
    }

    public function __invoke(PlaceOrder $command): void
    {
        ($this->dispatch)(new OrderPlaced(
            messageId: Id::new(),
            correlationId: $command->orderId,
            causationId: null,
        ));
    }
}
