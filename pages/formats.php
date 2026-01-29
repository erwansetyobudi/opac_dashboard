<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : formats.php
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

function od_table_exists($db, string $table): bool
{
  try {
    $q = $db->prepare("SHOW TABLES LIKE :t");
    $q->execute([':t' => $table]);
    return (bool)$q->fetchColumn();
  } catch (\Throwable $e) {
    return false;
  }
}

function od_column_exists($db, string $table, string $col): bool
{
  try {
    $q = $db->query("SHOW COLUMNS FROM `$table`");
    $cols = $q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
      if (strtolower($c['Field']) === strtolower($col)) return true;
    }
    return false;
  } catch (\Throwable $e) {
    return false;
  }
}

/**
 * Ambil statistik format koleksi
 * - Utama: item.coll_type_id -> mst_coll_type.coll_type_name
 * - Fallback: item.media_type_id -> mst_media_type.media_type_name (jika ada)
 * - Filter periode pakai item.input_date (fallback date_created kalau perlu)
 */
function od_get_formats(array $range, int $ttl = 300): array
{
  $cacheKey = od_cache_key('formats', $range);
  if ($c = od_cache_get($cacheKey, $ttl)) return $c;

  $db = DB::getInstance();

  // tentukan kolom tanggal item (agar aman lintas versi)
  $dateCol = 'input_date';
  if (!od_column_exists($db, 'item', 'input_date')) {
    if (od_column_exists($db, 'item', 'date_created')) $dateCol = 'date_created';
  }

  // prioritas: coll_type_id + mst_coll_type
  $hasCollTypeId = od_column_exists($db, 'item', 'coll_type_id') && od_table_exists($db, 'mst_coll_type');

  // fallback: media_type_id + mst_media_type (tidak semua instalasi punya)
  $hasMediaTypeId = od_column_exists($db, 'item', 'media_type_id') && od_table_exists($db, 'mst_media_type');

  // total item pada periode
  $qTotal = $db->prepare("
    SELECT COUNT(*)
    FROM item
    WHERE {$dateCol} >= :s
      AND {$dateCol} < DATE_ADD(:e, INTERVAL 1 DAY)
  ");
  $qTotal->execute([':s'=>$range['start'].' 00:00:00', ':e'=>$range['end'].' 00:00:00']);
  $totalItems = (int)$qTotal->fetchColumn();

  if ($hasCollTypeId) {
    // ✅ JOIN ke mst_coll_type
    $qFmt = $db->prepare("
      SELECT
        COALESCE(NULLIF(mct.coll_type_name,''), 'Tidak Diketahui') AS format,
        COUNT(*) AS total
      FROM item i
      LEFT JOIN mst_coll_type mct ON mct.coll_type_id = i.coll_type_id
      WHERE i.{$dateCol} >= :s
        AND i.{$dateCol} < DATE_ADD(:e, INTERVAL 1 DAY)
      GROUP BY format
      ORDER BY total DESC
    ");
    $qFmt->execute([':s'=>$range['start'].' 00:00:00', ':e'=>$range['end'].' 00:00:00']);
    $rows = $qFmt->fetchAll(PDO::FETCH_ASSOC);

    $meta = [
      'date_col'   => "item.$dateCol",
      'format_src' => 'item.coll_type_id -> mst_coll_type.coll_type_name',
    ];

  } elseif ($hasMediaTypeId) {
    // fallback: JOIN ke mst_media_type (kalau ada di sistem)
    $qFmt = $db->prepare("
      SELECT
        COALESCE(NULLIF(mmt.media_type_name,''), 'Tidak Diketahui') AS format,
        COUNT(*) AS total
      FROM item i
      LEFT JOIN mst_media_type mmt ON mmt.media_type_id = i.media_type_id
      WHERE i.{$dateCol} >= :s
        AND i.{$dateCol} < DATE_ADD(:e, INTERVAL 1 DAY)
      GROUP BY format
      ORDER BY total DESC
    ");
    $qFmt->execute([':s'=>$range['start'].' 00:00:00', ':e'=>$range['end'].' 00:00:00']);
    $rows = $qFmt->fetchAll(PDO::FETCH_ASSOC);

    $meta = [
      'date_col'   => "item.$dateCol",
      'format_src' => 'item.media_type_id -> mst_media_type.media_type_name',
    ];

  } else {
    // fallback paling aman (tidak bikin error)
    $rows = [];
    $meta = [
      'date_col'   => "item.$dateCol",
      'format_src' => 'Tidak ada kolom/tabel master format terdeteksi',
    ];
  }

  $data = [
    'meta' => $meta,
    'total_items'   => $totalItems,
    'total_formats' => count($rows),
    'rows'          => $rows,
  ];

  od_cache_set($cacheKey, $data);
  return $data;
}

$data = od_get_formats(['start'=>$start,'end'=>$end], 300);
?>

<div class="od-panel od-panel-soft">
  <div class="row g-3">

    <div class="col-12">
      <div class="od-section-title" style="margin:0;">
        Format Koleksi (Periode)
      </div>
      <div class="small" style="opacity:.75;">
        Sumber: <?= od_h($data['meta']['date_col']) ?> | <?= od_h($data['meta']['format_src']) ?>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="od-metric-card">
        <div class="od-metric-label">Total Item (Periode)</div>
        <div class="od-metric-value"><?= number_format((int)$data['total_items']) ?></div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="od-metric-card">
        <div class="od-metric-label">Jumlah Format</div>
        <div class="od-metric-value"><?= number_format((int)$data['total_formats']) ?></div>
      </div>
    </div>

    <div class="col-12">
      <div class="od-panel" style="padding:14px;">
        <div class="small mb-2">Distribusi Format Koleksi</div>

        <table class="table table-sm table-dark mb-0">
          <thead>
            <tr>
              <th style="width:50px;">#</th>
              <th>Format</th>
              <th style="width:140px;">Jumlah Item</th>
              <th style="width:120px;">Persentase</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($data['rows'])): ?>
            <tr>
              <td colspan="4" style="opacity:.8;">
                Data kosong pada periode ini (atau tabel master format tidak terdeteksi).
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($data['rows'] as $i => $r):
              $pct = $data['total_items'] > 0
                ? round(((int)$r['total'] / $data['total_items']) * 100, 2)
                : 0;
            ?>
              <tr>
                <td><?= $i+1 ?></td>
                <td><?= od_h((string)$r['format']) ?></td>
                <td><?= number_format((int)$r['total']) ?></td>
                <td><?= $pct ?>%</td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>
