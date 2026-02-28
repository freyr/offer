# Freyr Offer

PHP 8.4 offer generation and message dispatching system. DDD with three strict bounded contexts.

## Critical Rules

- **OFR001**: Three bounded contexts — Generator, Dispatcher, MessageIO. No cross-context imports.
- **OFR002**: PHP 8.4 property hooks used for `TemplateChangeMessage` interface — see `src/Dispatcher/Application/Template/`.
- **OFR003**: Handlers use `__invoke(Message $message)` pattern.
- **OFR004**: PHPStan max level with bleeding edge + all strict rules.

## Commands

```bash
docker compose run --rm php vendor/bin/phpunit                    # All tests
docker compose run --rm php vendor/bin/phpunit --filter name      # Single test
docker compose run --rm php vendor/bin/phpstan --memory-limit=-1  # Static analysis
docker compose run --rm php vendor/bin/ecs check --fix            # Code style
docker compose run --rm php vendor/bin/composer-normalize          # Normalize composer.json
```

## Architecture

Three DDD bounded contexts, each with `DomainModel/`, `Application/`, `Infrastructure/` layers:

- **Generator** — Prepares offers from adverts. Core aggregate + `PrepareOfferFromAdvert` command.
- **Dispatcher** — Distributes offers, manages templates. Property hooks for `TemplateChangeMessage`.
- **MessageIO** — Message formatting (Message, Sender, Receiver, Protocol, Format).

Cross-context communication via events (e.g., `OfferForAdvertWasPrepared`). CQRS with read models in Infrastructure.

## Dependencies

- PHP 8.4+ (property hooks), `freyr/identity`, PHPUnit 12
- ECS: PSR-12 + Array + Clean Code + Doctrine Annotations

## Boundaries

**NEVER:**
- Import classes across bounded contexts
- Skip property hooks for template change messages

**ASK FIRST:**
- New bounded contexts
- Changes to cross-context event contracts
- New Composer dependencies
