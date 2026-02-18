<?php

declare(strict_types=1);

namespace Freyr\Offer\Dispatcher\Application\Template;

class TemplateRemovedMessage implements TemplateChangeMessage
{
    public function __construct(
        public string $id,
        public string $content
    ) {
    }
}
