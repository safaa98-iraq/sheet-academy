<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>تقرير تقدم الطلاب</title>
    <style>
        body { color: #17212b; font-family: Arial, sans-serif; margin: 24px; }
        h1 { margin-bottom: 6px; }
        p { color: #52606d; }
        table { border-collapse: collapse; margin-top: 20px; width: 100%; }
        th, td { border: 1px solid #cbd2d9; padding: 9px; text-align: right; }
        th { background: #f1f4f6; }
        .print-actions { background: #f1f4f6; padding: 16px; border-radius: 8px; }
        .print-actions button { padding: 10px 16px; cursor: pointer; }
        td { overflow-wrap: anywhere; }
        @page { size: A4 landscape; margin: 12mm; }
        @media print { .print-actions { display: none; } body { margin: 0; font-size: 11px; } thead { display: table-header-group; } tr { break-inside: avoid; } }
    </style>
</head>
<body>
    <div class="print-actions">
        <p>١. راجع نتائج التقرير. ٢. اضغط طباعة / حفظ PDF. ٣. اختر الطابعة أو «حفظ بصيغة PDF» من نافذة المتصفح ثم أكد.</p>
        <button type="button" data-print>طباعة / حفظ PDF</button>
        <a href="{{ route('admin.student-progress', request()->query()) }}">العودة إلى تقدم الطلاب</a>
    </div>
    <h1>تقرير تقدم الطلاب</h1>
    <p>تاريخ التقرير: {{ now()->timezone('Asia/Baghdad')->format('Y-m-d H:i') }} · بتوقيت بغداد</p>
    <table>
        <thead>
            <tr><th>الطالب</th><th>البريد</th><th>عدد المواد</th><th>الدروس المكتملة</th><th>نسبة الإكمال</th><th>وقت المشاهدة</th><th>آخر نشاط</th></tr>
        </thead>
        <tbody>
            @forelse ($students as $student)
                <tr>
                    <td>{{ $student->name }}</td>
                    <td>{{ $student->email }}</td>
                    <td>{{ $student->courses_count }}</td>
                    <td>{{ $student->completed_lessons_count }}</td>
                    <td>{{ $student->available_duration_seconds > 0 ? (int) round($student->progress_score / $student->available_duration_seconds * 100) : 0 }}٪</td>
                    <td>{{ gmdate('H:i:s', (int) $student->watched_seconds_total) }}</td>
                    <td>{{ $student->last_learning_at ? \Illuminate\Support\Carbon::parse($student->last_learning_at)->format('Y-m-d H:i') : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="7">لا توجد نتائج.</td></tr>
            @endforelse
        </tbody>
    </table>
    <script nonce="{{ $cspNonce ?? '' }}">
        document.querySelector('[data-print]').addEventListener('click', () => window.print());
    </script>
</body>
</html>
