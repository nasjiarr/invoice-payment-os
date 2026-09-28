# Invoice & Payment OS — REST API Reference Documentation

Dokumentasi lengkap REST API **Invoice & Payment OS**. Dirancang agar developer atau consumer API dapat mengintegrasikan dan menggunakan seluruh endpoint tanpa perlu membaca source code backend.

---

## 📑 Daftar Isi

1. [Standar & Konvensi Umum](#standar--konvensi-umum)
2. [Authentication](#1-authentication)
3. [Business (Workspace)](#2-business-workspace)
4. [Customers](#3-customers)
5. [Products / Services](#4-products--services)
6. [Invoices](#5-invoices)
7. [Invoice Items (Snapshots & Perhitungan)](#6-invoice-items)
8. [Payments](#7-payments)
9. [Webhooks (Idempotent Event Ingest)](#8-webhooks)
10. [Reports (Financial & Revenue)](#9-reports)
11. [OpenAPI & Swagger UI](#10-openapi--swagger-ui)

---

## Standar & Konvensi Umum

* **Base URL:** `http://localhost:8000/api` atau `http://invoice-payment-os.test/api`
* **Format Request & Response:** `Content-Type: application/json`, `Accept: application/json`
* **Autentikasi:** Bearer Token via header HTTP:
  ```http
  Authorization: Bearer <sanctum_personal_access_token>
  ```
* **Format Error Validasi (HTTP 422):**
  ```json
  {
    "message": "Validation failed",
    "errors": {
      "field_name": [
        "Pesan error spesifik untuk field ini."
      ]
    }
  }
  ```
* **Format Error Autentikasi & Otorisasi:**
  * HTTP 401 Unauthenticated: `{"message": "Unauthenticated."}`
  * HTTP 403 Forbidden: `{"message": "This action is unauthorized."}`
  * HTTP 404 Not Found: `{"message": "Resource not found."}`

---

## 1. Authentication

### 1.1 Register User
* **Method:** `POST`
* **URL:** `/api/register`
* **Authentication:** Tidak ada (Public)
* **Authorization Rules:** Terbuka untuk umum.
* **Request Body (JSON):**
  | Field | Type | Rules | Keterangan |
  |---|---|---|---|
  | `name` | string | required, max:255 | Nama lengkap pengguna |
  | `email` | string | required, email, max:255, unique:users | Alamat email unik |
  | `password` | string | required, min:8, confirmed | Password akun |
  | `password_confirmation` | string | required | Konfirmasi password sama |
* **Contoh Request:**
  ```bash
  curl -X POST http://localhost:8000/api/register \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    -d '{
      "name": "Nasjiar",
      "email": "nasjiar@example.com",
      "password": "Password123!",
      "password_confirmation": "Password123!"
    }'
  ```
* **Successful Response (HTTP 201 Created):**
  ```json
  {
    "message": "User registered successfully",
    "data": {
      "user": {
        "id": 1,
        "name": "Nasjiar",
        "email": "nasjiar@example.com"
      },
      "token": "1|abcdef1234567890..."
    }
  }
  ```
* **Error Response (HTTP 422 Unprocessable Entity):**
  ```json
  {
    "message": "Validation failed",
    "errors": {
      "email": ["The email has already been taken."]
    }
  }
  ```

---

### 1.2 Login User
* **Method:** `POST`
* **URL:** `/api/login`
* **Authentication:** Tidak ada (Public)
* **Authorization Rules:** Terbuka untuk umum.
* **Request Body (JSON):**
  | Field | Type | Rules | Keterangan |
  |---|---|---|---|
  | `email` | string | required, email | Email terdaftar |
  | `password` | string | required, string | Password akun |
* **Contoh Request:**
  ```bash
  curl -X POST http://localhost:8000/api/login \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    -d '{
      "email": "nasjiar@example.com",
      "password": "Password123!"
    }'
  ```
* **Successful Response (HTTP 200 OK):**
  ```json
  {
    "message": "Login successful",
    "data": {
      "user": {
        "id": 1,
        "name": "Nasjiar",
        "email": "nasjiar@example.com"
      },
      "token": "2|9876543210fedcba..."
    }
  }
  ```
* **Error Response (HTTP 422 Unprocessable Entity):**
  ```json
  {
    "message": "Validation failed",
    "errors": {
      "email": ["The provided credentials do not match our records."]
    }
  }
  ```

---

### 1.3 Get Authenticated User
* **Method:** `GET`
* **URL:** `/api/user`
* **Authentication:** `Bearer Token`
* **Authorization Rules:** Hanya dapat diakses oleh user yang memiliki token Sanctum valid.
* **Contoh Request:**
  ```bash
  curl -X GET http://localhost:8000/api/user \
    -H "Authorization: Bearer <token>" \
    -H "Accept: application/json"
  ```
* **Successful Response (HTTP 200 OK):**
  ```json
  {
    "data": {
      "id": 1,
      "name": "Nasjiar",
      "email": "nasjiar@example.com"
    }
  }
  ```

---

### 1.4 Logout
* **Method:** `POST`
* **URL:** `/api/logout`
* **Authentication:** `Bearer Token`
* **Authorization Rules:** Menghapus token personal access yang sedang aktif digunakan.
* **Contoh Request:**
  ```bash
  curl -X POST http://localhost:8000/api/logout \
    -H "Authorization: Bearer <token>" \
    -H "Accept: application/json"
  ```
* **Successful Response (HTTP 200 OK):**
  ```json
  {
    "message": "Logged out successfully"
  }
  ```

---

## 2. Business (Workspace)

Workspace merepresentasikan tenant entitas bisnis (freelancer atau UMKM). Semua resource lain (customer, produk, invoice, payment) terikat pada sebuah Business.

### 2.1 List Businesses
* **Method:** `GET`
* **URL:** `/api/business`
* **Authentication:** `Bearer Token`
* **Authorization Rules:** Hanya mengembalikan daftar business di mana authenticated user adalah *owner* atau *member*. Tidak pernah membocorkan business milik user lain.
* **Successful Response (HTTP 200 OK):**
  ```json
  {
    "data": [
      {
        "id": 1,
        "owner_id": 1,
        "name": "Nasjiar Web Studio",
        "email": "studio@nasjiar.com",
        "phone": "+628123456789",
        "address": "Jl. Asia Afrika No. 10, Bandung",
        "tax_id": "01.234.567.8-901.000",
        "currency": "IDR",
        "role": "owner",
        "created_at": "2026-09-28T10:00:00.000000Z"
      }
    ]
  }
  ```

---

### 2.2 Create Business
* **Method:** `POST`
* **URL:** `/api/business`
* **Authentication:** `Bearer Token`
* **Request Body (JSON):**
  | Field | Type | Rules | Keterangan |
  |---|---|---|---|
  | `name` | string | required, max:255 | Nama bisnis/workspace |
  | `email` | string | required, email, max:255 | Email resmi bisnis |
  | `phone` | string | nullable, max:50 | Nomor telepon kantor |
  | `address` | string | nullable, max:1000 | Alamat fisik kantor |
  | `tax_id` | string | nullable, max:50 | NPWP / Nomor registrasi pajak |
  | `currency` | string | nullable, size:3 | Default 'IDR' |
* **Contoh Request:**
  ```bash
  curl -X POST http://localhost:8000/api/business \
    -H "Authorization: Bearer <token>" \
    -H "Content-Type: application/json" \
    -d '{
      "name": "Nasjiar Web Studio",
      "email": "studio@nasjiar.com",
      "phone": "+628123456789",
      "currency": "IDR"
    }'
  ```
* **Successful Response (HTTP 201 Created):**
  Mengembalikan objek Business yang baru dibuat dengan status user otomatis sebagai `owner`.

---

### 2.3 Get Business by ID
* **Method:** `GET`
* **URL:** `/api/business/{business}`
* **Authentication:** `Bearer Token`
* **Authorization Rules:** `BusinessPolicy@view` memastikan hanya owner atau anggota workspace yang dapat melihat data.
* **Error Response (HTTP 403 Forbidden):**
  Jika user mencoba mengakses ID business milik orang lain (IDOR protection):
  ```json
  {
    "message": "This action is unauthorized."
  }
  ```

---

### 2.4 Update Business
* **Method:** `PUT` / `PATCH`
* **URL:** `/api/business/{business}`
* **Authentication:** `Bearer Token`
* **Authorization Rules:** Hanya anggota workspace yang berwenang.

---

### 2.5 Delete Business
* **Method:** `DELETE`
* **URL:** `/api/business/{business}`
* **Authentication:** `Bearer Token`
* **Authorization Rules:** `BusinessPolicy@delete` hanya mengizinkan pemilik utama (*owner*) business untuk menghapus workspace.

---

## 3. Customers

Semua customer selalu terikat dalam satu business.

### 3.1 List Customers
* **Method:** `GET`
* **URL:** `/api/customers`
* **Authentication:** `Bearer Token`
* **Query Parameters:**
  * `business_id` (integer, opsional): Filter workspace.
  * `search` (string, opsional): Pencarian parsial berdasarkan nama atau email customer.
  * `sort_by` (string, opsional): `id`, `name`, `email`, `created_at`.
  * `sort_order` (string, opsional): `asc` atau `desc`.
  * `per_page` (integer, opsional): Default 15, max 100.
* **Contoh Response (HTTP 200 OK):**
  ```json
  {
    "data": [
      {
        "id": 1,
        "business_id": 1,
        "name": "PT Maju Mundur Sejahtera",
        "email": "procurement@majumundur.co.id",
        "phone": "+62811998877",
        "address": "Kuningan, Jakarta Selatan",
        "notes": "Term of Payment 30 hari"
      }
    ],
    "meta": {
      "current_page": 1,
      "per_page": 15,
      "total": 1
    }
  }
  ```

---

### 3.2 Create Customer
* **Method:** `POST`
* **URL:** `/api/customers`
* **Authentication:** `Bearer Token`
* **Request Body (JSON):**
  | Field | Type | Rules | Keterangan |
  |---|---|---|---|
  | `business_id` | integer | required, exists:businesses,id | ID workspace pemilik |
  | `name` | string | required, max:255 | Nama pelanggan |
  | `email` | string | required, email, max:255 | Email penagihan |
  | `phone` | string | nullable, max:50 | Nomor telepon |
  | `address` | string | nullable, max:1000 | Alamat penagihan |
  | `notes` | string | nullable, max:2000 | Catatan internal |
* **Authorization Rules:** Validasi memastikan `business_id` benar-benar dimiliki/dapat diakses oleh authenticated user.

---

### 3.3 Detail, Update, & Delete Customer
* `GET /api/customers/{customer}`
* `PUT /api/customers/{customer}`
* `DELETE /api/customers/{customer}`
* **Authorization Rules:** Dilindungi oleh `CustomerPolicy`. User dilarang membaca, memodifikasi, atau menghapus customer dari business user lain.

---

## 4. Products / Services

### 4.1 List Products
* **Method:** `GET`
* **URL:** `/api/products`
* **Authentication:** `Bearer Token`
* **Query Parameters:** `business_id`, `active` (boolean), `search` (nama/deskripsi).

---

### 4.2 Create Product
* **Method:** `POST`
* **URL:** `/api/products`
* **Authentication:** `Bearer Token`
* **Request Body (JSON):**
  | Field | Type | Rules | Keterangan |
  |---|---|---|---|
  | `business_id` | integer | required, exists:businesses,id | ID workspace |
  | `name` | string | required, max:255 | Nama produk / layanan |
  | `description` | string | nullable, max:1000 | Deskripsi teknis |
  | `price` | numeric | required, min:0, decimal:0,2 | Harga satuan (Fixed-Point) |
  | `active` | boolean | nullable | Status aktif produk |
* **Contoh Request:**
  ```json
  {
    "business_id": 1,
    "name": "Cloud DevOps Architecture",
    "description": "Perancangan arsitektur microservices AWS",
    "price": 15000000.00,
    "active": true
  }
  ```

---

### 4.3 Immutability Protection
* Perubahan nama atau harga produk via `PUT /api/products/{product}` **tidak akan pernah** mengubah tagihan dan item pada invoice masa lalu yang sudah diterbitkan.

---

## 5. Invoices

Invoice Engine adalah inti aplikasi dengan state machine yang ketat:
```
draft ──► sent ──► partially_paid ──► paid
  │         │            │
  ▼         ▼            ▼
cancelled  void         void
```

### 5.1 Create Invoice
* **Method:** `POST`
* **URL:** `/api/invoices`
* **Authentication:** `Bearer Token`
* **Request Body (JSON):**
  | Field | Type | Rules | Keterangan |
  |---|---|---|---|
  | `business_id` | integer | required, exists:businesses,id | Workspace |
  | `customer_id` | integer | required, exists:customers,id | Customer (harus milik business) |
  | `invoice_number` | string | nullable, max:50, unique | Nomor invoice (auto-generated jika kosong) |
  | `issue_date` | date | required, YYYY-MM-DD | Tanggal terbit |
  | `due_date` | date | required, after_or_equal:issue_date | Tanggal jatuh tempo |
  | `currency` | string | nullable, size:3 | Default 'IDR' |
  | `notes` | string | nullable, max:2000 | Catatan/instruksi pembayaran |
  | `items` | array | required, min:1 | Baris item invoice |
  | `items.*.product_id` | integer | nullable, exists:products,id | Opsional referensi katalog |
  | `items.*.description`| string | required, max:255 | Uraian pekerjaan/layanan |
  | `items.*.quantity` | numeric | required, min:0.01 | Jumlah |
  | `items.*.unit_price` | numeric | required, min:0, decimal:0,2 | Harga per unit |
  | `items.*.discount` | numeric | nullable, min:0, decimal:0,2 | Diskon baris item |
  | `items.*.tax` | numeric | nullable, min:0, decimal:0,2 | Pajak baris item |
* **Kalkulasi Server-Side (Zero-Trust):**
  Frontend **tidak boleh** menentukan total invoice. Backend otomatis menghitung:
  $$\text{subtotal} = \sum (\text{quantity} \times \text{unit\_price})$$
  $$\text{total} = \text{subtotal} - \text{total\_discount} + \text{total\_tax}$$
* **Contoh Request:**
  ```json
  {
    "business_id": 1,
    "customer_id": 1,
    "issue_date": "2026-09-28",
    "due_date": "2026-10-12",
    "currency": "IDR",
    "notes": "Transfer BCA 1234567890 a.n Nasjiar Web Studio",
    "items": [
      {
        "description": "Backend API Development (Phase 1-13)",
        "quantity": 1,
        "unit_price": 10000000.00,
        "discount": 1000000.00,
        "tax": 990000.00
      }
    ]
  }
  ```
* **Successful Response (HTTP 201 Created):**
  ```json
  {
    "message": "Invoice created successfully",
    "data": {
      "id": 1,
      "invoice_number": "INV-20260928-1001",
      "status": "draft",
      "issue_date": "2026-09-28",
      "due_date": "2026-10-12",
      "currency": "IDR",
      "subtotal": "10000000.00",
      "discount": "1000000.00",
      "tax": "990000.00",
      "total": "9990000.00",
      "items": [
        {
          "id": 1,
          "description": "Backend API Development (Phase 1-13)",
          "quantity": "1.00",
          "unit_price": "10000000.00",
          "discount": "1000000.00",
          "tax": "990000.00",
          "subtotal": "10000000.00",
          "total": "9990000.00"
        }
      ]
    }
  }
  ```

---

### 5.2 Invoice Lifecycle Actions
* **Send Invoice:** `POST /api/invoices/{invoice}/send`
  * Mengubah status dari `draft` $\rightarrow$ `sent`.
* **Void Invoice:** `POST /api/invoices/{invoice}/void`
  * Membatalkan invoice yang sudah pernah dikirim (`sent` atau `partially_paid`).
* **Cancel Invoice:** `POST /api/invoices/{invoice}/cancel`
  * Membatalkan invoice yang masih dalam status `draft`.
* **Download PDF:** `GET /api/invoices/{invoice}/pdf`
  * Mengembalikan binary stream `application/pdf` yang siap diunduh atau dipratinjau di browser.

---

## 6. Invoice Items

* Model `InvoiceItem` menyimpan snapshot permanen harga, kuantitas, deskripsi, diskon, dan pajak saat invoice dibuat.
* Perubahan katalog produk di kemudian hari tidak akan pernah memengaruhi riwayat invoice yang sudah dibuat.
* Item hanya dapat dimodifikasi saat invoice berstatus `draft`. Setelah berstatus `sent` atau `paid`, invoice terkunci secara permanen.

---

## 7. Payments

### 7.1 Record Payment
* **Method:** `POST`
* **URL:** `/api/invoices/{invoice}/payments`
* **Authentication:** `Bearer Token`
* **Request Body (JSON):**
  | Field | Type | Rules | Keterangan |
  |---|---|---|---|
  | `amount` | numeric | required, min:0.01, decimal:0,2 | Nominal bayar |
  | `currency` | string | nullable, size:3 | Harus sama dengan mata uang invoice |
  | `status` | string | nullable, in:pending,paid,failed,cancelled | Default 'paid' |
  | `payment_method` | string | nullable, max:50 | e.g. bank_transfer, qris, cc |
  | `transaction_id` | string | nullable, max:100 | Ref ID transaksi unik |
  | `notes` | string | nullable, max:1000 | Catatan internal |
* **Aturan Bisnis (Zero-Overpayment):**
  * Backend menghitung `outstanding_balance = total - sum(paid_payments)`.
  * Nominal pembayaran yang melebihi outstanding balance akan ditolak (HTTP 422: `"Payment amount exceeds invoice outstanding balance."`).
  * Jika `total_paid < total`: status invoice otomatis menjadi `partially_paid`.
  * Jika `total_paid >= total`: status invoice otomatis menjadi `paid`.
  * Client **dilarang keras** mengubah status invoice menjadi `paid` secara manual.

---

### 7.2 Process Pending Payment
* **Method:** `POST`
* **URL:** `/api/payments/{payment}/process`
* **Authentication:** `Bearer Token`
* **Idempotency:** Payment yang sudah berstatus `paid` tidak dapat diproses dua kali (mencegah double-crediting).

---

### 7.3 Refund Payment
* **Method:** `POST`
* **URL:** `/api/payments/{payment}/refund`
* **Authentication:** `Bearer Token`
* **Request Body:**
  ```json
  {
    "amount": 2500000.00,
    "reason": "Pembatalan proyek oleh klien"
  }
  ```
* Mendelegasikan refund ke payment gateway provider dan memperbarui status invoice kembali ke `partially_paid` atau `sent`.

---

### 7.4 Gateway Status Query
* **Method:** `GET`
* **URL:** `/api/payments/{payment}/status`
* **Authentication:** `Bearer Token`
* Mengambil status transaksi langsung dari gateway provider secara realtime.

---

## 8. Webhooks

Endpoint untuk menerima notifikasi asynchronous server-to-server dari payment gateway (Midtrans, Xendit, Stripe, dll).

* **Method:** `POST`
* **URL:** `/api/webhooks/payment/{provider}`
* **Authentication:** Tidak ada (Unauthenticated / Public server-to-server)
* **Request Body (JSON):**
  ```json
  {
    "event_id": "evt_20260928_892182",
    "event_type": "payment.success",
    "transaction_id": "TRX-20260928-89218291",
    "data": {
      "amount": 4995000.00,
      "status": "paid"
    }
  }
  ```
* **Event Types Didukung:**
  * Sukses: `payment.success`, `payment.paid`, `settlement`, `capture`
  * Gagal: `payment.failed`, `deny`, `cancel`, `expire`
  * Refund: `payment.refunded`, `refund`
* **Idempotency & Race Condition Handling:**
  * Dilindungi composite unique index di database: `(provider, event_id)`.
  * Pengiriman pertama: diproses secara atomik dalam `DB::transaction` $\rightarrow$ mengembalikan HTTP 200 `{ "status": "processed" }`.
  * Pengiriman kedua (duplikat): langsung dilewati $\rightarrow$ mengembalikan HTTP 200 `{ "status": "duplicate", "message": "Duplicate webhook event detected, skipping" }`.
  * Pengiriman concurrent: thread kedua menangkap `UniqueConstraintViolationException` dan melewatinya dengan aman tanpa terjadi double-crediting.
  * Retryable: jika pemrosesan pertama mengalami kegagalan teknis (status `failed`), webhook berikutnya dengan `event_id` yang sama diizinkan untuk diproses ulang.

---

## 9. Reports

Laporan analitik keuangan yang dihitung secara efisien di level database menggunakan fungsi agregasi SQL (`COUNT`, `SUM`, conditional `CASE WHEN`, dan `COALESCE`) tanpa perulangan memori di PHP.

* **Method:** `GET`
* **URL:** `/api/reports/revenue`
* **Authentication:** `Bearer Token`
* **Query Parameters:**
  | Parameter | Type | Keterangan |
  |---|---|---|
  | `date_from` | date | Format `YYYY-MM-DD` (berdasarkan `issue_date` invoice) |
  | `date_to` | date | Format `YYYY-MM-DD` (`after_or_equal:date_from`) |
  | `customer_id` | integer | Filter metrik untuk satu pelanggan spesifik |
  | `payment_status` | string | `draft`, `sent`, `partially_paid`, `paid`, `void`, `cancelled`, `overdue` |
  | `business_id` | integer | Opsional ID workspace (wajib dimiliki oleh user) |
* **Contoh Request:**
  ```bash
  curl -X GET "http://localhost:8000/api/reports/revenue?date_from=2026-09-01&date_to=2026-09-30" \
    -H "Authorization: Bearer <token>" \
    -H "Accept: application/json"
  ```
* **Successful Response (HTTP 200 OK):**
  ```json
  {
    "message": "Revenue report generated successfully",
    "data": {
      "total_invoices": 12,
      "total_invoice": 12,
      "total_invoiced": "60000000.00",
      "total_paid": "45000000.00",
      "total_outstanding": "15000000.00",
      "total_overdue": "5000000.00",
      "currency": "IDR"
    },
    "filters": {
      "date_from": "2026-09-01",
      "date_to": "2026-09-30",
      "customer": null,
      "customer_id": null,
      "payment_status": null,
      "business_id": 1
    }
  }
  ```

---

## 10. OpenAPI & Swagger UI

Dokumentasi ini tersedia dalam standar industri **OpenAPI 3.1.0**:

* **File Spesifikasi OpenAPI:**
  * Repository Root: [`openapi.yaml`](file:///c:/laragon/www/invoice-payment-os/openapi.yaml)
  * Public Asset: [`public/openapi.yaml`](file:///c:/laragon/www/invoice-payment-os/public/openapi.yaml)
* **Interactive Web Documentation (Swagger UI):**
  Buka browser dan akses salah satu URL berikut saat server aktif:
  * `http://localhost:8000/docs`
  * `http://localhost:8000/api/documentation`
* **Import ke Postman / Insomnia:**
  Developer dapat langsung mengimpor file `openapi.yaml` ke dalam Postman atau Insomnia untuk memperoleh seluruh collection API beserta parameter dan contoh request/response secara instan.
