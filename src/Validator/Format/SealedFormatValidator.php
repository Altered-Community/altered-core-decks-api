<?php

namespace App\Validator\Format;

use App\Client\AlteredDraftSealedPoolClient;
use App\Entity\Deck;
use App\Entity\DeckCard;

/**
 * Generic tournament sealed format, driven entirely by altered-draft's currently
 * active sealed event (today: Set 6 / Roots of Corruption / EOLE prerelease
 * tournaments run on `altered-draft.vercel.app`/`limited.altered.re`):
 * - Exactly 1 hero (base default — no format-specific override needed).
 * - Up to 3 factions.
 * - Minimum 29 cards, hero excluded (same "min/max excludes hero" convention as
 *   every other format's getMinCards()/getMaxCards() — see AbstractDeckFormatValidator::validateDeckSize()).
 * - Every card in the deck — hero included — must be in the player's own sealed pool
 *   (fetched from altered-draft and cached until the tournament event ends — see
 *   AlteredDraftSealedPoolClient). There's no special "any hero from set N is legal
 *   regardless of the player's pool" carve-out: altered-draft's pool endpoint
 *   guarantees every hero of the tournament's set is present in the returned pool
 *   (its `heroesInPool: false` event config — see its ROADMAP.md "Set 6 preview"), so
 *   a hero is just another pool ref here, checked the exact same way as everything else.
 *
 * NO set restriction is hardcoded here (unlike every other format's VALID_SETS /
 * FORBIDDEN_SETS): pool membership is the sole and sufficient legality gate — a card
 * from any other set literally can't be in the pool, so a redundant sets check would
 * only ever produce a less specific error message. This makes the format reusable
 * as-is for whichever set altered-draft is currently running a sealed tournament for,
 * without a new FormatValidator subclass per set.
 */
class SealedFormatValidator extends AbstractDeckFormatValidator
{
    private const MAX_FACTIONS = 3;

    public function __construct(
        private readonly AlteredDraftSealedPoolClient $poolClient,
    ) {
    }

    public function getFormat(): string
    {
        return 'sealed';
    }

    protected function getMinCards(): int
    {
        return 29;
    }

    protected function getMaxCards(): int
    {
        return \PHP_INT_MAX;
    }

    // No set restriction — pool membership below is the sole legality gate (see class docblock).
    protected function validateAllowedSets(Deck $deck, array $cardsData): array
    {
        return [];
    }

    // Base allows only 1 faction; this format allows up to 3.
    protected function validateFaction(array $deckCards, array $cardsData): array
    {
        $factions = [];
        foreach ($deckCards as $deckCard) {
            $data = $cardsData[$deckCard->getCardReference()] ?? [];
            $code = $data['faction']['code'] ?? null;
            if ($code && 'NE' !== $code) {
                $factions[$code] = true;
            }
        }

        if (count($factions) > self::MAX_FACTIONS) {
            return [sprintf(
                'Deck contains cards from %d factions (max %d): %s.',
                count($factions),
                self::MAX_FACTIONS,
                implode(', ', array_keys($factions)),
            )];
        }

        return [];
    }

    protected function validateFormatRules(array $deckCards, array $cardsData, ?DeckCard $hero): array
    {
        return $this->validatePoolMembership($deckCards, $cardsData, $hero);
    }

    protected function computeFormatRulesDetail(array $deckCards, array $cardsData, ?DeckCard $hero): array
    {
        return [
            'pool' => [] === $this->validatePoolMembership($deckCards, $cardsData, $hero),
        ];
    }

    /** @param DeckCard[] $deckCards */
    private function validatePoolMembership(array $deckCards, array $cardsData, ?DeckCard $hero): array
    {
        $pool = $this->poolClient->getPoolCounts();
        if (null === $pool) {
            return ['Could not verify your sealed pool (no active tournament, or altered-draft is unreachable) — deck cannot be validated right now.'];
        }

        $errors = [];
        $allCards = null !== $hero ? [...$deckCards, $hero] : $deckCards;
        foreach ($allCards as $deckCard) {
            $ref = $deckCard->getCardReference();
            $available = $pool[$ref] ?? 0;
            if ($deckCard->getQuantity() > $available) {
                $name = $this->getCardName($cardsData[$ref] ?? []);
                $errors[] = 0 === $available
                    ? sprintf('Card "%s" is not in your sealed pool.', $name)
                    : sprintf('Card "%s": only %d in your pool, deck has %d.', $name, $available, $deckCard->getQuantity());
            }
        }

        return $errors;
    }
}
