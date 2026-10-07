<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentDevice extends Model
{
    protected $fillable = ['student_id', 'student_token_id', 'device_name', 'ip_address', 'user_agent', 'session_id', 'view_link_hash', 'last_seen_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(StudentToken::class, 'student_token_id');
    }
}
