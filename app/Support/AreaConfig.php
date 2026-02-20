<?php

namespace App\Support;

use Illuminate\Support\Str;

class AreaConfig
{
    /**
     * Mapeo de claves de área a posibles nombres en tags.
     */
    public const AREA_MAPPINGS = [
        'lectura' => [
            'Lectura',
            'Lectura Critica',
            'Lectura Crítica',
        ],
        'matematicas' => [
            'Matematicas',
            'Matemáticas',
            'Matematica',
            'Matemática',
        ],
        'sociales' => [
            'Sociales',
            'Ciencias Sociales',
        ],
        'naturales' => [
            'Naturales',
            'Ciencias Naturales',
        ],
        'ingles' => [
            'Ingles',
            'Inglés',
            'English',
        ],
    ];

    /**
     * Labels para mostrar en UI.
     */
    public const AREA_LABELS = [
        'lectura' => 'Lectura Crítica',
        'matematicas' => 'Matemáticas',
        'sociales' => 'Ciencias Sociales',
        'naturales' => 'Ciencias Naturales',
        'ingles' => 'Inglés',
    ];

    /**
     * Prefijos para columnas de Excel.
     */
    public const AREA_PREFIXES = [
        'lectura' => 'Lec',
        'matematicas' => 'Mat',
        'sociales' => 'Soc',
        'naturales' => 'Nat',
        'ingles' => 'Ing',
    ];

    /**
     * Normaliza un nombre de área a su clave estándar.
     */
    public static function normalizeAreaName(string $tagName): ?string
    {
        $normalizedInput = self::normalized($tagName);

        foreach (self::AREA_MAPPINGS as $key => $names) {
            foreach ($names as $name) {
                if (self::normalized($name) === $normalizedInput) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Obtiene el label de un área.
     */
    public static function getLabel(string $areaKey): string
    {
        return self::AREA_LABELS[$areaKey] ?? ucfirst($areaKey);
    }

    /**
     * Obtiene el prefijo de un área.
     */
    public static function getPrefix(string $areaKey): string
    {
        return self::AREA_PREFIXES[$areaKey] ?? substr($areaKey, 0, 3);
    }

    /**
     * Lista de todas las claves de área.
     */
    public static function allKeys(): array
    {
        return array_keys(self::AREA_MAPPINGS);
    }

    private static function normalized(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }
}
