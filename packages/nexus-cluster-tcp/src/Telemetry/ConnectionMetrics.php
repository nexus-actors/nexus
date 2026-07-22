<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Telemetry;

use Monadial\Nexus\Observability\Metric\Counter;
use Monadial\Nexus\Observability\Metric\Histogram;
use Monadial\Nexus\Observability\Metric\Meter;

/**
 * Connection/frame/delivery instruments, created eagerly at wiring time and
 * injected as a plain instance (spec §3.5). This class is the single home of
 * the documented metric names — website/docs/packages/cluster-tcp.md.
 */
final readonly class ConnectionMetrics
{
    public Counter $framesSent;
    public Counter $framesReceived;
    public Counter $framesBuffered;
    public Counter $framesDropped;
    public Counter $framesDecodeFailed;
    public Counter $framesHandlerFailed;
    public Counter $controlSendFailed;
    public Counter $handshakeRejected;
    public Counter $socketWriteFailed;
    public Counter $messagesSent;
    public Counter $messagesReceived;
    public Counter $messagesLocalShortCircuit;
    public Counter $messagesUnroutable;
    public Counter $sendBufferDropped;
    public Histogram $bytesSent;
    public Histogram $bytesReceived;

    public function __construct(Meter $meter)
    {
        $this->framesSent = $meter->counter(
            'nexus.cluster.frames.sent',
            '{frame}',
            'Cluster frames admitted to a peer link',
        );
        $this->framesReceived = $meter->counter(
            'nexus.cluster.frames.received',
            '{frame}',
            'Cluster frames received from remote peers',
        );
        $this->framesBuffered = $meter->counter(
            'nexus.cluster.frames.buffered',
            '{frame}',
            'Cluster frames buffered while a peer reconnects',
        );
        $this->framesDropped = $meter->counter(
            'nexus.cluster.frames.dropped',
            '{frame}',
            'Cluster frames dropped without delivery',
        );
        $this->framesDecodeFailed = $meter->counter(
            'nexus.cluster.frames.decode_failed',
            '{frame}',
            'Cluster frames that failed payload decoding',
        );
        $this->framesHandlerFailed = $meter->counter(
            'nexus.cluster.frames.handler_failed',
            '{frame}',
            'Cluster frames whose handler threw',
        );
        $this->controlSendFailed = $meter->counter(
            'nexus.cluster.control_send.failed',
            '{send}',
            'Control frame sends that failed',
        );
        $this->handshakeRejected = $meter->counter(
            'nexus.cluster.handshake.rejected',
            '{handshake}',
            'Handshakes rejected during admission',
        );
        $this->socketWriteFailed = $meter->counter(
            'nexus.cluster.socket_write.failed',
            '{write}',
            'Socket writes that failed after admission',
        );
        $this->messagesSent = $meter->counter(
            'nexus.cluster.messages.sent',
            '{message}',
            'User messages sent to remote peers',
        );
        $this->messagesReceived = $meter->counter(
            'nexus.cluster.messages.received',
            '{message}',
            'User messages received from remote peers',
        );
        $this->messagesLocalShortCircuit = $meter->counter(
            'nexus.cluster.messages.local_shortcircuit',
            '{message}',
            'Messages delivered locally without the wire',
        );
        $this->messagesUnroutable = $meter->counter(
            'nexus.cluster.messages.unroutable',
            '{message}',
            'Inbound messages with no local target',
        );
        $this->sendBufferDropped = $meter->counter(
            'nexus.cluster.send_buffer.dropped',
            '{message}',
            'Sends dropped for lack of a routable endpoint',
        );
        $this->bytesSent = $meter->histogram('nexus.cluster.bytes.sent', 'By', 'Encoded payload bytes sent');
        $this->bytesReceived = $meter->histogram('nexus.cluster.bytes.received', 'By', 'Payload bytes received');
    }
}
