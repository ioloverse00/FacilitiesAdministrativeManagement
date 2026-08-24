# Authentication

This backend uses PHP sessions and HTTP-only cookies for API authentication. It does not use JWTs or browser localStorage.

## Endpoints

### `POST /api/auth/login.php`

Request:

```json
{
  "username": "gsms-super-admin",
  "password": "DemoPassword123!"
}
```

Successful response:

```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "user": {
      "id": 1,
      "username": "gsms-super-admin",
      "employee_id": 1,
      "employee_number": "EMP-2026-0001",
      "full_name": "Mara Ibarra",
      "position": "System Administrator",
      "email": "mara.ibarra@example-agency.test",
      "department": {
        "id": 6,
        "code": "DEP-IT",
        "name": "Information Technology"
      },
      "roles": [],
      "permissions": []
    },
    "csrf_token": "..."
  }
}
```

Invalid usernames and invalid passwords both return the generic message `Invalid username or password.`.

### `GET /api/auth/me.php`

Returns the current user profile, active roles, effective permissions, CSRF token, and idle-session metadata when the session cookie is valid.

Unauthenticated response:

```json
{
  "success": false,
  "message": "Authentication required.",
  "data": {}
}
```

### `POST /api/auth/logout.php`

Requires the session cookie. If the user is authenticated, also send:

```text
X-CSRF-Token: <token from login or me>
```

Logout is idempotent; an already logged-out request still receives a success response.

## Demo Password Setup

Seeded accounts contain placeholder password hashes. Set development passwords with the CLI-only script:

```powershell
C:\xampp\php\php.exe scripts/set-demo-passwords.php gsms-super-admin "DemoPassword123!"
```

To update all active demo accounts:

```powershell
C:\xampp\php\php.exe scripts/set-demo-passwords.php --all "DemoPassword123!"
```

Do not put real passwords in SQL files, URLs, frontend code, logs, or documentation.

## Session Cookies

The session name is `ISMERS_FAM_SESSION`. Session cookies are HTTP-only, SameSite=Lax, strict-mode, cookie-only, and secure when the request is HTTPS or `AUTH_COOKIE_SECURE=true`.

The session stores only minimal data:

- `user_account_id`
- `authenticated_at`
- `last_activity_at`
- `csrf_token`

User details, roles, and permissions are loaded from the database as needed.

## Idle Timeout

`AUTH_SESSION_LIFETIME` controls the idle timeout in seconds. The default is 7200 seconds. Expired sessions are cleared and `me.php` returns `401`.

## Account Lock

Failed password attempts increment `failed_login_count`. After `AUTH_MAX_FAILED_ATTEMPTS` failures, `locked_until` is set for `AUTH_LOCK_MINUTES`. Defaults are 5 attempts and 15 minutes.

## CORS

Same-origin XAMPP requests need no permissive CORS. If the frontend uses a different local origin, set `APP_ALLOWED_ORIGIN` to that exact origin. The API will allow credentials only for that configured origin and will not reflect arbitrary origins.

## Test Flow

PowerShell-friendly test script:

```powershell
.\scripts\test-auth.ps1 -BaseUrl "http://localhost/uiv2-components" -Username "gsms-super-admin"
```

Manual curl flow:

```powershell
curl.exe -i -c cookies.txt -H "Content-Type: application/json" -d "{\"username\":\"admin\",\"password\":\"DemoPassword123!\"}" http://localhost/uiv2-components/api/auth/login.php
curl.exe -i -b cookies.txt http://localhost/uiv2-components/api/auth/me.php
curl.exe -i -b cookies.txt -H "X-CSRF-Token: <token>" -X POST http://localhost/uiv2-components/api/auth/logout.php
curl.exe -i -b cookies.txt http://localhost/uiv2-components/api/auth/me.php
```

## Audit Logging

Authentication events are written to `audit_log` with safe metadata only. Passwords, password hashes, CSRF tokens, session IDs, cookies, and request bodies are never logged.
