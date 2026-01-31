<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamAreaItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_area_config_id',
        'dimension',
        'name',
        'order',
    ];

    protected $casts = [
        'dimension' => 'integer',
        'name' => 'string',
        'order' => 'integer',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(ExamAreaConfig::class, 'exam_area_config_id');
    }

    public function detailResults(): HasMany
    {
        return $this->hasMany(ExamDetailResult::class, 'exam_area_item_id');
    }

    /**
     * Get the slug for column naming.
     */
    public function getSlugAttribute(): string
    {
        return str_replace(' ', '_', strtolower($this->name));
    }

    /**
     * Get the column name for Excel exports/imports.
     */
    public function getColumnNameAttribute(): string
    {
        $areaPrefix = match ($this->config->area) {
            'lectura' => 'lec',
            'matematicas' => 'mat',
            'sociales' => 'soc',
            'naturales' => 'nat',
            'ingles' => 'ing',
            default => 'unk',
        };

        $dimensionPrefix = match ($this->config->dimension1_name) {
            'Competencias' => 'comp',
            'Partes' => 'part',
            default => $this->dimension === 1 ? 'dim1' : 'dim2',
        };

        // For dimension 2, check dimension2_name
        if ($this->dimension === 2) {
            $dimensionPrefix = match ($this->config->dimension2_name) {
                'Componentes' => 'cmpn',
                'Tipos de Texto' => 'txt',
                default => 'dim2',
            };
        }

        return "{$areaPrefix}_{$dimensionPrefix}_{$this->slug}";
    }
}
