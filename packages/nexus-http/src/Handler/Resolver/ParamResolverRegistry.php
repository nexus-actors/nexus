<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Handler\Resolver;

use Monadial\Nexus\Http\Handler\Resolver\Exception\UnresolvableParameterException;
use ReflectionParameter;

/**
 * @psalm-api
 *
 * Holds the ordered list of ParamResolvers consulted at compile time. First
 * non-null wins. Throws UnresolvableParameterException if no resolver claims
 * the parameter.
 *
 * Immutable: with() / withOverride() return a new registry. with() appends
 * (built-in resolvers tried first, in registration order). withOverride()
 * prepends (user-supplied resolver wins over built-ins of the same shape).
 */
final readonly class ParamResolverRegistry
{
    /** @param list<ParamResolver> $resolvers */
    public function __construct(private array $resolvers = [])
    {
    }

    public function with(ParamResolver $resolver): self
    {
        return new self([...$this->resolvers, $resolver]);
    }

    public function withOverride(ParamResolver $resolver): self
    {
        return new self([$resolver, ...$this->resolvers]);
    }

    public function compile(ReflectionParameter $param, CompileContext $ctx): ParamMetadata
    {
        foreach ($this->resolvers as $resolver) {
            $metadata = $resolver->compile($param, $ctx);

            if ($metadata !== null) {
                return $metadata;
            }
        }

        throw UnresolvableParameterException::forParameter($param, $ctx);
    }
}
