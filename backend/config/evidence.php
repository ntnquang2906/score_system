<?php

// Tự kiểm tra tệp minh chứng (xem app/Domain/Evidence).
return [
    // Tắt => chấm điểm như cũ, chỉ theo số liệu kê khai
    'enabled' => (bool) env('EVIDENCE_CHECK_ENABLED', false),

    // Dịch vụ đọc tệp (docker/evidence-ai), chỉ trong mạng nội bộ
    'analyzer_url' => env('EVIDENCE_ANALYZER_URL', 'http://evidence-ai:8000'),
    'analyzer_timeout' => (int) env('EVIDENCE_ANALYZER_TIMEOUT', 600),

    // Hàng đợi riêng (đọc tệp chậm), chạy bởi container evidence-worker
    'queue' => 'evidence',
];
