# Product WMS

This repository contains a warehouse management system. Below are the basic steps for setting up a development environment and running the project.

## Requirements

- **PHP** 8 or higher
- **MySQL** server
- **Node.js** with **npm**

## Configuration

1. Copy the example environment file and edit it:
   ```bash
   cp .env.example .env
   ```
2. Open `.env` and fill in the required values. At minimum set:
   - `DB_HOST` – database host
   - `DB_NAME` – database name
   - `CARGUS_USER`, `CARGUS_PASS`, `CARGUS_SUBSCRIPTION_KEY`, `CARGUS_API_URL`
   - `WMS_API_KEY`

   Cargus credentials must be valid in order to generate AWBs.

## Installing dependencies

Install Node dependencies and build the front‑end assets:

```bash
npm install

# For development
npm run dev

# For production builds
npm run build
```

## Database migrations

Run pending migrations using the migration CLI:

```bash
php migrate.php migrate
```

After pulling the latest code, run migrations to add SMTP fields to the `users`
table:

```bash
php migrate.php migrate
```

Then each user can configure their personal SMTP credentials from the profile
page at `views/users/profile.php`.

## Running automated tests

A simple test script is provided to verify the API endpoints:

```bash
php test_api.php
```

This performs a few requests against the configured API and prints the results.
