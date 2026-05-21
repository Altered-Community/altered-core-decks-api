<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

final class AdminSessionGuard implements EventSubscriberInterface
{
    private const EXCLUDED_PATHS = ['/admin/login', '/admin/callback', '/admin/logout'];

    public function __construct(private readonly RouterInterface $router)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 8]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();

        if (!str_starts_with($path, '/admin/')) {
            return;
        }

        if (in_array($path, self::EXCLUDED_PATHS, true)) {
            return;
        }

        if ($event->getRequest()->getSession()->has('admin_user_id')) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->router->generate('admin_login')));
    }
}
