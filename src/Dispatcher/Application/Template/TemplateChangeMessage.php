<?php

declare(strict_types=1);

namespace Freyr\Offer\Dispatcher\Application\Template;

interface TemplateChangeMessage
{
    public string $id { get; }
    public string $content { get; }
}