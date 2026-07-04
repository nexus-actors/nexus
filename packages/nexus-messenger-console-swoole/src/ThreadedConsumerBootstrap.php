<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Console\Swoole;

use Monadial\Nexus\Messenger\Console\ConsumerSetup;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * Contract for bootstrapping a threaded messenger consumer.
 *
 * Implement this interface and pass the class-string to
 * {@see ThreadedConsumeCommand}. The pool instantiates it fresh in every
 * worker thread — no cross-thread object sharing occurs.
 *
 * Two methods are called per thread inside the configure closure:
 *  - {@see ConsumerSetup::setup()} — spawn handler actors and return a router
 *  - {@see receiver()} — open a fresh transport connection for this thread
 *
 * Because each thread owns an isolated transport connection, the broker
 * naturally load-balances: N threads become N competing consumers.
 *
 * Usage:
 * ```php
 * final class OrderConsumerBootstrap implements ThreadedConsumerBootstrap
 * {
 *     public function setup(ActorSystem $system): MessageRouter
 *     {
 *         $ref = $system->spawn(Props::fromFactory(fn() => new OrdersActor()), 'orders');
 *
 *         return new MapMessageRouter([OrderPlaced::class => $ref]);
 *     }
 *
 *     public function receiver(): ReceiverInterface
 *     {
 *         // Return a FRESH connection per thread
 *         return new RedisTransport(Redis::connect('redis:6379'));
 *     }
 * }
 *
 * // In bin/console:
 * $app->addCommand(new ThreadedConsumeCommand(OrderConsumerBootstrap::class));
 * ```
 *
 * @psalm-api
 */
interface ThreadedConsumerBootstrap extends ConsumerSetup
{
    /**
     * Build a fresh transport connection for this thread.
     *
     * Called inside each worker thread — the returned object stays local to
     * that thread. Do NOT return the same object that was created on the main
     * thread; create a new connection here so the broker can load-balance
     * across threads independently.
     */
    public function receiver(): ReceiverInterface;
}
