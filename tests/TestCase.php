<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected function loginStudent(string $token): TestResponse
    {
        $response = $this->post('/login', ['token' => $token]);
        if ($response->headers->get('Location') === route('student.agreement.show')) {
            $this->post(route('student.agreement.accept'), ['accepted' => true])->assertRedirect(route('student.learning'));
        }

        return $response;
    }

    protected function issueStudentViewLink(string $targetType, int $targetId): string
    {
        return $this->postJson(route('student.view-links.issue'), [
            'target_type' => $targetType,
            'target_id' => $targetId,
        ])->assertOk()->json('url');
    }
}
