// landing/src/components/RuntimeTabs.tsx
import IDEEditor, { type IDEFile } from './IDEEditor';

const files: IDEFile[] = [
  {
    name: 'fiber.php',
    language: 'php',
    code: `use Monadial\\Nexus\\Core\\Actor\\ActorSystem;
use Monadial\\Nexus\\Runtime\\Fiber\\FiberRuntime;

// Cooperative multitasking — zero extensions required.
// Each actor runs in its own PHP Fiber. Fibers suspend
// when waiting for messages and resume on delivery.

$system = ActorSystem::create('app', new FiberRuntime());
$ref = $system->spawn($props, 'orders');
$ref->tell(new PlaceOrder('ORD-1'));
$system->run(); // blocks; fibers cooperate inside`,
  },
  {
    name: 'swoole.php',
    language: 'php',
    code: `use Monadial\\Nexus\\Core\\Actor\\ActorSystem;
use Monadial\\Nexus\\Runtime\\Swoole\\SwooleRuntime;

// Swoole coroutines — true async I/O, multi-process scaling.
// Backed by epoll/kqueue; actors map 1-to-1 to coroutines.
// Thread-safe worker pool available via nexus-worker-pool-swoole.

$system = ActorSystem::create('app', new SwooleRuntime());
$ref = $system->spawn($props, 'orders');
$ref->tell(new PlaceOrder('ORD-1'));
$system->run(); // Swoole event loop drives everything`,
  },
  {
    name: 'step.php',
    language: 'php',
    code: `use Monadial\\Nexus\\Core\\Actor\\ActorSystem;
use Monadial\\Nexus\\Runtime\\Step\\StepRuntime;
use Monadial\\Nexus\\Runtime\\Step\\VirtualClock;

// StepRuntime — deterministic, single-message-at-a-time.
// Virtual clock lets you skip timers without sleeping.
// Ideal for unit tests: no fibers, no event loop, no flakiness.

$clock   = new VirtualClock();
$runtime = new StepRuntime();
$system  = ActorSystem::create('app', $runtime, $clock);
$ref     = $system->spawn($props, 'orders');
$ref->tell(new PlaceOrder('ORD-1'));
$runtime->step(); // process exactly one message`,
  },
];

export default function RuntimeTabs() {
  return (
    <div className="rt-wrap">
      <IDEEditor files={files} defaultIndex={0} />
      <style>{`
        .rt-wrap { width: 100%; }
      `}</style>
    </div>
  );
}
