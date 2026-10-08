# Hệ thống đánh giá tổ chức KH&CN (score_system)

Nền tảng đánh giá, chấm điểm tổ chức khoa học & công nghệ và tra cứu điểm KHCN cho cán bộ, giảng viên.

- **Multi-tenant:** nhiều đơn vị đào tạo dùng chung một hệ thống, dữ liệu cách ly hoàn toàn.
- **Đăng nhập một lần (SSO):** qua Keycloak, tích hợp VNU-SSO và SSO của các đơn vị đào tạo.
- **API chuẩn REST** (`/api/v1`) dùng chung cho web và app mobile (iOS & Android).
- **Container hoá** toàn bộ bằng Docker.

## Kiến trúc

```
            :6868
  Web / App ──► Nginx ─┬─ /api/*  ─► Laravel 12 (PHP-FPM 8.3) ─┬─► PostgreSQL 17
                       │                + queue + scheduler      ├─► Redis (cache, queue)
                       │                                         └─► SeaweedFS (S3: file minh chứng)
                       ├─ /auth/* ─► Keycloak 26 ──► VNU-SSO / SSO các đơn vị
                       └─ /app/   ─► frontend (SPA)
```

| Thư mục | Nội dung |
|---|---|
| `backend/` | Laravel API |
| `frontend/` | SPA web, dùng chung cho app mobile |
| `mobile-app/` | App iOS/Android (Capacitor) |
| `docker/` | Dockerfile PHP, cấu hình Nginx, khởi tạo Postgres, realm Keycloak, SeaweedFS |

### Multi-tenant
- Tenant là đơn vị đào tạo (bảng `tenants`; `vnu` là tenant mặc định).
- Tenant **lấy từ claim `tenant` trong token**, không lấy từ tham số client gửi lên.
- Mọi bảng nghiệp vụ có `tenant_id` và được bảo vệ 2 lớp:
  1. Global scope Eloquent (`App\Models\Concerns\BelongsToTenant`).
  2. **Postgres Row-Level Security**: API kết nối bằng role `score_app` (không sở hữu bảng), nên RLS luôn có hiệu lực.
- Migration chạy bằng role owner `score` thông qua connection `pgsql_owner`.

### Đăng nhập
- Backend chỉ xác thực JWT do Keycloak phát hành (kiểm tra chữ ký JWKS, `iss`, `aud=score-api`), xem `backend/app/Auth/`.
- Lần đầu người dùng gọi API, hệ thống tự tạo hồ sơ trong bảng `users`, khớp theo claim `sub`.
- Vai trò (realm roles): `admin`, `editor`, `viewer`, `unit`, `researcher`. Kiểm tra quyền bằng middleware `role:editor,viewer`.
- Web và mobile đăng nhập theo luồng Authorization Code + PKCE, dùng client `score-web`.

**Thêm SSO của một đơn vị** (VNU-SSO hoặc trường khác) chỉ cần cấu hình trong Keycloak, không phải sửa code:
1. Thêm tenant vào bảng `tenants`.
2. Keycloak → realm `score` → *Identity providers*: thêm provider (OIDC/SAML), ví dụ alias `vnu-sso`.
3. Thêm mapper *Hardcoded attribute* `tenant=<mã tenant>` và map thuộc tính mã cán bộ sang `staff_code`.

## Cài đặt

Yêu cầu: Docker và Docker Compose. Không cần cài PHP hay Composer trên máy.

```bash
cp .env.example .env                 # sửa PUBLIC_URL, UID/GID (id -u, id -g), mật khẩu
cp backend/.env.example backend/.env
docker compose up -d --build

# lần đầu
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --database=pgsql_owner --seed
```

| Địa chỉ | |
|---|---|
| http://192.168.88.13:6868/app/ | Web |
| http://192.168.88.13:6868/api/v1/health | Health check |
| http://192.168.88.13:6868/auth/admin | Quản trị Keycloak (`admin` / `KEYCLOAK_ADMIN_PASSWORD`) |
| `127.0.0.1:5442` | PostgreSQL (DBeaver/psql, user `score`) |

Tài khoản test trong realm `score` (mật khẩu `Dev@12345`): `admin`, `editor`, `viewer`, `unit.uet`, `gv.test`.

Lấy token để thử API (client `score-dev-cli` chỉ dùng cho dev, **phải tắt ở production**):

```bash
TOKEN=$(curl -s -X POST http://192.168.88.13:6868/auth/realms/score/protocol/openid-connect/token \
  -d grant_type=password -d client_id=score-dev-cli -d username=gv.test -d 'password=Dev@12345' | jq -r .access_token)
curl -H "Authorization: Bearer $TOKEN" http://192.168.88.13:6868/api/v1/me
```

## Vận hành

### Lệnh thường dùng

```bash
docker compose ps                                               # trạng thái các dịch vụ
docker compose logs -f app keycloak                             # xem log
docker compose restart app                                      # khởi động lại một dịch vụ
docker compose exec app php artisan make:migration ...          # tạo migration
docker compose exec app php artisan migrate --database=pgsql_owner
docker compose down                                             # dừng (giữ dữ liệu)
```

> ⚠️ `docker compose down -v` **xoá toàn bộ dữ liệu** (database, file). Không chạy lệnh này trên production.

### Dữ liệu

| Dữ liệu | Nơi lưu |
|---|---|
| Database (ứng dụng + Keycloak) | Docker volume `score_pgdata` |
| File minh chứng (S3) | Docker volume `score_s3data` |
| Cache, hàng đợi | Docker volume `score_redisdata` |
| Cấu hình, mật khẩu | `.env`, `backend/.env` (không commit) |

Sao lưu database (nên đặt cron chạy hằng ngày):

```bash
docker compose exec -T postgres pg_dumpall -U score | gzip > ~/backup/score_$(date +%F).sql.gz
```

### Tài khoản hệ thống (đổi trước khi chạy production)

| Tài khoản | Cấu hình tại |
|---|---|
| Keycloak admin | `KEYCLOAK_ADMIN_PASSWORD` trong `.env` |
| Postgres owner `score` | `POSTGRES_PASSWORD` trong `.env` và `DB_OWNER_PASSWORD` trong `backend/.env` |
| Postgres ứng dụng `score_app` | `docker/postgres/01-init.sql` và `DB_PASSWORD` trong `backend/.env` |
| Kho file S3 | `docker/seaweedfs/s3.json` và `AWS_*` trong `backend/.env` |

Mật khẩu Postgres chỉ được áp dụng khi khởi tạo database lần đầu.

## Lộ trình

- [x] Hạ tầng Docker (Nginx, PHP-FPM, PostgreSQL, Redis, S3, Keycloak)
- [x] Schema multi-tenant và Row-Level Security, xác thực OIDC, `/api/v1/me`
- [ ] Dịch vụ chấm điểm theo bộ tiêu chí có phiên bản
- [ ] API đánh giá, upload minh chứng, xuất Excel
- [ ] Frontend SPA
- [ ] App mobile: tra cứu điểm KHCN, thông báo đề tài/công bố (push FCM), đăng nhập PKCE
- [ ] Tích hợp VNU-SSO
