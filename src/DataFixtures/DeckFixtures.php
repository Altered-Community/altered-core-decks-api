<?php

namespace App\DataFixtures;

use App\Entity\Deck;
use App\Entity\DeckCard;
use App\Entity\User;
use App\Enum\DeckFormat;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class DeckFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var User $admin */
        $admin = $this->getReference(UserFixtures::ADMIN, User::class);

        foreach ($this->decks($admin) as $deck) {
            $manager->persist($deck);
        }

        $manager->flush();
    }

    /** @return Deck[] */
    private function decks(User $admin): array
    {
        return [
            $this->build(
                name: 'Standard AX — full commons',
                format: DeckFormat::Standard,
                isPublic: true,
                user: $admin,
                cards: [
                    ['ALT_CORE_B_AX_01_C', 1], // hero
                    ['ALT_CORE_B_AX_04_C', 3],
                    ['ALT_CORE_B_AX_05_C', 3],
                    ['ALT_CORE_B_AX_06_C', 3],
                    ['ALT_CORE_B_AX_07_C', 3],
                    ['ALT_CORE_B_AX_08_C', 3],
                    ['ALT_CORE_B_AX_09_C', 3],
                    ['ALT_CORE_B_AX_10_C', 3],
                    ['ALT_CORE_B_AX_11_C', 3],
                    ['ALT_CORE_B_AX_12_C', 3],
                    ['ALT_CORE_B_AX_13_C', 3],
                    ['ALT_CORE_B_AX_14_C', 3],
                    ['ALT_CORE_B_AX_15_C', 3],
                    ['ALT_CORE_B_AX_16_C', 3],
                ],
            ),

            $this->build(
                name: 'Standard BR — commons + rares',
                format: DeckFormat::Standard,
                isPublic: true,
                user: $admin,
                cards: [
                    ['ALT_CORE_B_BR_01_C', 1], // hero
                    ['ALT_CORE_B_BR_04_C', 3],
                    ['ALT_CORE_B_BR_05_C', 3],
                    ['ALT_CORE_B_BR_06_C', 3],
                    ['ALT_CORE_B_BR_07_C', 3],
                    ['ALT_CORE_B_BR_08_C', 3],
                    ['ALT_CORE_B_BR_09_C', 3],
                    ['ALT_CORE_B_BR_10_C', 3],
                    ['ALT_CORE_B_BR_11_C', 3],
                    ['ALT_CORE_B_BR_12_C', 3],
                    ['ALT_CORE_B_BR_04_R1', 3],
                    ['ALT_CORE_B_BR_05_R1', 3],
                    ['ALT_CORE_B_BR_06_R1', 3],
                    ['ALT_CORE_B_BR_07_R1', 3],
                ],
            ),

            $this->build(
                name: 'NUC MU — no uniques',
                format: DeckFormat::Nuc,
                isPublic: true,
                user: $admin,
                cards: [
                    ['ALT_CORE_B_MU_01_C', 1], // hero
                    ['ALT_CORE_B_MU_04_C', 3],
                    ['ALT_CORE_B_MU_05_C', 3],
                    ['ALT_CORE_B_MU_06_C', 3],
                    ['ALT_CORE_B_MU_07_C', 3],
                    ['ALT_CORE_B_MU_08_C', 3],
                    ['ALT_CORE_B_MU_09_C', 3],
                    ['ALT_CORE_B_MU_10_C', 3],
                    ['ALT_CORE_B_MU_11_C', 3],
                    ['ALT_CORE_B_MU_12_C', 3],
                    ['ALT_CORE_B_MU_13_C', 3],
                    ['ALT_CORE_B_MU_14_C', 3],
                    ['ALT_CORE_B_MU_15_C', 3],
                    ['ALT_CORE_B_MU_16_C', 3],
                ],
            ),

            $this->build(
                name: 'Singleton LY — 1 par rareté',
                format: DeckFormat::Singleton,
                isPublic: true,
                user: $admin,
                cards: [
                    ['ALT_CORE_B_LY_01_C', 1], // hero
                    ['ALT_CORE_B_LY_04_C', 1], ['ALT_CORE_B_LY_04_R1', 1], ['ALT_CORE_B_LY_04_R2', 1],
                    ['ALT_CORE_B_LY_05_C', 1], ['ALT_CORE_B_LY_05_R1', 1], ['ALT_CORE_B_LY_05_R2', 1],
                    ['ALT_CORE_B_LY_06_C', 1], ['ALT_CORE_B_LY_06_R1', 1], ['ALT_CORE_B_LY_06_R2', 1],
                    ['ALT_CORE_B_LY_07_C', 1], ['ALT_CORE_B_LY_07_R1', 1], ['ALT_CORE_B_LY_07_R2', 1],
                    ['ALT_CORE_B_LY_08_C', 1], ['ALT_CORE_B_LY_08_R1', 1], ['ALT_CORE_B_LY_08_R2', 1],
                    ['ALT_CORE_B_LY_09_C', 1], ['ALT_CORE_B_LY_09_R1', 1], ['ALT_CORE_B_LY_09_R2', 1],
                    ['ALT_CORE_B_LY_10_C', 1], ['ALT_CORE_B_LY_10_R1', 1], ['ALT_CORE_B_LY_10_R2', 1],
                    ['ALT_CORE_B_LY_11_C', 1], ['ALT_CORE_B_LY_11_R1', 1], ['ALT_CORE_B_LY_11_R2', 1],
                    ['ALT_CORE_B_LY_12_C', 1], ['ALT_CORE_B_LY_12_R1', 1], ['ALT_CORE_B_LY_12_R2', 1],
                    ['ALT_CORE_B_LY_13_C', 1], ['ALT_CORE_B_LY_13_R1', 1], ['ALT_CORE_B_LY_13_R2', 1],
                    ['ALT_CORE_B_LY_14_C', 1], ['ALT_CORE_B_LY_14_R1', 1], ['ALT_CORE_B_LY_14_R2', 1],
                    ['ALT_CORE_B_LY_15_C', 1], ['ALT_CORE_B_LY_15_R1', 1], ['ALT_CORE_B_LY_15_R2', 1],
                    ['ALT_CORE_B_LY_16_C', 1], ['ALT_CORE_B_LY_16_R1', 1], ['ALT_CORE_B_LY_16_R2', 1],
                    ['ALT_CORE_B_LY_17_C', 1], ['ALT_CORE_B_LY_17_R1', 1], ['ALT_CORE_B_LY_17_R2', 1],
                    ['ALT_CORE_B_LY_18_C', 1], ['ALT_CORE_B_LY_18_R1', 1], ['ALT_CORE_B_LY_18_R2', 1],
                    ['ALT_CORE_B_LY_19_C', 1], ['ALT_CORE_B_LY_19_R1', 1], ['ALT_CORE_B_LY_19_R2', 1],
                    ['ALT_CORE_B_LY_20_C', 1], ['ALT_CORE_B_LY_20_R1', 1], ['ALT_CORE_B_LY_20_R2', 1],
                    ['ALT_CORE_B_LY_21_C', 1], ['ALT_CORE_B_LY_21_R1', 1], ['ALT_CORE_B_LY_21_R2', 1],
                    ['ALT_CORE_B_LY_22_C', 1], ['ALT_CORE_B_LY_22_R1', 1], ['ALT_CORE_B_LY_22_R2', 1],
                    ['ALT_CORE_B_LY_23_C', 1], ['ALT_CORE_B_LY_23_R1', 1], ['ALT_CORE_B_LY_23_R2', 1],
                ],
            ),

            $this->build(
                name: 'Sandbox OR — no restrictions',
                format: DeckFormat::Sandbox,
                isPublic: false,
                user: $admin,
                cards: [
                    ['ALT_CORE_B_OR_01_C', 1], // hero
                    ['ALT_CORE_B_OR_04_C', 3],
                    ['ALT_CORE_B_OR_05_C', 3],
                    ['ALT_CORE_B_OR_06_C', 3],
                    ['ALT_CORE_B_OR_07_C', 3],
                    ['ALT_CORE_B_OR_08_C', 3],
                    ['ALT_CORE_B_OR_09_C', 3],
                    ['ALT_CORE_B_OR_10_C', 3],
                    ['ALT_CORE_B_OR_11_C', 3],
                    ['ALT_CORE_B_OR_12_C', 3],
                    ['ALT_CORE_B_OR_13_C', 3],
                    ['ALT_CORE_B_OR_04_R1', 3],
                    ['ALT_CORE_B_OR_05_R1', 3],
                    ['ALT_CORE_B_OR_06_R1', 3],
                    ['ALT_CORE_B_OR_07_R1', 3],
                    ['ALT_CORE_B_OR_08_R1', 3],
                    ['ALT_CORE_B_OR_04_R2', 3],
                    ['ALT_CORE_B_OR_05_R2', 3],
                    ['ALT_CORE_B_OR_06_R2', 3],
                    ['ALT_CORE_B_OR_07_R2', 3],
                    ['ALT_CORE_B_OR_08_R2', 3],
                ],
            ),
        ];
    }

    /** @param array<array{string, int}> $cards */
    private function build(string $name, DeckFormat $format, bool $isPublic, User $user, array $cards): Deck
    {
        $deck = new Deck();
        $deck->setName($name);
        $deck->setFormat($format);
        $deck->setIsPublic($isPublic);
        $deck->setIsDraft(false);
        $deck->setUser($user);

        foreach ($cards as [$ref, $qty]) {
            $dc = new DeckCard();
            $dc->setCardReference($ref);
            $dc->setQuantity($qty);
            $deck->addDeckCard($dc);
        }

        return $deck;
    }
}
