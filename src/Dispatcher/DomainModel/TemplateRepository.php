<?php

declare(strict_types=1);

namespace Freyr\Offer\Dispatcher\DomainModel;

use Freyr\Offer\Dispatcher\Application\Template\TemplateCreatedMessage;
use Freyr\Offer\Dispatcher\Application\Template\TemplateRemovedMessage;
use Freyr\Offer\Dispatcher\Application\Template\TemplateUpdateMessage;

interface TemplateRepository {
    public function getBy(string $templateId): string;

    public function update(TemplateUpdateMessage $message): void;

    public function insert(TemplateCreatedMessage $message): void;

    public function remove(TemplateRemovedMessage $message): void;
}