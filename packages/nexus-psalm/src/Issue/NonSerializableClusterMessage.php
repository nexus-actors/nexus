<?php
declare(strict_types=1);

namespace Monadial\Nexus\Psalm\Issue;

use Psalm\CodeLocation;
use Psalm\Issue\PluginIssue;

final class NonSerializableClusterMessage extends PluginIssue
{
    public function __construct(string $className, CodeLocation $codeLocation)
    {
        parent::__construct(
            'Message class "' . $className . '" sent via RemoteActorRef::tell() lacks a #[MessageType] attribute.'
            . ' Cluster messages must be registered in TypeRegistry for cross-worker serialization.',
            $codeLocation,
        );
    }
}
