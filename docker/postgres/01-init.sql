-- Chạy 1 lần khi khởi tạo volume Postgres.
-- DB "score" (của ứng dụng) được tạo sẵn qua POSTGRES_DB; ở đây tạo thêm DB cho Keycloak.
CREATE DATABASE keycloak;

-- Role ứng dụng KHÔNG phải superuser/owner bảng => Row-Level Security có hiệu lực.
-- Migration chạy bằng user "score" (owner); runtime API kết nối bằng "score_app".
CREATE ROLE score_app LOGIN PASSWORD 'score_app';
GRANT CONNECT ON DATABASE score TO score_app;
\connect score
GRANT USAGE ON SCHEMA public TO score_app;
ALTER DEFAULT PRIVILEGES FOR ROLE score IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO score_app;
ALTER DEFAULT PRIVILEGES FOR ROLE score IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO score_app;

-- DB riêng cho test tự động (php artisan test)
\connect postgres
CREATE DATABASE score_test OWNER score;
