<?php

declare(strict_types=1);

namespace Freyr\Offer\Saga\Payment\Application;

use Closure;
use Freyr\Identity\Id;
use Freyr\Offer\Saga\Payment\DomainModel\PaymentRepository;
use Freyr\Offer\Saga\SagaEvent;
use Freyr\Offer\Saga\Stock\Application\StockReservationFailed;

class RefundPaymentHandler
{
    /** @param Closure(SagaEvent): void $dispatch */
    public function __construct(
        private readonly Closure $dispatch,
        private readonly PaymentRepository $paymentRepository,
    ) {
    }

    public function __invoke(StockReservationFailed $event): void
    {
        $paymentId = $this->paymentRepository->findByCorrelationId($event->correlationId);

        ($this->dispatch)(new PaymentRefunded(
            messageId: Id::new(),
            correlationId: $event->correlationId,
            causationId: $event->messageId,
            paymentId: $paymentId,
        ));
    }
}
