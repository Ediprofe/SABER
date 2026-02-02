<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionTag extends Model
{
    use HasFactory;

    protected $fillable = ['exam_question_id', 'tag_hierarchy_id', 'inferred_area'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(ExamQuestion::class, 'exam_question_id');
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(TagHierarchy::class, 'tag_hierarchy_id');
    }

    public function isAreaTag(): bool
    {
        return $this->tag?->isArea() ?? false;
    }
}
