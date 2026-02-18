<?php

declare(strict_types=1);

namespace Freyr\Offer\Saga\Order\Application;

use Closure;
use Freyr\Identity\Id;
use Freyr\Offer\Saga\Payment\Application\PaymentFailed;
use Freyr\Offer\Saga\SagaEvent;

class RejectOrderOnPaymentFailureHandler
{
    /** @param Closure(SagaEvent): void $dispatch */
    public function __construct(
        private readonly Closure $dispatch,
    ) {
    }

    public function __invoke(PaymentFailed $event): void
    {
        ($this->dispatch)(new OrderRejected(
            messageId: Id::new(),
            correlationId: $event->correlationId,
            causationId: $event->messageId,
            reason: 'payment failed',
        ));
    }
}
