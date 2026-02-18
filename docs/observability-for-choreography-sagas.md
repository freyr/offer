# Observability for Choreography Sagas

## The Problem

In a choreography saga, no central coordinator tracks the process. Each handler sees only the event it receives and the
event it publishes. The overall flow is invisible — you cannot answer "what happened in this saga?" by reading any single
service's logs.

This document describes how we address that invisibility using two complementary mechanisms:

1. **Correlation/causation IDs** in every event — lightweight, embedded in the domain layer
2. **OpenTelemetry** for operational visibility — external, infrastructure-level

## Correlation and Causation IDs

Every event carries three IDs, following Greg Young's model:

```
messageId:     unique ID for this event (UUID v7)
correlationId: copied from the triggering event (groups the business process)
causationId:   set to the triggering event's messageId (direct cause)
```

### What this gives you

- **Correlation stream** — group all events belonging to one saga by correlationId
- **Causation chain** — follow the causationId backwards to reconstruct exact processing order
- **Indexed projections** — an event store with a correlation index lets you replay all events for a given orderId at
  any point in the future

### What this does not give you

- **Journey visualisation** — you know which events exist, but you need to query and assemble them yourself
- **Latency breakdown** — no timing information beyond what you add to events manually
- **Stuck saga detection** — no mechanism to notice that an expected event never arrived
- **Cross-cutting visibility** — no view into HTTP calls, database queries, or external API calls within handlers

These gaps are where OpenTelemetry fits.

## Per-Service State (Not Cross-Context Data)

A common temptation is to propagate business data between bounded contexts through the event payload — for example,
having Stock forward a `paymentId` so that the refund handler can use it. This is a bounded context violation.

The correct model: each service maintains its own state, keyed by the correlation ID.

```
StockReservationFailed carries only: correlationId (orderId) + reason
    │
    ▼
RefundPaymentHandler receives StockReservationFailed
    → looks up its own records: "for orderId X, I charged paymentId Y"
    → issues refund using its own stored state
    → dispatches PaymentRefunded
```

The Payment service **remembers** what it did. It does not need Stock to remind it. Events stay thin: just the
correlation ID, causation ID, and context-specific facts. No service reads another service's data from the event
payload.

This design keeps bounded contexts fully autonomous and events lightweight.

## OpenTelemetry for Saga Observability

OTel instruments each handler as a span. The trace collector (Tempo, Jaeger) reconstructs the full picture externally.

### What OTel provides

- **Distributed trace** across all services in a single view
- **Span duration, status, and custom attributes** per handler
- **TraceQL structural queries** — "find traces where span A exists but span B does not"
- **Derived RED metrics** (Rate, Errors, Duration) from span data
- **Alerting** on slow steps or high error rates via Grafana
- **Cross-cutting visibility** — HTTP calls, database queries, cache lookups, external API calls within handlers

### How context propagates across async boundaries

- For Kafka: trace context is injected into message headers by the producer, extracted by the consumer
- For RabbitMQ: stored in AMQP message properties (`traceparent`, `tracestate`)
- The producer span and consumer span are linked via **span links** (not parent-child), which correctly models the
  async gap

### Domain outcomes as span attributes

OTel spans have two statuses: `OK` and `ERROR`. Saga steps have three outcomes: succeeded, failed, and compensated.
"Compensated" is a domain concept — a refund span has status `OK` (it succeeded technically) but its saga outcome is
compensated (it reversed a previous step).

Express this with custom span attributes:

```php
$span->setAttribute('saga.outcome', 'compensated');
$span->setAttribute('saga.service', 'Payment');
$span->setAttribute('saga.step', 'RefundPaymentHandler');
```

This gives you queryable domain semantics in Tempo without embedding observability in the domain layer.

### Detecting stuck sagas

OTel does not natively know that "a saga should have completed by now." You need deliberate instrumentation:

1. Emit a custom metric (`saga.started`) at the origin event
2. Emit a corresponding metric (`saga.completed`) at the terminal event
3. Alert when `saga.started - saga.completed > threshold` for a given correlationId
4. Or use TraceQL structural queries: find traces containing `OrderPlaced` but missing both `OrderConfirmed` and
   `OrderRejected`

This is achievable but requires explicit setup — it does not come for free.

## Where OTel Falls Short

### 1. Traces are transient

OTel traces are stored with retention limits (days to weeks). Events persisted in an event store or outbox live
indefinitely. If you need to answer "what happened in saga X?" six months from now, the trace is gone.

**Mitigation:** use a correlation-indexed event store. Replay all events for a given orderId to reconstruct the saga.
This is the most natural fit for event-driven systems.

### 2. Async context propagation is fragile

The MassTransit community reported OTel Activity becoming `null` when entering a saga state machine. The OTel
specification has an open discussion about visibility gaps in long-running processes — spans are held locally and lost on
ungraceful shutdown.

For event-driven systems with message brokers, trace propagation requires:

- The broker to support header propagation (Kafka and RabbitMQ do; not all brokers do)
- Every consumer to correctly extract and continue the trace
- No middleware to strip headers along the way

One broken link and the trace splits into disconnected fragments.

### 3. Domain outcomes require convention enforcement

The `saga.outcome` span attribute works, but every service must emit it consistently. There is no compile-time
enforcement. This is a team discipline problem, not a technical one.

## Practical Instrumentation Summary

### In the event payload (domain layer)

- `messageId` — unique ID for this event
- `correlationId` — groups the business process (orderId)
- `causationId` — links to the triggering event's messageId
- Context-specific data only — each event carries facts about its own bounded context

### In the OTel span (infrastructure layer)

- Journey visualisation — which handlers ran, in what order
- Latency analysis — which step is slow
- Stuck saga detection — TraceQL structural queries or custom metrics
- Alerting — span metrics → Prometheus → Grafana

### In per-service state (domain layer)

- Each bounded context stores what it did, keyed by correlationId
- Compensation handlers look up their own records — no upstream data needed

## Sources

### Correlation and Causation IDs

- [Correlation ID and Causation ID in Evented Systems](https://blog.arkency.com/correlation-id-and-causation-id-in-evented-systems/) —
  Arkency blog. Greg Young's three-ID rule explained with practical examples.
- [Correlation and Causation](https://railseventstore.org/docs/v2/correlation_causation/) — Rails Event Store. Concrete
  implementation with indexed correlation/causation streams.

### OpenTelemetry Messaging Conventions

- [Semantic Conventions for Messaging Spans](https://opentelemetry.io/docs/specs/semconv/messaging/messaging-spans/) —
  OTel specification. Defines producer/consumer span types, span links for async correlation, and messaging attributes.
- [Context Propagation](https://opentelemetry.io/docs/concepts/context-propagation/) — OTel documentation. How trace
  context travels across service and process boundaries.
- [OpenTelemetry Context Propagation Explained](https://betterstack.com/community/guides/observability/otel-context-propagation/) —
  Better Stack. Practical guide covering propagation through HTTP, message queues, and async callbacks.

### Challenges with Async Tracing

- [Building an OpenTelemetry Distributed Tracing Solution](https://solace.com/blog/opentelemetry-distributed-tracing-solution/) —
  Solace. Covers the fundamental asymmetry: publishers don't know where events go, creating visibility gaps.
- [Visibility Challenge: Long-Running Processes](https://github.com/open-telemetry/opentelemetry-specification/discussions/4646) —
  OTel specification discussion. Incomplete spans are lost on crash; no mechanism for periodic export of in-progress
  operations.
- [OpenTelemetry Activity Lost in Saga](https://github.com/MassTransit/MassTransit/discussions/5167) — MassTransit.
  Real-world report of trace context becoming null when entering a saga state machine.

### TraceQL and Structural Queries

- [Construct a TraceQL Query](https://grafana.com/docs/tempo/latest/traceql/construct-traceql-queries/) — Grafana Tempo.
  Structural operators (descendant, child, sibling), aggregation functions (count), and experimental not-operators for
  finding traces with missing spans.
- [Trace-Based Alerts](https://grafana.com/docs/grafana/latest/alerting/examples/trace-based-alerts/) — Grafana. How to
  derive span metrics (RED) and create alerting rules for slow operations and error rates.
- [Metrics from Traces](https://grafana.com/docs/tempo/latest/getting-started/metrics-from-traces/) — Grafana Tempo.
  Generating Prometheus-compatible metrics from trace data for dashboards and alerting.

### Saga Pattern

- [Pattern: Saga](https://microservices.io/patterns/data/saga.html) — Chris Richardson. Canonical reference for
  choreography and orchestration variants.
- [Saga Orchestration Using the Outbox Pattern](https://www.infoq.com/articles/saga-orchestration-outbox/) — InfoQ.
  Debezium approach with OpenTracing/Jaeger integration for identifying unfinished saga flows.
