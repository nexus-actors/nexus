<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Marshalling;

use CuyZ\Valinor\MapperBuilder;
use RuntimeException;

use function array_shift;
use function count;
use function explode;

final class MarshallerRegistry
{
    private const int CACHE_LIMIT = 64;

    /** @var array<string, Marshaller> */
    private array $byMediaType = [];

    private ?Marshaller $default = null;

    /** @var array<string, Marshaller> */
    private array $cache = [];

    public static function withDefaults(): self
    {
        return (new self())->register(
            new JsonValinorMarshaller((new MapperBuilder())->mapper()),
        );
    }

    public function register(Marshaller $marshaller): self
    {
        $key = (string) $marshaller->mediaType();
        $this->byMediaType[$key] = $marshaller;
        $this->default ??= $marshaller;
        $this->cache = [];

        return $this;
    }

    public function default(): Marshaller
    {
        if ($this->default === null) {
            throw new RuntimeException('no marshaller registered');
        }

        return $this->default;
    }

    public function byMediaType(MediaType $type): Marshaller
    {
        $key = (string) $type;

        if (isset($this->byMediaType[$key])) {
            return $this->byMediaType[$key];
        }

        foreach ($this->byMediaType as $registered) {
            if ($registered->mediaType()->matches($type)) {
                return $registered;
            }
        }

        throw new RuntimeException("no marshaller for {$key}");
    }

    public function negotiate(string $acceptHeader): Marshaller
    {
        if (isset($this->cache[$acceptHeader])) {
            return $this->cache[$acceptHeader];
        }

        $best = $this->default();
        $bestQ = -1.0;

        foreach ($this->parseAccept($acceptHeader) as [$mt, $q]) {
            foreach ($this->byMediaType as $candidate) {
                if (! $mt->matches($candidate->mediaType())) {
                    continue;
                }

                if ($q > $bestQ) {
                    $best = $candidate;
                    $bestQ = $q;
                }
            }
        }

        if (count($this->cache) >= self::CACHE_LIMIT) {
            array_shift($this->cache);
        }

        $this->cache[$acceptHeader] = $best;

        return $best;
    }

    /** @return iterable<array{0: MediaType, 1: float}> */
    private function parseAccept(string $header): iterable
    {
        if ($header === '') {
            yield [MediaType::parse('*/*'), 1.0];

            return;
        }

        foreach (explode(',', $header) as $entry) {
            $mt = MediaType::parse($entry);
            $q = isset($mt->params['q'])
                ? (float) $mt->params['q']
                : 1.0;

            yield [$mt, $q];
        }
    }
}
