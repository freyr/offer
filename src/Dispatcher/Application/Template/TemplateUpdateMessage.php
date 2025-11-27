<?php

declare(strict_types=1);

namespace Freyr\Offer\Dispatcher\Application\Template;

class TemplateUpdateMessage implements TemplateChangeMessage
{
    public function __construct(
        public string $id,
        public string $content
    ) {
    }
}