<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportGeneration extends Model
{
    protected $fillable = [
        'exam_id',
        'type',
        'status',
        'total_students',
        'processed_students',
        'file_path',
        'error_message',
    ];

    protected $casts = [
        'total_students' => 'integer',
        'processed_students' => 'integer',
    ];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function getProgressPercentAttribute(): int
    {
        if ($this->total_students === 0) {
            return 0;
        }
        return (int) round(($this->processed_students / $this->total_students) * 100);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }
}
