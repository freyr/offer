<?php

namespace Freyr\Offer\Offer\Generator\Application;

use Freyr\Offer\Offer\Generator\DomainModel\PrepareOfferFromAdvert;

class PrepareOfferFromAdvertHandler
{

    public function __invoke(PrepareOfferFromAdvert $command): void
    {
        $advert = $this->advertRepository->getById($command->advertId);

        $offer = $this->offerGenerator->prepareOfferFromAdvert($advert, $command);
        $this->offerRepository->save($offer);
        $this->bus->dispatch(new OfferForAdvertWasPrepared());
    }
}