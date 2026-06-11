<?php

declare(strict_types=1);

namespace Monadial\Nexus\Http\Discovery;

use Monadial\Nexus\Http\Routing\Attribute\Route as RouteAttribute;
use Monadial\Nexus\Http\Routing\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * @psalm-api
 *
 * Finds all classes with #[Route] attributes under a directory. Returns
 * Route value objects ready to add to the collection.
 */
final class RouteDiscoverer
{
    /** @return list<Route> */
    public function discover(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $routes = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $class = $this->classFromFile($file->getPathname());

            if ($class === null || !class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            foreach ($reflection->getAttributes(RouteAttribute::class) as $attr) {
                $routeAttr = $attr->newInstance();
                $routes[] = new Route(
                    $routeAttr->method,
                    $routeAttr->path,
                    $class,
                    $routeAttr->middleware,
                    $routeAttr->name,
                );
            }
        }

        return $routes;
    }

    private function classFromFile(string $path): ?string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $namespace = null;
        $class = null;

        if (preg_match('/^\s*namespace\s+([^;\s]+)\s*;/m', $contents, $m) === 1) {
            $namespace = $m[1];
        }

        if (preg_match('/(?:^|\s)(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $contents, $m) === 1) {
            $class = $m[1];
        }

        if ($class === null) {
            return null;
        }

        return $namespace === null
            ? $class
            : $namespace . '\\' . $class;
    }
}
