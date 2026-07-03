<?php

declare(strict_types=1);

namespace Monadial\Nexus\Messenger\Console;

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
 * $app->add(new ProduceCommand($transport, $serializer, $typeRegistry));
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
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Number of identical messages to publish.', 1);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $typeName = (string) $input->getArgument('type');
        $body = (string) $input->getArgument('body');
        $count = (int) $input->getOption('count');

        $class = $this->types->classForName($typeName);

        if ($class === null) {
            $io->error(sprintf(
                "Unknown message type '%s'. Register it in the TypeRegistry via #[MessageType] or TypeRegistry::register().",
                $typeName,
            ));

            return Command::FAILURE;
        }

        $message = $this->serializer->deserialize($body, $typeName);
        $gateway = MessengerBridge::gateway($this->sender);

        for ($i = 0; $i < $count; $i++) {
            $gateway->publish($message);
        }

        $io->success(sprintf("Published %d message(s) of type '%s'.", $count, $typeName));

        return Command::SUCCESS;
    }
}
