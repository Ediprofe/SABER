<?php

namespace App\Support;

class AreaConfig
{
    /**
     * Mapeo de claves de área a posibles nombres en tags.
     */
    public const AREA_MAPPINGS = [
        'lectura' => ['Lectura', 'Lectura Crítica', 'Lectura critica', 'lectura', 'Lectura critica'],
        'matematicas' => ['Matemáticas', 'matematicas', 'Matemática', 'Mat'],
        'sociales' => ['Sociales', 'Ciencias Sociales', 'ciencias sociales', 'sociales', 'Social'],
        'naturales' => ['Ciencias', 'Naturales', 'Ciencias Naturales', 'ciencias naturales', 'naturales'],
        'ingles' => ['Inglés', 'Ingles', 'ingles', 'English'],
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
        foreach (self::AREA_MAPPINGS as $key => $names) {
            if (in_array($tagName, $names, true)) {
                return $key;
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
}
