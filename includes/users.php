<?php
// Thư viện dùng chung cho tài khoản đơn vị tự đăng ký (register.php, login.php,
// unit_dashboard.php, view_file.php). Lưu trữ tại data/users.json.

require_once __DIR__ . '/vietnamese.php';

define('USERS_DATA_DIR', dirname(__DIR__) . '/data');
define('USERS_DATA_FILE', USERS_DATA_DIR . '/users.json');

function ensureUsersDataDir()
{
    if (!is_dir(USERS_DATA_DIR)) {
        mkdir(USERS_DATA_DIR, 0775, true);
    }
}

function loadUsers()
{
    ensureUsersDataDir();

    if (!file_exists(USERS_DATA_FILE)) {
        return [];
    }

    $data = json_decode(file_get_contents(USERS_DATA_FILE), true);

    return is_array($data) ? $data : [];
}

function saveUsers($users)
{
    ensureUsersDataDir();

    return file_put_contents(
        USERS_DATA_FILE,
        json_encode($users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    ) !== false;
}

// (Việc bỏ dấu tiếng Việt dùng chung hàm stripVietnameseDiacritics() trong
// includes/vietnamese.php, được require ở đầu file.)

// Cụm định danh đi kèm cần loại bỏ khi nhận diện tên đơn vị (dạng đã bỏ dấu,
// chữ thường, chỉ còn chữ/số/khoảng trắng - dùng để so sánh).
function unitAffiliationPhrases()
{
    return [
        'dai hoc quoc gia ha noi',
        'dai hoc quoc gia thanh pho ho chi minh',
        'dai hoc quoc gia tp ho chi minh',
        'dai hoc quoc gia tp hcm',
        'dhqghn',
        'dhqg tp hcm',
        'dhqg hcm',
        'dhqg',
        'vnu ha noi',
        'vnu hn',
        'vnu hcm',
        'vnu',
    ];
}

function normalizeForAffiliationCompare($text)
{
    $text = stripVietnameseDiacritics($text);
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/u', ' ', $text);

    return trim($text);
}

// Tách chuỗi theo dấu phẩy/gạch ngang/ngoặc đơn và loại bỏ các đoạn khớp với
// cụm định danh đi kèm (vd "Đại học Quốc gia Hà Nội"), giữ nguyên dấu/hoa
// thường của phần còn lại để làm tên hiển thị đẹp.
function stripUnitAffiliationSegments($text)
{
    $affiliationPhrases = unitAffiliationPhrases();
    $segments = preg_split('/[,\-–()]+/u', $text);
    $kept = [];

    foreach ($segments as $segment) {
        $segment = trim($segment);

        if ($segment === '') {
            continue;
        }

        if (in_array(normalizeForAffiliationCompare($segment), $affiliationPhrases, true)) {
            continue;
        }

        $kept[] = $segment;
    }

    $result = trim(implode(', ', $kept));

    return $result !== '' ? $result : trim($text);
}

// Cắt cụm định danh đi kèm khi nó bị nối liền bằng khoảng trắng/gạch dưới,
// không có dấu phẩy phân tách (áp dụng cho cả tên nhập tay lẫn tên đơn vị đã
// tách ra từ tên file kết quả cũ, vốn đã ở dạng ASCII nối bằng dấu gạch dưới).
function stripGluedUnitAffiliation($text)
{
    $alternatives = [];

    foreach (unitAffiliationPhrases() as $phrase) {
        $alternatives[] = preg_replace('/\s+/', '[ _]+', preg_quote($phrase, '/'));
    }

    $pattern = '/[ _]+(' . implode('|', $alternatives) . ')[ _]*$/iu';

    while (true) {
        $next = preg_replace($pattern, '', $text);

        if ($next === $text) {
            break;
        }

        $text = trim($next);
    }

    return $text;
}

// Chuẩn hoá tên đơn vị (có dấu, có thể kèm cụm affiliation) thành key so khớp
// dạng "Vien_Cong_nghe_thong_tin". Dùng cho cả tên nhập lúc đăng ký lẫn phần
// tên đơn vị tách được từ tên file kết quả (đã là ASCII/underscore).
function normalizeUnitKey($text)
{
    $text = trim($text);
    $text = stripUnitAffiliationSegments($text);
    $text = stripVietnameseDiacritics($text);
    $text = stripGluedUnitAffiliation($text);

    $text = preg_replace('/[\/\\\\:\*\?"<>\|]+/u', '_', $text);
    $text = preg_replace('/[\s\-,;]+/u', '_', $text);
    $text = preg_replace('/[^A-Za-z0-9_.]+/u', '_', $text);
    $text = preg_replace('/_+/u', '_', $text);

    return trim($text, '._');
}

// Tên hiển thị đẹp (giữ dấu tiếng Việt) sau khi cắt cụm affiliation - dùng để
// lưu lại cho tài khoản đơn vị hiển thị trên giao diện.
function cleanUnitDisplayName($text)
{
    $text = trim($text);
    $text = stripUnitAffiliationSegments($text);

    return trim($text, " \t\n\r\0\x0B,-–");
}

function unitKeyExists($unitKey, $users)
{
    foreach ($users as $user) {
        if (($user['unit_key'] ?? '') === $unitKey) {
            return true;
        }
    }

    return false;
}

function usernameTaken($username, $users, $accounts = [])
{
    $usernameLower = strtolower($username);

    foreach (array_keys($users) as $existing) {
        if (strtolower($existing) === $usernameLower) {
            return true;
        }
    }

    foreach (array_keys($accounts) as $existing) {
        if (strtolower($existing) === $usernameLower) {
            return true;
        }
    }

    return false;
}
