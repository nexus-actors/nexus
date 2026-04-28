<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Tests\Unit\Directive\Helpers;

use Monadial\Nexus\Core\Actor\ActorSystem;
use Monadial\Nexus\Http\DefaultRequestCtx;
use Monadial\Nexus\Http\Marshalling\MarshallerRegistry;
use Monadial\Nexus\Runtime\Step\StepRuntime;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\NullLogger;

final class CtxFactory
{
    public static function with(string $method, string $uri, ?string $body = null): DefaultRequestCtx
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest($method, $uri);

        if ($body !== null) {
            $request = $request->withBody($factory->createStream($body));
        }

        return new DefaultRequestCtx(
            request: $request,
            params: [],
            system: ActorSystem::create('test-ctx', new StepRuntime()),
            registry: MarshallerRegistry::withDefaults(),
            logger: new NullLogger(),
        );
    }
}
