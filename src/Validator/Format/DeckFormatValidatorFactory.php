<?php

namespace App\Validator\Format;

class DeckFormatValidatorFactory
{
    /** @var array<string, DeckFormatValidatorInterface> */
    private array $validators = [];

    /**
     * @param iterable<DeckFormatValidatorInterface> $validators
     */
    public function __construct(iterable $validators)
    {
        foreach ($validators as $validator) {
            $this->validators[$validator->getFormat()] = $validator;
        }
    }

    public function getValidator(string $format): DeckFormatValidatorInterface
    {
        if (!isset($this->validators[$format])) {
            throw new \InvalidArgumentException(sprintf(
                'No validator found for format "%s". Available: %s',
                $format,
                implode(', ', array_keys($this->validators))
            ));
        }

        return $this->validators[$format];
    }

    public function supports(string $format): bool
    {
        return isset($this->validators[$format]);
    }
}
