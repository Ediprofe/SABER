<?php

namespace App\Support;

class TagClassificationConfig
{
    /**
     * Configuracion de clasificacion por area.
     *
     * @var array<string,array{label:string,types:array<int,string>,default_type:string}>
     */
    public const AREA_CONFIG = [
        'lectura' => [
            'label' => 'Lectura Critica',
            'types' => ['area', 'competencia', 'componente', 'tipo_texto', 'nivel_lectura'],
            'default_type' => 'competencia',
        ],
        'matematicas' => [
            'label' => 'Matematicas',
            'types' => ['area', 'competencia', 'componente'],
            'default_type' => 'componente',
        ],
        'sociales' => [
            'label' => 'Ciencias Sociales',
            'types' => ['area', 'competencia', 'componente'],
            'default_type' => 'componente',
        ],
        'naturales' => [
            'label' => 'Ciencias Naturales',
            'types' => ['area', 'competencia', 'componente'],
            'default_type' => 'componente',
        ],
        'ingles' => [
            'label' => 'Ingles',
            'types' => ['area', 'parte', 'competencia'],
            'default_type' => 'parte',
        ],
        '__unclassified' => [
            'label' => 'Sin area (revisar)',
            'types' => ['area', 'competencia', 'componente', 'tipo_texto', 'nivel_lectura', 'parte'],
            'default_type' => 'componente',
        ],
    ];

    /**
     * @var array<string,string>
     */
    public const TYPE_LABELS = [
        'area' => 'Area',
        'competencia' => 'Competencia',
        'componente' => 'Componente',
        'tipo_texto' => 'Tipo de Texto',
        'nivel_lectura' => 'Nivel de Lectura',
        'parte' => 'Parte',
    ];

    /**
     * @return array<string,array{label:string,types:array<int,array{key:string,label:string}>,default_type:string}>
     */
    public static function catalogForUi(): array
    {
        $catalog = [];

        foreach (self::AREA_CONFIG as $areaKey => $config) {
            $typeItems = [];
            foreach ($config['types'] as $type) {
                $typeItems[] = [
                    'key' => $type,
                    'label' => self::labelForType($type),
                ];
            }

            $catalog[$areaKey] = [
                'label' => $config['label'],
                'types' => $typeItems,
                'default_type' => $config['default_type'],
            ];
        }

        return $catalog;
    }

    /**
     * @return array<int,string>
     */
    public static function areaKeys(): array
    {
        return array_keys(self::AREA_CONFIG);
    }

    public static function normalizeAreaKey(?string $areaKey): string
    {
        if ($areaKey !== null && isset(self::AREA_CONFIG[$areaKey])) {
            return $areaKey;
        }

        return '__unclassified';
    }

    public static function defaultTypeForArea(string $areaKey): string
    {
        $key = self::normalizeAreaKey($areaKey);

        return self::AREA_CONFIG[$key]['default_type'];
    }

    public static function isValidTypeForArea(string $areaKey, string $type): bool
    {
        $key = self::normalizeAreaKey($areaKey);

        return in_array($type, self::AREA_CONFIG[$key]['types'], true);
    }

    public static function labelForArea(string $areaKey): string
    {
        $key = self::normalizeAreaKey($areaKey);

        return self::AREA_CONFIG[$key]['label'];
    }

    public static function labelForType(string $type): string
    {
        return self::TYPE_LABELS[$type] ?? ucfirst($type);
    }

    /**
     * @return array<int,string>
     */
    public static function typeKeysForArea(string $areaKey): array
    {
        $key = self::normalizeAreaKey($areaKey);

        return self::AREA_CONFIG[$key]['types'];
    }
}
