<?php

namespace App\Tests\State;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use App\Entity\Deck;
use App\Entity\User;
use App\Repository\DeckRepository;
use App\State\DeckItemProvider;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Uid\Uuid;

class DeckItemProviderTest extends TestCase
{
    private const OWNER_ID = '11111111-1111-1111-1111-111111111111';
    private const OTHER_ID = '22222222-2222-2222-2222-222222222222';

    private function provider(DeckRepository $repo, Security $security): DeckItemProvider
    {
        return new DeckItemProvider($repo, $this->createStub(EntityManagerInterface::class), $security);
    }

    private function user(string $id): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(Uuid::fromString($id));

        return $user;
    }

    private function deck(bool $isPublic, string $ownerId): Deck
    {
        $deck = $this->createStub(Deck::class);
        $deck->method('getIsPublic')->willReturn($isPublic);
        $deck->method('getUser')->willReturn($this->user($ownerId));

        return $deck;
    }

    private function repo(?Deck $deck): DeckRepository
    {
        $repo = $this->createStub(DeckRepository::class);
        $repo->method('find')->willReturn($deck);

        return $repo;
    }

    private function security(?User $user): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }

    // ── 404 ──────────────────────────────────────────────────────────────────

    public function testThrowsNotFoundWhenDeckMissing(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->provider($this->repo(null), $this->security(null))
            ->provide(new Get(), ['id' => 'any']);
    }

    // ── GET public deck ───────────────────────────────────────────────────────

    public function testGetPublicDeckWithoutAuthReturnsIt(): void
    {
        $deck = $this->deck(true, self::OWNER_ID);
        $result = $this->provider($this->repo($deck), $this->security(null))
            ->provide(new Get(), ['id' => 'any']);

        self::assertSame($deck, $result);
    }

    public function testGetPublicDeckByOtherUserReturnsIt(): void
    {
        $deck = $this->deck(true, self::OWNER_ID);
        $result = $this->provider($this->repo($deck), $this->security($this->user(self::OTHER_ID)))
            ->provide(new Get(), ['id' => 'any']);

        self::assertSame($deck, $result);
    }

    public function testGetPublicDeckByOwnerReturnsIt(): void
    {
        $deck = $this->deck(true, self::OWNER_ID);
        $result = $this->provider($this->repo($deck), $this->security($this->user(self::OWNER_ID)))
            ->provide(new Get(), ['id' => 'any']);

        self::assertSame($deck, $result);
    }

    // ── GET private deck ──────────────────────────────────────────────────────

    public function testGetPrivateDeckWithoutAuthThrows401(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->provider($this->repo($this->deck(false, self::OWNER_ID)), $this->security(null))
            ->provide(new Get(), ['id' => 'any']);
    }

    public function testGetPrivateDeckByOtherUserThrows403(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->provider($this->repo($this->deck(false, self::OWNER_ID)), $this->security($this->user(self::OTHER_ID)))
            ->provide(new Get(), ['id' => 'any']);
    }

    public function testGetPrivateDeckByOwnerReturnsIt(): void
    {
        $deck = $this->deck(false, self::OWNER_ID);
        $result = $this->provider($this->repo($deck), $this->security($this->user(self::OWNER_ID)))
            ->provide(new Get(), ['id' => 'any']);

        self::assertSame($deck, $result);
    }

    // ── PATCH on public deck (the bug that was fixed) ─────────────────────────

    public function testPatchPublicDeckWithoutAuthThrows401(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->provider($this->repo($this->deck(true, self::OWNER_ID)), $this->security(null))
            ->provide(new Patch(), ['id' => 'any']);
    }

    public function testPatchPublicDeckByOtherUserThrows403(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->provider($this->repo($this->deck(true, self::OWNER_ID)), $this->security($this->user(self::OTHER_ID)))
            ->provide(new Patch(), ['id' => 'any']);
    }

    public function testPatchPublicDeckByOwnerReturnsIt(): void
    {
        $deck = $this->deck(true, self::OWNER_ID);
        $result = $this->provider($this->repo($deck), $this->security($this->user(self::OWNER_ID)))
            ->provide(new Patch(), ['id' => 'any']);

        self::assertSame($deck, $result);
    }

    // ── PATCH on private deck ─────────────────────────────────────────────────

    public function testPatchPrivateDeckWithoutAuthThrows401(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->provider($this->repo($this->deck(false, self::OWNER_ID)), $this->security(null))
            ->provide(new Patch(), ['id' => 'any']);
    }

    public function testPatchPrivateDeckByOtherUserThrows403(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->provider($this->repo($this->deck(false, self::OWNER_ID)), $this->security($this->user(self::OTHER_ID)))
            ->provide(new Patch(), ['id' => 'any']);
    }

    public function testPatchPrivateDeckByOwnerReturnsIt(): void
    {
        $deck = $this->deck(false, self::OWNER_ID);
        $result = $this->provider($this->repo($deck), $this->security($this->user(self::OWNER_ID)))
            ->provide(new Patch(), ['id' => 'any']);

        self::assertSame($deck, $result);
    }

    // ── DELETE on public deck ─────────────────────────────────────────────────

    public function testDeletePublicDeckWithoutAuthThrows401(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->provider($this->repo($this->deck(true, self::OWNER_ID)), $this->security(null))
            ->provide(new Delete(), ['id' => 'any']);
    }

    public function testDeletePublicDeckByOtherUserThrows403(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->provider($this->repo($this->deck(true, self::OWNER_ID)), $this->security($this->user(self::OTHER_ID)))
            ->provide(new Delete(), ['id' => 'any']);
    }

    public function testDeletePublicDeckByOwnerReturnsIt(): void
    {
        $deck = $this->deck(true, self::OWNER_ID);
        $result = $this->provider($this->repo($deck), $this->security($this->user(self::OWNER_ID)))
            ->provide(new Delete(), ['id' => 'any']);

        self::assertSame($deck, $result);
    }

    // ── DELETE on private deck ────────────────────────────────────────────────

    public function testDeletePrivateDeckWithoutAuthThrows401(): void
    {
        $this->expectException(UnauthorizedHttpException::class);

        $this->provider($this->repo($this->deck(false, self::OWNER_ID)), $this->security(null))
            ->provide(new Delete(), ['id' => 'any']);
    }

    public function testDeletePrivateDeckByOtherUserThrows403(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->provider($this->repo($this->deck(false, self::OWNER_ID)), $this->security($this->user(self::OTHER_ID)))
            ->provide(new Delete(), ['id' => 'any']);
    }

    public function testDeletePrivateDeckByOwnerReturnsIt(): void
    {
        $deck = $this->deck(false, self::OWNER_ID);
        $result = $this->provider($this->repo($deck), $this->security($this->user(self::OWNER_ID)))
            ->provide(new Delete(), ['id' => 'any']);

        self::assertSame($deck, $result);
    }
}
