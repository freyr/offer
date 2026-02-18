# Fanout Choreography: Parallel Participant Execution

## The Idea

The current implementation is **sequential** — each step triggers the next in a chain:

```
OrderPlaced → ChargePayment → ReserveStock → ConfirmOrder
```

Payment must complete before Stock begins. If Payment takes 2 seconds and Stock takes 1 second, the saga takes at
least 3 seconds.

The **fanout** strategy dispatches `OrderPlaced` to all participants simultaneously. Payment and Stock react in
parallel. When all have responded, the Order context decides: confirm or reject.

```
                    ┌──→ ChargePaymentHandler ──→ PaymentCharged ──┐
OrderPlaced ────────┤                                              ├──→ Order decides
                    └──→ ReserveStockHandler  ──→ StockReserved ──┘
```

Wall-clock time drops from `sum(participants)` to `max(participants)`.

## The Core Principle: Every Service Keeps State

Every bounded context maintains its own state, keyed by the correlationId. This is the same principle from the
sequential model — but in the fanout model it becomes the mechanism that makes compensation work without any
orchestration.

| Context     | State stored                          | Keyed by      |
|-------------|---------------------------------------|---------------|
| **Order**   | Which participants responded, outcome | correlationId |
| **Payment** | paymentId, gateway, charge status     | correlationId |
| **Stock**   | reservationId, warehouse, status      | correlationId |

When a compensation signal arrives, each service looks up its own state. If it did work for that correlationId, it
reverses it. If it did not (because it failed), it does nothing.

No service reads another service's state. No service tells another service what to compensate. Each service is
responsible for its own mess.

## Events

Each service reacts to exactly two events from the Order context:

| Event          | Meaning                         | Who reacts                     |
|----------------|---------------------------------|--------------------------------|
| `OrderPlaced`  | Start your work                 | Payment, Stock (parallel)      |
| `OrderRejected`| The saga failed — compensate    | Payment, Stock (self-check)    |

Additionally, each participant publishes its own result event:

| Event                     | Publisher | Consumed by |
|---------------------------|-----------|-------------|
| `PaymentCharged`          | Payment   | Order       |
| `PaymentFailed`           | Payment   | Order       |
| `StockReserved`           | Stock     | Order       |
| `StockReservationFailed`  | Stock     | Order       |

And each participant publishes a compensation result (for audit/observability):

| Event             | Publisher | Trigger        |
|-------------------|-----------|----------------|
| `PaymentRefunded` | Payment   | `OrderRejected`|
| `StockReleased`   | Stock     | `OrderRejected`|

**No N×N coupling.** Payment does not listen to Stock events. Stock does not listen to Payment events. Both listen only
to Order-level events (`OrderPlaced`, `OrderRejected`). Adding a fourth participant (e.g. Notification) requires no
changes to Payment or Stock.

## Flows

### Happy Path — Both Succeed

```
PlaceOrder (command)
    │
    ▼
PlaceOrderHandler (Order)
    │  Stores OrderProcess: expecting [payment, stock]
    │  Dispatches OrderPlaced
    │
    ├──────────────────────────────────────┐
    │                                      │
    ▼                                      ▼
ChargePaymentHandler (Payment)      ReserveStockHandler (Stock)
    │  Charges card                        │  Reserves items
    │  Stores paymentId in own repo        │  Stores reservationId in own repo
    │  Dispatches PaymentCharged           │  Dispatches StockReserved
    │                                      │
    └────────────────┬─────────────────────┘
                     │
                     ▼
          CollectOutcomeHandler (Order)
              │  First arrival: records outcome, waits
              │  Second arrival: all in, all succeeded
              │
              ▼
          OrderConfirmed (terminal)
```

The Order context records each result against its own state. When all participants have reported and all succeeded, it
dispatches `OrderConfirmed`. Arrival order does not matter.

### Both Fail — No Compensation

```
OrderPlaced (fanout)
    ├──→ ChargePaymentHandler → PaymentFailed
    └──→ ReserveStockHandler  → StockReservationFailed

CollectOutcomeHandler (Order)
    → records payment=failed, stock=failed
    → all in, none succeeded → no compensation needed
    → dispatches OrderRejected

Payment hears OrderRejected → checks own state → no charge recorded → nothing to do
Stock hears OrderRejected   → checks own state → no reservation recorded → nothing to do
```

`OrderRejected` is broadcast to all participants, but each checks its own state and only compensates if it actually
did work. When both failed, both do nothing.

### Payment Succeeds, Stock Fails

```
OrderPlaced (fanout)
    ├──→ ChargePaymentHandler → PaymentCharged   (Payment stores paymentId)
    └──→ ReserveStockHandler  → StockReservationFailed

CollectOutcomeHandler (Order)
    → records both outcomes
    → all in, stock failed → dispatches OrderRejected

Payment hears OrderRejected
    → checks own state: "I charged paymentId Y for orderId X"
    → issues refund
    → dispatches PaymentRefunded

Stock hears OrderRejected
    → checks own state: no reservation for this orderId
    → nothing to do
```

Payment self-compensates. Stock does nothing. No service told Payment to refund — it checked its own state and decided
for itself.

### Stock Succeeds, Payment Fails

The mirror case.

```
OrderPlaced (fanout)
    ├──→ ChargePaymentHandler → PaymentFailed
    └──→ ReserveStockHandler  → StockReserved   (Stock stores reservationId)

CollectOutcomeHandler (Order)
    → records both outcomes
    → all in, payment failed → dispatches OrderRejected

Stock hears OrderRejected
    → checks own state: "I reserved reservationId Z for orderId X"
    → releases reservation
    → dispatches StockReleased

Payment hears OrderRejected
    → checks own state: no charge for this orderId
    → nothing to do
```

## How the Order Context Tracks Outcomes

The Order context applies the same per-service state pattern as everyone else. Its state is a record of which
participants have responded and whether they succeeded, stored by correlationId.

```php
// Order's own state — same pattern as PaymentRepository or StockRepository
final class OrderProcess
{
    /** @var array<string, bool|null> participant → null (pending) | true | false */
    private array $outcomes;

    public function __construct(
        public readonly Id $correlationId,
        array $expectedParticipants, // ['payment', 'stock']
    ) {
        $this->outcomes = array_fill_keys($expectedParticipants, null);
    }

    public function recordOutcome(string $participant, bool $succeeded): void
    {
        $this->outcomes[$participant] = $succeeded;
    }

    public function allResponded(): bool
    {
        return !in_array(null, $this->outcomes, true);
    }

    public function allSucceeded(): bool
    {
        return $this->allResponded()
            && !in_array(false, $this->outcomes, true);
    }
}
```

```php
interface OrderProcessRepository
{
    public function findByCorrelationId(Id $correlationId): OrderProcess;
    public function save(OrderProcess $process): void;
}
```

The handler that collects outcomes:

```php
class CollectOutcomeHandler
{
    public function __invoke(SagaEvent $event): void
    {
        $process = $this->repository->findByCorrelationId($event->correlationId);

        match (true) {
            $event instanceof PaymentCharged          => $process->recordOutcome('payment', true),
            $event instanceof PaymentFailed           => $process->recordOutcome('payment', false),
            $event instanceof StockReserved           => $process->recordOutcome('stock', true),
            $event instanceof StockReservationFailed  => $process->recordOutcome('stock', false),
        };

        $this->repository->save($process);

        if (!$process->allResponded()) {
            return; // still waiting
        }

        if ($process->allSucceeded()) {
            ($this->dispatch)(new OrderConfirmed(...));
        } else {
            ($this->dispatch)(new OrderRejected(...));
        }
    }
}
```

This is not orchestration. Order does not tell anyone what to do. It reacts to events, records what happened in its
own state, and publishes a domain fact: either the order is confirmed or rejected. Every other service decides
independently what that fact means for them.

## How Each Service Self-Compensates

Each participant listens to `OrderRejected` and checks its own state:

```php
// Payment context
class CompensatePaymentHandler
{
    public function __invoke(OrderRejected $event): void
    {
        $paymentId = $this->paymentRepository->findOrNull($event->correlationId);

        if ($paymentId === null) {
            return; // Payment failed for this order — nothing to reverse
        }

        // Refund
        ($this->dispatch)(new PaymentRefunded(...));
    }
}
```

```php
// Stock context
class CompensateStockHandler
{
    public function __invoke(OrderRejected $event): void
    {
        $reservationId = $this->stockRepository->findOrNull($event->correlationId);

        if ($reservationId === null) {
            return; // Stock failed for this order — nothing to reverse
        }

        // Release
        ($this->dispatch)(new StockReleased(...));
    }
}
```

The pattern is identical in every service:

1. Receive `OrderRejected`
2. Look up own state by correlationId
3. If state exists (I succeeded earlier) → compensate
4. If no state (I failed or never started) → do nothing

Adding a fourth participant means writing one new compensation handler following this exact pattern. No existing
services change.

## Why Order Must Wait for All Responses

Order dispatches `OrderRejected` only after **all** participants have reported. This prevents a race condition:

```
Dangerous: Order rejects immediately on first failure

1. OrderPlaced fans out
2. Stock fails fast → StockReservationFailed
3. Order dispatches OrderRejected immediately
4. Payment receives OrderRejected → no state yet → nothing to compensate
5. Payment receives OrderPlaced → charges card → stores state
6. Payment has charged, but it already processed OrderRejected — the compensation signal is gone
```

By waiting for all responses, Order guarantees that every participant has finished its work (or failed) before the
compensation signal arrives. When Payment hears `OrderRejected`, it can trust its own state is complete.

## Event Bus Wiring

### Sequential (current)

```php
$this->on(OrderPlaced::class, new ChargePaymentHandler(...));
$this->on(PaymentCharged::class, new ReserveStockHandler(...));
$this->on(StockReserved::class, new ConfirmOrderHandler(...));
$this->on(StockReservationFailed::class, new RefundPaymentHandler(...));
$this->on(PaymentFailed::class, new RejectOrderOnPaymentFailureHandler(...));
$this->on(PaymentRefunded::class, new RejectOrderOnRefundHandler(...));
```

### Fanout

```php
// Fanout — both react to OrderPlaced
$this->on(OrderPlaced::class, new ChargePaymentHandler(...));
$this->on(OrderPlaced::class, new ReserveStockHandler(...));

// Order collects all outcomes
$this->on(PaymentCharged::class, $collectOutcome);
$this->on(PaymentFailed::class, $collectOutcome);
$this->on(StockReserved::class, $collectOutcome);
$this->on(StockReservationFailed::class, $collectOutcome);

// Each service self-compensates on rejection
$this->on(OrderRejected::class, new CompensatePaymentHandler(...));
$this->on(OrderRejected::class, new CompensateStockHandler(...));
```

No compensation commands from Order. No two-phase state machine. No compensation tracking.

## What Changes in the Code

### New files

| File                                        | Purpose                                        |
|---------------------------------------------|-------------------------------------------------|
| `Order/DomainModel/OrderProcess.php`        | Order's own state — tracks participant outcomes |
| `Order/DomainModel/OrderProcessRepository.php` | Persistence interface for Order's state      |
| `Order/Application/CollectOutcomeHandler.php`| Listens to all results, dispatches terminal event |
| `Stock/DomainModel/StockRepository.php`     | Stock's own state — stores reservationId        |
| `Stock/Application/CompensateStockHandler.php` | Listens to `OrderRejected`, self-compensates |
| `Stock/Application/StockReleased.php`       | Compensation result event                       |

### Modified files

| File                                         | Change                                                        |
|----------------------------------------------|---------------------------------------------------------------|
| `Stock/Application/ReserveStockHandler.php`  | Listens to `OrderPlaced` instead of `PaymentCharged`; stores reservationId in `StockRepository` |
| `Payment/Application/RefundPaymentHandler.php` | Renamed to `CompensatePaymentHandler`; listens to `OrderRejected` instead of `StockReservationFailed`; checks own state before compensating |
| `Payment/DomainModel/PaymentRepository.php`  | Add `findOrNull` method (returns `null` when no charge exists) |

### Removed files

| File                                                     | Reason                                       |
|----------------------------------------------------------|----------------------------------------------|
| `Order/Application/ConfirmOrderHandler.php`              | Absorbed into `CollectOutcomeHandler`         |
| `Order/Application/RejectOrderOnPaymentFailureHandler.php` | Absorbed into `CollectOutcomeHandler`       |
| `Order/Application/RejectOrderOnRefundHandler.php`       | No longer needed — compensations are autonomous |

## Trade-offs vs Sequential Model

### Gains

- **Lower latency** — `max(participants)` instead of `sum(participants)`
- **No coupling between participants** — Payment and Stock know nothing about each other in either direction
- **Uniform pattern** — every service follows the same three-step pattern: react to `OrderPlaced`, keep state,
  react to `OrderRejected`
- **Easy to extend** — adding a participant means one handler for `OrderPlaced` and one for `OrderRejected`

### Costs

- **Wasted work** — if Payment fails, Stock still completes and must reverse. Sequential would have skipped Stock
  entirely
- **Order needs outcome tracking** — a simple state record per correlationId, but the sequential model avoids this
- **Eventual compensation** — `OrderRejected` is the terminal event; compensations happen after. In the sequential
  model, compensations complete before the terminal event
- **Concurrency on Order state** — two results arriving simultaneously can race on the Order process record. Requires
  optimistic locking or serialised consumers per correlationId

### When to use which

**Fanout** when participants are genuinely independent, latency matters, and you accept wasted work on failure.

**Sequential** when there is a data dependency between steps, wasted work is expensive (real money, external API calls),
or simplicity is the priority.
