<?php

namespace App\EventSubscriber;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class FixtureProductionGuard implements EventSubscriberInterface
{
    public function __construct(private readonly string $env)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ConsoleEvents::COMMAND => 'onCommand'];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        if ('prod' !== $this->env) {
            return;
        }

        $name = $event->getCommand()?->getName() ?? '';
        if (str_starts_with($name, 'doctrine:fixtures')) {
            $event->disableCommand();
            $event->getOutput()->writeln(
                '<error>BLOCKED: doctrine:fixtures:load is forbidden in APP_ENV=prod.</error>'
            );
        }
    }
}
