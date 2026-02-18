<?php

declare(strict_types=1);

namespace Freyr\Offer\Saga\Order\Application;

use Closure;
use Freyr\Identity\Id;
use Freyr\Offer\Saga\Payment\Application\PaymentRefunded;
use Freyr\Offer\Saga\SagaEvent;

class RejectOrderOnRefundHandler
{
    /** @param Closure(SagaEvent): void $dispatch */
    public function __construct(
        private readonly Closure $dispatch,
    ) {
    }

    public function __invoke(PaymentRefunded $event): void
    {
        ($this->dispatch)(new OrderRejected(
            messageId: Id::new(),
            correlationId: $event->correlationId,
            causationId: $event->messageId,
            reason: 'stock unavailable, payment refunded',
        ));
    }
}
