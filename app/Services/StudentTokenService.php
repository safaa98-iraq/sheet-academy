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

            do {
                $plainTextToken = $this->generateToken();
            } while (StudentToken::where('token_hash', hash('sha256', $plainTextToken))->exists());
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

    private function generateToken(): string
    {
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $symbols = '!@#$%*+-_';
        $alphabet = $letters.$digits.$symbols;
        $characters = [$letters[random_int(0, strlen($letters) - 1)], $digits[random_int(0, strlen($digits) - 1)], $symbols[random_int(0, strlen($symbols) - 1)]];
        for ($index = 3; $index < 8; $index++) {
            $characters[] = $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        for ($index = 7; $index > 0; $index--) {
            $other = random_int(0, $index);
            [$characters[$index], $characters[$other]] = [$characters[$other], $characters[$index]];
        }

        return implode('', $characters);
    }
}
