# Invoice & Payment OS

Aplikasi SaaS modern untuk freelancer dan UMKM untuk membuat invoice profesional, mengelola pelacakan pembayaran, mengirim reminder otomatis, dan melihat laporan keuangan sederhana.

Proyek ini dibangun dengan arsitektur backend Laravel yang bersih, modular, aman, dan menerapkan standar industri (clean code, zero-trust payment amount, idempotent webhooks, and multi-tenant data isolation).

---

## 🛠️ Tech Stack

* **Framework:** Laravel 13 (Arsitektur modern Laravel)
* **Bahasa:** PHP 8.3+ (Active runtime: PHP 8.4)
* **Database:** MySQL 8.0+
* **Autentikasi:** Laravel Sanctum (Session web & API tokens)
* **Frontend UI:** Blade Templates + Tailwind CSS + Vite
* **Background Jobs:** Laravel Queue
* **Task Scheduling:** Laravel Scheduler (Automated reminders)
* **Notifications:** Laravel Notifications (Email / DB)
* **API:** RESTful API (Form Requests & API Resources)
* **Testing:** PHPUnit / Pest
* **Version Control:** Git

---

## 📐 Arsitektur & Prinsip Utama

1. **Clean Code & Junior Developer Friendly:** Struktur kode mudah dipahami tanpa premature overengineering.
2. **Business Logic Separation:** Controller tetap ramping; kalkulasi kompleks berada di layer Service/Action.
3. **Multi-Tenant Workspace:** Setiap data (pelanggan, produk, invoice, pembayaran) terisolasi kuat per Workspace untuk mencegah kerentanan IDOR (*Insecure Direct Object References*).
4. **Zero-Trust Client Pricing:** Nominal transaksi selalu dihitung dan divalidasi di server. Nilai pembayaran yang dikirim dari client tidak pernah dipercaya secara mentah.
5. **Idempotent Payment Webhooks:** Webhook dari payment gateway diproses secara idempoten menggunakan event logging untuk mencegah double balance updates akibat duplicate webhook delivery.
6. **Strict State Transitions:** Status invoice (`draft`, `sent`, `partially_paid`, `paid`, `void`, `cancelled`) dan status pembayaran (`pending`, `paid`, `failed`, `expired`) diatur dengan Enum/State yang ketat.
7. **Database Integrity:** Menggunakan tipe data `DECIMAL(15, 2)` untuk moneter, transaksi database (`DB::transaction()`), dan foreign keys berindeks.

---

## 🚀 Persyaratan Sistem

* PHP >= 8.3
* Composer >= 2.0
* MySQL >= 8.0
* Node.js >= 20 & NPM

---

## ⚙️ Panduan Instalasi & Setup Lokal

1. **Clone repository:**
   ```bash
   git clone <repository-url>
   cd invoice-payment-os
   ```

2. **Install dependency PHP & Node:**
   ```bash
   composer install
   npm install
   ```

3. **Setup environment file:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Konfigurasi Database di `.env`:**
   ```env
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=invoice_payment_os
   DB_USERNAME=root
   DB_PASSWORD=
   ```

5. **Pengaturan Lokalisasi & Mata Uang:**
   ```env
   APP_TIMEZONE=Asia/Jakarta
   APP_LOCALE=id
   APP_FALLBACK_LOCALE=en
   APP_FAKER_LOCALE=id_ID
   APP_CURRENCY=IDR
   ```

6. **Jalankan Migrasi Database:**
   ```bash
   php artisan migrate
   ```

7. **Jalankan Server Pengembangan:**
   ```bash
   # Terminal 1: Laravel Server
   php artisan serve

   # Terminal 2: Asset Bundler
   npm run dev
   ```

---
---

## 📚 Dokumentasi REST API & Swagger UI

Sistem ini menyediakan RESTful API lengkap untuk semua modul bisnis dengan standar OpenAPI 3.1.0 dan antarmuka interaktif Swagger UI.

### 🌐 Akses Dokumentasi Interaktif
Jalankan aplikasi (`php artisan serve`) lalu buka di browser:
* **Interactive Swagger UI:** [http://localhost:8000/docs](http://localhost:8000/docs) (atau alias: `/api/documentation`)
* **OpenAPI 3.1 Spec (YAML):** [http://localhost:8000/openapi.yaml](http://localhost:8000/openapi.yaml) (lokasi file: `public/openapi.yaml` & `openapi.yaml`)
* **Panduan Markdown Lengkap:** [docs/api-reference.md](docs/api-reference.md)

### 📦 Modul API yang Didokumentasikan
1. **Authentication:** Register, Login, Logout, Current User (`/api/register`, `/api/login`, `/api/logout`, `/api/user`)
2. **Business / Workspace:** Profil bisnis & konfigurasi workspace (`/api/businesses`, `/api/businesses/{business}`)
3. **Customers:** Manajemen pelanggan dengan pagination & pencarian (`/api/customers`, `/api/customers/{customer}`)
4. **Products / Services:** Manajemen katalog produk & harga aman (`/api/products`, `/api/products/{product}`)
5. **Invoices:** Lifecycle invoice (`draft` → `sent` → `partially_paid` → `paid` / `void` / `cancelled`), actions, dan download PDF (`/api/invoices`, `/api/invoices/{invoice}`, `/send`, `/void`, `/cancel`, `/pdf`)
6. **Invoice Items:** Snapshots item, kalkulasi otomatis subtotal, diskon, pajak, dan grand total (`/api/invoices/{invoice}/items`)
7. **Payments:** Payment engine internal, pelacakan partial/full payment, overpayment guard (`/api/invoices/{invoice}/payments`, `/api/payments/{payment}`)
8. **Webhooks:** Idempotent payment webhook ingest dengan deduplikasi event (`/api/webhooks/payment/{provider}`)
9. **Reports:** Agregasi revenue, total invoiced, total paid, outstanding balance, dan overdue via database query (`/api/reports/revenue`)

### ⚡ Contoh Penggunaan Cepat (Quick Start cURL)

**1. Login & Dapatkan Bearer Token:**
```bash
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email": "owner@example.com", "password": "password"}'
```
*Respons:*
```json
{
  "token": "1|eXamPLeTokEn...",
  "user": { "id": 1, "name": "Budi Santoso", "email": "owner@example.com" }
}
```

**2. Memanggil Endpoint dengan Token:**
```bash
curl -X GET http://localhost:8000/api/invoices \
  -H "Accept: application/json" \
  -H "Authorization: Bearer 1|eXamPLeTokEn..."
```

---

## 🧪 Menjalankan Pengujian (Testing)

Proyek ini memiliki cakupan pengujian otomatis (*Automated Feature & Unit Tests*) yang menyeluruh untuk setiap domain bisnis:

```bash
# Menjalankan seluruh test suite
php artisan test

# Menjalankan pengujian spesifik
php artisan test --filter=InvoiceTest
php artisan test --filter=PaymentWebhookTest
php artisan test --filter=RevenueReportTest
php artisan test --filter=DocumentationTest
```

---

## 🗺️ Roadmap Pengembangan

- [x] **Phase 1 — Project Foundation:** Inisialisasi framework, env setup, koneksi MySQL, migrasi dasar, timezone `Asia/Jakarta`, locale `id`, format IDR, git setup.
- [x] **Phase 2 — Database Design:** Skema tabel relasional lengkap (workspace, customer, product, invoice, items, payments, events).
- [x] **Phase 3 — Authentication:** Web session auth & Sanctum token auth.
- [x] **Phase 4 — Business / Workspace Management:** Isolasi workspace dan relasi user-tenant.
- [x] **Phase 5 — Customer Management:** CRUD pelanggan dengan pagination, search, validation, authorization & API Resource.
- [x] **Phase 6 — Product / Service Management:** CRUD produk/layanan dengan tipe data desimal moneter aman & snapshot protection.
- [x] **Phase 7 — Invoice Engine:** State machine invoice (`draft`, `sent`, `partially_paid`, `paid`, `void`, `cancelled`), generator nomor invoice unik, kalkulasi subtotal/diskon/pajak otomatis, dan atomic database transaction.
- [x] **Phase 8 — Invoice PDF:** Generasi file PDF invoice profesional dari database dengan validasi otorisasi tenant (`GET /api/invoices/{invoice}/pdf`).
- [x] **Phase 9 — Payment Engine:** Pembayaran parsial/penuh, validasi overpayment, update status invoice otomatis, dan database locking/transaction.
- [x] **Phase 10 — Payment Gateway Abstraction:** Arsitektur decoupled dengan `PaymentGatewayInterface`, `MockPaymentGateway`, dan Laravel Service Container Dependency Injection.
- [x] **Phase 11 — Webhook & Idempotency:** Webhook ingest handler aman terhadap pengiriman event ganda & race condition menggunakan atomic transaction dan deduplikasi `PaymentEvent`.
- [x] **Phase 12 — Queue & Reminder:** Pengingat invoice jatuh tempo otomatis (H-7, H-3, H-0, Overdue) via `SendInvoiceReminderJob`, `InvoiceDueReminderNotification`, dan Laravel Scheduler.
- [x] **Phase 13 — Reports:** Endpoint agregasi performa finansial (`/api/reports/revenue`) berbasis database aggregate query yang aman dari N+1 dan IDOR.
- [x] **Phase 14 — REST API Documentation:** Dokumentasi OpenAPI 3.1.0 interaktif via Swagger UI (`/docs`) dan referensi lengkap developer (`docs/api-reference.md`).
- [ ] **Phase 15 — Real Payment Gateway Adapter:** Integrasi konkret Midtrans Snap / Xendit Invoice adapter.
- [x] **Phase 16 — Security Audit & Hardening:** Audit keamanan menyeluruh, IDOR protection, mass assignment guard, zero-trust amount, idempotent webhooks, rate limiting, dan [SECURITY.md](SECURITY.md).
- [x] **Phase 17 — Docker & Deployment:** Containerization Docker (Nginx, PHP 8.4-FPM, MySQL 8.0, Redis 7), autonomous queue worker & task scheduler daemons, dan panduan operasional lengkap di [DEPLOYMENT.md](DEPLOYMENT.md).

---

## 🐳 Docker Deployment Cepat

Aplikasi telah sepenuhnya di-containerize menggunakan Docker Compose dengan layanan mandiri:

```bash
# 1. Jalankan seluruh container di background
docker compose up -d --build

# 2. Setup database awal
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed

# 3. Cek status container
docker compose ps
```

Untuk panduan produksi, backup, logging, dan manajemen worker/scheduler, baca dokumentasi lengkap di:
👉 **[Panduan Deployment & Operasional (DEPLOYMENT.md)](DEPLOYMENT.md)**

---

## 📄 Lisensi

Proyek ini dirilis di bawah lisensi [MIT](LICENSE).

