# Saga Pattern: Choreography

## The Problem: Distributed Transactions

In a monolith, a single database transaction guarantees atomicity — either everything succeeds or everything rolls back.
When you split a system into bounded contexts (or microservices), each with its own data store, you lose that guarantee.
A business process that spans multiple contexts cannot use a single transaction.

The naive solution — distributed two-phase commit (2PC) — is brittle, slow, and tightly couples every participant. It
requires all services to be available simultaneously and introduces a single coordinator as a point of failure.

Sagas offer an alternative.

## What Is a Saga?

A **saga** is a sequence of local transactions, each confined to a single bounded context. If a step fails, the saga
executes **compensating transactions** to undo the work of preceding steps. There is no global rollback — instead, the
system moves forward through corrective actions.

The term originates from a 1987 paper by Hector Garcia-Molina and Kenneth Salem, originally describing long-lived
database transactions. It was later adopted by the distributed systems community for cross-service coordination.

### Key Properties

- **No global ACID transaction** — each step is a local transaction with local consistency guarantees.
- **Eventual consistency** — the system is temporarily inconsistent between steps but converges to a consistent state.
- **Compensations, not rollbacks** — you cannot "undo" a sent email or a charged payment. You issue a new action (
  refund, cancellation notice) that semantically reverses the effect.
- **ACD, not ACID** — sagas provide Atomicity (all or compensated), Consistency (local), Durability (local) but not
  Isolation. Intermediate states are visible to other processes.

## Two Styles: Orchestration vs Choreography

There are two fundamentally different ways to coordinate a saga's steps.

### Orchestration

A central **orchestrator** (sometimes called a process manager) directs the saga. It tells each participant what to do
and when, listens for responses, and decides the next step or compensation.

```
        ┌──────────────┐
        │  Orchestrator │
        └──────┬───────┘
               │
    ┌──────────┼──────────┐
    │          │          │
    ▼          ▼          ▼
 Service A  Service B  Service C
```

The orchestrator holds the entire process definition in one place. It is a command-and-control model.

**Advantages:**

- The full process is visible in a single file — easy to understand, debug, and modify.
- Adding a new step means changing one place.
- Easier to implement timeouts, retries, and complex branching.

**Disadvantages:**

- The orchestrator becomes a coupling point — it must know about every participant.
- Risk of becoming a "god object" that accumulates business logic from all contexts.
- Single point of failure (though this can be mitigated with persistence).

### Choreography

There is **no central coordinator**. Each bounded context reacts to events published by others and publishes its own
events in response. The overall process emerges from the interaction of independent, autonomous participants.

```
 Service A ──event──▶ Service B ──event──▶ Service C
     ▲                                        │
     └────────────────event────────────────────┘
```

Each service knows only about the events it listens to and the events it publishes. No service has a view of the
complete process.

**Advantages:**

- Maximum decoupling — services can be developed, deployed, and scaled independently.
- No single point of failure in the coordination layer.
- Adding a new listener to an existing event requires no changes to the publisher.
- Natural fit for event-driven architectures.

**Disadvantages:**

- The overall process is **invisible** — there is no single file, class, or diagram you can read to understand the full
  saga. You must trace through multiple handlers across multiple contexts.
- Harder to reason about failure modes, especially cascading compensations.
- Adding a new step in the middle of the chain may require multiple services to change.
- Difficult to implement timeouts (who is responsible for detecting that a step never completed?).
- Testing the full flow requires wiring up all participants.

## Choreography in Depth

### The Event Chain

In a choreography saga, the process advances through a chain of events:

1. A command triggers the first service.
2. That service performs its local transaction and publishes an event (a fact about what happened).
3. Another service listens to that event, performs its own local transaction, and publishes a new event.
4. The chain continues until a terminal state is reached.

Each link in the chain follows the same pattern:

```
[Listen to event] → [Perform local work] → [Publish new event]
```

The handler never calls another service directly. It only publishes a fact about what it did. Who reacts to that fact is
not its concern.

### Events as Facts, Not Commands

This is a critical distinction. In choreography, events represent **things that have happened** (past tense), not
instructions to do something (imperative).

| Type    | Example          | Semantics                                                  |
|---------|------------------|------------------------------------------------------------|
| Command | `ChargePayment`  | "I want you to do this" — directed at a specific recipient |
| Event   | `PaymentCharged` | "This happened" — broadcast to anyone who cares            |

A command implies coupling: the sender must know who will handle it. An event implies decoupling: the publisher does not
know (or care) who listens.

In practice, a handler receives an event from another context and may internally issue a command to its own domain, then
publish a new event. The boundary between "external event in" and "internal command" is the bounded context boundary.

### Compensation

When a step fails, the saga must undo the effects of previous steps. But "undo" is a misleading term — you cannot
un-charge a credit card or un-send an email. Instead, you issue a **compensating action**: a new forward action that
semantically reverses the effect.

| Original Action         | Compensating Action     |
|-------------------------|-------------------------|
| Charge payment          | Refund payment          |
| Reserve stock           | Release stock           |
| Send confirmation email | Send cancellation email |
| Create invoice          | Void invoice            |

Compensating actions are themselves local transactions that can fail. This creates a recursive problem: what compensates
the compensation?

### The Compensation Pyramid Problem

Consider a saga with three steps:

```
Step 1 (success) → Step 2 (success) → Step 3 (FAILS)
```

Step 3 fails, so we compensate Step 2. But what if the compensation for Step 2 also fails?

```
Step 1 (success) → Step 2 (success) → Step 3 (FAILS)
                   Compensate Step 2 (FAILS)
                   ???
```

Choreography has no built-in answer to this. Common strategies:

1. **Retry with backoff** — compensating actions are retried until they succeed (requires idempotency).
2. **Dead-letter queue** — failed compensations are sent to a queue for manual intervention.
3. **Human escalation** — an alert is raised and a human resolves the inconsistency.
4. **Accept temporary inconsistency** — the system self-heals through periodic reconciliation jobs.

This is the fundamental limitation of choreography sagas and the strongest argument for orchestration in complex
domains.

### Fat Events vs Thin Events

A design decision that significantly affects coupling:

**Thin events** carry only an identifier (e.g., `orderId`). The receiving handler must query the originating context to
get the data it needs. This creates runtime coupling — the handler cannot function if the other service is down.

**Fat events** carry all the data the handler needs. The receiving handler is fully autonomous — it never queries
another context. This creates data coupling — if the event schema changes, consumers must adapt.

For choreography sagas, fat events are strongly preferred. The entire point of choreography is autonomous, decoupled
participants. Requiring cross-context queries at runtime defeats that purpose.

### Idempotency

In any asynchronous event-driven system, events can be delivered more than once (at-least-once delivery). A handler must
be **idempotent** — processing the same event twice must produce the same result as processing it once.

Strategies:

- **Check-then-act** — before performing work, check whether it has already been done (e.g., "is this payment already
  charged?").
- **Idempotency keys** — store a unique event ID and skip processing if already seen.
- **Natural idempotency** — some operations are inherently idempotent (e.g., setting a status to "confirmed" is the same
  whether done once or twice).

### Correlation

Every event in a saga must be traceable to the business process it belongs to. A **correlation ID** (typically the ID of
the initiating entity, such as `orderId`) is carried on every event. This allows:

- Handlers to load the correct aggregate.
- Logging and monitoring tools to trace the full saga.
- Dead-letter processing to associate failed events with their business context.

## When to Use Choreography

Choreography works well when:

- The number of participants is small (2-4 contexts).
- The process is linear or has simple branching.
- Services are owned by different teams who want maximum autonomy.
- The system is already event-driven.
- You can accept that the full process is implicit.

Choreography becomes painful when:

- The number of participants grows beyond 4-5.
- The process has complex branching, loops, or conditional paths.
- Timeouts and deadlines are critical.
- You need to answer "what is the current state of this saga?" at any moment.
- Compensation chains are deep or can themselves fail.

## When to Use Orchestration

Orchestration works well when:

- The process is complex with many conditional branches.
- Visibility and debuggability of the full process are priorities.
- You need saga-level timeouts and retry policies.
- A single team owns the process definition.
- You want to answer "where is this saga right now?" easily.

## Further Reading

- Garcia-Molina, H. & Salem, K. (1987). "Sagas" — the original paper.
- Hohpe, G. & Woolf, B. (2003). *Enterprise Integration Patterns* — foundational patterns for messaging.
- Richardson, C. (2018). *Microservices Patterns* — Chapter 4 covers sagas in depth with both orchestration and
  choreography examples.
- Kleppmann, M. (2017). *Designing Data-Intensive Applications* — Chapter 9 on consistency and consensus.
- Vernon, V. (2013). *Implementing Domain-Driven Design* — bounded contexts, events, and eventual consistency.
