<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : years.php
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

use SLiMS\DB;

/**
 * helper cek kolom
 */
function od_col_exists($db, string $table, string $col): bool
{
  try {
    $q = $db->query("SHOW COLUMNS FROM `$table`");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
      if (strtolower($r['Field']) === strtolower($col)) return true;
    }
  } catch (\Throwable $e) {}
  return false;
}

/**
 * Ambil data tahun terbit
 */
function od_get_years(array $range, int $ttl = 300): array
{
  $cacheKey = od_cache_key('years', $range);
  if ($c = od_cache_get($cacheKey, $ttl)) return $c;

  $db = DB::getInstance();

  // tentukan kolom tahun terbit
  $yearCol = null;
  if (od_col_exists($db, 'biblio', 'publish_year')) {
    $yearCol = 'publish_year';
  } elseif (od_col_exists($db, 'biblio', 'publication_year')) {
    $yearCol = 'publication_year';
  }

  if (!$yearCol) {
    $data = [
      'ok' => false,
      'reason' => 'Kolom tahun terbit tidak ditemukan',
      'rows' => [],
      'sum' => 0,
    ];
    od_cache_set($cacheKey, $data);
    return $data;
  }

  // total judul periode
  $qTotal = $db->prepare("
    SELECT COUNT(*)
    FROM biblio
    WHERE input_date >= :s
      AND input_date < DATE_ADD(:e, INTERVAL 1 DAY)
  ");
  $qTotal->execute([
    ':s' => $range['start'].' 00:00:00',
    ':e' => $range['end'].' 00:00:00'
  ]);
  $totalPeriod = (int)$qTotal->fetchColumn();

  // grouping tahun terbit
  $q = $db->prepare("
    SELECT {$yearCol} AS year, COUNT(*) AS total
    FROM biblio
    WHERE {$yearCol} IS NOT NULL
      AND {$yearCol} <> ''
      AND input_date >= :s
      AND input_date < DATE_ADD(:e, INTERVAL 1 DAY)
    GROUP BY {$yearCol}
    ORDER BY {$yearCol} DESC
  ");
  $q->execute([
    ':s' => $range['start'].' 00:00:00',
    ':e' => $range['end'].' 00:00:00'
  ]);

  $rows = $q->fetchAll(PDO::FETCH_ASSOC);

  $data = [
    'ok' => true,
    'year_col' => $yearCol,
    'total_period' => $totalPeriod,
    'rows' => $rows,
    'sum' => array_sum(array_column($rows, 'total')),
  ];

  od_cache_set($cacheKey, $data);
  return $data;
}

$data = od_get_years(['start'=>$start,'end'=>$end], 300);
?>

<div class="od-panel od-panel-soft">
  <div class="row g-3">

    <div class="col-12">
      <div class="od-section-title" style="margin:0;">Tahun Terbit</div>
      <div class="small" style="opacity:.75;">
        Kolom: <?= od_h($data['year_col'] ?? '-') ?>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="od-metric-card">
        <div class="od-metric-label">Judul Masuk Katalog (Periode)</div>
        <div class="od-metric-value"><?= number_format((int)$data['total_period']) ?></div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="od-metric-card">
        <div class="od-metric-label">Total Judul Bertahun Terbit</div>
        <div class="od-metric-value"><?= number_format((int)$data['sum']) ?></div>
      </div>
    </div>

    <div class="col-12">
      <div class="od-panel" style="padding:14px;">
        <div class="small mb-2">Distribusi Judul per Tahun Terbit</div>

        <table class="table table-sm table-dark mb-0">
          <thead>
            <tr>
              <th style="width:80px;">Tahun</th>
              <th style="width:160px;">Jumlah Judul</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($data['rows'])): ?>
            <tr>
              <td colspan="2" style="opacity:.75;">Tidak ada data pada periode ini.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($data['rows'] as $r): ?>
              <tr>
                <td><?= od_h($r['year']) ?></td>
                <td><?= number_format((int)$r['total']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>

        <div class="small" style="opacity:.65; margin-top:10px;">
          Catatan: Data dikelompokkan berdasarkan tahun terbit metadata, difilter oleh tanggal input katalog.
        </div>
      </div>
    </div>

  </div>
</div>
