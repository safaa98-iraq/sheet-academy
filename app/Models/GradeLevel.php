<?php

namespace App\Models;

use Database\Factories\GradeLevelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class GradeLevel extends Model
{
    /** @use HasFactory<GradeLevelFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = ['name', 'position'];

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }
}
