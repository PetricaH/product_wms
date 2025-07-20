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

### Location dimensions and shelf configuration

New columns were added to the `locations` table to track physical size and weight limits:

* `length_mm`
* `depth_mm`
* `height_mm`
* `max_weight_kg`

Run migrations after pulling the code to add these fields.

The warehouse configuration page (`warehouse_settings.php`) allows administrators to set:

* pallets per shelf level
* barrels per pallet for 5 L, 10 L and 25 L containers

These values are stored in the `settings` table and used when calculating capacity.

## Running automated tests

A simple test script is provided to verify the API endpoints:

```bash
php test_api.php
```

This performs a few requests against the configured API and prints the results.
