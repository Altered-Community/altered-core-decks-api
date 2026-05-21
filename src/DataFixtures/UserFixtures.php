<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class UserFixtures extends Fixture
{
    public const ADMIN = 'user-admin';
    public const USER = 'user-regular';

    public function load(ObjectManager $manager): void
    {
        $admin = new User();
        $admin->setKeycloakId('dev-admin');
        $admin->setEmail('admin@dev.local');
        $admin->setUsername('admin');
        $admin->setIsAdmin(true);

        $user = new User();
        $user->setKeycloakId('dev-user');
        $user->setEmail('player@dev.local');
        $user->setUsername('player1');

        $manager->persist($admin);
        $manager->persist($user);
        $manager->flush();

        $this->addReference(self::ADMIN, $admin);
        $this->addReference(self::USER, $user);
    }
}
