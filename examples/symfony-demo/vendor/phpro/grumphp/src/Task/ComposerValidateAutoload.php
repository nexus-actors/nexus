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

/**
 * @extends AbstractExternalTask<ProcessFormatterInterface>
 */
class ComposerValidateAutoload extends AbstractExternalTask
{
    public function canRunInContext(ContextInterface $context): bool
    {
        return $context instanceof GitPreCommitContext || $context instanceof RunContext;
    }

    public static function getConfigurableOptions(): ConfigOptionsResolver
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'file' => './composer.json',
            'strict_ambiguous' => false,
        ]);

        $resolver->addAllowedTypes('file', ['string']);
        $resolver->addAllowedTypes('strict_ambiguous', ['bool']);

        return ConfigOptionsResolver::fromOptionsResolver($resolver);
    }

    public function run(ContextInterface $context): TaskResultInterface
    {
        $config = $this->getConfig()->getOptions();
        $composerDir = pathinfo($config['file'], PATHINFO_DIRNAME);
        $composerFile = pathinfo($config['file'], PATHINFO_BASENAME);
        $files = $context->getFiles()
            ->path($composerDir)
            ->name($composerFile);
        if (0 === \count($files)) {
            return TaskResult::createSkipped($this, $context);
        }

        $config = $this->getConfig()->getOptions();

        $arguments = $this->processBuilder->createArgumentsForCommand('composer');
        $arguments->add('dump-autoload');
        $arguments->add('--optimize');
        $arguments->add('--dry-run');
        $arguments->add('--strict-psr');
        $arguments->addOptionalArgument('--strict-ambiguous', $config['strict_ambiguous']);

        $process = $this->processBuilder->buildProcess($arguments);
        $process->run();

        if (!$process->isSuccessful()) {
            return TaskResult::createFailed($this, $context, $process->getErrorOutput());
        }

        return TaskResult::createPassed($this, $context);
    }
}
