<?php

declare(strict_types=1);

namespace CoenJacobs\WordPressAiProvider\ModelDirectory;

use WordPress\AiClient\Messages\Enums\ModalityEnum;

/**
 * Helper for converting API modality strings to SDK ModalityEnum values.
 */
class ModalityDetector
{
    /**
     * Convert an array of modality strings to ModalityEnum values.
     *
     * @param list<string> $modalities
     * @return list<ModalityEnum>
     */
    public static function toModalityEnums(array $modalities): array
    {
        $enums = [];

        foreach ($modalities as $modality) {
            $enum = self::toModalityEnum($modality);
            if ($enum !== null) {
                $enums[] = $enum;
            }
        }

        if (empty($enums)) {
            $enums[] = ModalityEnum::text();
        }

        return $enums;
    }

    /**
     * Convert a single modality string to a ModalityEnum, or null if unknown.
     */
    public static function toModalityEnum(string $modality): ?ModalityEnum
    {
        switch ($modality) {
            case 'text':
                return ModalityEnum::text();
            case 'image':
                return ModalityEnum::image();
            case 'audio':
                return ModalityEnum::audio();
            default:
                return null;
        }
    }
}
