<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Membership;

/**
 * @psalm-api
 *
 * Executes the outbound effects a membership transition produces. The
 * MembershipActor delegates every {@see MembershipEffect} it receives from a
 * transition to this seam, keeping the actor free of transport wiring.
 *
 * C1.6e supplies the real implementation: it resolves each HandshakeResponse /
 * SendGossip target path-prefix to a live TCP endpoint and performs the send
 * via the cluster transport / OutboundSink. Tests use a recording double.
 */
interface MembershipEffectInterpreter
{
    public function interpret(MembershipEffect $effect): void;
}
