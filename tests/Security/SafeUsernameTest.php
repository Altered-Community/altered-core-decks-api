<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\SafeUsername;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SafeUsernameTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, ?string}>
     */
    public static function values(): iterable
    {
        yield 'pseudo' => ['PseudoJoueur', 'PseudoJoueur'];
        yield 'pseudo with spaces trimmed' => ['  Pseudo Joueur  ', 'Pseudo Joueur'];
        yield 'email' => ['joueur@example.com', null];
        yield 'email with spaces' => ['  joueur@example.com ', null];
        yield 'partial email' => ['joueur@', null];
        yield 'bare at sign' => ['@', null];
        yield 'empty' => ['', null];
        yield 'blank' => ['   ', null];
        yield 'null' => [null, null];
        yield 'non-string claim' => [['joueur@example.com'], null];
    }

    #[DataProvider('values')]
    public function testSanitize(mixed $value, ?string $expected): void
    {
        self::assertSame($expected, SafeUsername::sanitize($value));
    }

    public function testUserNeverReturnsEmailAsUsername(): void
    {
        $user = (new User())->setUsername('joueur@example.com');

        self::assertNull($user->getUsername());
    }

    public function testUserKeepsPseudo(): void
    {
        $user = (new User())->setUsername('PseudoJoueur');

        self::assertSame('PseudoJoueur', $user->getUsername());
    }
}
