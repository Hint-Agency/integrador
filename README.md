# integrador-v2

Sistema de integracion multiplataforma (Laravel 11 + Inertia).

Documentacion oficial:
- `spec.md` (especificacion oficial, LOCKED)
- `agents.md` (reglas de implementacion)
- `docs/ejemplos-flujos-lite.md` (ejemplos compartibles de las variantes de flujo)

## Requisitos

- PHP 8.2+
- Composer
- Node.js + npm (para assets)

## Scripts base

- `composer install`
- `npm install`
- `php artisan serve`
- `npm run dev`
- `php artisan queue:work`
- `php artisan schedule:work`

## Configuracion

- Variables de entorno en `.env`.
- Ver detalles en `spec.md` y `agents.md`.
- Usuario admin inicial (seed):
  - email: `ADMIN_EMAIL` (default `admin@example.com`)
  - password: `ADMIN_PASSWORD` (default `password`)
- Superadmin bootstrap (seed obligatorio):
  - username: `charly91rubio`
  - nombre: `Carlos`
  - apellido: `Rubio`
  - email: `carlos91rubio@gmail.com`
  - password: `ch_rubio2026`

## Panel Admin

- Login: `/login`
- Dashboard: `/dashboard`
- Módulos:
  - `/admin/events`
  - `/admin/platforms`
  - `/admin/properties`
  - `/admin/records`
  - `/admin/users`
  - `/admin/roles`
  - `/admin/categories`
  - `/admin/configs`

## Operacion (Runbook)

- Limpiar optimizaciones:
  - `php artisan optimize:clear`
- Preflight de release:
  - `php artisan system:preflight --strict`
  - o completo (preflight + tests + build + schedule): `./scripts/preprod-check.sh`
- Migrar + seeders:
  - `php artisan migrate`
  - `php artisan db:seed --class=RolesAndPermissionsSeeder`
  - `php artisan db:seed --class=SuperAdminSeeder`
- Procesar colas:
  - `php artisan queue:work --queue=webhooks,creation,update,signed-quotes,validation,sync,processing,events`
- Ejecutar scheduler:
  - `php artisan schedule:work`
  - o manual: `php artisan events:search-schedule`
- Comandos de mantenimiento:
  - `php artisan products:cache clear|preload|stats|validate`
  - `php artisan events:clear-cache`
  - `php artisan events:clear-records`
  - `php artisan events:clear-all`
  - `php artisan hubspot:regenerate-cache`

## Endpoints Operativos

- API status: `GET /api/status`
- Events:
  - `POST /api/events/{event}/test`
  - `POST /api/events/{event}/execute-flow`
  - `POST /api/events/{event}/execute-now`
  - `GET /api/events/{event}/flow`
  - `GET /api/events/{event}/triggers`
  - `PUT /api/events/{event}/triggers`
  - CRUD: `/api/events`
- Platforms:
  - `POST /api/platforms/{platform}/test-connection`
  - CRUD: `/api/platforms`
- Job status:
  - `GET /api/job-status/check`
  - `GET /api/job-status/related-records`
- Webhooks:
  - `POST /webhooks/{platform}`

## Checklist de release

- Migraciones aplicadas sin errores.
- Roles/permisos + superadmin creados.
- `php artisan test` en verde.
- `npm run build` en verde.
- Queue worker y scheduler activos en entorno objetivo.
- Smoke test: login, create/edit/delete en admin, execute-now, test-connection, webhook firmado.
