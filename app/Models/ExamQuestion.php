<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamQuestion extends Model
{
    use HasFactory;

    protected $fillable = ['exam_session_id', 'question_number'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(ExamSession::class, 'exam_session_id');
    }

    public function questionTags(): HasMany
    {
        return $this->hasMany(QuestionTag::class, 'exam_question_id');
    }

    public function tags()
    {
        return $this->belongsToMany(TagHierarchy::class, 'question_tags', 'exam_question_id', 'tag_hierarchy_id')
            ->withPivot('inferred_area');
    }

    public function studentAnswers(): HasMany
    {
        return $this->hasMany(StudentAnswer::class, 'exam_question_id');
    }

    public function hasTag(string $tagName): bool
    {
        return $this->tags()->where('tag_name', $tagName)->exists();
    }

    public function getAreaTag(): ?TagHierarchy
    {
        return $this->tags()->where('tag_type', 'area')->first();
    }
}
