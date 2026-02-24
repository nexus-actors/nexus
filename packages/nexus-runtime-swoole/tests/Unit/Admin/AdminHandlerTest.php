<?php

declare(strict_types=1);

namespace Monadial\Nexus\Runtime\Swoole\Tests\Unit\Admin;

use Monadial\Nexus\Runtime\Swoole\Admin\AdminHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminHandler::class)]
final class AdminHandlerTest extends TestCase
{
    #[Test]
    public function dispatch_returns_success_structure(): void
    {
        $handler = new AdminHandler();
        $result = $handler->dispatch('get_version_info');

        self::assertArrayHasKey('code', $result);
        self::assertArrayHasKey('data', $result);
        self::assertSame(0, $result['code']);
    }

    #[Test]
    public function unknown_command_returns_error(): void
    {
        $handler = new AdminHandler();
        $result = $handler->dispatch('nonexistent_command');

        self::assertSame(4004, $result['code']);
        self::assertIsString($result['data']);
        self::assertStringContainsString('nonexistent_command', $result['data']);
    }

    #[Test]
    public function get_version_info_returns_versions(): void
    {
        $handler = new AdminHandler();
        $result = $handler->dispatch('get_version_info');

        /** @var array<string, mixed> $data */
        $data = $result['data'];

        self::assertSame(PHP_VERSION, $data['php']);
        self::assertArrayHasKey('swoole', $data);
        self::assertArrayHasKey('os', $data);
        self::assertArrayHasKey('extensions', $data);
        self::assertIsArray($data['extensions']);
    }

    #[Test]
    public function get_server_cpu_usage_returns_percentage_data(): void
    {
        $handler = new AdminHandler();
        $result = $handler->dispatch('get_server_cpu_usage');

        /** @var array<string, mixed> $data */
        $data = $result['data'];

        self::assertArrayHasKey('cpu_count', $data);
        self::assertArrayHasKey('system', $data);
        self::assertArrayHasKey('total', $data);
        self::assertArrayHasKey('user', $data);
        self::assertIsFloat($data['user']);
        self::assertIsFloat($data['system']);
        self::assertIsFloat($data['total']);
        self::assertIsInt($data['cpu_count']);
        self::assertGreaterThanOrEqual(1, $data['cpu_count']);
    }

    #[Test]
    public function get_server_memory_usage_returns_memory_data(): void
    {
        $handler = new AdminHandler();
        $result = $handler->dispatch('get_server_memory_usage');

        /** @var array<string, mixed> $data */
        $data = $result['data'];

        self::assertArrayHasKey('memory_get_usage', $data);
        self::assertArrayHasKey('memory_get_usage_real', $data);
        self::assertArrayHasKey('memory_get_peak_usage', $data);
        self::assertArrayHasKey('memory_get_peak_usage_real', $data);
        self::assertIsInt($data['memory_get_usage']);
    }

    #[Test]
    public function server_stats_returns_stats(): void
    {
        $handler = new AdminHandler();
        $result = $handler->dispatch('server_stats');

        /** @var array<string, mixed> $data */
        $data = $result['data'];

        self::assertArrayHasKey('pid', $data);
        self::assertArrayHasKey('uptime_seconds', $data);
        self::assertArrayHasKey('coroutine', $data);
        self::assertIsArray($data['coroutine']);
        self::assertIsInt($data['pid']);
    }

    #[Test]
    public function get_worker_info_returns_worker_data(): void
    {
        $handler = new AdminHandler();
        $result = $handler->dispatch('get_worker_info');

        /** @var array<string, mixed> $data */
        $data = $result['data'];

        self::assertArrayHasKey('pid', $data);
        self::assertArrayHasKey('memory_usage', $data);
        self::assertArrayHasKey('coroutine_num', $data);
        self::assertArrayHasKey('timer_num', $data);
        self::assertIsInt($data['pid']);
    }

    #[Test]
    public function get_coroutine_list_returns_coroutines_with_stack_traces(): void
    {
        $handler = new AdminHandler();
        $result = $handler->dispatch('get_coroutine_list');

        /** @var array<string, mixed> $data */
        $data = $result['data'];

        self::assertArrayHasKey('count', $data);
        self::assertArrayHasKey('coroutines', $data);
        self::assertIsArray($data['coroutines']);
        self::assertIsInt($data['count']);

        if ($data['count'] > 0) {
            /** @var array<string, mixed> $coroutine */
            $coroutine = $data['coroutines'][0];
            self::assertArrayHasKey('cid', $coroutine);
            self::assertArrayHasKey('elapsed_ms', $coroutine);
            self::assertArrayHasKey('stack_trace', $coroutine);
            self::assertArrayHasKey('stack_usage', $coroutine);
            self::assertIsArray($coroutine['stack_trace']);
        }
    }

    #[Test]
    public function get_timer_list_returns_array(): void
    {
        $handler = new AdminHandler();
        $result = $handler->dispatch('get_timer_list');

        /** @var array<string, mixed> $data */
        $data = $result['data'];

        self::assertArrayHasKey('count', $data);
        self::assertArrayHasKey('timers', $data);
        self::assertIsArray($data['timers']);
    }

    #[Test]
    public function gc_status_returns_gc_data(): void
    {
        $handler = new AdminHandler();
        $result = $handler->dispatch('gc_status');

        /** @var array<string, mixed> $data */
        $data = $result['data'];

        self::assertArrayHasKey('runs', $data);
        self::assertArrayHasKey('collected', $data);
    }

    #[Test]
    public function get_included_files_returns_files(): void
    {
        $handler = new AdminHandler();
        $result = $handler->dispatch('get_included_files');

        /** @var array<string, mixed> $data */
        $data = $result['data'];

        self::assertArrayHasKey('count', $data);
        self::assertArrayHasKey('files', $data);
        self::assertIsArray($data['files']);
        self::assertGreaterThan(0, $data['count']);
    }

    #[Test]
    public function get_loaded_extensions_returns_extensions(): void
    {
        $handler = new AdminHandler();
        $result = $handler->dispatch('get_loaded_extensions');

        /** @var array<string, mixed> $data */
        $data = $result['data'];

        self::assertArrayHasKey('count', $data);
        self::assertArrayHasKey('extensions', $data);
        self::assertIsArray($data['extensions']);
        self::assertContains('swoole', $data['extensions']);
    }

    #[Test]
    public function get_declared_classes_returns_classes(): void
    {
        $handler = new AdminHandler();
        $result = $handler->dispatch('get_declared_classes');

        /** @var array<string, mixed> $data */
        $data = $result['data'];

        self::assertArrayHasKey('count', $data);
        self::assertArrayHasKey('classes', $data);
        self::assertIsArray($data['classes']);
        self::assertGreaterThan(0, $data['count']);
    }

    #[Test]
    public function multi_executes_multiple_commands(): void
    {
        $handler = new AdminHandler();
        $result = $handler->dispatch('multi', ['commands' => ['get_version_info', 'gc_status']]);

        /** @var list<array{code: int, data: mixed}> $data */
        $data = $result['data'];

        self::assertSame(0, $result['code']);
        self::assertCount(2, $data);
        self::assertSame(0, $data[0]['code']);
        self::assertSame(0, $data[1]['code']);
    }

    #[Test]
    public function multi_with_unknown_command_includes_error(): void
    {
        $handler = new AdminHandler();
        $result = $handler->dispatch('multi', ['commands' => ['get_version_info', 'nonexistent']]);

        /** @var list<array{code: int, data: mixed}> $data */
        $data = $result['data'];

        self::assertSame(0, $result['code']);
        self::assertCount(2, $data);
        self::assertSame(0, $data[0]['code']);
        self::assertSame(4004, $data[1]['code']);
    }

    #[Test]
    public function multi_without_commands_returns_error(): void
    {
        $handler = new AdminHandler();
        $result = $handler->dispatch('multi', []);

        self::assertSame(4004, $result['code']);
    }
}
