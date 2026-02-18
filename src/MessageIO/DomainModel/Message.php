<?php

declare(strict_types=1);

namespace Freyr\Offer\MessageIO\DomainModel;

class Message
{
    public function __construct(
        public string $id,
        public string $message,
        public Format $format,
        public Protocol $type,
        public Sender $sender,
        public Receiver $receiver,
    ) {
    }
}
