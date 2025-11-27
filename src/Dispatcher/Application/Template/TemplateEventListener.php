<?php

declare(strict_types=1);

namespace Freyr\Offer\Dispatcher\Application\Template;



use Freyr\Offer\Dispatcher\DomainModel\TemplateRepository;

#[AsMessageHandler]
class TemplateEventListener
{
    public function __construct(
        private TemplateRepository $templateRepository
    )
    {

    }
    public function __invoke(TemplateChangeMessage $message): void
    {
        match (get_class($message)) {
            TemplateUpdateMessage::class => $this->onUpdate($message),
            TemplateCreatedMessage::class => $this->onCreate($message),
            TemplateRemovedMessage::class => $this->onRemoved($message),
            default => throw new \Exception('Unknown message type')
        };
    }

    private function onUpdate(TemplateUpdateMessage $message): void
    {
        $this->templateRepository->update($message);
    }

    private function onCreate(TemplateCreatedMessage $message): void
    {
        $this->templateRepository->insert($message);
    }

    private function onRemoved(TemplateRemovedMessage $message): void
    {
        $this->templateRepository->remove($message);
    }



}