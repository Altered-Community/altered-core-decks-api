<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class DevDebugController extends AbstractController
{
    public function __construct(
        private readonly bool $kernelDebug,
        private readonly bool $devAuthEnabled,
    ) {
    }

    #[Route('/dev/debug', name: 'dev_debug', methods: ['GET'])]
    public function __invoke(): Response
    {
        if (!$this->kernelDebug || !$this->devAuthEnabled) {
            throw new NotFoundHttpException();
        }

        return $this->render('dev/debug.html.twig');
    }
}
