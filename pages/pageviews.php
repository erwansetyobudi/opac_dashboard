<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : pageviews.php
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
 * Granularitas otomatis
 */
$startTs = strtotime($start.' 00:00:00');
$endTs   = strtotime($end.' 00:00:00');
$days    = (int)(($endTs - $startTs)/86400)+1;
$gran    = ($days > 120) ? 'month' : 'day';

/**
 * Ambil data pageviews (cache)
 */
function od_get_pageviews(array $range, string $gran, int $ttl = 300): array
{
  $cacheKey = od_cache_key('pageviews', [$range, $gran]);
  if ($c = od_cache_get($cacheKey, $ttl)) return $c;

  $db = DB::getInstance();

  /** TOTAL PAGEVIEWS */
  $qTotal = $db->prepare("
    SELECT COUNT(*)
    FROM read_counter
    WHERE created_at >= :s AND created_at < DATE_ADD(:e, INTERVAL 1 DAY)
  ");
  $qTotal->execute([':s'=>$range['start'].' 00:00:00', ':e'=>$range['end'].' 00:00:00']);
  $totalPV = (int)$qTotal->fetchColumn();

  /** UNIQUE VISITOR (berdasarkan ip) - opsional, kalau kolom ip ada */
  $uniqueIp = null;
  try {
    $qUnique = $db->prepare("
      SELECT COUNT(DISTINCT ip)
      FROM read_counter
      WHERE ip IS NOT NULL AND ip <> ''
        AND created_at >= :s AND created_at < DATE_ADD(:e, INTERVAL 1 DAY)
    ");
    $qUnique->execute([':s'=>$range['start'].' 00:00:00', ':e'=>$range['end'].' 00:00:00']);
    $uniqueIp = (int)$qUnique->fetchColumn();
  } catch (\Throwable $e) {
    $uniqueIp = null; // read_counter tidak punya kolom ip
  }

  /** UNIQUE TITLE COUNT (jumlah judul unik) */
  $uniqueTitleCount = null;
  try {
    $qTitle = $db->prepare("
        SELECT COUNT(DISTINCT title)
        FROM read_counter
        WHERE title IS NOT NULL AND title <> ''
          AND created_at >= :s AND created_at < DATE_ADD(:e, INTERVAL 1 DAY)
    ");
    $qTitle->execute([':s'=>$range['start'].' 00:00:00', ':e'=>$range['end'].' 00:00:00']);
    $uniqueTitleCount = (int)$qTitle->fetchColumn();
  } catch (\Throwable $e) {
    $uniqueTitleCount = null;
  }

  /** TREND */
  if ($gran === 'month') {
    $sqlTrend = "
      SELECT DATE_FORMAT(created_at,'%Y-%m') AS k, COUNT(*) v
      FROM read_counter
      WHERE created_at >= :s AND created_at < DATE_ADD(:e, INTERVAL 1 DAY)
      GROUP BY k ORDER BY k
    ";
  } else {
    $sqlTrend = "
      SELECT DATE(created_at) AS k, COUNT(*) v
      FROM read_counter
      WHERE created_at >= :s AND created_at < DATE_ADD(:e, INTERVAL 1 DAY)
      GROUP BY k ORDER BY k
    ";
  }

  $qTrend = $db->prepare($sqlTrend);
  $qTrend->execute([':s'=>$range['start'].' 00:00:00', ':e'=>$range['end'].' 00:00:00']);
  $rows = $qTrend->fetchAll(PDO::FETCH_ASSOC);

  $map = [];
  foreach ($rows as $r) $map[$r['k']] = (int)$r['v'];

  $labels = [];
  $series = [];

  $cur = new DateTime($range['start']);
  $endD = new DateTime($range['end']);
  if ($gran === 'month') $cur->modify('first day of this month');

  while ($cur <= $endD) {
    $key = $gran === 'month' ? $cur->format('Y-m') : $cur->format('Y-m-d');
    $labels[] = $key;
    $series[] = $map[$key] ?? 0;
    $cur->modify($gran === 'month' ? '+1 month' : '+1 day');
  }

  /** TOP PAGES (dengan kolom url kalau ada) */
  $topPages = [];
  $urlCol = null;

  // deteksi kolom "url" atau "page" atau "uri"
  try {
    $cols = $db->query("SHOW COLUMNS FROM read_counter")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_map(fn($c) => strtolower($c['Field']), $cols);
    foreach (['url','page','uri','path'] as $candidate) {
      if (in_array($candidate, $names, true)) { $urlCol = $candidate; break; }
    }
  } catch (\Throwable $e) {
    $urlCol = null;
  }

  if ($urlCol) {
    $qTop = $db->prepare("
      SELECT {$urlCol} AS page, COUNT(*) total
      FROM read_counter
      WHERE created_at >= :s AND created_at < DATE_ADD(:e, INTERVAL 1 DAY)
      GROUP BY {$urlCol}
      ORDER BY total DESC
      LIMIT 10
    ");
    $qTop->execute([':s'=>$range['start'].' 00:00:00', ':e'=>$range['end'].' 00:00:00']);
    $topPages = $qTop->fetchAll(PDO::FETCH_ASSOC);
  } else {
    // fallback: top berdasarkan biblio_id kalau ada
    try {
      $cols = $db->query("SHOW COLUMNS FROM read_counter")->fetchAll(PDO::FETCH_ASSOC);
      $names = array_map(fn($c) => strtolower($c['Field']), $cols);
      if (in_array('biblio_id', $names, true)) {
        $qTop = $db->prepare("
          SELECT biblio_id AS page, COUNT(*) total
          FROM read_counter
          WHERE created_at >= :s AND created_at < DATE_ADD(:e, INTERVAL 1 DAY)
          GROUP BY biblio_id
          ORDER BY total DESC
          LIMIT 10
        ");
        $qTop->execute([':s'=>$range['start'].' 00:00:00', ':e'=>$range['end'].' 00:00:00']);
        $topPages = $qTop->fetchAll(PDO::FETCH_ASSOC);
      }
    } catch (\Throwable $e) {
      $topPages = [];
    }
  }

  $data = [
    'total'    => $totalPV,
    'uniqueIp' => $uniqueIp,
    'uniqueTitleCount' => $uniqueTitleCount,
    'gran'     => $gran,
    'labels'   => $labels,
    'series'   => $series,
    'top'      => $topPages,
    'topMode'  => $urlCol ? 'url' : 'fallback',
  ];

  od_cache_set($cacheKey, $data);
  return $data;
}

$data = od_get_pageviews(['start'=>$start,'end'=>$end], $gran);
?>

<div class="od-panel od-panel-soft">
  <div class="row g-3">

    <!-- METRIC -->
    <div class="col-lg-4">
      <div class="od-metric-card">
        <div class="od-metric-label">Total Baca Di Tempat (Eksemplar)</div>
        <div class="od-metric-value"><?= number_format($data['total']) ?></div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="od-metric-card">
        <div class="od-metric-label">Total Baca Di Tempat (Judul Unik)</div>
        <div class="od-metric-value">
          <?= $data['uniqueTitleCount'] === null ? '-' : number_format($data['uniqueTitleCount']) ?>
        </div>
        <div class="small" style="opacity:.75;margin-top:6px;">
          <?= $data['uniqueTitleCount'] === null ? 'Kolom title tidak tersedia di read_counter.' : 'Berdasarkan DISTINCT title.' ?>
        </div>
      </div>
    </div>

    <!-- CHART -->
    <div class="col-12">
      <div class="od-panel" style="padding:14px;">
        <div class="small mb-2">Trend Halaman Dilihat (<?= $data['gran']==='month'?'Bulanan':'Harian' ?>)</div>
        <canvas id="chartPV" height="120"></canvas>
      </div>
    </div>

    <!-- TOP PAGES -->
    <div class="col-12">
      <div class="od-panel" style="padding:14px;">
        <div class="small mb-2">Top 10 Halaman Paling Sering Dibuka</div>
        <table class="table table-sm table-dark">
          <thead>
            <tr><th>#</th><th>Halaman</th><th>Dilihat</th></tr>
          </thead>
          <tbody>
          <?php if (empty($data['top'])): ?>
            <tr><td colspan="3" style="opacity:.8;">Tidak ada data top pages (kolom URL/biblio_id tidak ditemukan).</td></tr>
          <?php else: ?>
            <?php foreach ($data['top'] as $i=>$r): ?>
              <tr>
                <td><?= $i+1 ?></td>
                <td style="max-width:520px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                  <?= od_h((string)$r['page']) ?>
                </td>
                <td><?= number_format((int)$r['total']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<script>
(function(){
  const labels = <?= json_encode($data['labels']) ?>;
  const data   = <?= json_encode($data['series']) ?>;

  const c = document.getElementById('chartPV');
  if(!c) return;
  const ctx = c.getContext('2d');

  const w = c.width = c.parentElement.clientWidth;
  const h = c.height = 120;
  ctx.clearRect(0,0,w,h);

  const pad=30, max=Math.max(1,...data);
  const step=(w-pad*2)/Math.max(1,data.length-1);

  ctx.strokeStyle='#4a4a4a';
  ctx.beginPath();
  data.forEach((v,i)=>{
    const x=pad+i*step;
    const y=h-pad-(v/max)*(h-pad*2);
    i?ctx.lineTo(x,y):ctx.moveTo(x,y);
  });
  ctx.stroke();
})();
</script>