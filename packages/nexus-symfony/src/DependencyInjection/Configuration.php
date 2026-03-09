<?php

declare(strict_types=1);

namespace Monadial\Nexus\Symfony\DependencyInjection;

use Override;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    #[Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('nexus');
        $root = $tree->getRootNode();

        $root
            ->children()
                ->arrayNode('kernel_pool')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_pending')->defaultValue(100)->end()
                        ->integerNode('size')->defaultValue(8)->end()
                    ->end()
                ->end()
                ->scalarNode('name')->defaultValue('app')->end()
                ->integerNode('shutdown_timeout')->defaultValue(30)->end()
            ->end();

        return $tree;
    }
}
