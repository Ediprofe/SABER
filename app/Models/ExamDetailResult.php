<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExamDetailResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_result_id',
        'exam_area_item_id',
        'score',
    ];

    protected $casts = [
        'score' => 'integer',
    ];

    public function examResult(): BelongsTo
    {
        return $this->belongsTo(ExamResult::class, 'exam_result_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ExamAreaItem::class, 'exam_area_item_id');
    }
}
