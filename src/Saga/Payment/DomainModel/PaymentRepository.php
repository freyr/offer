<?php

declare(strict_types=1);

namespace Freyr\Offer\Saga\Payment\DomainModel;

use Freyr\Identity\Id;

interface PaymentRepository
{
    public function store(Id $correlationId, Id $paymentId): void;

    public function findByCorrelationId(Id $correlationId): Id;
}
