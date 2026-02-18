# Choreography Saga: Example Flows

This document walks through the scenarios implemented in `tests/Saga/ChoreographySagaTest.php`. Each shows how events
chain through independent handlers with no central coordinator.

## Core Concepts

### Every event carries three IDs

```
messageId:     unique ID for this event (UUID v7)
correlationId: the business process ID (orderId) — same across all events in the saga
causationId:   the messageId of the event that triggered this one (null at the origin)
```

This follows Greg Young's three-ID model: correlationId groups the conversation, causationId traces direct
cause-and-effect.

### Each service owns its own state

No business data travels between bounded contexts via events. Each service remembers what it did, keyed by the
correlationId. When a compensation event arrives, the service looks up its own records.

## The Bounded Contexts

Three contexts participate in the saga:

| Context     | Responsibility                   | Handlers                                                                                              |
|-------------|----------------------------------|-------------------------------------------------------------------------------------------------------|
| **Order**   | Initiates and concludes the saga | PlaceOrderHandler, ConfirmOrderHandler, RejectOrderOnPaymentFailureHandler, RejectOrderOnRefundHandler |
| **Payment** | Charges and refunds payments     | ChargePaymentHandler, RefundPaymentHandler                                                            |
| **Stock**   | Reserves inventory               | ReserveStockHandler                                                                                   |

Each handler follows the same pattern:

```
Receive event → Do local work → Publish new event (copying correlationId, setting causationId)
```

No handler calls another handler directly. They communicate solely through events.

---

## Flow 1: Happy Path

Everything succeeds. The order is confirmed.

```
PlaceOrder (command)
    │
    ▼
PlaceOrderHandler (Order)
    │  Publishes OrderPlaced [correlationId=orderId, causationId=null]
    ▼
ChargePaymentHandler (Payment)
    │  Charges card, stores paymentId in its own repository
    │  Publishes PaymentCharged [causationId=OrderPlaced.messageId]
    ▼
ReserveStockHandler (Stock)
    │  Reserves items
    │  Publishes StockReserved [causationId=PaymentCharged.messageId]
    ▼
ConfirmOrderHandler (Order)
    │  Publishes OrderConfirmed [causationId=StockReserved.messageId]
    ▼
OrderConfirmed (terminal event)
```

### The causation chain

```
OrderPlaced     (messageId=AAA, causationId=null)
PaymentCharged  (messageId=BBB, causationId=AAA)
StockReserved   (messageId=CCC, causationId=BBB)
OrderConfirmed  (messageId=DDD, causationId=CCC)
```

All four events share the same `correlationId` (the orderId). Following the causation chain backwards from
`OrderConfirmed` reconstructs the exact processing order: DDD→CCC→BBB→AAA.

### What each event carries

Each event carries only its own context's data — nothing from upstream:

| Event          | Context-specific data |
|----------------|-----------------------|
| OrderPlaced    | _(none)_              |
| PaymentCharged | paymentId             |
| StockReserved  | reservationId         |
| OrderConfirmed | _(none)_              |

---

## Flow 2: Payment Failure

The payment is declined. The order is rejected immediately — no compensation needed because nothing was charged.

```
PlaceOrder (command)
    │
    ▼
PlaceOrderHandler (Order)
    │  Publishes OrderPlaced
    ▼
ChargePaymentHandler (Payment)
    │  Card declined
    │  Publishes PaymentFailed [reason: "insufficient funds"]
    ▼
RejectOrderOnPaymentFailureHandler (Order)
    │  Publishes OrderRejected [reason: "payment failed"]
    ▼
OrderRejected (terminal event)
```

Three events. No compensation needed — nothing was charged, so nothing needs reversing.

---

## Flow 3: Stock Failure with Compensation

The payment succeeds, but stock reservation fails. The payment must be refunded before the order can be rejected.

```
PlaceOrder (command)
    │
    ▼
PlaceOrderHandler (Order)
    │  Publishes OrderPlaced
    ▼
ChargePaymentHandler (Payment)
    │  Charges card, stores paymentId in PaymentRepository keyed by orderId
    │  Publishes PaymentCharged
    ▼
ReserveStockHandler (Stock)
    │  Out of stock
    │  Publishes StockReservationFailed [reason: "out of stock"]
    │  ⚠ Stock knows NOTHING about Payment — no paymentId here
    ▼
RefundPaymentHandler (Payment)
    │  Receives StockReservationFailed
    │  Looks up PaymentRepository by correlationId (orderId)
    │  Finds the paymentId it charged earlier
    │  Issues refund
    │  Publishes PaymentRefunded [paymentId from own state]
    ▼
RejectOrderOnRefundHandler (Order)
    │  Publishes OrderRejected [reason: "stock unavailable, payment refunded"]
    ▼
OrderRejected (terminal event)
```

### How the refund handler knows what to refund

This is the key design point. `StockReservationFailed` carries only:

- `messageId` — its own unique ID
- `correlationId` — the orderId
- `causationId` — PaymentCharged's messageId
- `reason` — "out of stock"

It carries **no** `paymentId`. Stock knows nothing about payments.

`RefundPaymentHandler` finds the payment through its **own state**:

```php
$paymentId = $this->paymentRepository->findByCorrelationId($event->correlationId);
```

When `ChargePaymentHandler` charged the card earlier, it stored the payment keyed by orderId. When the refund handler
needs to reverse it, it looks up that same key. Each bounded context remembers what it did.

This is the correct DDD approach: no cross-context data coupling. The correlationId is the only shared concept.

---

## How Handlers Stay Decoupled

Each handler knows only about:

1. The **event type** it receives (its input).
2. The **event type** it publishes (its output).
3. Its **own bounded context's state** (if it needs to remember something).

| Handler                            | Receives               | Publishes                          | Own state         |
|------------------------------------|------------------------|------------------------------------|-------------------|
| PlaceOrderHandler                  | PlaceOrder (command)   | OrderPlaced                        | —                 |
| ChargePaymentHandler               | OrderPlaced            | PaymentCharged / PaymentFailed     | Stores paymentId  |
| ReserveStockHandler                | PaymentCharged         | StockReserved / StockReservationFailed | —             |
| ConfirmOrderHandler                | StockReserved          | OrderConfirmed                     | —                 |
| RefundPaymentHandler               | StockReservationFailed | PaymentRefunded                    | Reads paymentId   |
| RejectOrderOnPaymentFailureHandler | PaymentFailed          | OrderRejected                      | —                 |
| RejectOrderOnRefundHandler         | PaymentRefunded        | OrderRejected                      | —                 |

The Payment context does not know that Order exists. The Stock context does not know that Payment will refund on
failure. **Stock does not even know that payments exist** — it dispatches a failure event with a correlation ID, and
whoever is listening handles the consequences.

---

## Testing Without Infrastructure

The test wires all handlers together using an in-memory event bus — a simple array of listeners. Handlers that need
state share an in-memory PaymentRepository:

```php
$paymentRepo = $this->createPaymentRepository();

$this->on(OrderPlaced::class, new ChargePaymentHandler($this->bus(), $paymentRepo));
$this->on(PaymentCharged::class, new ReserveStockHandler($this->bus(), shouldSucceed: false));
$this->on(StockReservationFailed::class, new RefundPaymentHandler($this->bus(), $paymentRepo));
$this->on(PaymentRefunded::class, new RejectOrderOnRefundHandler($this->bus()));
```

When a handler dispatches an event, the bus synchronously invokes the next handler. This lets you test the full saga
flow — including compensation chains — without a message broker, database, or any external dependency.

To simulate failure, handlers accept a `shouldSucceed` flag:

```php
new ReserveStockHandler($this->bus(), shouldSucceed: false)
```

### What the tests verify

| Test                              | Asserts                                                                        |
|-----------------------------------|--------------------------------------------------------------------------------|
| `testHappyPath`                   | OrderConfirmed is terminal; all 4 events share the same correlationId          |
| `testPaymentFailure`              | OrderRejected with reason; 3 events total                                      |
| `testStockFailureWithCompensation`| 5 events; refund paymentId matches original charge paymentId                   |
| `testCausationChain`              | Each event's causationId equals the previous event's messageId                 |
| `testRefundUsesOwnStateNotUpstreamData` | StockReservationFailed has no paymentId property; refund still finds correct payment |
