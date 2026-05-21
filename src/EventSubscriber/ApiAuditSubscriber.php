<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiAuditSubscriber implements EventSubscriberInterface
{
    private const MUTABLE_METHODS = ['POST', 'PATCH', 'PUT', 'DELETE'];

    public function __construct(
        private Security $security,
        private LoggerInterface $auditLogger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onResponse', 0],
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        if (!in_array($request->getMethod(), self::MUTABLE_METHODS, true)) {
            return;
        }

        $user = $this->security->getUser();
        $status = $event->getResponse()->getStatusCode();

        $this->auditLogger->info('api.request', [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'status' => $status,
            'ip' => $request->getClientIp(),
            'user_id' => $user instanceof User ? (string) $user->getId() : null,
            'username' => $user instanceof User ? $user->getUsername() : null,
            'email' => $user instanceof User ? $user->getEmail() : null,
        ]);
    }
}
