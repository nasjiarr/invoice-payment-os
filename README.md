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

## 🧪 Menjalankan Pengujian (Testing)

```bash
php artisan test
```

---

## 🗺️ Roadmap Pengembangan (19 Phase)

- [x] **Phase 1 — Project Foundation:** Inisialisasi framework, env setup, koneksi MySQL, migrasi dasar, timezone `Asia/Jakarta`, locale `id`, format IDR, git setup.
- [ ] **Phase 2 — Database Design:** Skema tabel relasional lengkap (workspace, customer, product, invoice, items, payments, events).
- [ ] **Phase 3 — Authentication:** Web session auth & Sanctum token auth.
- [ ] **Phase 4 — Business / Workspace Management:** Isolasi workspace dan relasi user-tenant.
- [ ] **Phase 5 — Customer Management:** CRUD pelanggan dengan policy & Form Request.
- [ ] **Phase 6 — Product / Service Management:** CRUD produk/layanan harga satuan.
- [ ] **Phase 7 — Invoice Engine:** Generator nomor invoice, kalkulator subtotal/diskon/pajak, state machine invoice.
- [ ] **Phase 8 — Invoice PDF:** Template cetak invoice profesional & PDF stream/download.
- [ ] **Phase 9 — Payment Engine:** Input pembayaran parsial/lunas & atomisitas balance update.
- [ ] **Phase 10 — Mock Payment Gateway:** Abstraksi interface gateway & implementasi mock gateway lokal.
- [ ] **Phase 11 — Webhook + Idempotency:** Webhook processor aman dengan deduplikasi transaksi.
- [ ] **Phase 12 — Real Payment Gateway:** Integrasi Midtrans / Xendit adapter.
- [ ] **Phase 13 — Reminder & Queue:** Background jobs untuk email reminder tagihan mendekati jatuh tempo.
- [ ] **Phase 14 — Reports:** Ringkasan keuangan (pemasukan, piutang, status penagihan).
- [ ] **Phase 15 — REST API:** API Resource & API endpoints terstandarisasi.
- [ ] **Phase 16 — Automated Testing:** Unit & Feature test untuk skenario bisnis krusial.
- [ ] **Phase 17 — Security Hardening:** Evaluasi IDOR, sanitasi input, audit trail, security headers.
- [ ] **Phase 18 — Docker & Deployment:** Containerization & production configuration.
- [ ] **Phase 19 — Documentation:** OpenAPI/Swagger & project portfolio documentation.

---

## 📄 Lisensi

Proyek ini dirilis di bawah lisensi [MIT](LICENSE).
