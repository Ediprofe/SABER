<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_id',
        'enrollment_id',
        'lectura',
        'matematicas',
        'sociales',
        'naturales',
        'ingles',
        'global_score',
    ];

    protected $casts = [
        'lectura' => 'integer',
        'matematicas' => 'integer',
        'sociales' => 'integer',
        'naturales' => 'integer',
        'ingles' => 'integer',
        'global_score' => 'integer',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    protected static function booted(): void
    {
        static::saving(function ($result) {
            // Calculate global score automatically
            $lectura = $result->lectura ?? 0;
            $matematicas = $result->matematicas ?? 0;
            $sociales = $result->sociales ?? 0;
            $naturales = $result->naturales ?? 0;
            $ingles = $result->ingles ?? 0;

            $result->global_score = round((($lectura + $matematicas + $sociales + $naturales) * 3 + $ingles) / 13 * 5);
        });
    }
}
