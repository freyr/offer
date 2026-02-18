<?php

namespace Freyr\Offer\Generator\Application;

class FailOfferHandler
{
    public function __invoke(OfferFail $command): void
    {
        $offer = $this->offerRepository->getById($command->offerId);
        $offer->fail();
        $this->offerRepository->save($offer);
        $this->bus->dispatch(new OfferFail());
    }
}
