<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    use HasFactory;

    protected $fillable = [
        'academic_year_id',
        'name',
        'type',
        'date',
    ];

    protected $casts = [
        'type' => 'string',
        'date' => 'date',
    ];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function examResults(): HasMany
    {
        return $this->hasMany(ExamResult::class);
    }

    public function areaConfigs(): HasMany
    {
        return $this->hasMany(ExamAreaConfig::class);
    }

    /**
     * Check if this exam has detailed configuration for a specific area.
     */
    public function hasDetailConfig(?string $area = null): bool
    {
        if ($area === null) {
            return $this->areaConfigs()->exists();
        }

        return $this->areaConfigs()->where('area', $area)->exists();
    }

    /**
     * Get the detailed configuration for a specific area.
     */
    public function getDetailConfig(string $area): ?ExamAreaConfig
    {
        return $this->areaConfigs()->where('area', $area)->first();
    }
}
