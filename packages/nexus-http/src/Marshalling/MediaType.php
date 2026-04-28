<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Marshalling;

use Override;
use Stringable;

use function array_map;
use function array_pad;
use function array_shift;
use function explode;
use function strtolower;
use function trim;

final readonly class MediaType implements Stringable
{
    /** @param array<string, string> $params */
    public function __construct(public string $type, public string $subtype, public array $params = []) {}

    public static function parse(string $value): self
    {
        $value = trim($value);
        $parts = array_map('trim', explode(';', $value));
        $primary = array_shift($parts);
        [$type, $subtype] = array_pad(explode('/', $primary, 2), 2, '*');

        $params = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
            $params[trim($k)] = trim($v);
        }

        return new self(strtolower($type), strtolower($subtype), $params);
    }

    public function matches(self $other): bool
    {
        if ($this->type !== '*' && $this->type !== $other->type) {
            return false;
        }

        return $this->subtype === '*' || $this->subtype === $other->subtype;
    }

    #[Override]
    public function __toString(): string
    {
        return "{$this->type}/{$this->subtype}";
    }
}
