# Hệ thống đánh giá tổ chức KH&CN (score_system)

Nền tảng đánh giá, chấm điểm tổ chức khoa học & công nghệ của ĐHQGHN: đơn vị kê khai theo bộ tiêu chí kèm minh chứng,
hệ thống tự chấm điểm, tự kiểm tra minh chứng và lãnh đạo xem lại. Nhiều tenant (đơn vị đào tạo) dùng chung, dữ liệu cách ly;
đăng nhập qua Keycloak (sau này nối VNU-SSO); một API REST dùng chung cho web và app mobile.

## Kiến trúc

```
  Web / App ──► Nginx ─┬─ /api/*  ─► Laravel 12 (PHP-FPM) ──┬─► PostgreSQL 17 (dữ liệu, Row-Level Security)
                       │            + queue (việc chạy nền)  ├─► Redis (cache, hàng đợi)
                       │                                     ├─► SeaweedFS (S3: tệp minh chứng)
                       │                                     └─► evidence-ai (đọc tệp minh chứng)
                       ├─ /auth/* ─► Keycloak 26 ──► (VNU-SSO / SSO các đơn vị)
                       └─ /       ─► frontend (Vue SPA)
```

| Thành phần | Vai trò |
|---|---|
| Nginx | Cổng vào duy nhất; chuyển `/api` vào Laravel, `/auth` vào Keycloak, còn lại là SPA |
| Laravel (`app`) | API REST `/api/v1`: xác thực token, phân quyền, chấm điểm, lưu bài, xuất Excel |
| `queue` | Worker chạy nền: đọc tệp minh chứng (hàng đợi `evidence`) |
| `evidence-ai` | Dịch vụ Python nội bộ: trích chữ, OCR tiếng Việt, tìm con dấu/chữ ký; không gửi dữ liệu ra ngoài |
| PostgreSQL | Dữ liệu ứng dụng và Keycloak; API dùng role không sở hữu bảng nên RLS luôn có hiệu lực |
| SeaweedFS | Kho S3 lưu tệp minh chứng; chỉ tải qua API (có kiểm tra quyền) |
| Keycloak | Đăng nhập (OIDC, Authorization Code + PKCE), vai trò, broker tới SSO bên ngoài |

## Cấu trúc code

```
backend/                     Laravel API
  app/Auth/                  Xác thực JWT của Keycloak (JWKS, iss, aud), tạo/cập nhật user nội bộ theo claim sub
  app/Domain/Scoring/        Chấm điểm: 26 loại tính điểm theo bộ tiêu chí, tổng E, xếp loại
  app/Domain/Evaluation/     Nộp/sửa bài (SubmitEvaluation), kiểm tra dữ liệu (EvaluationRules), xuất Excel
  app/Domain/Evidence/       Kiểm tra minh chứng: gọi evidence-ai, kết luận từng tiêu chí, chấm lại điểm
  app/Http/Controllers/Api/V1/   Endpoint REST; app/Policies/ phân quyền; app/Http/Resources/ định dạng JSON
  app/Jobs/                  CheckEvaluationEvidence (đọc tệp chạy nền)
  app/Models/                Tenant, User, Organization, CriteriaVersion, Evaluation, Attachment, EvidenceReview, AuditLog
  database/data/criteria_v1.json   Bộ tiêu chí (câu hỏi, cách tính điểm, quy tắc kiểm tra minh chứng)
  database/migrations/       Schema, Row-Level Security
  config/                    Chỉ ghi phần khác mặc định của Laravel (oidc.php, evidence.php...)
  tests/                     Unit (chấm điểm, đối chiếu ~50.000 trường hợp với hệ cũ; kiểm tra minh chứng) và Feature (API)
frontend/src/                Vue 3 SPA
  auth.js                    Đăng nhập OIDC + PKCE (chạy được cả HTTP lẫn HTTPS)
  api.js, session.js         Gọi API, thông tin người dùng và quyền
  views/                     Form đánh giá, danh sách, kết quả, tổng hợp, trang cá nhân
  locales/                   Tiếng Việt / tiếng Anh
mobile-app/                  App Android/iOS (Capacitor) mở giao diện web
docker/
  evidence-ai/app.py         Dịch vụ đọc tệp minh chứng
  keycloak/                  Realm score (client, vai trò, mapper) và giao diện đăng nhập riêng
  nginx/, php/, postgres/, seaweedfs/
```

## Cài đặt

Yêu cầu: Docker, Docker Compose; Node.js (chỉ để build giao diện). Không cần cài PHP/Composer trên máy.

```bash
cp .env.example .env                 # sửa UID/GID (id -u, id -g), mật khẩu; TEMP_ACCOUNTS_CLIENT_SECRET=$(openssl rand -hex 24)
cp backend/.env.example backend/.env # sửa OIDC_ISSUER theo các link truy cập (cách nhau dấu phẩy), mật khẩu DB/S3
docker compose up -d --build         # build cả image PHP và evidence-ai

# lần đầu
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --database=pgsql_owner --seed   # schema + tenant vnu + bộ tiêu chí v1

cd frontend && npm install && npm run build   # tạo frontend/dist (Nginx phục vụ thư mục này)
```

- Link truy cập công khai: **https://scoring.datasphere.id.vn/** và **http://a36a0a125b14.sn.mynetname.net:6868/** (tự chuyển sang link HTTPS);
  quản trị Keycloak tại `/auth/admin` (`admin` / `KEYCLOAK_ADMIN_PASSWORD`). Nginx nghe ở cổng `NGINX_PORT` (mặc định 6868).
- Realm `score` được import tự động lần đầu từ `docker/keycloak/import/realm-score.json`, gồm vai trò, client và vài tài khoản thử
  (mật khẩu `Dev@12345`, xoá trước khi dùng thật). Thêm link truy cập mới: thêm vào `OIDC_ISSUER` và *Valid redirect URIs* của client `score-web`.
- Sửa code backend: `docker compose restart queue` (worker giữ code cũ); sửa `docker/evidence-ai/`: `docker compose up -d --build evidence-ai`;
  sửa giao diện: `npm run build`.
- Chạy test: `docker compose exec app php artisan test` (dùng database riêng `score_test`, không đụng dữ liệu thật).

## Vai trò

Vai trò là realm role trong Keycloak, đi theo token; quyền kiểm tra ở `app/Policies/EvaluationPolicy.php`.

| Vai trò | Quyền |
|---|---|
| `admin`, `editor` (lãnh đạo, quản trị) | Xem mọi bài trong tenant, nộp bài cho bất kỳ đơn vị, sửa bài (chấm lại), xem/tải tổng hợp, đặt lại kết luận minh chứng |
| `viewer` (lãnh đạo chỉ xem) | Xem mọi bài, xem/tải tổng hợp; không nộp, không sửa |
| `unit` (đơn vị) | Nộp bài và xem bài. Đã gán đơn vị (`unit_name`): chỉ nộp cho đơn vị mình, xem mọi bài của đơn vị. Chưa gán (tài khoản tạm): tự nhập tên đơn vị, chỉ xem bài mình nộp |
| `researcher` (cán bộ, giảng viên) | Đăng nhập; tra cứu điểm KHCN cá nhân sẽ có ở phiên bản sau |

## Tính năng

- **Form đánh giá** sinh từ bộ tiêu chí (4 chức năng: nghiên cứu cơ bản, ứng dụng, phát triển công nghệ, chính sách; 52 tiêu chí):
  chọn chức năng và trọng số (tổng 100%), trả lời Có/Không, nhập số liệu, mô tả và tệp minh chứng (mọi định dạng); tự lưu nháp.
- **Chấm điểm tự động** kèm lời giải thích từng tiêu chí; điểm quy đổi theo trọng số, tổng E và xếp loại A ≥ 80, B ≥ 60, C ≥ 40, D < 40.
- **Tự kiểm tra minh chứng**: Đạt / Cần xem lại / Không đạt cho từng tiêu chí, có lý do và trích dẫn; lãnh đạo đặt lại kết luận.
- **Danh sách và lịch sử**: lãnh đạo thấy lần nộp mới nhất của mỗi đơn vị, xem được lịch sử nộp; lọc theo tên, xếp loại.
- **Xuất Excel** từng bài và file tổng hợp mọi đơn vị (cùng cột với hệ cũ); xem tổng hợp trên web.
- **Sửa bài** (lãnh đạo): sửa số liệu, thêm/xoá tệp, chấm lại; bài nhập từ hệ PHP cũ chỉ đổi được tên đơn vị.
- **Nhật ký** (`audit_logs`): nộp, sửa, chấm lại do minh chứng, lãnh đạo đặt lại kết luận — ai, lúc nào, trước/sau.
- **Song ngữ** Việt/Anh; app mobile dùng chung giao diện web.

## Luồng chính

### Đăng nhập và multi-tenant

1. SPA chuyển sang Keycloak (Authorization Code + PKCE, client `score-web`), nhận access token.
2. Mỗi request API kèm Bearer token; backend kiểm tra chữ ký (JWKS), `iss` (mỗi link truy cập 1 issuer, khai báo trong `OIDC_ISSUER`), `aud=score-api`.
3. Tenant lấy từ claim `tenant` trong token (không lấy từ tham số client). Backend đặt `app.tenant_id` cho phiên Postgres:
   dữ liệu được lọc 2 lớp — global scope Eloquent (`BelongsToTenant`) và Row-Level Security.
4. Lần đầu gọi API, hồ sơ người dùng được tạo trong `users` (khớp claim `sub`), vai trò và `unit_name` cập nhật theo token.
5. Nối SSO một đơn vị chỉ cần cấu hình Keycloak: thêm Identity provider (OIDC/SAML) và mapper gán `tenant`, `staff_code`.

### Nộp bài và chấm điểm

1. SPA lấy bộ tiêu chí đang áp dụng (`criteria_versions`, có phiên bản), dựng form; gửi multipart kèm tệp.
2. `EvaluationRules` kiểm tra dữ liệu; `EvaluationScorer` chấm từng tiêu chí theo `scoring.type` và số liệu kê khai.
3. Lưu bài (trỏ đúng phiên bản tiêu chí), tệp lên S3, nhật ký — trong 1 transaction (lỗi thì xoá tệp đã đẩy lên S3).
4. Bật kiểm tra minh chứng: kết luận ngay các tiêu chí không có tệp, rồi giao job đọc tệp cho hàng đợi.

### Kiểm tra minh chứng

1. Job `CheckEvaluationEvidence` gửi lần lượt từng tệp sang `evidence-ai`. Dịch vụ nhận mọi định dạng (PDF, ảnh kể cả HEIC/TIFF,
   Word/Excel/PowerPoint mọi đời qua LibreOffice, tệp nén đọc từng tệp bên trong), trích chữ/OCR tiếng Việt (tự OCR lại khi lớp chữ
   PDF lỗi phông), tìm con dấu đỏ và chữ ký (chữ ký số, mực xanh, nét bút đè dấu, chỗ ký trống) — chỉ trả **dữ kiện**,
   lưu ở `attachments.analysis`.
2. `EvidenceChecker` kết luận từng tiêu chí theo quy tắc `evidence` trong bộ tiêu chí (`documents` loại văn bản, `keywords` nội dung,
   `signed` cần dấu + chữ ký, `period_years`, `count` đối chiếu ô kê khai); chủ đề văn bản xét theo trích yếu ("Về việc..."):
   - 🔴 **Không đạt → 0 điểm**: không có tệp và mô tả không nhắc tới nội dung tiêu chí; tệp không đọc được; không liên quan; văn bản của đơn vị khác
   - 🟡 **Cần xem lại → giữ điểm kê khai**: chỉ có mô tả nhưng nêu đúng nội dung; thiếu dấu/chữ ký; sai năm; không thấy tên đơn vị;
     trích yếu chưa rõ; số mục ít hơn kê khai; tệp Word/Excel (bản sửa được); định dạng hoặc dịch vụ đọc lỗi
   - 🟢 **Đạt → giữ điểm kê khai**
3. `ApplyEvidenceChecks` chấm lại cả bài, ghi lời giải thích (lý do, trích dẫn, trang) vào từng tiêu chí; trang kết quả tự cập nhật khi đọc xong.
4. Lãnh đạo đặt lại kết luận kèm lý do (`evidence_reviews`); đổi tệp của tiêu chí thì kết luận đó hết hiệu lực.
   Dữ kiện tệp đã lưu nên sửa quy tắc là chấm lại được ngay, không phải đọc lại tệp.
5. **Mô hình AI đọc ảnh (tuỳ chọn, cần GPU)**: service `vlm` (Ollama, `qwen2.5vl:7b`, profile `gpu`). Đặt `VLM_URL=http://vlm:11434`
   thì `evidence-ai` gửi thêm ảnh trang đầu + trang có dấu/chữ ký cùng nội dung tiêu chí, nhận về dữ kiện `vlm` (loại văn bản,
   có chứng minh được tiêu chí không và lý do, đơn vị, năm, dấu, chữ ký). `EvidenceChecker` ưu tiên nhận định này thay cho
   so khớp từ khoá và ghi lý do của AI vào lời giải thích. Mô hình lỗi thì vẫn dùng kết quả OCR như bình thường.
6. `php artisan evidence:dataset [--out=tệp.jsonl]`: xuất các tiêu chí đã được lãnh đạo kết luận (nhãn) kèm dữ kiện tệp, để
   tinh chỉnh mô hình; in tỉ lệ khớp và bảng so sánh kết luận tự động với lãnh đạo.
7. Tắt toàn bộ bằng `EVIDENCE_CHECK_ENABLED=false` (chấm như cũ theo số liệu kê khai).

## API (`/api/v1`)

Mọi endpoint (trừ `health`, `temp-accounts`) cần `Authorization: Bearer <access token>`.

| Method | Đường dẫn | Quyền | Mô tả |
|---|---|---|---|
| GET | `/health` | công khai | Kiểm tra hệ thống |
| POST | `/temp-accounts` | công khai, 10 lần/phút | Tạo tài khoản tạm bằng email (đến khi có SSO) |
| GET | `/me` | đã đăng nhập | Người dùng, vai trò, đơn vị, tenant |
| GET | `/criteria/active`, `/criteria/{id}` | đã đăng nhập | Bộ tiêu chí đang áp dụng / theo phiên bản |
| GET | `/evaluations` | xem bài | Danh sách theo phạm vi quyền; `?organization_id=` lịch sử, `?q=`, `?grade=` |
| GET | `/evaluations/{id}` | xem bài | Chi tiết: điểm, giải thích, kết luận minh chứng, tệp |
| POST | `/evaluations` | admin, editor, unit | Nộp bài (JSON hoặc multipart kèm tệp) |
| PUT | `/evaluations/{id}` | admin, editor | Sửa bài, chấm lại |
| GET | `/evaluations/{id}/export` | xem bài | Excel 1 bài |
| GET | `/evaluations/summary`, `/summary/export` | admin, editor, viewer | Tổng hợp (JSON / Excel) |
| PUT | `/evaluations/{id}/evidence` | admin, editor | Đặt lại kết luận minh chứng 1 tiêu chí (`question_key`, `verdict`, `reason`) |
| POST | `/evaluations/{id}/evidence/recheck` | admin, editor | Đọc lại tệp chưa đọc được (`all=1`: mọi tệp) |
| GET | `/attachments/{id}/download` | xem bài | Tải tệp minh chứng |
