# Hệ thống đánh giá tổ chức KH&CN (score_system)

Đang chuyển đổi từ PHP thuần sang kiến trúc **multi-tenant, container hoá**, đăng nhập qua **Keycloak**.
Keycloak sẽ làm broker cho **VNU-SSO**, sau này cho cả SSO của các đơn vị đào tạo khác.

> Code PHP cũ và dữ liệu cũ nằm trong [`legacy/`](legacy/). Thư mục này chỉ dùng làm tài liệu khi chuyển logic chấm điểm và import dữ liệu, **không còn chạy**.

## Kiến trúc

```
            :6868
  Web / App ──► Nginx ─┬─ /api/*  ─► Laravel 12 (PHP-FPM 8.3) ─┬─► PostgreSQL 17 (RLS theo tenant)
                       │                + queue + scheduler      ├─► Redis (cache, queue)
                       │                                         └─► SeaweedFS (S3: file minh chứng)
                       ├─ /auth/* ─► Keycloak 26 ──(sau này)──► VNU-SSO / SSO các trường
                       └─ /       ─► frontend/dist (SPA)
```

| Thư mục | Nội dung |
|---|---|
| `backend/` | Laravel API (`/api/v1`) |
| `frontend/` | SPA web, dùng chung cho app mobile (hiện mới có trang tạm) |
| `mobile-app/` | Capacitor (Android/iOS) |
| `docker/` | Dockerfile PHP, cấu hình Nginx, Postgres init, realm Keycloak, SeaweedFS |
| `legacy/` | Hệ PHP cũ (tham khảo) |

### Multi-tenant
- Tenant = đơn vị đào tạo (bảng `tenants`, mã `vnu` là tenant đầu tiên).
- Tenant **lấy từ claim `tenant` trong token**, không lấy từ tham số client gửi lên.
- Mọi bảng nghiệp vụ có `tenant_id` và được bảo vệ 2 lớp:
  1. Global scope Eloquent (`App\Models\Concerns\BelongsToTenant`).
  2. **Postgres Row-Level Security**. API kết nối bằng role `score_app` (không sở hữu bảng), nên RLS luôn có hiệu lực.
- Migration chạy bằng role owner `score` thông qua connection `pgsql_owner`.

### Đăng nhập
- Backend chỉ xác thực JWT do Keycloak phát hành (chữ ký JWKS, `iss`, `aud=score-api`), xem `backend/app/Auth/`.
- Lần đầu user gọi API, hệ thống tự tạo hồ sơ trong bảng `users`, khớp theo claim `sub`.
- Vai trò (realm roles): `admin`, `editor`, `viewer`, `unit`, `researcher`. Kiểm tra quyền bằng middleware `role:editor,viewer`.
- **Nối VNU-SSO:** chỉ cần cấu hình trong Keycloak, không phải sửa code:
  1. Thêm Identity Provider (OIDC/SAML) với alias `vnu-sso`.
  2. Thêm mapper *Hardcoded attribute* `tenant=vnu`.
  3. Map thuộc tính mã cán bộ → `staff_code`.

## Chạy môi trường dev

Yêu cầu: Docker và Docker Compose. Không cần cài PHP hay Composer trên máy.

```bash
cp .env.example .env                 # sửa PUBLIC_URL, UID/GID (id -u, id -g)
cp backend/.env.example backend/.env
docker compose up -d --build

# lần đầu
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --database=pgsql_owner --seed
```

| URL | |
|---|---|
| http://192.168.88.13:6868/ | Frontend |
| http://192.168.88.13:6868/api/v1/health | Health check |
| http://192.168.88.13:6868/auth/admin | Keycloak admin (`admin` / `KEYCLOAK_ADMIN_PASSWORD`) |
| `127.0.0.1:5442` | Postgres (DBeaver/psql, user `score`) |

Tài khoản test trong realm `score` (mật khẩu `Dev@12345`): `admin`, `editor`, `viewer`, `unit.uet`, `gv.test`.

Lấy token để thử API (client `score-dev-cli` chỉ dùng cho dev, **phải tắt ở production**):

```bash
TOKEN=$(curl -s -X POST http://192.168.88.13:6868/auth/realms/score/protocol/openid-connect/token \
  -d grant_type=password -d client_id=score-dev-cli -d username=gv.test -d 'password=Dev@12345' | jq -r .access_token)
curl -H "Authorization: Bearer $TOKEN" http://192.168.88.13:6868/api/v1/me
```

Các lệnh hay dùng:

```bash
docker compose exec app php artisan make:migration ...          # tạo migration
docker compose exec app php artisan migrate --database=pgsql_owner
docker compose logs -f app keycloak                             # xem log
docker compose down                                             # dừng (giữ dữ liệu trong volume)
```

## Lộ trình

- [x] Hạ tầng Docker (Nginx, PHP-FPM, Postgres, Redis, S3, Keycloak)
- [x] Schema multi-tenant và RLS, xác thực OIDC, `/api/v1/me`
- [ ] Chuyển logic chấm điểm từ `legacy/process.php` sang `ScoringService`, kèm test so sánh với file `.tsv` cũ
- [ ] Lệnh import dữ liệu cũ (`legacy/results`, `legacy/uploads`, `legacy/criteria.json`)
- [ ] API đánh giá, xuất Excel
- [ ] Frontend SPA (thay các trang PHP)
- [ ] App mobile: đóng gói SPA, đăng nhập PKCE, push FCM
- [ ] Nối VNU-SSO
