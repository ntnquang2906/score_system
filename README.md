# Hệ thống đánh giá tổ chức KH&CN (score_system)

Ứng dụng web PHP thuần (không framework, không database) dùng để thu thập, chấm điểm tự động và quản lý kết quả đánh giá các tổ chức khoa học & công nghệ theo bộ tiêu chí có trọng số. Hỗ trợ song ngữ Việt/Anh, phân quyền theo tài khoản (lãnh đạo/quản trị xem toàn bộ, đơn vị tự đăng ký chỉ xem dữ liệu của mình), có PWA (cài trên điện thoại như app) và một bản đóng gói Android bằng Capacitor.

## 1. Tổng quan chức năng

- **Form đánh giá công khai** (`index.php`): người dùng nhập tên tổ chức, chọn các nhóm chức năng (`basic`, `applied`, `tech`, `policy`), trả lời bộ câu hỏi tương ứng và upload minh chứng (file). Không cần đăng nhập để nộp form.
- **Chấm điểm tự động** (`process.php`): đọc bộ tiêu chí từ `criteria.json`, tính điểm từng câu hỏi (fixed score, theo khoảng giá trị, phạt liêm chính…), tổng hợp Đt1–Đt4, ĐT, điểm quy đổi theo trọng số, tổng điểm E và xếp loại. Sau khi nộp, hệ thống hiển thị trang báo cáo kết quả (huy hiệu xếp loại theo màu, thanh điểm, giải thích chi tiết từng câu hỏi) và ghi 2 file vào `results/`: bản `.tsv` (lưu trữ nội bộ, dùng cho dashboard/sửa file) và bản **`.xlsx` thật** để người điền tải về mở bằng Excel không lo lỗi font tiếng Việt.
- **Đăng nhập chung 1 cổng** (`login.php` / `logout.php`): cùng 1 form đăng nhập cho cả 2 loại tài khoản, hệ thống tự nhận diện:
  - **Tài khoản lãnh đạo/quản trị** — định nghĩa cứng trong `includes/credentials.php`, có 2 vai trò `editor` (xem/sửa/tải toàn bộ) và `viewer` (chỉ xem/tải toàn bộ) → vào `dashboard.php`.
  - **Tài khoản đơn vị** — tự đăng ký qua `register.php`, chỉ xem được kết quả của chính đơn vị mình → vào `unit_dashboard.php`.
- **Đăng ký tài khoản đơn vị** (`register.php`): đơn vị nhập tên đăng nhập, mật khẩu và tên đơn vị (có dấu, có thể kèm cụm như "Đại học Quốc gia Hà Nội"/"VNU"). Hệ thống tự chuẩn hoá tên đơn vị (bỏ dấu, cắt cụm định danh đi kèm) thành một "khoá đơn vị" duy nhất — mỗi đơn vị chỉ được đăng ký **1 tài khoản**, đăng ký trùng đơn vị sẽ bị từ chối. Tài khoản lưu tại `data/users.json` (mật khẩu hash bằng `password_hash`).
- **Dashboard quản trị** (`dashboard.php`): liệt kê toàn bộ file kết quả trong `results/`, xem tổng hợp `results.tsv`.
- **Dashboard đơn vị** (`unit_dashboard.php`): chỉ liệt kê các lần đơn vị đang đăng nhập đã nộp (đối chiếu theo khoá đơn vị, kể cả các file cũ trước khi có tính năng đăng ký).
- **Xem / sửa file kết quả** (`view_file.php`, `edit_file.php`): xem nội dung `.tsv`, đổi tên file; sửa nội dung chỉ dành cho role `editor`. Tài khoản đơn vị chỉ xem được file của chính mình (không xem được `results.tsv` tổng hợp hay file đơn vị khác), không có quyền sửa.
- **Đa ngôn ngữ**: `includes/lang.php` + `includes/lang/vi.php`, `includes/lang/en.php`, chuyển ngôn ngữ qua `?lang=vi|en`, lưu cookie `site_lang`.
- **Logging hệ thống** (`includes/logger.php`): ghi log JSON theo dòng vào `logs/system.log`, tự động rotate khi > 20MB, giữ tối đa 10 file log cũ; đồng thời có hook bắt lỗi PHP (`set_error_handler`, `register_shutdown_function`).
- **PWA**: `manifest.json`, `sw.js`, `pwa-register.js` giúp cài app trên di động/desktop.
- **Ứng dụng di động (`mobile-app/`)**: dự án Capacitor đóng gói giao diện web thành app Android, hiện đang trỏ tới server nội bộ qua `capacitor.config.json` (`server.url`).

## 2. Cấu trúc thư mục

```
score_system/
├── index.php              # Form đánh giá công khai
├── process.php            # Xử lý chấm điểm + trang báo cáo kết quả + xuất .tsv/.xlsx
├── login.php / logout.php # Đăng nhập chung (tự phân nhánh admin/đơn vị) / đăng xuất
├── register.php           # Đăng ký tài khoản đơn vị (tự động, chống đăng ký trùng)
├── dashboard.php          # Trang quản trị: toàn bộ kết quả
├── unit_dashboard.php     # Trang đơn vị: chỉ kết quả của đơn vị mình
├── view_file.php          # Xem chi tiết 1 file kết quả (có kiểm tra quyền theo đơn vị)
├── edit_file.php          # Sửa nội dung file kết quả (role editor)
├── log_event.php          # Endpoint nhận log từ frontend (script.js)
├── includes/              # Thư viện dùng chung — KHÔNG truy cập trực tiếp qua URL
│   ├── logger.php            # Ghi log hệ thống
│   ├── lang.php + lang/      # Đa ngôn ngữ (vi.php, en.php)
│   ├── credentials.php       # Danh sách tài khoản quản trị (username/password/role)
│   ├── users.php             # Tài khoản đơn vị: đọc/ghi data/users.json, chuẩn hoá tên đơn vị
│   ├── tsv.php               # Đọc/ghi file .tsv, phân tích tên file kết quả
│   ├── vietnamese.php        # Bỏ dấu tiếng Việt, chuẩn hoá chuỗi thành tên file an toàn
│   └── xlsx_writer.php       # Ghi file .xlsx thật (tự dựng ZIP bằng PHP lõi, không cần ext-zip)
├── criteria.json          # Bộ tiêu chí, thang điểm cho từng chức năng
├── manifest.json, sw.js, pwa-register.js   # Cấu hình PWA
├── script.js, style.css, i18n.css          # Frontend
├── php.ini                # Cấu hình PHP khuyến nghị (upload/post size, timeout)
├── icons/                 # Icon PWA
├── mobile-app/            # Dự án Capacitor (bản Android)
├── results/               # (tạo khi chạy) file .tsv + .xlsx kết quả — không commit
├── uploads/                # (tạo khi chạy) file minh chứng đính kèm theo form — không commit
├── data/                  # (tạo khi chạy) users.json — tài khoản đơn vị tự đăng ký — không commit
└── logs/                  # (tạo khi chạy) log hệ thống — không commit
```

## 3. Yêu cầu môi trường

- PHP **7.4+** (khuyến nghị 8.x), chỉ cần các extension mặc định — dự án **không dùng Composer, không dùng database**, không yêu cầu extension `mbstring` hay `zip` (file `.xlsx` được tự dựng bằng PHP lõi, xem `includes/xlsx_writer.php`).
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

- `http://localhost:6868/index.php` (hoặc `http://<IP-máy>:6868/index.php` nếu truy cập từ thiết bị khác trong mạng LAN, ví dụ từ app Android) — form đánh giá, có link **🔐 Đăng nhập** ở góc trên để vào khu quản trị/đơn vị
- `http://localhost:6868/login.php` — đăng nhập (dùng chung cho tài khoản lãnh đạo/quản trị lẫn tài khoản đơn vị)
- `http://localhost:6868/register.php` — đơn vị tự đăng ký tài khoản

> Lưu ý: các thư mục `results/`, `uploads/`, `logs/`, `data/` sẽ được ứng dụng tự tạo (`mkdir`) khi có lượt nộp form/đăng ký/ghi log đầu tiên, nếu process PHP có quyền ghi vào thư mục gốc dự án.

## 5. Triển khai production (Apache/Nginx + PHP-FPM)

1. Đặt toàn bộ mã nguồn vào document root (ví dụ `/var/www/score_system`).
2. Áp dụng các giá trị trong `php.ini` vào cấu hình PHP thực tế (`/etc/php/*/apache2/php.ini` hoặc `php.ini` riêng của pool PHP-FPM), quan trọng nhất là:
   - `upload_max_filesize`, `post_max_size` — đủ lớn cho file minh chứng đính kèm.
   - `max_execution_time`, `max_input_time` — tránh timeout khi form nhiều câu hỏi/upload nhiều file.
3. Cấp quyền ghi (`www-data` hoặc user chạy PHP) cho thư mục gốc dự án để PHP tự tạo được `results/`, `uploads/`, `logs/`, `data/`.
4. Bật HTTPS ở tầng web server (Let's Encrypt/Nginx reverse proxy) — ứng dụng dùng session PHP thuần cho đăng nhập, nên cần HTTPS để bảo vệ cookie phiên.
5. Không public trực tiếp thư mục `logs/` và `data/` qua web server (chặn bằng rule `.htaccess`/`location` block, hoặc để ngoài document root nếu chỉnh lại đường dẫn trong code) — `data/users.json` chứa mật khẩu đã hash của các tài khoản đơn vị, `logs/` có thể chứa thông tin nhạy cảm về hoạt động hệ thống. Thư mục `results/` cần public vì link tải kết quả trỏ trực tiếp vào đó.

## 6. Tài khoản quản trị (lãnh đạo/quản trị viên)

Định nghĩa cứng trong `includes/credentials.php` (username, password dạng plain text, role `editor`/`viewer`). Thêm tài khoản mới bằng cách thêm phần tử vào mảng `$accounts`.

⚠️ **Lưu ý bảo mật quan trọng**: mật khẩu hiện đang lưu dạng plain text ngay trong file PHP nằm trong repo Git. Trước khi đưa vào production hoặc public repo, nên:
- Đổi toàn bộ mật khẩu hiện tại (đã lộ trong lịch sử Git).
- Chuyển sang lưu mật khẩu đã hash (`password_hash`/`password_verify`) thay vì so sánh chuỗi trực tiếp — như cách `includes/users.php` đang làm cho tài khoản đơn vị.
- Thêm `includes/credentials.php` vào `.gitignore` và chỉ deploy file này thủ công lên server (tương tự cách `.env` đang được ignore).

## 7. Tài khoản đơn vị (tự đăng ký)

Đơn vị tự đăng ký qua `register.php`, không cần quản trị viên tạo trước. Quy tắc:

- Mỗi đơn vị chỉ được đăng ký **1 tài khoản** — hệ thống chuẩn hoá tên đơn vị đã nhập (bỏ dấu tiếng Việt, cắt cụm định danh đi kèm như "Đại học Quốc gia Hà Nội"/"ĐHQGHN"/"VNU") thành một khoá duy nhất để đối chiếu; nếu khoá đó đã tồn tại thì báo "đơn vị đã có tài khoản". Ví dụ: *"Viện Công nghệ thông tin, Đại học Quốc gia Hà Nội"* → khoá `Vien_Cong_nghe_thong_tin`.
- Khoá này cũng được dùng để đối chiếu với tên đơn vị đã lưu trong tên file kết quả (`results/{timestamp}_{TenDonVi}.tsv`), kể cả các file nộp từ trước khi có tính năng đăng ký, nên đăng nhập vào là thấy ngay lịch sử đã nộp.
- Tài khoản đơn vị chỉ xem/tải được kết quả của chính mình, không có quyền sửa và không xem được file tổng hợp `results.tsv`.
- Dữ liệu tài khoản lưu tại `data/users.json` (không commit git), mật khẩu được hash bằng `password_hash()`.

## 8. Chỉnh sửa bộ tiêu chí chấm điểm

Toàn bộ câu hỏi, thang điểm, trọng số nằm trong `criteria.json` (nhóm theo `functions.basic / applied / tech / policy`). Sửa file này để thêm/bớt câu hỏi hoặc đổi công thức tính điểm (`fixed`, theo khoảng giá trị, phạt liêm chính…) mà không cần sửa code PHP, miễn giữ đúng cấu trúc trường dữ liệu mà `process.php` (hàm `calculateQuestionScore`, `scoreByRanges`…) đang đọc.

## 9. Build bản Android (tuỳ chọn)

```bash
cd mobile-app
npm install
npx cap sync android
npx cap open android   # mở bằng Android Studio để build/run
```

Trước khi build, cập nhật `capacitor.config.json` → `server.url` trỏ tới địa chỉ server thật (đang để mặc định là một IP nội bộ dùng khi phát triển).

## 10. Ghi chú khác

- Không có bộ test tự động trong repo hiện tại.
- Mỗi lượt nộp form sinh ra 2 file trong `results/`: `.tsv` (UTF-8 kèm BOM, dùng nội bộ cho dashboard/sửa file) và **`.xlsx` thật** (dùng để người điền tải về, không phụ thuộc việc Excel "đoán" bảng mã như file văn bản thuần nên không còn lỗi font tiếng Việt). File `.xlsx` được tự dựng bằng `includes/xlsx_writer.php`, không cần Composer hay extension `zip`/`mbstring`.
- Log hệ thống ghi dạng JSON-line vào `logs/system.log`, tự rotate khi vượt 20MB và giữ tối đa 10 bản backup gần nhất.
