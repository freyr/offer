<?php

declare(strict_types=1);

namespace Freyr\Offer\Saga\Order\Application;

use Closure;
use Freyr\Identity\Id;
use Freyr\Offer\Saga\SagaEvent;
use Freyr\Offer\Saga\Stock\Application\StockReserved;

class ConfirmOrderHandler
{
    /** @param Closure(SagaEvent): void $dispatch */
    public function __construct(
        private readonly Closure $dispatch,
    ) {
    }

    public function __invoke(StockReserved $event): void
    {
        ($this->dispatch)(new OrderConfirmed(
            messageId: Id::new(),
            correlationId: $event->correlationId,
            causationId: $event->messageId,
        ));
    }
}
