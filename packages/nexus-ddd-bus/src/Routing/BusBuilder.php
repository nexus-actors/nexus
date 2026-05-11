<?php

declare(strict_types=1);

namespace Monadial\Nexus\Ddd\Bus\Routing;

use Monadial\Nexus\Ddd\Bus\Attribute\Authorize;
use Monadial\Nexus\Ddd\Bus\Attribute\Idempotent;
use Monadial\Nexus\Ddd\Bus\Attribute\Validate;
use Monadial\Nexus\Ddd\Bus\Exception\MissingAuthorizationDeciderException;
use Monadial\Nexus\Ddd\Bus\Exception\MissingValidatorException;
use Monadial\Nexus\Ddd\Bus\Middleware\Middleware;
use Monadial\Nexus\Ddd\Bus\Middleware\PipelineStage;
use Monadial\Nexus\Ddd\Bus\Profile\Profile;
use NoDiscard;
use ReflectionClass;
use RuntimeException;
use UnitEnum;

use function array_is_list;
use function array_keys;
use function dirname;
use function file_put_contents;
use function get_debug_type;
use function implode;
use function is_array;
use function is_bool;
use function is_file;
use function is_int;
use function is_object;
use function is_string;
use function ksort;
use function rename;
use function sprintf;
use function str_repeat;
use function tempnam;
use function var_export;

/**
 * @psalm-api
 *
 * Boot orchestrator (H4 + H10 + H1 + H13). Reflects all registered
 * handler classes once, builds the `HandlerAttributeIndex` cache and
 * the `BusBuildResult` payload, and runs the suite of boot validators:
 * missing-validator (`#[Validate]` without registered `Validator`),
 * missing-decider (`#[Authorize]` without registered `AuthorizationDecider`),
 * in-process-same-db (Phase 12a's `InProcessSameDbBootValidator`),
 * composite routing conflict (Phase 11's `Composite::validate`).
 *
 * The builder is `final` and non-readonly because it accumulates
 * registrations during construction. After `build()` it produces an
 * immutable `BusBuildResult` carrying the index, the handler map, and
 * the ordered list of adopter-supplied middleware splices.
 */
final class BusBuilder
{
    /** @var array<class-string, class-string> message-class → handler-class */
    private array $handlers = [];

    /** @var array<class-string, string> connection bindings keyed by class-string */
    private array $bindings = [];

    /** @var list<CustomMiddlewareRegistration> */
    private array $customMiddlewares = [];

    /**
     * @param class-string $messageClass
     * @param class-string $handlerClass
     */
    #[NoDiscard('registerHandler returns the builder — chain or assign')]
    public function registerHandler(string $messageClass, string $handlerClass): self
    {
        $this->handlers[$messageClass] = $handlerClass;

        return $this;
    }

    /** @param class-string $boundClass */
    #[NoDiscard('bindConnection returns the builder — chain or assign')]
    public function bindConnection(string $boundClass, string $connectionName): self
    {
        $this->bindings[$boundClass] = $connectionName;

        return $this;
    }

    /**
     * Splice a custom Middleware into the canonical pipeline. Adopters and
     * downstream packages (e.g. `nexus-ddd-aggregate`'s
     * `OneAggregatePerCommandMiddleware`) ship their own `Middleware` impl
     * and register it here; the canonical `PipelineStage` enum stays
     * locked, callers reference it to declare the insertion point.
     *
     * `$before === null` → append after the last canonical stage (after `SpanClose`).
     * `$before === PipelineStage::X` → insert immediately before the canonical X middleware.
     * Multiple registrations sharing the same `$before` preserve registration order.
     *
     * The actual splice happens inside the downstream pipeline assembler
     * (Phase 13's `Sync*Bus` constructors). `BusBuilder` only accumulates
     * registration records exposed via `BusBuildResult::$customMiddlewares`.
     */
    #[NoDiscard('withMiddleware returns the builder — chain or assign')]
    public function withMiddleware(Middleware $middleware, ?PipelineStage $before = null): self
    {
        $this->customMiddlewares[] = new CustomMiddlewareRegistration($middleware, $before);

        return $this;
    }

    /**
     * @throws MissingValidatorException when a handler is `#[Validate]`-annotated and no validator is registered
     * @throws MissingAuthorizationDeciderException when a handler is `#[Authorize]`-annotated and no decider is registered
     * @throws \Monadial\Nexus\Ddd\Bus\Exception\InProcessConnectionMismatchException when an `#[InProcess]` handler binds to a different connection than its source aggregate
     * @throws \Monadial\Nexus\Ddd\Bus\Exception\DuplicateRoutingException when multiple routing strategies resolve the same message class to different bus names
     *
     * @psalm-suppress UnusedParam — `$profile` is part of the H4-locked signature; Phase 12b's `BusRegistry` consumes it for profile-aware availability checks.
     */
    public function build(Profile $profile, bool $hasValidator, bool $hasDecider, Composite $routing): BusBuildResult
    {
        $entries = [];

        foreach ($this->handlers as $messageClass => $handlerClass) {
            $resolved = $this->reflectHandler($handlerClass);

            if ($resolved->attribute(Validate::class)->isSome() && !$hasValidator) {
                throw MissingValidatorException::for($messageClass);
            }

            if ($resolved->attribute(Authorize::class)->isSome() && !$hasDecider) {
                throw MissingAuthorizationDeciderException::for($messageClass);
            }

            $entries[$messageClass] = $resolved;
        }

        new InProcessSameDbBootValidator($this->bindings)->validate($this->handlers);
        $routing->validate(array_keys($this->handlers));

        return new BusBuildResult(
            new HandlerAttributeIndex($entries),
            $this->handlers,
            $this->customMiddlewares,
        );
    }

    /**
     * Run reflection (same path as `build()`) and write the result to
     * disk as opcache-friendly PHP. Adopters run this at deploy time
     * (typically via `bin/console ddd:routes:compile <path>`) so the
     * production boot path can `loadCompiledFrom()` without paying the
     * reflection cost.
     *
     * The output is written atomically: a tmp file in the destination
     * directory + `rename()`.
     *
     * @throws MissingValidatorException
     * @throws MissingAuthorizationDeciderException
     * @throws \Monadial\Nexus\Ddd\Bus\Exception\InProcessConnectionMismatchException
     * @throws \Monadial\Nexus\Ddd\Bus\Exception\DuplicateRoutingException
     * @throws RuntimeException when the snapshot file cannot be written
     */
    public function dumpCompiledTo(
        string $path,
        Profile $profile,
        bool $hasValidator,
        bool $hasDecider,
        Composite $routing,
    ): void {
        $result = $this->build($profile, $hasValidator, $hasDecider, $routing);
        $code = $this->renderSnapshot($result);

        $tmp = tempnam(dirname($path), 'ddd-routes-compile-');

        if ($tmp === false) {
            throw new RuntimeException(sprintf('Could not create temp file in directory of %s', $path));
        }

        if (file_put_contents($tmp, $code) === false) {
            throw new RuntimeException(sprintf('Could not write snapshot to temp file %s', $tmp));
        }

        if (!rename($tmp, $path)) {
            throw new RuntimeException(sprintf('Could not rename %s to %s', $tmp, $path));
        }
    }

    /**
     * Load a precomputed snapshot and return an equivalent
     * `BusBuildResult` WITHOUT running reflection. The snapshot file
     * must have been produced by `dumpCompiledTo()`. Custom middleware
     * registrations are NOT serialized — they are wired at runtime
     * through `withMiddleware()` on the builder before this call (per
     * H13).
     *
     * @throws RuntimeException when the file is missing or does not return a `CompiledBusBootSnapshot`
     */
    public function loadCompiledFrom(string $path): BusBuildResult
    {
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Compiled snapshot not found at %s', $path));
        }

        /** @var mixed $snapshot */
        $snapshot = require $path;

        if (!$snapshot instanceof CompiledBusBootSnapshot) {
            throw new RuntimeException(sprintf(
                'Compiled snapshot file %s did not return a CompiledBusBootSnapshot instance',
                $path,
            ));
        }

        /** @var array<class-string, ResolvedAttributesEntry> $entries */
        $entries = [];

        foreach ($snapshot->entries as $messageClass => $entry) {
            $entries[$messageClass] = $entry->toResolvedAttributesEntry();
        }

        return new BusBuildResult(
            new HandlerAttributeIndex($entries),
            $snapshot->handlerMap,
            $this->customMiddlewares,
        );
    }

    /**
     * @param class-string $handlerClass
     *
     * @psalm-suppress ArgumentTypeCoercion — `$attributes` is keyed by reflected attribute class-strings; the local union narrows to specific literals but is structurally `array<class-string, object>`.
     */
    private function reflectHandler(string $handlerClass): ResolvedAttributesEntry
    {
        $reflection = new ReflectionClass($handlerClass);
        /** @var array<class-string, object> $attributes */
        $attributes = [];

        foreach ($reflection->getAttributes() as $attr) {
            /** @var class-string $name */
            $name = $attr->getName();
            $attributes[$name] = $attr->newInstance();
        }

        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getAttributes() as $attr) {
                /** @var class-string $name */
                $name = $attr->getName();
                $attributes[$name] = $attr->newInstance();
            }
        }

        $authorize = $attributes[Authorize::class] ?? null;
        $authorizeBeforeValidate = $authorize instanceof Authorize && $authorize->before === 'validation';

        $idempotent = $attributes[Idempotent::class] ?? null;
        $idempotencyOptedOut = $idempotent instanceof Idempotent && $idempotent->off;

        return new ResolvedAttributesEntry($handlerClass, $attributes, $authorizeBeforeValidate, $idempotencyOptedOut);
    }

    /**
     * Emit the snapshot as opcache-friendly PHP. Top-level shape:
     *
     *     <?php
     *     declare(strict_types=1);
     *     return new \Monadial\Nexus\Ddd\Bus\Routing\CompiledBusBootSnapshot(
     *         handlerMap: [...],
     *         entries: [...],
     *     );
     *
     * Attribute instances are emitted as `new \FQN(prop: value, ...)`
     * — we walk the public properties of each attribute class
     * (every Phase 8 attribute is `final readonly` with public
     * scalar/array properties, so var_export of each value is safe).
     */
    private function renderSnapshot(BusBuildResult $result): string
    {
        $handlerMap = $this->renderArray($result->handlerMap, 1);

        /** @var array<class-string, CompiledHandlerEntry> $compiled */
        $compiled = [];

        foreach ($result->index->all() as $messageClass => $entry) {
            $compiled[$messageClass] = new CompiledHandlerEntry(
                $entry->handlerClass,
                $entry->attributes,
                $entry->authorizeBeforeValidate,
                $entry->idempotencyOptedOut,
            );
        }

        $entries = $this->renderEntriesMap($compiled, 1);

        return sprintf(
            "<?php\n\ndeclare(strict_types=1);\n\n// Generated by ddd:routes:compile — do not edit by hand.\n// To refresh: bin/console ddd:routes:compile <output-path>.\n\nreturn new \\%s(\n    handlerMap: %s,\n    entries: %s,\n);\n",
            CompiledBusBootSnapshot::class,
            $handlerMap,
            $entries,
        );
    }

    /**
     * @param array<class-string, CompiledHandlerEntry> $entries
     */
    private function renderEntriesMap(array $entries, int $depth): string
    {
        if ($entries === []) {
            return '[]';
        }

        ksort($entries);
        $pad = str_repeat('    ', $depth);
        $innerPad = str_repeat('    ', $depth + 1);
        $lines = [];

        foreach ($entries as $messageClass => $entry) {
            $lines[] = sprintf(
                '%s%s => %s,',
                $innerPad,
                var_export($messageClass, true),
                $this->renderCompiledHandlerEntry($entry, $depth + 1),
            );
        }

        return "[\n" . implode("\n", $lines) . "\n{$pad}]";
    }

    private function renderCompiledHandlerEntry(CompiledHandlerEntry $entry, int $depth): string
    {
        $pad = str_repeat('    ', $depth);
        $innerPad = str_repeat('    ', $depth + 1);
        $attributes = $this->renderAttributesMap($entry->attributes, $depth + 1);

        return sprintf(
            "new \\%s(\n%shandlerClass: %s,\n%sattributes: %s,\n%sauthorizeBeforeValidate: %s,\n%sidempotencyOptedOut: %s,\n%s)",
            CompiledHandlerEntry::class,
            $innerPad,
            var_export($entry->handlerClass, true),
            $innerPad,
            $attributes,
            $innerPad,
            var_export($entry->authorizeBeforeValidate, true),
            $innerPad,
            var_export($entry->idempotencyOptedOut, true),
            $pad,
        );
    }

    /**
     * @param array<class-string, object> $attributes
     */
    private function renderAttributesMap(array $attributes, int $depth): string
    {
        if ($attributes === []) {
            return '[]';
        }

        ksort($attributes);
        $pad = str_repeat('    ', $depth);
        $innerPad = str_repeat('    ', $depth + 1);
        $lines = [];

        foreach ($attributes as $attrClass => $instance) {
            $lines[] = sprintf(
                '%s\\%s::class => %s,',
                $innerPad,
                $attrClass,
                $this->renderAttributeInstance($instance, $depth + 1),
            );
        }

        return "[\n" . implode("\n", $lines) . "\n{$pad}]";
    }

    private function renderAttributeInstance(object $instance, int $depth): string
    {
        $reflection = new ReflectionClass($instance);
        $class = $reflection->getName();
        $properties = $reflection->getProperties();

        if ($properties === []) {
            return sprintf('new \\%s()', $class);
        }

        $pad = str_repeat('    ', $depth);
        $innerPad = str_repeat('    ', $depth + 1);
        $lines = [];

        foreach ($properties as $property) {
            /** @var mixed $value */
            $value = $property->getValue($instance);
            $lines[] = sprintf(
                '%s%s: %s,',
                $innerPad,
                $property->getName(),
                $this->renderValue($value, $depth + 1),
            );
        }

        return sprintf("new \\%s(\n%s\n%s)", $class, implode("\n", $lines), $pad);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function renderArray(array $value, int $depth): string
    {
        if ($value === []) {
            return '[]';
        }

        $pad = str_repeat('    ', $depth);
        $innerPad = str_repeat('    ', $depth + 1);
        $isList = array_is_list($value);

        if (!$isList) {
            // Project rule: arrays with string keys sorted alphabetically.
            ksort($value);
        }

        $lines = [];

        /** @var mixed $entry */
        foreach ($value as $key => $entry) {
            $lines[] = $isList
                ? sprintf('%s%s,', $innerPad, $this->renderValue($entry, $depth + 1))
                : sprintf(
                    '%s%s => %s,',
                    $innerPad,
                    var_export($key, true),
                    $this->renderValue($entry, $depth + 1),
                );
        }

        return "[\n" . implode("\n", $lines) . "\n{$pad}]";
    }

    private function renderValue(mixed $value, int $depth): string
    {
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return var_export($value, true);
        }

        if (is_array($value)) {
            return $this->renderArray($value, $depth);
        }

        if ($value instanceof UnitEnum) {
            return sprintf('\\%s::%s', $value::class, $value->name);
        }

        if (is_object($value)) {
            return $this->renderAttributeInstance($value, $depth);
        }

        throw new RuntimeException(sprintf(
            'Cannot render value of type %s in compiled snapshot',
            get_debug_type($value),
        ));
    }
}
