<?php

namespace App\Tests\State;

use ApiPlatform\Metadata\GetCollection;
use App\Entity\User;
use App\Repository\DeckRepository;
use App\State\DeckCollectionProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

class DeckCollectionProviderTest extends TestCase
{
    /**
     * @return iterable<string, array{0: mixed, 1: 'ASC'|'DESC'|null}>
     */
    public static function orderProvider(): iterable
    {
        yield 'no order' => [null, null];
        yield 'asc' => [['lastModifiedAt' => 'asc'], 'ASC'];
        yield 'desc' => [['lastModifiedAt' => 'desc'], 'DESC'];
        yield 'uppercase' => [['lastModifiedAt' => 'DESC'], 'DESC'];
        yield 'invalid direction' => [['lastModifiedAt' => 'sideways'], null];
        yield 'array direction' => [['lastModifiedAt' => ['asc']], null];
        yield 'other field keeps default' => [['name' => 'asc'], null];
        yield 'updatedAt keeps default' => [['updatedAt' => 'asc'], null];
        yield 'scalar order' => ['lastModifiedAt', null];
        yield 'with another key' => [['name' => 'asc', 'lastModifiedAt' => 'asc'], 'ASC'];
    }

    #[DataProvider('orderProvider')]
    public function testOrderLastModifiedAtIsPassedToRepository(mixed $order, ?string $expected): void
    {
        $user = $this->createStub(User::class);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $repo = $this->createMock(DeckRepository::class);
        $repo->expects($this->once())
            ->method('findByUser')
            ->with($user, 'LY', null, $expected)
            ->willReturn([]);

        $filters = ['faction' => 'LY'];
        if (null !== $order) {
            $filters['order'] = $order;
        }

        (new DeckCollectionProvider($repo, $security))->provide(new GetCollection(), [], ['filters' => $filters]);
    }
}
