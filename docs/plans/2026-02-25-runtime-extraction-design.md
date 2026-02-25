# Runtime Extraction Design

Date: 2026-02-25
Topic: Extract runtime and async components from `nexus-core` into standalone `nexus-runtime`
Status: Approved

## Goal

Make futures and runtime contracts usable without the actor system by moving runtime/async abstractions out of `nexus-core` into a standalone `nexus-runtime` package, with a hard namespace break and public-facing docs updates.

## Constraints

- New package: `packages/nexus-runtime` with namespace `Monadial\\Nexus\\Runtime\\`.
- Hard break migration (no compatibility aliases in `core`).
- Public consumers are in scope (docs/examples/meta package updates included).
- Update deptrac to enforce new dependency direction.

## Architecture

- Create `packages/nexus-runtime` (`nexus-actors/runtime`) with PSR-4 root `Monadial\\Nexus\\Runtime\\`.
- Move:
  - `Monadial\\Nexus\\Core\\Runtime\\Runtime` -> `Monadial\\Nexus\\Runtime\\Runtime\\Runtime`
  - `Monadial\\Nexus\\Core\\Async\\Future` -> `Monadial\\Nexus\\Runtime\\Async\\Future`
  - `Monadial\\Nexus\\Core\\Async\\FutureSlot` -> `Monadial\\Nexus\\Runtime\\Async\\FutureSlot`
  - `Monadial\\Nexus\\Core\\Async\\LazyFutureSlot` -> `Monadial\\Nexus\\Runtime\\Async\\LazyFutureSlot`
- Remove old `core` async/runtime classes to enforce the hard break.

## Components and Packaging

- New package files:
  - `packages/nexus-runtime/composer.json`
  - `packages/nexus-runtime/src/Runtime/Runtime.php`
  - `packages/nexus-runtime/src/Async/Future.php`
  - `packages/nexus-runtime/src/Async/FutureSlot.php`
  - `packages/nexus-runtime/src/Async/LazyFutureSlot.php`
  - `packages/nexus-runtime/README.md`
- Update imports and dependencies in:
  - `nexus-core`
  - `nexus-runtime-fiber`
  - `nexus-runtime-step`
  - `nexus-runtime-swoole`
  - `nexus-app`
  - `nexus-cluster`
  - affected tests
- Update `packages/nexus/composer.json` meta-package to require `nexus-actors/runtime`.

## Data Flow

- `Runtime::createFutureSlot()` remains the runtime boundary and now returns `Monadial\\Nexus\\Runtime\\Async\\FutureSlot`.
- `ActorRef::ask()` in core uses runtime to create a slot and returns `Monadial\\Nexus\\Runtime\\Async\\Future`.
- Concrete runtime packages continue to implement suspension/resume details in their own `*FutureSlot` implementations.
- `Future::map()` and `Future::flatMap()` remain implemented via `LazyFutureSlot`, preserving standalone async composition without actor system dependencies.

## Error Handling and Compatibility

- Hard break behavior is expected: references to old core async/runtime namespaces fail fast.
- Composer constraints are updated so packages using runtime abstractions must require `nexus-actors/runtime`.
- Deptrac rules are updated to enforce:
  - `core` can depend on `runtime`
  - `runtime` cannot depend on `core`
  - concrete runtime implementations can depend on both where required
- Migration docs provide direct old->new namespace mappings and upgrade checklist.

## Documentation

- Add standalone runtime usage docs (futures and runtime contract usage without actors) in website docs.
- Add docs navigation/sidebar links for the new runtime package.
- Update homepage with a dedicated feature block that highlights standalone runtime and futures.
- Update package readmes where runtime imports appear.

## Testing and Verification

- Run/adjust tests in:
  - `packages/nexus-runtime`
  - `packages/nexus-core`
  - `packages/nexus-runtime-fiber`
  - `packages/nexus-runtime-step`
  - `packages/nexus-runtime-swoole` (environment permitting)
  - `packages/nexus-app`
  - `packages/nexus-cluster`
- Validate composer dependency wiring across packages.
- Run deptrac to verify architecture boundaries.
- Build/check docs site and ensure runtime docs are linked and rendered.
- Final grep validation: no remaining `Monadial\\Nexus\\Core\\Async\\` or `Monadial\\Nexus\\Core\\Runtime\\Runtime` references outside migration/changelog docs.

## Out of Scope

- Backward compatibility shims in `nexus-core`.
- Additional runtime abstraction redesign beyond extraction and namespace move.
