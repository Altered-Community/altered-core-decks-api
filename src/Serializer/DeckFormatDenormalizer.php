<?php

namespace App\Serializer;

use App\Enum\DeckFormat;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

final class DeckFormatDenormalizer implements DenormalizerInterface
{
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): ?DeckFormat
    {
        if (null === $data || '' === $data) {
            return null;
        }

        return DeckFormat::fromInput((string) $data);
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return DeckFormat::class === $type;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [DeckFormat::class => true];
    }
}
