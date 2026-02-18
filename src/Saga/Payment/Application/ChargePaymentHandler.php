<?php

declare(strict_types=1);

namespace Freyr\Offer\Saga\Payment\Application;

use Closure;
use Freyr\Identity\Id;
use Freyr\Offer\Saga\Order\Application\OrderPlaced;
use Freyr\Offer\Saga\Payment\DomainModel\PaymentRepository;
use Freyr\Offer\Saga\SagaEvent;

class ChargePaymentHandler
{
    /** @param Closure(SagaEvent): void $dispatch */
    public function __construct(
        private readonly Closure $dispatch,
        private readonly PaymentRepository $paymentRepository,
        private readonly bool $shouldSucceed = true,
    ) {
    }

    public function __invoke(OrderPlaced $event): void
    {
        if ($this->shouldSucceed) {
            $paymentId = Id::new();
            $this->paymentRepository->store($event->correlationId, $paymentId);

            ($this->dispatch)(new PaymentCharged(
                messageId: Id::new(),
                correlationId: $event->correlationId,
                causationId: $event->messageId,
                paymentId: $paymentId,
            ));

            return;
        }

        ($this->dispatch)(new PaymentFailed(
            messageId: Id::new(),
            correlationId: $event->correlationId,
            causationId: $event->messageId,
            reason: 'insufficient funds',
        ));
    }
}
