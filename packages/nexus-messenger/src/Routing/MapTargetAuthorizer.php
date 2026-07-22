<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Routing;

use Monadial\Nexus\Messenger\Stamp\ProducerIdentityStamp;
use Override;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;

use function in_array;

/**
 * Static allowlist TargetAuthorizer: maps a producer identity to the exact set of
 * actor-path targets it may reach. Fails closed — an envelope with no
 * ProducerIdentityStamp, an identity absent from the map, or a target not in that
 * identity's list is denied. No wildcards; targets match by exact path.
 *
 * The producer identity is read from the envelope's ProducerIdentityStamp; see that
 * stamp's trust-boundary note — across mutually untrusted producers the identity must
 * be established at a trusted boundary, not merely self-asserted by the producer.
 *
 * @psalm-api
 */
final readonly class MapTargetAuthorizer implements TargetAuthorizer
{
    private LoggerInterface $logger;

    /**
     * @param array<string, list<string>> $acl producer identity → allowed target actor-paths
     */
    public function __construct(private array $acl, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();
    }

    #[Override]
    public function authorize(string $targetPath, Envelope $envelope): bool
    {
        $stamp = $envelope->last(ProducerIdentityStamp::class);

        if (!$stamp instanceof ProducerIdentityStamp) {
            $this->logger->warning('Messenger route denied: no producer identity on envelope', [
                'target' => $targetPath,
            ]);

            return false;
        }

        $allowed = $this->acl[$stamp->identity] ?? [];

        if (!in_array($targetPath, $allowed, true)) {
            $this->logger->warning('Messenger route denied: producer not authorized for target', [
                'identity' => $stamp->identity,
                'target' => $targetPath,
            ]);

            return false;
        }

        return true;
    }
}
