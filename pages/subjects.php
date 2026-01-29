<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : subjects.php
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
 * Ambil data subjek/topik (dengan cache)
 * Relasi standar SLiMS:
 * - biblio.input_date (filter periode)
 * - biblio_topic.biblio_id <-> biblio.biblio_id
 * - biblio_topic.topic_id <-> mst_topic.topic_id
 */
function od_get_subjects(array $range, int $limit = 25, int $ttl = 300): array
{
  $cacheKey = od_cache_key('subjects', ['start'=>$range['start'], 'end'=>$range['end'], 'limit'=>$limit]);
  if ($c = od_cache_get($cacheKey, $ttl)) return $c;

  $db = DB::getInstance();

  // deteksi tabel penting
  $hasBiblio      = od_table_exists($db, 'biblio');
  $hasBiblioTopic = od_table_exists($db, 'biblio_topic');
  $hasMstTopic    = od_table_exists($db, 'mst_topic');

  if (!$hasBiblio || !$hasBiblioTopic || !$hasMstTopic) {
    $data = [
      'meta' => [
        'ok' => false,
        'reason' => 'Tabel biblio / biblio_topic / mst_topic tidak lengkap',
      ],
      'total_biblio_period' => 0,
      'total_topics_period' => 0,
      'rows' => [],
    ];
    od_cache_set($cacheKey, $data);
    return $data;
  }

  // kolom tanggal biblio (umumnya input_date)
  $dateCol = 'input_date';
  if (!od_column_exists($db, 'biblio', 'input_date')) {
    // fallback jika instalasi beda
    if (od_column_exists($db, 'biblio', 'last_update')) $dateCol = 'last_update';
  }

  // kolom nama topik (umumnya "topic")
  $topicNameCol = 'topic';
  if (!od_column_exists($db, 'mst_topic', 'topic')) {
    // fallback jika penamaan beda
    if (od_column_exists($db, 'mst_topic', 'topic_name')) $topicNameCol = 'topic_name';
  }

  // total judul (biblio) pada periode
  $qTotalB = $db->prepare("
    SELECT COUNT(*)
    FROM biblio
    WHERE {$dateCol} >= :s
      AND {$dateCol} < DATE_ADD(:e, INTERVAL 1 DAY)
  ");
  $qTotalB->execute([':s'=>$range['start'].' 00:00:00', ':e'=>$range['end'].' 00:00:00']);
  $totalBiblioPeriod = (int)$qTotalB->fetchColumn();

  // total subjek unik pada periode (topic_id distinct)
  $qTotalT = $db->prepare("
    SELECT COUNT(DISTINCT bt.topic_id)
    FROM biblio_topic bt
    INNER JOIN biblio b ON b.biblio_id = bt.biblio_id
    WHERE b.{$dateCol} >= :s
      AND b.{$dateCol} < DATE_ADD(:e, INTERVAL 1 DAY)
  ");
  $qTotalT->execute([':s'=>$range['start'].' 00:00:00', ':e'=>$range['end'].' 00:00:00']);
  $totalTopicsPeriod = (int)$qTotalT->fetchColumn();

  // top subjek: hitung kemunculan relasi + jumlah judul unik yang memakai subjek tsb
  $limit = max(1, (int)$limit);
  $q = $db->prepare("
    SELECT
      COALESCE(NULLIF(mt.`{$topicNameCol}`,''), 'Tidak Diketahui') AS subject,
      COUNT(*) AS rel_total,
      COUNT(DISTINCT bt.biblio_id) AS biblio_total
    FROM biblio_topic bt
    INNER JOIN biblio b ON b.biblio_id = bt.biblio_id
    LEFT JOIN mst_topic mt ON mt.topic_id = bt.topic_id
    WHERE b.{$dateCol} >= :s
      AND b.{$dateCol} < DATE_ADD(:e, INTERVAL 1 DAY)
    GROUP BY subject
    ORDER BY rel_total DESC
    LIMIT {$limit}
  ");
  $q->execute([':s'=>$range['start'].' 00:00:00', ':e'=>$range['end'].' 00:00:00']);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);

  $data = [
    'meta' => [
      'ok' => true,
      'date_col' => "biblio.$dateCol",
      'topic_col' => "mst_topic.$topicNameCol",
      'source' => 'biblio_topic -> mst_topic (filtered by biblio date)',
    ],
    'total_biblio_period' => $totalBiblioPeriod,
    'total_topics_period' => $totalTopicsPeriod,
    'rows' => $rows,
  ];

  od_cache_set($cacheKey, $data);
  return $data;
}

$data = od_get_subjects(['start'=>$start,'end'=>$end], 25, 300);
?>

<div class="od-panel od-panel-soft">
  <div class="row g-3">

    <div class="col-12">
      <div class="od-section-title" style="margin:0;">Subjek (Top)</div>
      <div class="small" style="opacity:.75;">
        Sumber: <?= od_h($data['meta']['source'] ?? '-') ?> |
        Tanggal: <?= od_h($data['meta']['date_col'] ?? '-') ?> |
        Kolom subjek: <?= od_h($data['meta']['topic_col'] ?? '-') ?>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="od-metric-card">
        <div class="od-metric-label">Judul Masuk Katalog (Periode)</div>
        <div class="od-metric-value"><?= number_format((int)$data['total_biblio_period']) ?></div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="od-metric-card">
        <div class="od-metric-label">Subjek Unik (Periode)</div>
        <div class="od-metric-value"><?= number_format((int)$data['total_topics_period']) ?></div>
      </div>
    </div>

    <div class="col-12">
      <div class="od-panel" style="padding:14px;">
        <div class="small mb-2">Top Subjek pada Periode</div>

        <table class="table table-sm table-dark mb-0">
          <thead>
            <tr>
              <th style="width:50px;">#</th>
              <th>Subjek</th>
              <th style="width:140px;">Relasi</th>
              <th style="width:160px;">Judul Unik</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($data['rows'])): ?>
            <tr>
              <td colspan="4" style="opacity:.8;">
                Data kosong pada periode ini (atau struktur tabel tidak sesuai).
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($data['rows'] as $i => $r): ?>
              <tr>
                <td><?= $i+1 ?></td>
                <td><?= od_h((string)$r['subject']) ?></td>
                <td><?= number_format((int)$r['rel_total']) ?></td>
                <td><?= number_format((int)$r['biblio_total']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>

        <div class="small" style="opacity:.65; margin-top:10px;">
          Catatan: “Relasi” = jumlah hubungan biblio–subjek pada periode (bisa > judul), sedangkan “Judul Unik” = jumlah judul berbeda yang memakai subjek tsb.
        </div>
      </div>
    </div>

  </div>
</div>
