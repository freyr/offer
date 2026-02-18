<?php

declare(strict_types=1);

namespace Freyr\Offer\Dispatcher\DomainModel;

interface TemplateReadModelRepository
{
    public function getBy(string $templateId): string;
}
