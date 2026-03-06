<?php

declare(strict_types=1);

namespace GrumPHP\Task;

use GrumPHP\Formatter\ProcessFormatterInterface;
use GrumPHP\Runner\TaskResult;
use GrumPHP\Runner\TaskResultInterface;
use GrumPHP\Task\Config\ConfigOptionsResolver;
use GrumPHP\Task\Context\ContextInterface;
use GrumPHP\Task\Context\GitPreCommitContext;
use GrumPHP\Task\Context\RunContext;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractExternalTask<ProcessFormatterInterface> */
class SymfonyConsole extends AbstractExternalTask
{
    public static function getConfigurableOptions(): ConfigOptionsResolver
    {
        return ConfigOptionsResolver::fromOptionsResolver(
            (new OptionsResolver())
                ->setDefaults([
                    'bin' => './bin/console',
                    'command' => [],
                    'ignore_patterns' => [],
                    'whitelist_patterns' => [],
                    'triggered_by' => ['php', 'yml', 'xml'],
                    'run_always' => false,
                ])
                ->addAllowedTypes('bin', ['string'])
                ->addAllowedTypes('command', ['string[]'])
                ->addAllowedTypes('ignore_patterns', ['array'])
                ->addAllowedTypes('whitelist_patterns', ['array'])
                ->addAllowedTypes('triggered_by', ['array'])
                ->addAllowedTypes('run_always', ['bool'])
                ->setAllowedValues(
                    'bin',
                    static fn(string $bin): bool => '' !== $bin
                )
                ->setAllowedValues(
                    'command',
                    static fn(array $command): bool => '' !== \implode('', $command)
                )
                ->setAllowedValues('bin', static fn(string $bin): bool => '' !== $bin)
                ->setRequired('command')
        );
    }

    public function canRunInContext(ContextInterface $context): bool
    {
        return ($context instanceof GitPreCommitContext || $context instanceof RunContext);
    }

    public function run(ContextInterface $context): TaskResultInterface
    {
        $config = $this->getConfig()->getOptions();

        $files = $context->getFiles()
            ->extensions($config['triggered_by'])
            ->paths($config['whitelist_patterns'] ?? [])
            ->notPaths($config['ignore_patterns'] ?? []);
        if (!$config['run_always'] && 0 === \count($files)) {
            return TaskResult::createSkipped($this, $context);
        }

        $arguments = $this->processBuilder->createArgumentsForCommand('php');
        $arguments->add($config['bin']);
        $arguments->addArgumentArray('%s', $config['command']);

        $process = $this->processBuilder->buildProcess($arguments);
        $process->run();

        if (!$process->isSuccessful()) {
            return TaskResult::createFailed($this, $context, $this->formatter->format($process));
        }

        return TaskResult::createPassed($this, $context);
    }
}
