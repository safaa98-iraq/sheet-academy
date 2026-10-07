<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StudentAgreementController extends Controller
{
    public function show(): View
    {
        return view('student.agreement');
    }

    public function accept(Request $request): RedirectResponse
    {
        $request->validate(['accepted' => ['required', 'accepted']], ['accepted.accepted' => 'يجب الموافقة على التعهد لمتابعة المحتوى.']);
        $request->user('student')->forceFill([
            'content_agreement_accepted_at' => now(), 'content_agreement_version' => config('audit.agreement_version'),
        ])->save();

        return redirect()->intended(route('student.learning'))->with('status', 'تم تسجيل موافقتك.');
    }
}
