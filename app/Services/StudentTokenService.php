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
        $deviceLimit = 1;

        return DB::transaction(function () use ($student, $actor, $expiresAt, $deviceLimit): array {
            $student->newQuery()->whereKey($student->id)->lockForUpdate()->firstOrFail();
            $student->tokens()->whereIn('status', ['active', 'frozen', 'suspended'])->update(['status' => 'revoked']);
            $student->devices()->whereNull('revoked_at')->update(['revoked_at' => now(), 'view_link_hash' => null]);

            $plainTextToken = bin2hex(random_bytes(32));
            $record = $student->tokens()->create([
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
