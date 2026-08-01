# Database Setup

## Requirements

- PHP 8.2+
- PDO MySQL extension
- MariaDB 10.4+
- Imported `ismers_fam` database schema

## Setup

1. Copy `.env.example` to `.env`.
2. Update `.env` with local database credentials.
3. Confirm MariaDB is running.
4. Verify the `ismers_fam` database has already been imported.

## Example Local XAMPP Configuration

```ini
APP_ENV=local
APP_DEBUG=true

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ismers_fam
DB_USERNAME=root
DB_PASSWORD=
DB_CHARSET=utf8mb4
```

Use the empty password only for local XAMPP-style development. Do not use the root database account as a production recommendation.

## Security

- Never commit `.env`.
- Never place credentials in JavaScript.
- Never expose database credentials or raw database exceptions in API responses.
- Keep production credentials in server-managed environment variables.

## CLI Verification

Run this from the project root:

```bash
php scripts/test-database-connection.php
```

Or run a quick one-line check:

```bash
php -r "require 'config/database.php'; Database::connection()->query('SELECT 1'); echo 'OK';"
```

## Testing the API

After configuring `.env` and starting Apache or the PHP development server, open:

```text
http://localhost/uiv2-components/api/health.php
```

You can also verify with curl:

```bash
curl http://localhost/uiv2-components/api/health.php
```

Expected response shape:

```json
{
  "success": true,
  "message": "Health check passed.",
  "data": {
    "application": "Facilities & Administrative Management",
    "environment": "local",
    "php_version": "8.2.x",
    "database": "ismers_fam",
    "database_server": "10.4.x-MariaDB",
    "database_connection": true,
    "server_time": "2026-07-27T14:25:10+08:00"
  }
}
```

The health endpoint currently sends `Access-Control-Allow-Origin: *` for local development. Restrict this to trusted origins before production deployment.
