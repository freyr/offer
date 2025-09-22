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

            $this->outbox->dispatch(new OfferWasSentToOwner(paylod: $response->toArray()));

            $this->outbox->dispatch(new OfferShouldBeSentAsMessage(paylod: $response->toArray()));
            $this->outbox->dispatch(new OfferShouldBeWasSent(paylod: $response->toArray()));

            $offer->sentBy($response);
            $this->offerRepository->save($offer);
        }
    }

}