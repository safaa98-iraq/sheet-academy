<?php

return [
    'pdfinfo' => env('PDFINFO_BINARY', 'pdfinfo'),
    'pdftoppm' => env('PDFTOPPM_BINARY', 'pdftoppm'),
    'max_pages' => (int) env('DOCUMENT_MAX_PAGES', 60),
    'temporary_hours' => (int) env('DOCUMENT_PAGE_TTL_HOURS', 24),
];
