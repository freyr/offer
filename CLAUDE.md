# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a PHP 8.4 project for the Freyr Offer system, which handles offer generation and message dispatching. The project requires PHP 8.4+ and uses modern PHP features including property hooks (new in PHP 8.4).

## Development Environment

All PHP/Composer commands MUST be run through Docker Compose:

```bash
# Run any composer command
docker compose run --rm php composer [command]

# Run any PHP command
docker compose run --rm php php [command]

# Open a shell in the PHP container
docker compose run --rm php sh
# Or use: make shell
```

**Important**: Always use `run --rm` to avoid problems with non-running containers.

## Common Commands

### Testing
```bash
# Run all tests
docker compose run --rm php vendor/bin/phpunit

# Run tests with testdox output
make test

# Run a single test file
docker compose run --rm php vendor/bin/phpunit tests/path/to/TestFile.php

# Run a specific test method
docker compose run --rm php vendor/bin/phpunit --filter testMethodName
```

### Code Quality
```bash
# Run PHPStan (static analysis at max level)
docker compose run --rm php vendor/bin/phpstan --memory-limit=-1
# Or: docker compose run --rm php composer phpstan

# Run ECS (coding standards check and fix)
docker compose run --rm php vendor/bin/ecs check --fix
# Or: docker compose run --rm php composer ecs

# Normalise composer.json
docker compose run --rm php vendor/bin/composer-normalize
```

### Installing Dependencies
```bash
docker compose run --rm php composer install
docker compose run --rm php composer require [package]
docker compose run --rm php composer require --dev [package]
```

## Architecture

The codebase follows Domain-Driven Design principles with clear bounded contexts:

### Bounded Contexts

1. **Generator** - Handles offer preparation from adverts
   - `DomainModel/`: Domain logic and commands (`PrepareOfferFromAdvert`, `Aggregate`)
   - `Application/`: Handlers and events (`PrepareOfferFromAdvertHandler`, `OfferForAdvertWasPrepared`)

2. **Dispatcher** - Manages offer distribution and template handling
   - `DomainModel/`: Domain interfaces (`TemplateRepository`, `DispatcherStrategy`)
   - `Application/`: Handlers and template events (`SendOfferToOwnerHandler`, `TemplateEventListener`)
   - `Application/Template/`: Template change messages with property hooks (`TemplateChangeMessage` interface, `TemplateCreatedMessage`, `TemplateUpdateMessage`, `TemplateRemovedMessage`)
   - `Infrastructure/`: Read model repositories (`TemplateReadModelRepository`)

3. **MessageIO** - Handles message formatting and I/O operations
   - `DomainModel/`: Message domain concepts (`Message`, `Sender`, `Receiver`, `Protocol`, `Format`)
   - `Application/`: Message handlers (`MessageWriterHandler`)

### Key Patterns

- **CQRS**: Separation of write operations (domain model) and read operations (infrastructure read models)
- **Event-Driven**: Events like `OfferForAdvertWasPrepared` connect bounded contexts
- **Message Handlers**: Classes with `__invoke(Message $message)` methods handle commands/events
- **Property Hooks (PHP 8.4)**: The `TemplateChangeMessage` interface uses property hooks to define required getters:
  ```php
  interface TemplateChangeMessage {
      public string $id { get; }
      public string $content { get; }
  }
  ```

### Directory Structure Convention

Each bounded context follows this structure:
- `DomainModel/` - Domain entities, value objects, interfaces, commands
- `Application/` - Application services, handlers, events
- `Infrastructure/` - Persistence, external services, read models

## Code Standards

### PHPStan Configuration
- Level: max
- All strict rules enabled
- Bleeding edge features enabled (`.phpstan.neon`)

### ECS Configuration
- PSR-12 compliant
- Additional rulesets: ARRAY, CLEAN_CODE, DOCTRINE_ANNOTATIONS
- Runs in parallel mode

### Import Style
Do NOT use grouped imports with braces. Import each class separately:
```php
// ✓ Correct
use Foo\Bar\ClassA;
use Foo\Bar\ClassB;

// ✗ Wrong
use Foo\Bar\{ClassA, ClassB};
```

### Database
Primary keys in MySQL should be stored as binary UUID v7 by default.

### Language
Use British English grammar and spelling rules in all code and documentation.

## Project Dependencies

- PHP 8.4+
- Extensions: `ext-json`, `ext-redis`
- Framework: Depends on `freyr/identity` package
- Testing: PHPUnit 12
