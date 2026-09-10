# Hệ thống đánh giá tổ chức KH&CN (score_system)

Ứng dụng web PHP thuần (không framework) dùng để thu thập, chấm điểm tự động và quản lý kết quả đánh giá các tổ chức khoa học & công nghệ theo bộ tiêu chí có trọng số. Hỗ trợ song ngữ Việt/Anh, có PWA (cài trên điện thoại như app) và một bản đóng gói Android bằng Capacitor.

## 1. Tổng quan chức năng

- **Form đánh giá công khai** (`index.php`): người dùng nhập tên tổ chức, chọn các nhóm chức năng (`basic`, `applied`, `tech`, `policy`), trả lời bộ câu hỏi tương ứng và upload minh chứng (file).
- **Chấm điểm tự động** (`process.php`): đọc bộ tiêu chí từ `criteria.json`, tính điểm từng câu hỏi (fixed score, theo khoảng giá trị, phạt liêm chính…), tổng hợp Đt1–Đt4, ĐT, điểm quy đổi theo trọng số, tổng điểm E và xếp loại. Sau khi nộp, hệ thống xuất file kết quả chi tiết dạng `.tsv` vào thư mục `results/` và cho tải về.
- **Đăng nhập quản trị** (`login.php` / `logout.php`): tài khoản định nghĩa trong `credentials.php`, có 2 vai trò `editor` (xem/sửa/tải) và `viewer` (chỉ xem/tải).
- **Dashboard quản trị** (`dashboard.php`): liệt kê các file kết quả trong `results/`, xem tổng hợp `results.tsv`.
- **Xem / sửa file kết quả** (`view_file.php`, `edit_file.php`): xem nội dung `.tsv`, đổi tên file; sửa nội dung chỉ dành cho role `editor`.
- **Đa ngôn ngữ**: `lang.php` + `lang/vi.php`, `lang/en.php`, chuyển ngôn ngữ qua `?lang=vi|en`, lưu cookie `site_lang`.
- **Logging hệ thống** (`logger.php`): ghi log JSON theo dòng vào `logs/system.log`, tự động rotate khi > 20MB, giữ tối đa 10 file log cũ; đồng thời có hook bắt lỗi PHP (`set_error_handler`, `register_shutdown_function`).
- **PWA**: `manifest.json`, `sw.js`, `pwa-register.js` giúp cài app trên di động/desktop.
- **Ứng dụng di động (`mobile-app/`)**: dự án Capacitor đóng gói giao diện web thành app Android, hiện đang trỏ tới server nội bộ qua `capacitor.config.json` (`server.url`).

## 2. Cấu trúc thư mục

```
score_system/
├── index.php           # Form đánh giá công khai
├── process.php         # Xử lý chấm điểm + xuất kết quả .tsv
├── login.php / logout.php
├── credentials.php     # Danh sách tài khoản quản trị (username/password/role)
├── dashboard.php        # Trang quản trị: danh sách kết quả
├── view_file.php        # Xem chi tiết 1 file kết quả
├── edit_file.php        # Sửa nội dung file kết quả (role editor)
├── logger.php            # Ghi log hệ thống
├── lang.php + lang/      # Đa ngôn ngữ (vi.php, en.php)
├── criteria.json         # Bộ tiêu chí, thang điểm cho từng chức năng
├── manifest.json, sw.js, pwa-register.js   # Cấu hình PWA
├── script.js, style.css, i18n.css          # Frontend
├── php.ini                # Cấu hình PHP khuyến nghị (upload/post size, timeout)
├── icons/                 # Icon PWA
├── mobile-app/            # Dự án Capacitor (bản Android)
├── results/                # (tạo khi chạy) chứa file .tsv kết quả — không commit
└── logs/                   # (tạo khi chạy) log hệ thống — không commit
```

## 3. Yêu cầu môi trường

- PHP **7.4+** (khuyến nghị 8.x), có sẵn extension mặc định (không cần Composer/DB — dự án không dùng database, lưu kết quả trực tiếp ra file `.tsv`).
- Web server: Apache/Nginx, hoặc dùng PHP built-in server để chạy nhanh khi phát triển.
- Không cần Node.js để chạy phần web chính; Node/npm chỉ cần nếu build lại phần `mobile-app/` (Capacitor).

## 4. Chạy nhanh (development)

```bash
git clone https://github.com/ntnquang2906/score_system.git
cd score_system

# copy cấu hình PHP khuyến nghị (tăng giới hạn upload/post size, timeout)
# nếu dùng PHP built-in server, trỏ trực tiếp tới file này:
php -c php.ini -S 0.0.0.0:6868
```

> Port `6868` là port đang được dùng thực tế (xem `mobile-app/capacitor.config.json` → `server.url: http://192.168.88.13:6868`, bản Android trỏ vào đúng port này để load giao diện web). Nếu đổi port khi chạy dev, nhớ cập nhật lại `capacitor.config.json` tương ứng để app mobile không mất kết nối.

Sau đó mở:

- `http://localhost:6868/index.php` (hoặc `http://<IP-máy>:6868/index.php` nếu truy cập từ thiết bị khác trong mạng LAN, ví dụ từ app Android) — form đánh giá
- `http://localhost:6868/login.php` — đăng nhập quản trị (tài khoản xem tại `credentials.php`)

> Lưu ý: thư mục `results/` và `logs/` sẽ được ứng dụng tự tạo (`mkdir`) khi có lượt nộp form/ghi log đầu tiên, nếu process PHP có quyền ghi vào thư mục gốc dự án.

## 5. Triển khai production (Apache/Nginx + PHP-FPM)

1. Đặt toàn bộ mã nguồn vào document root (ví dụ `/var/www/score_system`).
2. Áp dụng các giá trị trong `php.ini` vào cấu hình PHP thực tế (`/etc/php/*/apache2/php.ini` hoặc `php.ini` riêng của pool PHP-FPM), quan trọng nhất là:
   - `upload_max_filesize`, `post_max_size` — đủ lớn cho file minh chứng đính kèm.
   - `max_execution_time`, `max_input_time` — tránh timeout khi form nhiều câu hỏi/upload nhiều file.
3. Cấp quyền ghi (`www-data` hoặc user chạy PHP) cho thư mục gốc dự án để PHP tự tạo được `results/`, `logs/`, và các thư mục upload minh chứng.
4. Bật HTTPS ở tầng web server (Let's Encrypt/Nginx reverse proxy) — ứng dụng dùng session PHP thuần cho đăng nhập, nên cần HTTPS để bảo vệ cookie phiên.
5. Không public trực tiếp thư mục `results/`, `logs/` qua web server nếu không muốn lộ dữ liệu (chặn bằng rule `.htaccess`/`location` block, hoặc để ngoài document root nếu chỉnh lại đường dẫn trong code).

## 6. Tài khoản quản trị

Định nghĩa cứng trong `credentials.php` (username, password dạng plain text, role `editor`/`viewer`). Thêm tài khoản mới bằng cách thêm phần tử vào mảng `$accounts`.

⚠️ **Lưu ý bảo mật quan trọng**: mật khẩu hiện đang lưu dạng plain text ngay trong file PHP nằm trong repo Git. Trước khi đưa vào production hoặc public repo, nên:
- Đổi toàn bộ mật khẩu hiện tại (đã lộ trong lịch sử Git).
- Chuyển sang lưu mật khẩu đã hash (`password_hash`/`password_verify`) thay vì so sánh chuỗi trực tiếp.
- Thêm `credentials.php` vào `.gitignore` và chỉ deploy file này thủ công lên server (tương tự cách `.env` đang được ignore).

## 7. Chỉnh sửa bộ tiêu chí chấm điểm

Toàn bộ câu hỏi, thang điểm, trọng số nằm trong `criteria.json` (nhóm theo `functions.basic / applied / tech / policy`). Sửa file này để thêm/bớt câu hỏi hoặc đổi công thức tính điểm (`fixed`, theo khoảng giá trị, phạt liêm chính…) mà không cần sửa code PHP, miễn giữ đúng cấu trúc trường dữ liệu mà `process.php` (hàm `calculateQuestionScore`, `scoreByRanges`…) đang đọc.

## 8. Build bản Android (tuỳ chọn)

```bash
cd mobile-app
npm install
npx cap sync android
npx cap open android   # mở bằng Android Studio để build/run
```

Trước khi build, cập nhật `capacitor.config.json` → `server.url` trỏ tới địa chỉ server thật (đang để mặc định là một IP nội bộ dùng khi phát triển).

## 9. Ghi chú khác

- Không có bộ test tự động trong repo hiện tại.
- File kết quả `.tsv` được ghi UTF-8 kèm BOM để mở đúng dấu tiếng Việt trên Excel.
- Log hệ thống ghi dạng JSON-line vào `logs/system.log`, tự rotate khi vượt 20MB và giữ tối đa 10 bản backup gần nhất.
