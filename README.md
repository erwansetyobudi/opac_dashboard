# Plugin OPAC Dashboard by Erwan Setyo Budi

Plugin **OPAC Dashboard** untuk **SLiMS Bulian (≥ 9.3.0)** yang menyediakan analisis statistik OPAC, bibliometrik, dan sirkulasi dalam tampilan dashboard interaktif berbasis web.

---

## Fitur Utama

- **Ringkasan Statistik**
  - Total judul, eksemplar, anggota
  - Kunjungan, pengunjung unik, dan pageviews
  - Filter periode (tanggal)

- **Trend**
  - Penambahan judul
  - Kunjungan perpustakaan
  - Halaman dilihat (harian / bulanan otomatis)

- **Bibliometrik**
  - Co-Author Network (jaringan kolaborasi penulis)
  - Subject Network
  - Author – Subject Network
  - Topic Bubble Chart

- **Performa Sirkulasi**
  - Analisis peminjaman (loan)
  - Distribusi berdasarkan:
    - Tipe anggota
    - Jenis koleksi
    - Lokasi koleksi
  - Top judul dan top peminjam

- **Visualisasi**
  - Grafik interaktif (D3.js)
  - Zoom, filter, dan share URL
  - Responsive & fullscreen OPAC

---

## Persyaratan Sistem

- **SLiMS Bulian ≥ 9.3.0**
- **PHP ≥ 8.1**
- **MySQL 5.7+ / MariaDB 10.3+**
- Ekstensi PHP:
  - `pdo`
  - `pdo_mysql`
  - `mbstring`
  - `json`
  - `gettext`
- Browser modern (Chrome, Firefox, Edge)

---

## Instalasi

1. **Salin plugin**
   ```bash
   plugins/opac_dashboard/
2. Aktifkan Plugin dengan cara login sebgai super admin, klik Menu Sistem --> Plugins
3. Akses dasboard melalui url index.php?p=dashboard

## Screen Shoot
<img width="1365" height="646" alt="preview" src="https://github.com/user-attachments/assets/20ed4e58-d691-4cda-b5f4-a1c67a0223ff" />

