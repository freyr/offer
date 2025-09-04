<?php

namespace Freyr\Offer\Dispatcher\Application;

class SendOfferToOwnerHandler
{
    public function __invoke(OfferForAdvertWasPrepared $message): void
    {
        $offer = $this->offerRepository->getBy($message->offerId);
        if ($offer->canBeSentToOwner()) {
            // dispatcher
            $response = $this->dispatcherStrategy->sent($offer);

            $this->bus->dispatch(new OfferWasSentToOwner());
            $offer->sentBy($response);
            $this->offerRepository->save($offer);
        }
    }

}