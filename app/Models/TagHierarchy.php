<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TagHierarchy extends Model
{
    use HasFactory;

    protected $table = 'tag_hierarchy';

    protected $fillable = ['tag_name', 'tag_type', 'parent_area'];

    public function questionTags(): HasMany
    {
        return $this->hasMany(QuestionTag::class, 'tag_hierarchy_id');
    }

    public function isArea(): bool
    {
        return $this->tag_type === 'area';
    }

    public function isCompetencia(): bool
    {
        return $this->tag_type === 'competencia';
    }

    public function isComponente(): bool
    {
        return $this->tag_type === 'componente';
    }

    public function isTipoTexto(): bool
    {
        return $this->tag_type === 'tipo_texto';
    }

    public function isParte(): bool
    {
        return $this->tag_type === 'parte';
    }
}
