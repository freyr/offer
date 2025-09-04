<?php

namespace Freyr\Offer\Offer\Generator\Application;

class CheckForResponsesHandler
{

    public function __invoke(CheckForResponses $command): void
    {


        $adverts = $this->advertRepository->getByStatus(AdvertStatus::PUBLISHED);
        foreach ($adverts as $advert) {
            if ($advert->hasNoResponses()) {
                $this->bus->dispatch(new AnswerForOfferWasNotReceived());
            }
        }


    }
}