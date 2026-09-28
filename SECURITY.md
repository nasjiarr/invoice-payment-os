# Security Policy & Considerations — Invoice & Payment OS

Dokumen ini menjelaskan arsitektur keamanan, mitigasi ancaman, dan kebijakan perlindungan data yang diterapkan pada aplikasi **Invoice & Payment OS**.

---

## 🛡️ Ikhtisar Arsitektur Keamanan

Aplikasi ini mengadopsi prinsip **Defense in Depth** dan **Zero-Trust**:
* **Zero-Trust Pricing & Calculation:** Klien/frontend tidak pernah dipercaya dalam menentukan subtotal, diskon, pajak, grand total, maupun sisa tagihan (*outstanding balance*).
* **Strict Tenant Boundary:** Data sepenuhnya terisolasi per Bisnis/Workspace untuk mencegah *Insecure Direct Object References* (IDOR).
* **Concurrency & Race Condition Immunity:** Menggunakan transaksi atomik database (`DB::transaction`) dan *pessimistic row locking* (`lockForUpdate()`) untuk mencegah eksploitasi transaksi pembayaran paralel.
* **Idempotent Webhooks:** Deduplikasi transaksi gateway melalui tabel event berindeks unik ganda (`provider`, `event_id`).

---

## 🔒 18 Pilar Keamanan & Mitigasi Ancaman

### 1. Authentication (Autentikasi)
* Menggunakan **Laravel Sanctum** untuk Personal Access Tokens (Bearer Token).
* Token disimpan dalam bentuk hash SHA-256 di database (`personal_access_tokens`).
* Password di-hash menggunakan algoritma **Bcrypt** dengan `BCRYPT_ROUNDS=12`.
* Pencabutan token (*token revocation*) otomatis menghapus token aktif dari database saat `/api/logout`. Percobaan akses ulang menggunakan token yang telah dicabut langsung ditolak (`401 Unauthenticated`).
* Format Bearer Token palsu atau malformed otomatis ditolak (`401 Unauthenticated`).

### 2. Authorization (Otorisasi)
* Otorisasi berbasis Policy dan Gate diterapkan pada setiap endpoint:
  * [`BusinessPolicy`](app/Policies/BusinessPolicy.php)
  * [`CustomerPolicy`](app/Policies/CustomerPolicy.php)
  * [`ProductPolicy`](app/Policies/ProductPolicy.php)
  * [`InvoicePolicy`](app/Policies/InvoicePolicy.php)
  * [`PaymentPolicy`](app/Policies/PaymentPolicy.php)
* Setiap request memverifikasi keanggotaan pengguna di dalam bisnis yang bersangkutan (`owner_id` atau relasi pivot `business_user`).

### 3. IDOR (Insecure Direct Object Reference) & Multi-Tenant Isolation
* **Query Scoping:** Seluruh query koleksi menggunakan scope multi-tenant `accessibleBy($user)` sehingga pengguna tidak dapat melihat data bisnis lain dalam response list.
* **Cross-Tenant Mutation Guard:** Upaya membaca, mengubah, menghapus, atau memicu aksi (`send`, `void`, `cancel`, `pdf`, `process`, `refund`) terhadap resource milik bisnis lain ditolak tegas (`403 Forbidden`).
* **Cross-Tenant Injection Guard:** Saat membuat atau memperbarui Invoice di Bisnis A, sistem memvalidasi bahwa `customer_id` dan seluruh `product_id` di dalam items benar-benar milik Bisnis A. Percobaan menyuntikkan ID milik Bisnis B ditolak (`422 Unprocessable Content`).

### 4. Mass Assignment Protection
* Seluruh Eloquent Model menggunakan atribut `#[Fillable([...])]` atau properti `$fillable` yang eksplisit.
* Controller memfilter input yang aman menggunakan `$request->safe()->except(['owner_id', 'business_id', 'status', 'total', 'subtotal'])`. Upaya memanipulasi kolom sensitif melalui payload JSON diabaikan oleh sistem.

### 5. SQL Injection Prevention
* Menggunakan Eloquent ORM dan PDO Prepared Statements dengan parameter binding otomatis.
* Query agregat laporan finansial di [`RevenueReportService`](app/Domain/Report/RevenueReportService.php) menggunakan parameterized binding `?` pada klausa `selectRaw`.
* Parameter sorting (`sort`, `direction`) di whitelist secara ketat (`['id', 'name', 'email', 'price', 'created_at']`), mencegah injeksi kolom atau query SQL arbitrary.

### 6. XSS (Cross-Site Scripting)
* Response API murni dalam format JSON (`Content-Type: application/json`), kebal terhadap injeksi skrip HTML/JS di browser.
* Template dokumen PDF ([`resources/views/invoices/pdf.blade.php`](resources/views/invoices/pdf.blade.php)) menerapkan escaping bawaan Blade `{{ }}` serta `{!! nl2br(e($text)) !!}` untuk entitas multiline, mengubah karakter berbahaya seperti `<script>` menjadi entitas aman `&lt;script&gt;`.

### 7. CSRF (Cross-Site Request Forgery)
* Endpoint API berbasis stateless Bearer Token dilindungi dari serangan CSRF karena browser tidak mengirimkan Bearer token secara otomatis dalam request lintas domain (berbeda dengan cookie-based authentication).

### 8. Validation (Validasi Input)
* Seluruh input diproses melalui **Form Request** berdedikasi:
  * Uang/Moneter: divalidasi dengan `numeric`, `min:0`, `decimal:0,2`. Nilai negatif tidak diizinkan.
  * Tanggal: `due_date` wajib `after_or_equal:issue_date`.
  * Status: Dibatasi menggunakan enum rules (`Rule::in(...)`).

### 9. Rate Limiting (Pembatasan Laju Request)
Dikonfigurasi di [`AppServiceProvider`](app/Providers/AppServiceProvider.php) menggunakan Laravel RateLimiter:
* **Login (`throttle:login`):** Maksimal 5 percobaan per menit per kombinasi email + IP. Mencegah serangan *credential stuffing* dan *brute-force attack*.
* **Register (`throttle:register`):** Maksimal 10 registrasi per menit per IP untuk mencegah *bot registration flood*.
* **Webhooks (`throttle:webhook`):** Maksimal 120 request per menit per IP.
* **General API (`throttle:api`):** Maksimal 120 request per menit per user/IP.

### 10. Sensitive Data Exposure
* Atribut `password` dan `remember_token` pada model `User` disembunyikan menggunakan `#[Hidden]`.
* Output API selalu disaring melalui **API Resources**, memastikan metadata internal database atau hash password tidak pernah bocor ke klien.
* Endpoint non-existent (`404 Not Found`) mengembalikan response JSON seragam `{"message": "Resource not found."}` tanpa membeberkan path file, nama tabel, atau stack trace internal.

### 11. Payment Manipulation Defense
* **Client Pricing Ignored:** Kalkulasi invoice (subtotal, diskon, pajak, total) dihitung ulang di server via `InvoiceCalculator` dari snapshot item.
* **Overpayment Guard:** Pembayaran tidak dapat melebihi sisa tagihan (*outstanding balance*).
* **Double Processing Guard:** Pembayaran berstatus non-pending tidak dapat diproses ulang (`processPayment`).
* **Concurrency Locking:** Transaksi pembayaran menggunakan `lockForUpdate()` pada baris `Invoice` dan `Payment` di database. Jika ada dua pembayaran masuk bersamaan, sistem memproses secara sekuensial dan menolak request yang melebihi saldo.

### 12. Webhook Security & Signature Verification
* **Provider Whitelisting:** Endpoint `/api/webhooks/payment/{provider}` hanya menerima provider terdaftar (`mock`, `midtrans`, `xendit`). Provider lain ditolak (`422 Unprocessable Content`).
* **Signature Verification:** Jika `PAYMENT_WEBHOOK_SECRET` dikonfigurasi, sistem memvalidasi tanda tangan digital (HMAC-SHA256 atau Callback Token) menggunakan `hash_equals()`. Webhook tanpa tanda tangan yang valid langsung ditolak.
* **Zero-Trust Amount:** Status sukses dari webhook memicu eksekusi pembayaran menggunakan nominal yang telah tercatat sebelumnya di database, bukan mempercayai nominal di payload webhook.

### 13. Duplicate Webhook & Idempotency
* Tabel `payment_events` memiliki *composite UNIQUE index* pada kolom `(provider, event_id)`.
* Jika webhook yang sama dikirim dua kali:
  * Request #1: Diproses atomik, pembayaran diperbarui, event dicatat sebagai `processed`.
  * Request #2: Dideteksi sebagai duplikat, dilewati secara aman (`duplicate`), tanpa memicu pembaruan saldo ganda.
* Jika terjadi *race condition* (dua webhook tiba bersamaan), database melempar `UniqueConstraintViolationException` yang ditangkap secara elegan oleh processor tanpa merusak konsistensi data.

### 14. File Upload Security
* Aplikasi saat ini tidak membuka attack surface untuk upload berkas sembarang dari pengguna. Berkas PDF dibuat secara dinamis di memori dari database dan di-stream langsung ke browser pengguna yang terotorisasi.

### 15. Secrets & Environment Management
* Semua kunci API, webhook secret, dan kredensial database disimpan secara eksklusif dalam file `.env` dan tidak pernah di-hardcode di kode program.
* Berkas `.gitignore` mengecualikan berkas `.env` dari version control.

### 16. Audit Trail & Logging
* Model `AuditLog` mencatat setiap aksi signifikan:
  * `invoice.created`, `invoice.sent`, `invoice.voided`, `invoice.cancelled`
  * `payment.created`, `payment.received`, `payment.failed`, `payment.cancelled`, `payment.refunded`
  * `invoice.reminder_sent`
* Catatan audit menyimpan `business_id`, `user_id`, aksi, timestamp, dan metadata kontekstual.

### 17. Error Handling & Exception Masking
* Seluruh exception API ditangkap di [`bootstrap/app.php`](bootstrap/app.php) dan diformat menjadi JSON standar.
* Pesan error ramah pengguna tanpa menampilkan informasi sensitif seperti *database credentials*, *SQL syntax errors*, atau *server directory paths*.

### 18. API Abuse & State Transition Enforcement
* **Strict State Machine:** Invoice yang telah berstatus `paid` tidak dapat dibatalkan atau di-void (`422`). Invoice berstatus `void` atau `cancelled` tidak dapat dikirim ulang (`422`).
* **Draft Immutability:** Hanya invoice berstatus `draft` yang dapat diubah baris item atau catatannya. Invoice yang sudah dikirim ke pelanggan terkunci dari perubahan harga atau deskripsi.

---

## 🧪 Verifikasi Pengujian Keamanan

Seluruh kontrol keamanan di atas diuji secara otomatis melalui test suite terisolasi:
* [`tests/Feature/SecurityBoundaryTest.php`](tests/Feature/SecurityBoundaryTest.php) (8 tests, 56 assertions)
* [`tests/Feature/SecurityAuditTest.php`](tests/Feature/SecurityAuditTest.php) (7 tests, 38 assertions)

Jalankan test keamanan dengan:
```bash
php artisan test --filter=Security
```
