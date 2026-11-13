# Lingofy Admin API

Separate Laravel API for the `lingofy-admin` web frontend. It has its own Git repository and its own tenant authentication surface. It shares the Lingofy MySQL data store with `lingofy-backend` so admin-created learners and courses are available to the student API.

## Local run

```sh
cd lingofy-admin-backend
php artisan serve --host=127.0.0.1 --port=8081
```

The local `.env` needs the existing MySQL connection settings. `ADMIN_API_URL` in the admin frontend should point to `http://127.0.0.1:8081/api`.

## Database and tenants

The additive migration in `database/migrations/` has been applied to the configured `language-learning` MySQL database. It created the tenant and admin-token tables, added tenant ownership to students, staff users, courses, and quizzes, and assigned existing records to the `lingofy` tenant. It also changed student and staff email uniqueness to be per organization. Classes inherit ownership through their course, enrollments through their student, and quiz results through their student and quiz; the student API scopes those related records through these relationships.

The pre-migration backup is stored outside the app repositories at `../private-backups/language-learning-pre-tenant-2026-11-02.sql` with file mode `600`. Keep it private and do not commit it. Existing rows currently assigned to `lingofy`: 86 students, 29 staff users, 4 courses, 4 classes, 6 quizzes, 29 enrollments, and 36 quiz results.

The first owner account is provisioned as `admin@lingofy.test` for local use. Its temporary password was provided separately and is intentionally not stored in documentation. Replace this placeholder email and password before deployment. For another tenant, create its first owner with:

```sh
php artisan tenant:provision "Lingofy" lingofy owner@example.com "Lingofy Admin"
```

The command securely prompts for an initial password (minimum 12 characters). For each new client, run `tenant:provision` with its display name, unique slug, owner email, and owner name. Existing tenant slugs add an admin to that tenant rather than creating a duplicate. The student UI uses `TENANT_SLUG` to select its tenant; the admin API derives tenant scope from the authenticated admin token.

Standard clients use the shared apps and separate tenant data. Keep shared behavior in the standard code. If a client later needs custom behavior, use a tenant-specific extension folder and add the extension loader when the first concrete feature is designed; no client-specific extension code is active yet.

## API surface

The admin frontend uses browser `fetch` with a bearer token for tenant-scoped data reads and writes. Sign-in calls `/api/admin/login` directly, then sends the short-lived token to the same-origin frontend session bridge. Configure `ADMIN_CORS_ALLOWED_ORIGINS` as a comma-separated list of exact frontend origins. The local example includes `http://127.0.0.1:8002` and `http://localhost:8002`; production must use the deployed HTTPS admin origin.

- `POST /api/admin/login` — organization slug and credentials; returns a short-lived bearer token.
- `GET /api/admin/me`, `POST /api/admin/logout` — current admin session.
- `GET /api/admin/dashboard` — counts scoped to the signed-in tenant.
- `GET|POST /api/admin/students` — list or create a learner for that tenant.
- `GET|POST /api/admin/courses` — list or create a tenant course.
- `PATCH /api/admin/courses/{id}` — update/publish a course owned by that tenant.
- `GET|POST /api/admin/classes` — list classes under tenant-owned courses or create a class for one of those courses.
- `GET /api/admin/quizzes` — list class quizzes for the signed-in tenant, with their class and course names.
- `GET /api/admin/classes/{id}/content`, `PATCH /api/admin/classes/{id}` — read or update class details and materials.
- `PUT /api/admin/classes/{id}/quiz` — create or update the class quiz and attach it to the student-facing class materials.

Class materials are stored in the existing `classes.materials` JSON column. Admins can add video, PDF, or link materials by URL; the student site renders supported video/PDF URLs and opens links separately. Quiz questions use the existing `quizzes.contens` JSON format so the student quiz flow can display them. The class UI supports one quiz per class, matching the current student experience.

Admin APIs derive tenant scope from the authenticated token. They never accept a tenant ID from the browser as an authorization decision. `lingofy-backend` scopes student-facing course, student, enrollment, class, quiz, and result requests using the `X-Tenant-Slug` header; the student frontend sets this using `TENANT_SLUG`.

## Safety

- Do not run `migrate:fresh`, reset, seed, or roll back the shared database.
- Back up the intended database before applying future migrations.
- Keep database credentials in `.env`; do not commit or copy them into notes.
- Student passwords use the current student API's SHA-256 format for compatibility. This needs a coordinated upgrade before production hardening.
- Configure HTTPS, rate limits, secure session cookies, backup/restore, and least-privilege MySQL credentials before deployment.
