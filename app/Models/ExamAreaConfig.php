<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamAreaConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_id',
        'area',
        'dimension1_name',
        'dimension2_name',
    ];

    protected $casts = [
        'area' => 'string',
        'dimension1_name' => 'string',
        'dimension2_name' => 'string',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExamAreaItem::class, 'exam_area_config_id')->orderBy('dimension')->orderBy('order');
    }

    public function itemsDimension1(): HasMany
    {
        return $this->hasMany(ExamAreaItem::class, 'exam_area_config_id')->where('dimension', 1)->orderBy('order');
    }

    public function itemsDimension2(): HasMany
    {
        return $this->hasMany(ExamAreaItem::class, 'exam_area_config_id')->where('dimension', 2)->orderBy('order');
    }

    /**
     * Get area label in Spanish.
     */
    public function getAreaLabelAttribute(): string
    {
        return match ($this->area) {
            'lectura' => 'Lectura Crítica',
            'matematicas' => 'Matemáticas',
            'sociales' => 'Ciencias Sociales',
            'naturales' => 'Ciencias Naturales',
            'ingles' => 'Inglés',
            default => $this->area,
        };
    }

    /**
     * Check if this area has dimension 2 configured.
     */
    public function hasDimension2(): bool
    {
        return $this->dimension2_name !== null;
    }
}
