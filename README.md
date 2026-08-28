# Pingly — backend

Laravel API for the Pingly platform. Setup, architecture, demo credentials,
and the deployment checklist all live in the [root README](../README.md).

Quick reference:

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve --port 8000
```

- `app/Services/Billing/` — the shared billing engine every service registers into.
- `app/Services/WhatsApp/`, `app/Services/Ai/` — the two service modules (docs §3, §4).
- `app/Services/Auth/JwtService.php` — token issuing/verification.
- `routes/api.php` — client routes unprefixed, admin routes under `/admin`.
