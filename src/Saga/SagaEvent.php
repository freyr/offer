<?php

declare(strict_types=1);

namespace Freyr\Offer\Saga;

use Freyr\Identity\Id;

interface SagaEvent
{
    public Id $messageId { get; }
    public Id $correlationId { get; }
    public ?Id $causationId { get; }
}
