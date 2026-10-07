<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StudentTokenService
{
    /** @return array{token: string, record: StudentToken} */
    public function issue(Student $student, ?User $actor = null, ?Carbon $expiresAt = null, ?int $deviceLimit = null): array
    {
        $deviceLimit = max(1, min(10, $deviceLimit ?? (int) config('audit.device_limit', 1)));

        return DB::transaction(function () use ($student, $actor, $expiresAt, $deviceLimit): array {
            $student->tokens()->where('status', 'active')->update(['status' => 'revoked']);

            $plainTextToken = bin2hex(random_bytes(32));
            $record = $student->tokens()->create([
                'encrypted_token' => $plainTextToken,
                'token_hash' => hash('sha256', $plainTextToken),
                'status' => 'active',
                'device_limit' => $deviceLimit,
                'expires_at' => $expiresAt,
                'created_by' => $actor?->id,
            ]);

            return ['token' => $plainTextToken, 'record' => $record];
        });
    }
}
