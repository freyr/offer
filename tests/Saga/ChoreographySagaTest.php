<?php

declare(strict_types=1);

namespace Freyr\EventSourcing\Tests\Saga;

use Freyr\Identity\Id;
use Freyr\Offer\Saga\Order\Application\ConfirmOrderHandler;
use Freyr\Offer\Saga\Order\Application\OrderConfirmed;
use Freyr\Offer\Saga\Order\Application\OrderPlaced;
use Freyr\Offer\Saga\Order\Application\OrderRejected;
use Freyr\Offer\Saga\Order\Application\PlaceOrderHandler;
use Freyr\Offer\Saga\Order\Application\RejectOrderOnPaymentFailureHandler;
use Freyr\Offer\Saga\Order\Application\RejectOrderOnRefundHandler;
use Freyr\Offer\Saga\Order\DomainModel\PlaceOrder;
use Freyr\Offer\Saga\Payment\Application\ChargePaymentHandler;
use Freyr\Offer\Saga\Payment\Application\PaymentCharged;
use Freyr\Offer\Saga\Payment\Application\PaymentFailed;
use Freyr\Offer\Saga\Payment\Application\PaymentRefunded;
use Freyr\Offer\Saga\Payment\Application\RefundPaymentHandler;
use Freyr\Offer\Saga\Payment\DomainModel\PaymentRepository;
use Freyr\Offer\Saga\SagaEvent;
use Freyr\Offer\Saga\Stock\Application\ReserveStockHandler;
use Freyr\Offer\Saga\Stock\Application\StockReservationFailed;
use Freyr\Offer\Saga\Stock\Application\StockReserved;
use PHPUnit\Framework\TestCase;

final class ChoreographySagaTest extends TestCase
{
    /** @var list<SagaEvent> */
    private array $events = [];

    /** @var array<class-string, list<callable>> */
    private array $listeners = [];

    private function dispatch(SagaEvent $event): void
    {
        $this->events[] = $event;

        foreach ($this->listeners[$event::class] ?? [] as $handler) {
            $handler($event);
        }
    }

    private function on(string $eventClass, callable $handler): void
    {
        $this->listeners[$eventClass][] = $handler;
    }

    private function bus(): \Closure
    {
        return $this->dispatch(...);
    }

    private function lastEvent(): SagaEvent
    {
        return $this->events[array_key_last($this->events)];
    }

    private function createCommand(): PlaceOrder
    {
        return new PlaceOrder(
            orderId: Id::new(),
            amount: 9999,
            currency: 'GBP',
            productId: Id::new(),
        );
    }

    private function createPaymentRepository(): PaymentRepository
    {
        return new class() implements PaymentRepository {
            /** @var array<string, Id> */
            private array $payments = [];

            public function store(Id $correlationId, Id $paymentId): void
            {
                $this->payments[(string) $correlationId] = $paymentId;
            }

            public function findByCorrelationId(Id $correlationId): Id
            {
                return $this->payments[(string) $correlationId];
            }
        };
    }

    public function testHappyPath(): void
    {
        $paymentRepo = $this->createPaymentRepository();

        $this->on(OrderPlaced::class, new ChargePaymentHandler($this->bus(), $paymentRepo));
        $this->on(PaymentCharged::class, new ReserveStockHandler($this->bus()));
        $this->on(StockReserved::class, new ConfirmOrderHandler($this->bus()));

        $command = $this->createCommand();
        $placeOrderHandler = new PlaceOrderHandler($this->bus());
        $placeOrderHandler($command);

        $terminal = $this->lastEvent();
        self::assertInstanceOf(OrderConfirmed::class, $terminal);
        self::assertCount(4, $this->events);

        // All events share the same correlationId
        foreach ($this->events as $event) {
            self::assertTrue($event->correlationId->sameAs($command->orderId));
        }
    }

    public function testPaymentFailure(): void
    {
        $paymentRepo = $this->createPaymentRepository();

        $this->on(OrderPlaced::class, new ChargePaymentHandler($this->bus(), $paymentRepo, shouldSucceed: false));
        $this->on(PaymentFailed::class, new RejectOrderOnPaymentFailureHandler($this->bus()));

        $command = $this->createCommand();
        $placeOrderHandler = new PlaceOrderHandler($this->bus());
        $placeOrderHandler($command);

        $terminal = $this->lastEvent();
        self::assertInstanceOf(OrderRejected::class, $terminal);
        self::assertSame('payment failed', $terminal->reason);
        self::assertCount(3, $this->events);
    }

    public function testStockFailureWithCompensation(): void
    {
        $paymentRepo = $this->createPaymentRepository();

        $this->on(OrderPlaced::class, new ChargePaymentHandler($this->bus(), $paymentRepo));
        $this->on(PaymentCharged::class, new ReserveStockHandler($this->bus(), shouldSucceed: false));
        $this->on(StockReservationFailed::class, new RefundPaymentHandler($this->bus(), $paymentRepo));
        $this->on(PaymentRefunded::class, new RejectOrderOnRefundHandler($this->bus()));

        $command = $this->createCommand();
        $placeOrderHandler = new PlaceOrderHandler($this->bus());
        $placeOrderHandler($command);

        // Events: OrderPlaced, PaymentCharged, StockReservationFailed, PaymentRefunded, OrderRejected
        self::assertCount(5, $this->events);

        $terminal = $this->lastEvent();
        self::assertInstanceOf(OrderRejected::class, $terminal);
        self::assertSame('stock unavailable, payment refunded', $terminal->reason);

        // Stock never touched paymentId — it dispatched StockReservationFailed with only correlationId
        $stockFailed = $this->events[2];
        self::assertInstanceOf(StockReservationFailed::class, $stockFailed);
        self::assertSame('out of stock', $stockFailed->reason);

        // RefundPaymentHandler found the paymentId via its own repository, not from Stock's event
        $refunded = $this->events[3];
        self::assertInstanceOf(PaymentRefunded::class, $refunded);
        $charged = $this->events[1];
        self::assertInstanceOf(PaymentCharged::class, $charged);
        self::assertTrue($refunded->paymentId->sameAs($charged->paymentId));
    }

    public function testCausationChain(): void
    {
        $paymentRepo = $this->createPaymentRepository();

        $this->on(OrderPlaced::class, new ChargePaymentHandler($this->bus(), $paymentRepo));
        $this->on(PaymentCharged::class, new ReserveStockHandler($this->bus()));
        $this->on(StockReserved::class, new ConfirmOrderHandler($this->bus()));

        $command = $this->createCommand();
        $placeOrderHandler = new PlaceOrderHandler($this->bus());
        $placeOrderHandler($command);

        self::assertCount(4, $this->events);

        // First event has no causation (origin)
        self::assertNull($this->events[0]->causationId);

        // Each subsequent event's causationId equals the previous event's messageId
        for ($i = 1, $count = count($this->events); $i < $count; $i++) {
            self::assertTrue(
                $this->events[$i]->causationId->sameAs($this->events[$i - 1]->messageId),
                sprintf('Event %d causationId should match event %d messageId', $i, $i - 1),
            );
        }

        // Every event has a unique messageId
        $messageIds = array_map(
            static fn (SagaEvent $e): string => (string) $e->messageId,
            $this->events,
        );
        self::assertCount(4, array_unique($messageIds));
    }

    public function testRefundUsesOwnStateNotUpstreamData(): void
    {
        $paymentRepo = $this->createPaymentRepository();

        $this->on(OrderPlaced::class, new ChargePaymentHandler($this->bus(), $paymentRepo));
        $this->on(PaymentCharged::class, new ReserveStockHandler($this->bus(), shouldSucceed: false));
        $this->on(StockReservationFailed::class, new RefundPaymentHandler($this->bus(), $paymentRepo));
        $this->on(PaymentRefunded::class, new RejectOrderOnRefundHandler($this->bus()));

        $command = $this->createCommand();
        $placeOrderHandler = new PlaceOrderHandler($this->bus());
        $placeOrderHandler($command);

        // StockReservationFailed carries no paymentId — only correlationId and reason
        $stockFailed = $this->events[2];
        self::assertInstanceOf(StockReservationFailed::class, $stockFailed);
        self::assertFalse(
            property_exists($stockFailed, 'paymentId'),
            'StockReservationFailed must not carry paymentId — Stock knows nothing about Payment',
        );

        // Yet the refund correctly identified the payment via its own repository
        $refunded = $this->events[3];
        self::assertInstanceOf(PaymentRefunded::class, $refunded);
        self::assertNotNull($refunded->paymentId);

        // The paymentId on the refund matches the one from the original charge
        $charged = $this->events[1];
        self::assertInstanceOf(PaymentCharged::class, $charged);
        self::assertTrue($refunded->paymentId->sameAs($charged->paymentId));
    }
}
