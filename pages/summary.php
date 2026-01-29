<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : summary.php
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 */

if (!defined('INDEX_AUTH') || INDEX_AUTH != 1) {
    die('can not access this file directly');
}

// $stats berasal dari opac_dashboard.inc.php
?>

<div class="od-panel od-panel-soft">
  <div class="row g-3">

    <!-- TOTAL JUDUL -->
    <div class="col-lg-4 pb-3">
      <div class="od-metric-card">
        <div class="od-metric-label">Total Judul Bibliografi</div>
        <div class="od-metric-value"><?= number_format((int)$stats['total_document']) ?></div>
        <div class="od-metric-icon"><i class="fas fa-book"></i></div>
      </div>
    </div>

    <!-- TOTAL JUDUL UNIK -->
    <div class="col-lg-4 pb-3">
      <div class="od-metric-card">
        <div class="od-metric-label">Total Judul Unik</div>
        <div class="od-metric-value"><?= number_format((int)$stats['total_unique_doc']) ?></div>
        <div class="od-metric-icon"><i class="fas fa-copy"></i></div>
      </div>
    </div>

    <!-- TOTAL EKSEMPLAR -->
    <div class="col-lg-4 pb-3">
      <div class="od-metric-card">
        <div class="od-metric-label">Total Eksemplar</div>
        <div class="od-metric-value"><?= number_format((int)$stats['total_library']) ?></div>
        <div class="od-metric-icon"><i class="fas fa-layer-group"></i></div>
      </div>
    </div>

    <!-- TOTAL ANGGOTA -->
    <div class="col-lg-6 pb-3">
      <div class="od-metric-card od-metric-wide">
        <div class="od-metric-label">Total Anggota Terdaftar</div>
        <div class="od-metric-value"><?= number_format((int)$stats['total_institution']) ?></div>
        <div class="od-metric-icon"><i class="fas fa-id-card"></i></div>
      </div>
    </div>

    <!-- TOTAL BERKAS DIGITAL -->
    <div class="col-lg-6 pb-3">
      <div class="od-metric-card od-metric-wide">
        <div class="od-metric-label">Total Berkas Digital</div>
        <div class="od-metric-value"><?= number_format((int)$stats['total_repository']) ?></div>
        <div class="od-metric-icon"><i class="fas fa-paperclip"></i></div>
      </div>
    </div>

    <!-- PENGUNJUNG UNIK -->
    <div class="col-lg-4 pb-3">
      <div class="od-metric-card">
        <div class="od-metric-label">Pengunjung Unik</div>
        <div class="od-metric-value"><?= number_format((int)$stats['total_visitors']) ?></div>
        <div class="od-metric-icon"><i class="fas fa-users"></i></div>
      </div>
    </div>

    <!-- TOTAL KUNJUNGAN -->
    <div class="col-lg-4 pb-3">
      <div class="od-metric-card">
        <div class="od-metric-label">Total Kunjungan</div>
        <div class="od-metric-value"><?= number_format((int)$stats['total_visits']) ?></div>
        <div class="od-metric-icon"><i class="fas fa-map-marker-alt"></i></div>
      </div>
    </div>

    <!-- HALAMAN DILIHAT -->
    <div class="col-lg-4 pb-3">
      <div class="od-metric-card">
        <div class="od-metric-label">Halaman Dilihat</div>
        <div class="od-metric-value"><?= number_format((int)$stats['total_pageviews']) ?></div>
        <div class="od-metric-icon"><i class="fas fa-eye"></i></div>
      </div>
    </div>

  </div>
</div>
