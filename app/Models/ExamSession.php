<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExamSession extends Model
{
    use HasFactory;

    protected $fillable = ['exam_id', 'session_number', 'name', 'zipgrade_quiz_name', 'total_questions'];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function zipgradeImport(): HasOne
    {
        return $this->hasOne(ZipgradeImport::class, 'exam_session_id');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(ZipgradeImport::class, 'exam_session_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ExamQuestion::class, 'exam_session_id');
    }

    public function getDisplayNameAttribute(): string
    {
        return "Sesión {$this->session_number}";
    }
}
