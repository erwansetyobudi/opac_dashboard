<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : locations.php
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
 * cek tabel & kolom sederhana (anti error kalau beda struktur)
 */
function od_table_exists($db, string $table): bool
{
  try {
    $db->query("SELECT 1 FROM `$table` LIMIT 1");
    return true;
  } catch (\Throwable $e) {
    return false;
  }
}

function od_get_publish_places(array $range, int $limit = 25, int $ttl = 300): array
{
  $cacheKey = od_cache_key('publish_places', [$range, $limit]);
  if ($c = od_cache_get($cacheKey, $ttl)) return $c;

  $db = DB::getInstance();

  // pastikan tabel kunci ada
  if (!od_table_exists($db, 'item') || !od_table_exists($db, 'mst_place') || !od_table_exists($db, 'biblio')) {
    $data = [
      'ok' => false,
      'reason' => 'Tabel item / mst_place / biblio tidak ditemukan',
      'total_publish_place' => 0,
      'rows' => [],
      'sum_items' => 0,
    ];
    od_cache_set($cacheKey, $data);
    return $data;
  }

  // total kota terbit terdaftar
  $totalPlace = (int)$db->query("SELECT COUNT(*) FROM mst_place")->fetchColumn();

  // TOP kota terbit berdasarkan jumlah item yang terkait judul dalam periode
  $q = $db->prepare("
    SELECT
      p.place_name AS publish_place,
      COUNT(i.item_id) AS total_item
    FROM item i
    JOIN biblio b ON b.biblio_id = i.biblio_id
    LEFT JOIN mst_place p ON p.place_id = b.publish_place_id
    WHERE b.input_date >= :s
      AND b.input_date < DATE_ADD(:e, INTERVAL 1 DAY)
      AND b.publish_place_id IS NOT NULL
    GROUP BY p.place_id, p.place_name
    ORDER BY total_item DESC
    LIMIT {$limit}
  ");
  $q->execute([
    ':s' => $range['start'].' 00:00:00',
    ':e' => $range['end'].' 00:00:00'
  ]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);

  $data = [
    'ok' => true,
    'total_publish_place' => $totalPlace,
    'rows' => $rows,
    'sum_items' => array_sum(array_map(fn($r) => (int)$r['total_item'], $rows)),
  ];

  od_cache_set($cacheKey, $data);
  return $data;
}

$data = od_get_publish_places(['start'=>$start,'end'=>$end], 25, 300);
?>

<div class="od-panel od-panel-soft">
  <div class="row g-3">

    <div class="col-12">
      <div class="od-section-title" style="margin:0;">Kota Terbit</div>
      <div class="small" style="opacity:.75;">
        Top kota terbit koleksi berdasarkan jumlah eksemplar (item) untuk judul yang masuk katalog pada periode terpilih
      </div>
    </div>

    <div class="col-lg-6">
      <div class="od-metric-card">
        <div class="od-metric-label">Total Kota Terbit Terdaftar</div>
        <div class="od-metric-value"><?= number_format((int)($data['total_publish_place'] ?? 0)) ?></div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="od-metric-card">
        <div class="od-metric-label">Total Item (Top)</div>
        <div class="od-metric-value"><?= number_format((int)($data['sum_items'] ?? 0)) ?></div>
      </div>
    </div>

    <div class="col-12">
      <div class="od-panel" style="padding:14px;">
        <div class="small mb-2">Top Kota Terbit</div>

        <table class="table table-sm table-dark mb-0">
          <thead>
            <tr>
              <th style="width:40px;">#</th>
              <th>Kota Terbit</th>
              <th style="width:180px;">Jumlah Item</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($data['rows'])): ?>
            <tr>
              <td colspan="3" style="opacity:.75;">Tidak ada data pada periode ini.</td>
            </tr>
          <?php else: ?>
            <?php $no=1; foreach ($data['rows'] as $r): ?>
              <tr>
                <td><?= $no++ ?></td>
                <td><?= od_h($r['publish_place']) ?></td>
                <td><?= number_format((int)$r['total_item']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>

        <div class="small" style="opacity:.65; margin-top:10px;">
          Catatan: perhitungan memakai <code>biblio.publish_place_id</code> → <code>mst_place</code>,
          difilter oleh <code>biblio.input_date</code>. Hanya menampilkan judul yang memiliki data kota terbit.
        </div>
      </div>
    </div>

  </div>
</div>