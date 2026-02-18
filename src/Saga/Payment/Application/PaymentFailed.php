<?php

declare(strict_types=1);

namespace Freyr\Offer\Saga\Payment\Application;

use Freyr\Identity\Id;
use Freyr\Offer\Saga\SagaEvent;

readonly class PaymentFailed implements SagaEvent
{
    public function __construct(
        public Id $messageId,
        public Id $correlationId,
        public ?Id $causationId,
        public string $reason,
    ) {
    }
}
