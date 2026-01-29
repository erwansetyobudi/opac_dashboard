<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : authors.php
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
 * Ambil data pengarang (cache)
 * - Total pengarang (semua)
 * - Pengarang aktif di periode (punya judul yang input_date dalam range)
 * - Top pengarang di periode (berdasarkan jumlah judul)
 */
function od_get_authors(array $range, int $ttl = 300): array
{
  $cacheKey = od_cache_key('authors', $range);
  if ($c = od_cache_get($cacheKey, $ttl)) return $c;

  $db = DB::getInstance();

  // --- Deteksi tabel relasi author (umumnya: mst_author + biblio_author)
  // fallback: author + biblio_author, atau author + biblio_author_link (kalau ada)
  $authorTable = 'mst_author';
  $linkTable   = 'biblio_author';

  try {
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM);
    $t = array_map(fn($r)=>strtolower($r[0] ?? ''), $tables);

    if (!in_array('mst_author', $t, true) && in_array('author', $t, true)) {
      $authorTable = 'author';
    }

    if (!in_array('biblio_author', $t, true)) {
      // fallback paling umum lain jarang, tapi kita coba beberapa nama
      foreach (['biblio_author_link','biblio_author_relation','biblio_author_map'] as $cand) {
        if (in_array($cand, $t, true)) { $linkTable = $cand; break; }
      }
    }
  } catch (\Throwable $e) {
    // default tetap
  }

  // --- Total pengarang (semua)
  $totalAuthors = 0;
  try {
    $totalAuthors = (int)$db->query("SELECT COUNT(*) FROM {$authorTable}")->fetchColumn();
  } catch (\Throwable $e) {
    $totalAuthors = 0;
  }

  // --- Total judul ditambahkan pada periode (biblio.input_date)
  $qTitles = $db->prepare("
    SELECT COUNT(*)
    FROM biblio
    WHERE input_date >= :s AND input_date < DATE_ADD(:e, INTERVAL 1 DAY)
  ");
  $qTitles->execute([':s'=>$range['start'].' 00:00:00', ':e'=>$range['end'].' 00:00:00']);
  $titlesInRange = (int)$qTitles->fetchColumn();

  // --- Pengarang aktif pada periode (DISTINCT author_id yang terkait judul pada periode)
  $activeAuthors = 0;
  try {
    // cari nama kolom relasi di linkTable: umumnya biblio_id & author_id
    $cols = $db->query("SHOW COLUMNS FROM {$linkTable}")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_map(fn($c)=>strtolower($c['Field']), $cols);

    $colBiblio = in_array('biblio_id', $colNames, true) ? 'biblio_id' : (in_array('biblio', $colNames, true) ? 'biblio' : 'biblio_id');
    $colAuthor = in_array('author_id', $colNames, true) ? 'author_id' : (in_array('author', $colNames, true) ? 'author' : 'author_id');

    $qActive = $db->prepare("
      SELECT COUNT(DISTINCT ba.{$colAuthor})
      FROM {$linkTable} ba
      INNER JOIN biblio b ON b.biblio_id = ba.{$colBiblio}
      WHERE b.input_date >= :s AND b.input_date < DATE_ADD(:e, INTERVAL 1 DAY)
    ");
    $qActive->execute([':s'=>$range['start'].' 00:00:00', ':e'=>$range['end'].' 00:00:00']);
    $activeAuthors = (int)$qActive->fetchColumn();
  } catch (\Throwable $e) {
    $activeAuthors = 0;
  }

  // --- Top pengarang pada periode
  $topAuthors = [];
  try {
    // kolom nama pengarang (umumnya: author_name)
    $authorNameCol = 'author_name';
    try {
      $acols = $db->query("SHOW COLUMNS FROM {$authorTable}")->fetchAll(PDO::FETCH_ASSOC);
      $aNames = array_map(fn($c)=>strtolower($c['Field']), $acols);
      foreach (['author_name','name','author','full_name'] as $cand) {
        if (in_array($cand, $aNames, true)) { $authorNameCol = $cand; break; }
      }
    } catch (\Throwable $e) {}

    $cols = $db->query("SHOW COLUMNS FROM {$linkTable}")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_map(fn($c)=>strtolower($c['Field']), $cols);

    $colBiblio = in_array('biblio_id', $colNames, true) ? 'biblio_id' : (in_array('biblio', $colNames, true) ? 'biblio' : 'biblio_id');
    $colAuthor = in_array('author_id', $colNames, true) ? 'author_id' : (in_array('author', $colNames, true) ? 'author' : 'author_id');

    $qTop = $db->prepare("
      SELECT a.{$authorNameCol} AS author, COUNT(DISTINCT b.biblio_id) AS total
      FROM biblio b
      INNER JOIN {$linkTable} ba ON ba.{$colBiblio} = b.biblio_id
      INNER JOIN {$authorTable} a ON a.author_id = ba.{$colAuthor}
      WHERE b.input_date >= :s AND b.input_date < DATE_ADD(:e, INTERVAL 1 DAY)
      GROUP BY a.author_id
      ORDER BY total DESC
      LIMIT 15
    ");
    $qTop->execute([':s'=>$range['start'].' 00:00:00', ':e'=>$range['end'].' 00:00:00']);
    $topAuthors = $qTop->fetchAll(PDO::FETCH_ASSOC);
  } catch (\Throwable $e) {
    $topAuthors = [];
  }

  $data = [
    'meta' => [
      'authorTable' => $authorTable,
      'linkTable'   => $linkTable,
    ],
    'totalAuthors'    => $totalAuthors,
    'activeAuthors'   => $activeAuthors,
    'titlesInRange'   => $titlesInRange,
    'topAuthorsRange' => $topAuthors,
  ];

  od_cache_set($cacheKey, $data);
  return $data;
}

$data = od_get_authors(['start'=>$start,'end'=>$end], 300);
?>

<div class="od-panel od-panel-soft">
  <div class="row g-3">

    <div class="col-12">
      <div class="od-section-title" style="margin:0;">
        Pengarang (berdasarkan judul yang ditambahkan pada periode)
      </div>
      <div class="small" style="opacity:.75;">
        Sumber: biblio.input_date + relasi pengarang (<?= od_h($data['meta']['authorTable']) ?> / <?= od_h($data['meta']['linkTable']) ?>)
      </div>
    </div>

    <!-- METRICS -->
    <div class="col-lg-4">
      <div class="od-metric-card">
        <div class="od-metric-label">Total Pengarang</div>
        <div class="od-metric-value"><?= number_format((int)$data['totalAuthors']) ?></div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="od-metric-card">
        <div class="od-metric-label">Pengarang Aktif (Periode)</div>
        <div class="od-metric-value"><?= number_format((int)$data['activeAuthors']) ?></div>
        <div class="small" style="opacity:.75;margin-top:6px;">
          Pengarang yang punya judul masuk pada periode.
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="od-metric-card">
        <div class="od-metric-label">Judul Ditambahkan (Periode)</div>
        <div class="od-metric-value"><?= number_format((int)$data['titlesInRange']) ?></div>
      </div>
    </div>

    <!-- TOP AUTHORS -->
    <div class="col-12">
      <div class="od-panel" style="padding:14px;">
        <div class="small mb-2">Top 15 Pengarang (Periode)</div>

        <table class="table table-sm table-dark mb-0">
          <thead>
            <tr>
              <th style="width:50px;">#</th>
              <th>Pengarang</th>
              <th style="width:120px;">Jumlah Judul</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($data['topAuthorsRange'])): ?>
            <tr>
              <td colspan="3" style="opacity:.8;">
                Data kosong. Cek: apakah relasi pengarang tersimpan di tabel <?= od_h($data['meta']['linkTable']) ?>,
                dan biblio punya input_date dalam periode ini.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($data['topAuthorsRange'] as $i => $r): ?>
              <tr>
                <td><?= $i+1 ?></td>
                <td style="max-width:560px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                  <?= od_h((string)($r['author'] ?? '-')) ?>
                </td>
                <td><?= number_format((int)($r['total'] ?? 0)) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>
