<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : trend.php
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

// pastikan variabel ini tersedia dari opac_dashboard.inc.php
// $start, $end, $scope (group), dan helper cache (od_cache_key/get/set), od_h()

/**
 * Tentukan granularitas:
 * - kalau range panjang → per bulan
 * - kalau range pendek → per hari
 */
$startTs = strtotime($start . ' 00:00:00');
$endTs   = strtotime($end   . ' 00:00:00');
$daysDiff = (int) floor(($endTs - $startTs) / 86400) + 1;

$gran = ($daysDiff > 120) ? 'month' : 'day'; // ambang bisa kamu ubah

/**
 * Ambil data trend (dengan cache)
 */
function od_get_trend(array $range, string $gran, int $ttlSeconds = 300): array
{
  $start = $range['start'];
  $end   = $range['end'];

  $cacheKey = od_cache_key('trend', ['start' => $start, 'end' => $end, 'gran' => $gran]);
  $cached = od_cache_get($cacheKey, $ttlSeconds);
  if (is_array($cached)) return $cached;

  $db = DB::getInstance();

  // helper untuk map hasil query ke associative array
  $toMap = function(array $rows, string $k, string $v): array {
    $m = [];
    foreach ($rows as $r) {
      if (!isset($r[$k])) continue;
      $m[(string)$r[$k]] = (int)($r[$v] ?? 0);
    }
    return $m;
  };

  if ($gran === 'month') {
    // label: YYYY-MM
    $labels = [];
    $cursor = new DateTime($start);
    $cursor->modify('first day of this month');
    $endDT = new DateTime($end);
    $endDT->modify('first day of this month');

    while ($cursor <= $endDT) {
      $labels[] = $cursor->format('Y-m');
      $cursor->modify('+1 month');
    }

    // biblio per bulan
    $qB = $db->prepare("
      SELECT DATE_FORMAT(input_date,'%Y-%m') AS k, COUNT(*) AS v
      FROM biblio
      WHERE input_date >= :start
        AND input_date < DATE_ADD(:end, INTERVAL 1 DAY)
      GROUP BY k
      ORDER BY k
    ");
    $qB->execute([':start' => $start.' 00:00:00', ':end' => $end.' 00:00:00']);
    $mapBiblio = $toMap($qB->fetchAll(PDO::FETCH_ASSOC), 'k', 'v');

    // kunjungan per bulan
    $qV = $db->prepare("
      SELECT DATE_FORMAT(checkin_date,'%Y-%m') AS k, COUNT(*) AS v
      FROM visitor_count
      WHERE checkin_date >= :start
        AND checkin_date < DATE_ADD(:end, INTERVAL 1 DAY)
      GROUP BY k
      ORDER BY k
    ");
    $qV->execute([':start' => $start.' 00:00:00', ':end' => $end.' 00:00:00']);
    $mapVisits = $toMap($qV->fetchAll(PDO::FETCH_ASSOC), 'k', 'v');

    // pageviews per bulan
    $qP = $db->prepare("
      SELECT DATE_FORMAT(created_at,'%Y-%m') AS k, COUNT(*) AS v
      FROM read_counter
      WHERE created_at >= :start
        AND created_at < DATE_ADD(:end, INTERVAL 1 DAY)
      GROUP BY k
      ORDER BY k
    ");
    $qP->execute([':start' => $start.' 00:00:00', ':end' => $end.' 00:00:00']);
    $mapPV = $toMap($qP->fetchAll(PDO::FETCH_ASSOC), 'k', 'v');

  } else {
    // label: YYYY-MM-DD
    $labels = [];
    $cursor = new DateTime($start);
    $endDT  = new DateTime($end);

    while ($cursor <= $endDT) {
      $labels[] = $cursor->format('Y-m-d');
      $cursor->modify('+1 day');
    }

    // biblio per hari
    $qB = $db->prepare("
      SELECT DATE(input_date) AS k, COUNT(*) AS v
      FROM biblio
      WHERE input_date >= :start
        AND input_date < DATE_ADD(:end, INTERVAL 1 DAY)
      GROUP BY k
      ORDER BY k
    ");
    $qB->execute([':start' => $start.' 00:00:00', ':end' => $end.' 00:00:00']);
    $mapBiblio = $toMap($qB->fetchAll(PDO::FETCH_ASSOC), 'k', 'v');

    // kunjungan per hari
    $qV = $db->prepare("
      SELECT DATE(checkin_date) AS k, COUNT(*) AS v
      FROM visitor_count
      WHERE checkin_date >= :start
        AND checkin_date < DATE_ADD(:end, INTERVAL 1 DAY)
      GROUP BY k
      ORDER BY k
    ");
    $qV->execute([':start' => $start.' 00:00:00', ':end' => $end.' 00:00:00']);
    $mapVisits = $toMap($qV->fetchAll(PDO::FETCH_ASSOC), 'k', 'v');

    // pageviews per hari
    $qP = $db->prepare("
      SELECT DATE(created_at) AS k, COUNT(*) AS v
      FROM read_counter
      WHERE created_at >= :start
        AND created_at < DATE_ADD(:end, INTERVAL 1 DAY)
      GROUP BY k
      ORDER BY k
    ");
    $qP->execute([':start' => $start.' 00:00:00', ':end' => $end.' 00:00:00']);
    $mapPV = $toMap($qP->fetchAll(PDO::FETCH_ASSOC), 'k', 'v');
  }

  // isi series sesuai labels (agar tanggal kosong jadi 0)
  $seriesB = [];
  $seriesV = [];
  $seriesP = [];

  foreach ($labels as $k) {
    $seriesB[] = (int)($mapBiblio[$k] ?? 0);
    $seriesV[] = (int)($mapVisits[$k] ?? 0);
    $seriesP[] = (int)($mapPV[$k] ?? 0);
  }

  $payload = [
    'gran'   => $gran,
    'labels' => $labels,
    'biblio' => $seriesB,
    'visits' => $seriesV,
    'pv'     => $seriesP,
    'sum'    => [
      'biblio' => array_sum($seriesB),
      'visits' => array_sum($seriesV),
      'pv'     => array_sum($seriesP),
    ],
  ];

  od_cache_set($cacheKey, $payload);
  return $payload;
}

$data = od_get_trend(['start' => $start, 'end' => $end], $gran, 300);

$labelsJson = json_encode($data['labels'], JSON_UNESCAPED_UNICODE);
$biblioJson = json_encode($data['biblio'], JSON_UNESCAPED_UNICODE);
$visitsJson = json_encode($data['visits'], JSON_UNESCAPED_UNICODE);
$pvJson     = json_encode($data['pv'], JSON_UNESCAPED_UNICODE);
?>

<div class="od-panel od-panel-soft">
  <div class="row g-3">

    <div class="col-12">
      <div class="od-section-title" style="margin:0;">
        Trend (<?= od_h($data['gran'] === 'month' ? 'Per Bulan' : 'Per Hari') ?>)
      </div>
    </div>

    <div class="col-lg-4">
      <div class="od-metric-card od-metric-wide">
        <div class="od-metric-label">Judul Ditambahkan (<?= od_h($data['gran']==='month'?'bulan':'hari') ?>)</div>
        <div class="od-metric-value"><?= number_format((int)$data['sum']['biblio']) ?></div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="od-metric-card od-metric-wide">
        <div class="od-metric-label">Total Kunjungan (<?= od_h($data['gran']==='month'?'bulan':'hari') ?>)</div>
        <div class="od-metric-value"><?= number_format((int)$data['sum']['visits']) ?></div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="od-metric-card od-metric-wide">
        <div class="od-metric-label">Halaman Dilihat (<?= od_h($data['gran']==='month'?'bulan':'hari') ?>)</div>
        <div class="od-metric-value"><?= number_format((int)$data['sum']['pv']) ?></div>
      </div>
    </div>

    <div class="col-12">
      <div class="od-panel" style="padding:14px;">
        <div class="row g-3">
          <div class="col-lg-12">
            <div class="small" style="opacity:.75; margin-bottom:8px;">Judul Ditambahkan</div>
            <canvas id="odChartBiblio" height="90"></canvas>
          </div>
          <div class="col-lg-12">
            <div class="small" style="opacity:.75; margin-bottom:8px;">Kunjungan</div>
            <canvas id="odChartVisits" height="90"></canvas>
          </div>
          <div class="col-lg-12">
            <div class="small" style="opacity:.75; margin-bottom:8px;">Halaman Dilihat</div>
            <canvas id="odChartPV" height="90"></canvas>
          </div>
          
        </div>
      </div>
    </div>

  </div>
</div>

<script>
(function(){
  const labels = <?= $labelsJson ?: '[]' ?>;
  const dataB  = <?= $biblioJson ?: '[]' ?>;
  const dataV  = <?= $visitsJson ?: '[]' ?>;
  const dataP  = <?= $pvJson ?: '[]' ?>;

  function drawLine(canvasId, labels, series){
    const c = document.getElementById(canvasId);
    if(!c) return;

    const ctx = c.getContext('2d');

    // ukuran FIX
    const w = c.width  = c.parentElement.clientWidth;
    const h = c.height = 120;

    ctx.clearRect(0,0,w,h);

    const padL = 36, padR = 10, padT = 10, padB = 20;

    const max = Math.max(1, ...series);
    const min = 0;

    // background lembut
    ctx.fillStyle = 'rgba(0,0,0,0.03)'; // sedikit gelap biar kontras
    ctx.fillRect(0,0,w,h);


    // grid
    ctx.globalAlpha = 0.12;
    for(let i=0;i<=4;i++){
      const y = padT + (h-padT-padB) * (i/4);
      ctx.beginPath();
      ctx.moveTo(padL,y);
      ctx.lineTo(w-padR,y);
      ctx.strokeStyle = '#bdbdbd';
      ctx.stroke();
    }
    ctx.globalAlpha = 1;

    // label nilai
    ctx.font = '11px sans-serif';
    ctx.fillStyle = 'rgba(0,0,0,.65)'; // teks gelap
    ctx.fillText(String(max), 6, padT+10);
    ctx.fillText('0', 16, h-padB);


    const n = series.length || 1;
    const xStep = (w - padL - padR) / Math.max(1, n-1);

    function x(i){ return padL + i*xStep; }
    function y(v){
      const t = v / (max || 1);
      return (h - padB) - t*(h - padT - padB);
    }

    // line
    ctx.beginPath();
    series.forEach((v,i)=>{
      const px = x(i);
      const py = y(v || 0);
      if(i===0) ctx.moveTo(px,py);
      else ctx.lineTo(px,py);
    });
    ctx.lineWidth = 2;
    ctx.strokeStyle = '#4a4a4a';
    ctx.stroke();

    // titik
    ctx.fillStyle = '#4a4a4a';
    series.forEach((v,i)=>{
      const px = x(i);
      const py = y(v || 0);
      ctx.beginPath();
      ctx.arc(px,py,2.5,0,Math.PI*2);
      ctx.fill();
    });
  }


  // redraw saat resize
  let t;
  function redraw(){
    drawLine('odChartBiblio', labels, dataB);
    drawLine('odChartVisits', labels, dataV);
    drawLine('odChartPV', labels, dataP);
  }
  window.addEventListener('resize', function(){
    clearTimeout(t);
    t=setTimeout(redraw, 150);
  });

  redraw();
})();
</script>
