# Choreography Saga Pattern — E-Commerce Order Example

**Date:** 2026-02-16
**Status:** Ready for implementation
**Tags:** saga, choreography, event-driven, DDD

## What We're Building

A simple, workshop-style example of the **Saga pattern using choreography** (not orchestration). Three bounded contexts — Order, Payment, and Stock — coordinate a multi-step business process purely through domain events. No central coordinator exists; each service reacts independently to events it cares about and publishes its own.

The example covers both the **happy path** and **two failure paths** with full compensating actions.

## Why Choreography (Not Orchestration)

| Aspect | Choreography | Orchestration |
|--------|-------------|---------------|
| Coupling | Each service only knows its own events | Orchestrator knows all services |
| Single point of failure | None | The orchestrator |
| Scalability | Services evolve independently | Orchestrator must change for any flow change |
| Traceability | Harder to follow the full process | Easy — one place shows the entire flow |
| Complexity | Grows with number of participants | Contained in the orchestrator |

Choreography is chosen here to demonstrate **maximum decoupling** — the key trade-off being that the overall process is implicit (spread across handlers) rather than explicit (in one place).

## Bounded Contexts

### 1. Order (`Saga/Order/`)
- **Owns:** Order lifecycle (placed → confirmed / rejected)
- **Publishes:** `OrderPlaced`, `OrderConfirmed`, `OrderRejected`
- **Listens to:** `StockReserved`, `PaymentFailed`, `PaymentRefunded`

### 2. Payment (`Saga/Payment/`)
- **Owns:** Payment charging and refunding
- **Publishes:** `PaymentCharged`, `PaymentFailed`, `PaymentRefunded`
- **Listens to:** `OrderPlaced`, `StockReservationFailed`

### 3. Stock (`Saga/Stock/`)
- **Owns:** Stock reservation and release
- **Publishes:** `StockReserved`, `StockReservationFailed`
- **Listens to:** `PaymentCharged`

## Event Flows

### Happy Path

```
Order BC                    Payment BC                 Stock BC
─────────                   ──────────                 ────────
PlaceOrderHandler
  → OrderPlaced
                            ChargePaymentHandler
                            (reacts to OrderPlaced)
                              → PaymentCharged
                                                       ReserveStockHandler
                                                       (reacts to PaymentCharged)
                                                         → StockReserved
ConfirmOrderHandler
(reacts to StockReserved)
  → OrderConfirmed
```

### Failure: Payment Fails

```
Order BC                    Payment BC                 Stock BC
─────────                   ──────────                 ────────
PlaceOrderHandler
  → OrderPlaced
                            ChargePaymentHandler
                            (reacts to OrderPlaced)
                            Payment declined
                              → PaymentFailed
RejectOrderHandler
(reacts to PaymentFailed)
  → OrderRejected
```

Stock was never reserved, so no stock compensation needed.

### Failure: Stock Reservation Fails

```
Order BC                    Payment BC                 Stock BC
─────────                   ──────────                 ────────
PlaceOrderHandler
  → OrderPlaced
                            ChargePaymentHandler
                              → PaymentCharged
                                                       ReserveStockHandler
                                                       (reacts to PaymentCharged)
                                                       No stock available
                                                         → StockReservationFailed
                            RefundPaymentHandler
                            (reacts to StockReservationFailed)
                              → PaymentRefunded
RejectOrderHandler
(reacts to PaymentRefunded)
  → OrderRejected
```

Payment was already charged, so it must be **compensated** (refunded) before the order is rejected.

## Directory Structure

```
src/Saga/
├── Order/
│   ├── DomainModel/
│   │   └── PlaceOrder.php              # Command
│   └── Application/
│       ├── PlaceOrderHandler.php       # Publishes OrderPlaced
│       ├── ConfirmOrderHandler.php     # Reacts to StockReserved → OrderConfirmed
│       ├── RejectOrderHandler.php      # Reacts to PaymentFailed|PaymentRefunded → OrderRejected
│       ├── OrderPlaced.php             # Event
│       ├── OrderConfirmed.php          # Event
│       └── OrderRejected.php           # Event
├── Payment/
│   ├── DomainModel/
│   │   └── (empty — no commands from outside)
│   └── Application/
│       ├── ChargePaymentHandler.php    # Reacts to OrderPlaced → PaymentCharged|PaymentFailed
│       ├── RefundPaymentHandler.php    # Reacts to StockReservationFailed → PaymentRefunded
│       ├── PaymentCharged.php          # Event
│       ├── PaymentFailed.php           # Event
│       └── PaymentRefunded.php         # Event
└── Stock/
    ├── DomainModel/
    │   └── (empty — no commands from outside)
    └── Application/
        ├── ReserveStockHandler.php     # Reacts to PaymentCharged → StockReserved|StockReservationFailed
        ├── StockReserved.php           # Event
        └── StockReservationFailed.php  # Event
```

## Key Decisions

1. **Classic e-commerce domain** — universally understood, no domain knowledge needed to read the example.
2. **3 services** (Order, Payment, Stock) — enough to show the choreography triangle without unnecessary complexity.
3. **Full compensation logic** — both failure paths demonstrate how each service independently undoes its own work.
4. **Repo conventions** — uses `#[AsMessageHandler]`, `#[AsMessage]`, readonly classes, `__invoke` pattern, PHP 8.4 property hooks where appropriate.
5. **Payment before Stock** — the chain is Order → Payment → Stock (not Order → Stock → Payment) so that the most interesting compensation case (refund after stock failure) is demonstrated.

## Key Insight: Choreography's Hidden Cost

Each service is beautifully decoupled, but the **overall saga is invisible** — there is no single file you can read to understand the full order process. You must trace through multiple handlers across multiple bounded contexts. This is the fundamental trade-off of choreography vs orchestration, and it's exactly what this example should make viscerally clear.

## Open Questions

None — ready for implementation.
