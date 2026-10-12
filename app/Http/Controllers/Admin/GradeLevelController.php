<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreGradeLevelRequest;
use App\Models\GradeLevel;
use App\Services\ContentDeletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GradeLevelController extends Controller
{
    public function index(): View
    {
        return view('admin.grade-levels.index', ['gradeLevels' => GradeLevel::withCount('courses')->orderBy('position')->orderBy('id')->get()]);
    }

    public function create(): View
    {
        return view('admin.grade-levels.form', ['gradeLevel' => new GradeLevel(['position' => (int) GradeLevel::max('position') + 1])]);
    }

    public function store(StoreGradeLevelRequest $request): RedirectResponse
    {
        GradeLevel::create($request->validated());

        return redirect()->route('admin.grade-levels.index')->with('status', 'تم إنشاء المرحلة الصفية. يمكنك الآن إضافة موادها.');
    }

    public function edit(GradeLevel $gradeLevel): View
    {
        return view('admin.grade-levels.form', compact('gradeLevel'));
    }

    public function update(StoreGradeLevelRequest $request, GradeLevel $gradeLevel): RedirectResponse
    {
        $gradeLevel->update($request->validated());

        return redirect()->route('admin.grade-levels.index')->with('status', 'تم حفظ المرحلة الصفية.');
    }

    public function destroy(Request $request, GradeLevel $gradeLevel, ContentDeletionService $deletion): RedirectResponse
    {
        $deletion->delete($request, $gradeLevel);

        return redirect()->route('admin.grade-levels.index')->with('status', 'أُرشف الصف ومواده ودروسه وحُجب محتواه عن الطلاب. بقيت حساباتهم وتقدّمهم محفوظين.');
    }
}
