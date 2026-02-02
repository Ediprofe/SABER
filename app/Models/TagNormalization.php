<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TagNormalization extends Model
{
    use HasFactory;

    protected $fillable = [
        'tag_csv_name',
        'tag_system_name',
        'tag_type',
        'parent_area',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Scope para normalizaciones activas.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Buscar normalización por nombre de CSV.
     */
    public static function findByCsvName(string $csvName): ?self
    {
        return self::active()
            ->where('tag_csv_name', $csvName)
            ->first();
    }

    /**
     * Crear o actualizar normalización.
     */
    public static function storeNormalization(array $data): self
    {
        return self::updateOrCreate(
            ['tag_csv_name' => $data['tag_csv_name']],
            [
                'tag_system_name' => $data['tag_system_name'],
                'tag_type' => $data['tag_type'],
                'parent_area' => $data['parent_area'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]
        );
    }
}
