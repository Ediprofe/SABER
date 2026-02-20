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
        'sessions_count',
    ];

    protected $casts = [
        'type' => 'string',
        'date' => 'date',
        'sessions_count' => 'integer',
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

    public function sessions(): HasMany
    {
        return $this->hasMany(ExamSession::class);
    }

    public function getSession(int $number): ?ExamSession
    {
        return $this->sessions()->where('session_number', $number)->first();
    }

    public function hasSessions(): bool
    {
        return $this->sessions()->exists();
    }

    public function getConfiguredSessionNumbers(): array
    {
        $count = max(1, min(2, (int) ($this->sessions_count ?: 2)));

        return range(1, $count);
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
