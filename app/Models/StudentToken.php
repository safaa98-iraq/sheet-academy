<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentToken extends Model
{
    use HasFactory;

    protected $fillable = ['student_id', 'encrypted_token', 'token_hash', 'status', 'device_limit', 'expires_at', 'last_used_at', 'created_by'];

    protected $hidden = ['token_hash', 'encrypted_token'];

    protected function casts(): array
    {
        return ['encrypted_token' => 'encrypted', 'device_limit' => 'integer', 'expires_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
