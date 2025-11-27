<?php

declare(strict_types=1);

namespace Freyr\Offer\Dispatcher\Infrastructure;

use Freyr\Offer\Dispatcher\Application\Template\TemplateCreatedMessage;
use Freyr\Offer\Dispatcher\Application\Template\TemplateRemovedMessage;
use Freyr\Offer\Dispatcher\Application\Template\TemplateUpdateMessage;
use Freyr\Offer\Dispatcher\DomainModel\TemplateRepository;
use Redis;

class TemplateReadModelRepository implements TemplateRepository
{
    public function __construct(private Redis $redis) {}
    public function getBy(string $templateId): string
    {
        return $this->redis->get($templateId);
    }

    public function update(TemplateUpdateMessage $message): void
    {
        $this->redis->set($message->id, $message->content);
    }

    public function insert(TemplateCreatedMessage $message): void
    {
        $this->redis->set($message->id, $message->content);
    }

    public function remove(TemplateRemovedMessage $message): void
    {
        $this->redis->del($message->id);
    }
}