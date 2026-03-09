<?php

declare(strict_types=1);

namespace Monadial\Nexus\Codegen;

use Composer\Autoload\ClassLoader;
use Monadial\Nexus\Codegen\Analyzer\InterfaceParser;
use Monadial\Nexus\Codegen\Analyzer\ServiceAnalyzer;
use Monadial\Nexus\Codegen\Analyzer\TypeResolver;
use Monadial\Nexus\Codegen\Generator\ActorGenerator;
use Monadial\Nexus\Codegen\Generator\AsyncInterfaceGenerator;
use Monadial\Nexus\Codegen\Generator\MessageGenerator;
use Monadial\Nexus\Codegen\Generator\ProxyGenerator;
use PhpParser\ParserFactory;

final class Actorizer
{
    private readonly ServiceAnalyzer $analyzer;
    private readonly MessageGenerator $messageGenerator;
    private readonly ActorGenerator $actorGenerator;
    private readonly AsyncInterfaceGenerator $asyncInterfaceGenerator;
    private readonly ProxyGenerator $proxyGenerator;

    public function __construct(private readonly string $outputBaseDir, ClassLoader $loader,) {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->analyzer = new ServiceAnalyzer($loader, new InterfaceParser($parser, new TypeResolver()));
        $this->messageGenerator = new MessageGenerator();
        $this->actorGenerator = new ActorGenerator();
        $this->asyncInterfaceGenerator = new AsyncInterfaceGenerator();
        $this->proxyGenerator = new ProxyGenerator();
    }

    public static function fromAutoloader(string $outputBaseDir = 'src'): self
    {
        /** @var ClassLoader $loader */
        $loader = require 'vendor/autoload.php';

        return new self($outputBaseDir, $loader);
    }

    public function actorize(string $sourceFile): void
    {
        $definition = $this->analyzer->analyze($sourceFile);

        $parts = explode('\\', $definition->outputNamespace);
        $relative = implode('/', array_slice($parts, 1));
        $outputDir = $this->outputBaseDir . '/' . $relative;
        $messageDir = $outputDir . '/Message';

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        if (!is_dir($messageDir)) {
            mkdir($messageDir, 0755, true);
        }

        foreach ($definition->methods as $method) {
            file_put_contents(
                $messageDir . '/' . $method->pascalName . '.php',
                $this->messageGenerator->generateInput($definition->outputNamespace, $method),
            );

            $response = $this->messageGenerator->generateResponse($definition->outputNamespace, $method);

            if ($response !== null) {
                file_put_contents($messageDir . '/' . $method->pascalName . 'Response.php', $response);
            }
        }

        file_put_contents(
            $outputDir . '/' . $definition->shortName . 'ServiceActor.php',
            $this->actorGenerator->generate($definition),
        );

        if ($definition->async) {
            file_put_contents(
                $outputDir . '/' . $definition->shortName . 'ServiceAsyncInterface.php',
                $this->asyncInterfaceGenerator->generate($definition),
            );

            file_put_contents(
                $outputDir . '/' . $definition->shortName . 'ServiceActorProxy.php',
                $this->proxyGenerator->generate($definition),
            );
        }
    }
}
