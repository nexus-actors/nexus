<?php

declare(strict_types=1);

namespace GrumPHP\Configuration;

use GrumPHP\Configuration\Environment\DotEnvRegistrar;
use GrumPHP\Configuration\Model\EnvConfig;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

class GrumPHPExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $this->loadInternal(
            $this->processConfiguration(
                $this->getConfiguration($configs, $container),
                $configs
            ),
            $container
        );
    }

    public function getConfiguration(array $config, ContainerBuilder $container): ConfigurationInterface
    {
        return new Configuration();
    }

    public function getAlias(): string
    {
        return 'grumphp';
    }

    private function loadInternal(array $config, ContainerBuilder $container): void
    {
        // Register all configuration keys as parameters in the container:
        foreach ($config as $key => $value) {
            $container->setParameter($key, $value);
        }

        // Load environment configuration settings into ENV
        $envConfig = EnvConfig::fromArray((array) ($config['environment'] ?? []));
        DotEnvRegistrar::register($envConfig);
    }
}
