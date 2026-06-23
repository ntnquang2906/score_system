<?php
// Danh sách tài khoản cho lãnh đạo/quản trị viên
// role = editor: được xem, tải và sửa file kết quả
// role = viewer: chỉ xem và tải, không được sửa

$accounts = [
    'admin' => [
        'password' => 'dhqg2026',
        'role' => 'editor'
    ],
    'Tuttt' => [
        'password' => 'Tuvnu2026',
        'role' => 'editor'
    ],
    'Sonpb' => [
        'password' => 'Sonvnu2026',
        'role' => 'editor'
    ],
    'Dungtt' => [
        'password' => 'Dungvnu2026',
        'role' => 'editor'
    ]
];

// Nếu thêm tài khoản mới sau này:
// 'username' => [
//     'password' => 'matkhau',
//     'role' => 'viewer'
// ];