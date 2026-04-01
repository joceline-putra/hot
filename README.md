## Hot Sync (Klien → VPS)

Project ini untuk sinkronisasi data hotel dari 2 PC Klien (Microsoft Access .mdb/.accdb) ke 1 VPS (MySQL) via API.

- Klien: service Python baca tabel Access lalu kirim perubahan (per branch) ke VPS
- VPS: API PHP menerima payload, validasi API key, lalu upsert ke MySQL

### Struktur Folder

- client_service/
  - config.py (konfigurasi cabang, URL, API key, path DB Access)
  - sync_service.py (service sinkronisasi)
  - requirements.txt
- api/
  - config.php (API key + koneksi MySQL)
  - v1/sync/index.php (endpoint sync)
- v1/sync/index.php (alias endpoint agar URL bisa /v1/sync)
- hotel.sql (schema MySQL VPS)

### Database (VPS / MySQL)

Import schema:
- Jalankan file hotel.sql ke database MySQL VPS.

Catatan penting (multi-cabang):
- Tabel sinkronisasi memakai kombinasi (branch_id, source_id) sebagai unik.
- id di VPS adalah internal (AUTO_INCREMENT).
- source_id berasal dari nilai kolom unik di DB Klien (kolom ini ditentukan lewat primary_key di config Klien).

### Mapping Tabel (Klien → VPS)

Di Klien (Access) nama tabel biasanya tanpa underscore, sedangkan di VPS (MySQL) nama tabel memakai underscore di depan.

- source: nama tabel di Access (contoh: bill_info)
- target: nama tabel di VPS (contoh: _bill_info)

Kolom primary_key:
- Bukan harus "Primary Key" secara constraint di Access.
- Wajib kolom yang ada dan nilainya unik/stabil untuk membedakan tiap baris.

Catatan:
- Jika 1 bill_no bisa punya banyak baris (contoh di bill_rooms), jangan pakai bill_no saja sebagai primary_key. Pakai kolom yang benar-benar unik (mis. pms_line_id, atau kombinasi kolom yang unik).

### API (VPS / PHP)

Konfigurasi ada di api/config.php:
- api_key: kunci akses untuk mencegah orang lain mengirim data ke endpoint Anda
- db: host/port/dbname/user/pass untuk koneksi MySQL

Endpoint:
- POST https://hotel.cvmaj.com/v1/sync
- Header wajib: X-API-Key: <api_key>
- Content-Type: application/json

Contoh payload:
{
  "branch_id": 1,
  "branch_session": "HL-SMG-001",
  "sent_at": "2026-04-01T00:00:00Z",
  "table": "_bill_info",
  "primary_key": "bill_no",
  "rows": [
    {"bill_no": "B001", "guest_name": "JOE"}
  ]
}

Catatan:
- API akan membuat source_id dari nilai primary_key (contoh: bill_no).
- Kolom primary_key tidak dihapus dari data kecuali primary_key = "id".

### Service Klien (Python / Windows)

1) Edit client_service/config.py:
- branch_id, branch_session: identitas cabang
- api_url: contoh https://hotel.cvmaj.com/v1/sync
- api_key: harus sama dengan api/config.php
- db_path: path ke file .mdb/.accdb
- db_password: password Access (default: eLock0103)
- interval_seconds: interval sync

2) Install dependency:
- pip install -r client_service/requirements.txt

3) Jalankan service:
- python client_service/sync_service.py

Catatan driver Access:
- PC klien wajib punya ODBC driver "Microsoft Access Driver (*.mdb, *.accdb)".

### Keamanan

- Jangan simpan kredensial database asli di README atau repo.
- Jangan expose api_key ke publik.
- Idealnya simpan api_key + db password di environment / konfigurasi server, bukan hardcode.

### Troubleshooting

- Error konek Access: pastikan driver Access terpasang dan db_path benar.
- HTTP 401: api_key klien tidak sama dengan api/config.php.
- Sync tidak masuk: cek endpoint /v1/sync bisa diakses dan database MySQL sesuai.
