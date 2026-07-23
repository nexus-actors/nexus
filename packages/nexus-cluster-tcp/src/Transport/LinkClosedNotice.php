<?php

declare(strict_types=1);

namespace Monadial\Nexus\Cluster\Tcp\Transport;

/**
 * @psalm-api
 *
 * The transport link an {@see \Monadial\Nexus\Cluster\Tcp\Connection\InboundLinkActor} owns has
 * closed (the remote peer disconnected, or this end detected the disconnect). Posted by the
 * pump that wired the link's `onClose` callback — never sent by user code. Lives in `Transport/`
 * for the same reason {@see LinkFrame} does (produced by transport-layer code; the dependency
 * boundary only allows `Connection/` to depend on `Transport/`, not the reverse). Carries no
 * payload: the actor already knows its own link and (if identified) its own peer address, so it
 * can apply the equivalent of {@see \Monadial\Nexus\Cluster\Tcp\Connection\Message\LinkClosed}
 * bookkeeping (a tell to {@see \Monadial\Nexus\Cluster\Tcp\Connection\ConnectionSupervisor}) itself
 * before stopping.
 */
final readonly class LinkClosedNotice {}
