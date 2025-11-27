<?php

declare(strict_types=1);

namespace Freyr\Offer\Dispatcher\Application\Template;

#[AsMessage]
class TemplateCreatedMessage implements TemplateChangeMessage
{
    public function __construct(
        public string $id,
        public string $content
    ) {
    }
}