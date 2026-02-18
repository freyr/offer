<?php

declare(strict_types=1);

namespace Freyr\Offer\Saga\Stock\Application;

use Closure;
use Freyr\Identity\Id;
use Freyr\Offer\Saga\Payment\Application\PaymentCharged;
use Freyr\Offer\Saga\SagaEvent;

class ReserveStockHandler
{
    /** @param Closure(SagaEvent): void $dispatch */
    public function __construct(
        private readonly Closure $dispatch,
        private readonly bool $shouldSucceed = true,
    ) {
    }

    public function __invoke(PaymentCharged $event): void
    {
        if ($this->shouldSucceed) {
            ($this->dispatch)(new StockReserved(
                messageId: Id::new(),
                correlationId: $event->correlationId,
                causationId: $event->messageId,
                reservationId: Id::new(),
            ));

            return;
        }

        ($this->dispatch)(new StockReservationFailed(
            messageId: Id::new(),
            correlationId: $event->correlationId,
            causationId: $event->messageId,
            reason: 'out of stock',
        ));
    }
}
