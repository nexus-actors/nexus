<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Response;

use Iterator;
use Override;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * @psalm-api
 *
 * PSR-7 StreamInterface backed by an Iterator. Each read() pulls one yielded
 * chunk. Designed for server adapters that want to flush per chunk
 * (Swoole's Response::write loop). Non-seekable; read-only.
 */
final class IteratorStream implements StreamInterface
{
    private bool $eof = false;

    public function __construct(private readonly Iterator $iterator) {}

    #[Override]
    public function close(): void
    {
        // No underlying resource to release.
    }

    #[Override]
    public function detach()
    {
        return null;
    }

    #[Override]
    public function eof(): bool
    {
        return $this->eof;
    }

    #[Override]
    public function getContents(): string
    {
        $out = '';

        while (!$this->eof) {
            $out .= $this->read(8192);
        }

        return $out;
    }

    #[Override]
    public function getMetadata(?string $key = null)
    {
        return $key === null
            ? []
            : null;
    }

    #[Override]
    public function getSize(): ?int
    {
        return null;
    }

    #[Override]
    public function isReadable(): bool
    {
        return true;
    }

    #[Override]
    public function isSeekable(): bool
    {
        return false;
    }

    #[Override]
    public function isWritable(): bool
    {
        return false;
    }

    #[Override]
    public function read(int $length): string
    {
        if ($this->eof) {
            return '';
        }

        if (!$this->iterator->valid()) {
            $this->eof = true;

            return '';
        }

        $chunk = (string) $this->iterator->current();
        $this->iterator->next();

        if (!$this->iterator->valid()) {
            $this->eof = true;
        }

        return $chunk;
    }

    #[Override]
    public function rewind(): void
    {
        throw new RuntimeException('IteratorStream is not seekable');
    }

    #[Override]
    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        throw new RuntimeException('IteratorStream is not seekable');
    }

    #[Override]
    public function tell(): int
    {
        throw new RuntimeException('IteratorStream is not seekable');
    }

    #[Override]
    public function write(string $string): int
    {
        throw new RuntimeException('IteratorStream is read-only');
    }

    #[Override]
    public function __toString(): string
    {
        return $this->getContents();
    }
}
