<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : keywords.php
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
 * cek tabel & kolom
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

/**
 * ambil data keywords
 */
function od_get_keywords(array $range, int $limit = 20, int $ttl = 300): array
{
  $cacheKey = od_cache_key('keywords', [$range, $limit]);
  if ($c = od_cache_get($cacheKey, $ttl)) return $c;

  $db = DB::getInstance();

  if (!od_table_exists($db, 'biblio_topic')) {
    $data = [
      'ok' => false,
      'reason' => 'Tabel biblio_topic tidak ditemukan',
      'rows' => [],
      'sum' => 0
    ];
    od_cache_set($cacheKey, $data);
    return $data;
  }

  // total keyword unik
  $qTotal = $db->query("SELECT COUNT(*) FROM mst_topic");
  $totalKeyword = (int)$qTotal->fetchColumn();

  // keyword paling sering muncul (difilter periode input katalog)
  $q = $db->prepare("
    SELECT t.topic, COUNT(bt.biblio_id) AS total
    FROM biblio_topic bt
    JOIN mst_topic t ON t.topic_id = bt.topic_id
    JOIN biblio b ON b.biblio_id = bt.biblio_id
    WHERE b.input_date >= :s
      AND b.input_date < DATE_ADD(:e, INTERVAL 1 DAY)
    GROUP BY t.topic_id, t.topic
    ORDER BY total DESC
    LIMIT {$limit}
  ");
  $q->execute([
    ':s' => $range['start'].' 00:00:00',
    ':e' => $range['end'].' 00:00:00'
  ]);

  $rows = $q->fetchAll(PDO::FETCH_ASSOC);

  $data = [
    'ok' => true,
    'total_keyword' => $totalKeyword,
    'rows' => $rows,
    'sum' => array_sum(array_column($rows, 'total')),
  ];

  od_cache_set($cacheKey, $data);
  return $data;
}

$data = od_get_keywords(['start'=>$start,'end'=>$end], 25, 300);
?>

<div class="od-panel od-panel-soft">
  <div class="row g-3">

    <div class="col-12">
      <div class="od-section-title" style="margin:0;">Kata Kunci</div>
      <div class="small" style="opacity:.75;">
        Top keyword berdasarkan judul yang masuk katalog pada periode terpilih
      </div>
    </div>

    <div class="col-lg-6">
      <div class="od-metric-card">
        <div class="od-metric-label">Total Kata Kunci Unik</div>
        <div class="od-metric-value">
          <?= number_format((int)($data['total_keyword'] ?? 0)) ?>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="od-metric-card">
        <div class="od-metric-label">Total Pemakaian Keyword (Top)</div>
        <div class="od-metric-value">
          <?= number_format((int)($data['sum'] ?? 0)) ?>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="od-panel" style="padding:14px;">
        <div class="small mb-2">Top Kata Kunci</div>

        <table class="table table-sm table-dark mb-0">
          <thead>
            <tr>
              <th style="width:40px;">#</th>
              <th>Kata Kunci</th>
              <th style="width:160px;">Jumlah Judul</th>
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
                <td><?= od_h($r['topic']) ?></td>
                <td><?= number_format((int)$r['total']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>

        <div class="small" style="opacity:.65; margin-top:10px;">
          Catatan: Perhitungan berdasarkan relasi <code>biblio_topic</code> dan difilter oleh
          <code>biblio.input_date</code>.
        </div>
      </div>
    </div>

  </div>
</div>
