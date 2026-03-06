<?php

declare(strict_types=1);

namespace GrumPHP\Task;

use GrumPHP\Collection\FilesCollection;
use GrumPHP\Exception\RuntimeException;
use GrumPHP\Formatter\ProcessFormatterInterface;
use GrumPHP\Runner\TaskResult;
use GrumPHP\Runner\TaskResultInterface;
use GrumPHP\Task\Config\ConfigOptionsResolver;
use GrumPHP\Task\Context\ContextInterface;
use GrumPHP\Task\Context\GitPreCommitContext;
use GrumPHP\Task\Context\RunContext;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractExternalTask<ProcessFormatterInterface>
 */
class Shell extends AbstractExternalTask
{
    public static function getConfigurableOptions(): ConfigOptionsResolver
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'scripts' => [],
            'ignore_patterns' => [],
            'whitelist_patterns' => [],
            'triggered_by' => ['php'],
        ]);

        $resolver->addAllowedTypes('scripts', ['array']);
        $resolver->addAllowedTypes('ignore_patterns', ['array']);
        $resolver->addAllowedTypes('whitelist_patterns', ['array']);
        $resolver->addAllowedTypes('triggered_by', ['array']);
        $resolver->setNormalizer('scripts', function (Options $options, array $scripts) {
            return array_map(
                /**
                 * @param string|array $script
                 */
                function ($script) {
                    return is_string($script) ? (array) $script : $script;
                },
                $scripts
            );
        });

        return ConfigOptionsResolver::fromOptionsResolver($resolver);
    }

    /**
     * {@inheritdoc}
     */
    public function canRunInContext(ContextInterface $context): bool
    {
        return $context instanceof GitPreCommitContext || $context instanceof RunContext;
    }

    /**
     * {@inheritdoc}
     */
    public function run(ContextInterface $context): TaskResultInterface
    {
        $config = $this->getConfig()->getOptions();

        $files = $context->getFiles()
            ->extensions($config['triggered_by'])
            ->paths($config['whitelist_patterns'] ?? [])
            ->notPaths($config['ignore_patterns'] ?? []);
        if (0 === \count($files)) {
            return TaskResult::createSkipped($this, $context);
        }

        $exceptions = [];
        foreach ($config['scripts'] as $script) {
            try {
                $this->runShell($script);
            } catch (RuntimeException $e) {
                $exceptions[] = $e->getMessage();
            }
        }

        if (\count($exceptions)) {
            return TaskResult::createFailed($this, $context, implode(PHP_EOL, $exceptions));
        }

        return TaskResult::createPassed($this, $context);
    }

    private function runShell(array $scriptArguments): void
    {
        $arguments = $this->processBuilder->createArgumentsForCommand('sh');
        $arguments->addArgumentArray('%s', $scriptArguments);

        $process = $this->processBuilder->buildProcess($arguments);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException($this->formatter->format($process));
        }
    }
}
