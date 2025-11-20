<?php

namespace Freyr\Offer\Dispatcher\Application;

use Freyr\Offer\Dispatcher\DomainModel\DispatcherStrategy;
use Freyr\Offer\Dispatcher\DomainModel\TemplateRepository;

class SendOfferToOwnerHandler
{

    public function __construct(
        private TemplateRepository $templateRepository,
        private DispatcherStrategy $dispatcherStrategy,
    )
    {

    }

    public function __invoke(OfferForAdvertWasPrepared $message): void
    {
        $template = $this->templateRepository->getBy($message->templateId);
        $offerContent = $message->offerContent;
        $messageContent = $this->templateRenderer->render($template, $offerContent);
        $recipient = new Recipient(
            $message->ownerId,
            $message->targetPlatformId,
            $message->targetPlatform,
        );

        $response = $this->dispatcherStrategy->sent($messageContent, $recipient);
        $this->bus->dispatch(new OfferWasSentToOwner(paylod: $response->toArray()));
    }

}