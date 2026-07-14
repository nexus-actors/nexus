<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Console;

use InvalidArgumentException;
use Monadial\Nexus\Messenger\MessengerBridge;
use Monadial\Nexus\Serialization\MessageSerializer;
use Monadial\Nexus\Serialization\TypeRegistry;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Throwable;

use function is_numeric;
use function sprintf;

/**
 * Publishes one or more messages to a Symfony Messenger transport by
 * deserializing a JSON body into a registered message class.
 *
 * Types must be registered in the injected TypeRegistry (via
 * `#[MessageType]` or `TypeRegistry::register()`). Passing an unknown type
 * name produces a clear error message rather than a silent failure.
 *
 * Example wiring inside a Symfony Console Application:
 * ```php
 * $app = new Application('nexus-worker', '1.0.0');
 * $app->addCommand(new ProduceCommand($transport, $serializer, $typeRegistry));
 * $app->run();
 * ```
 *
 * @psalm-api
 */
#[AsCommand(
    name: 'nexus:messenger:produce',
    description: 'Publish one or more messages to a Symfony Messenger transport.',
)]
final class ProduceCommand extends Command
{
    public function __construct(
        private readonly SenderInterface $sender,
        private readonly MessageSerializer $serializer,
        private readonly TypeRegistry $types,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument(
                'type',
                InputArgument::REQUIRED,
                'Registered message type name (from #[MessageType] attribute).',
            )
            ->addArgument('body', InputArgument::REQUIRED, 'Message body as a JSON object.')
            ->addOption(
                'count',
                'c',
                InputOption::VALUE_REQUIRED,
                'Number of identical messages to publish; must be a positive integer.',
                1,
            );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $typeName = (string) $input->getArgument('type');
        $body = (string) $input->getArgument('body');

        try {
            $count = $this->positiveInt($input->getOption('count'), '--count');
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $class = $this->types->classForName($typeName);

        if ($class === null) {
            $io->error(sprintf(
                "Unknown message type '%s'; register it or check the name (via #[MessageType] or TypeRegistry::register()).",
                $typeName,
            ));

            return Command::INVALID;
        }

        try {
            $message = $this->serializer->deserialize($body, $class);
        } catch (Throwable $e) {
            $io->error(sprintf(
                "Failed to deserialize body for type '%s': %s. The body format must match the configured serializer "
                . '(e.g. SERIALIZER=php-native expects PHP-serialized data; pass a JSON body with SERIALIZER=json).',
                $typeName,
                $e->getMessage(),
            ));

            return Command::INVALID;
        }

        $gateway = MessengerBridge::gateway($this->sender);

        for ($i = 0; $i < $count; $i++) {
            $gateway->publish($message);
        }

        $io->success(sprintf("Published %d message(s) of type '%s'.", $count, $typeName));

        return Command::SUCCESS;
    }

    /**
     * @throws InvalidArgumentException When the value is not a positive integer.
     */
    private function positiveInt(mixed $value, string $option): int
    {
        if (!is_numeric($value) || (string) (int) $value !== (string) $value || (int) $value < 1) {
            throw new InvalidArgumentException(sprintf('%s must be a positive integer.', $option));
        }

        return (int) $value;
    }
}
