<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : visitors.php
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
 * Ambil data pengunjung (cache)
 */
function od_get_visitors(array $range, string $gran, int $ttl = 300): array
{
  $cacheKey = od_cache_key('visitors', [$range, $gran]);
  if ($c = od_cache_get($cacheKey, $ttl)) return $c;

  $db = DB::getInstance();

  /** TOTAL */
  $qTotal = $db->prepare("
    SELECT COUNT(*) 
    FROM visitor_count
    WHERE checkin_date >= :s AND checkin_date < DATE_ADD(:e, INTERVAL 1 DAY)
  ");
  $qTotal->execute([':s'=>$range['start'].' 00:00:00', ':e'=>$range['end'].' 00:00:00']);
  $totalVisits = (int)$qTotal->fetchColumn();

  /** UNIK */
  $qUnique = $db->prepare("
    SELECT COUNT(DISTINCT member_id)
    FROM visitor_count
    WHERE member_id IS NOT NULL AND member_id <> ''
      AND checkin_date >= :s AND checkin_date < DATE_ADD(:e, INTERVAL 1 DAY)
  ");
  $qUnique->execute([':s'=>$range['start'].' 00:00:00', ':e'=>$range['end'].' 00:00:00']);
  $uniqueVisitors = (int)$qUnique->fetchColumn();

  /** TREND */
  if ($gran === 'month') {
    $sqlTrend = "
      SELECT DATE_FORMAT(checkin_date,'%Y-%m') AS k, COUNT(*) v
      FROM visitor_count
      WHERE checkin_date >= :s AND checkin_date < DATE_ADD(:e, INTERVAL 1 DAY)
      GROUP BY k ORDER BY k
    ";
  } else {
    $sqlTrend = "
      SELECT DATE(checkin_date) AS k, COUNT(*) v
      FROM visitor_count
      WHERE checkin_date >= :s AND checkin_date < DATE_ADD(:e, INTERVAL 1 DAY)
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

  /** TOP MEMBER */
  $qTop = $db->prepare("
    SELECT m.member_name, COUNT(*) total
    FROM visitor_count v
    JOIN member m ON m.member_id=v.member_id
    WHERE v.checkin_date >= :s AND v.checkin_date < DATE_ADD(:e, INTERVAL 1 DAY)
    GROUP BY v.member_id
    ORDER BY total DESC
    LIMIT 10
  ");
  $qTop->execute([':s'=>$range['start'].' 00:00:00', ':e'=>$range['end'].' 00:00:00']);

  $data = [
    'total'  => $totalVisits,
    'unique' => $uniqueVisitors,
    'gran'   => $gran,
    'labels' => $labels,
    'series' => $series,
    'top'    => $qTop->fetchAll(PDO::FETCH_ASSOC)
  ];

  od_cache_set($cacheKey, $data);
  return $data;
}

$data = od_get_visitors(['start'=>$start,'end'=>$end], $gran);
?>

<div class="od-panel od-panel-soft">
  <div class="row g-3">

    <!-- METRIC -->
    <div class="col-lg-6">
      <div class="od-metric-card">
        <div class="od-metric-label">Total Kunjungan</div>
        <div class="od-metric-value"><?= number_format($data['total']) ?></div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="od-metric-card">
        <div class="od-metric-label">Pengunjung Unik</div>
        <div class="od-metric-value"><?= number_format($data['unique']) ?></div>
      </div>
    </div>

    <!-- CHART -->
    <div class="col-12">
      <div class="od-panel" style="padding:14px;">
        <div class="small mb-2">Trend Pengunjung (<?= $data['gran']==='month'?'Bulanan':'Harian' ?>)</div>
        <canvas id="chartVisitors" height="120"></canvas>
      </div>
    </div>

    <!-- TOP VISITOR -->
    <div class="col-12">
      <div class="od-panel" style="padding:14px;">
        <div class="small mb-2">Top 10 Pengunjung Teraktif</div>
        <table class="table table-sm table-dark">
          <thead>
            <tr><th>#</th><th>Nama</th><th>Kunjungan</th></tr>
          </thead>
          <tbody>
          <?php foreach ($data['top'] as $i=>$r): ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td><?= od_h($r['member_name']) ?></td>
              <td><?= number_format($r['total']) ?></td>
            </tr>
          <?php endforeach; ?>
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

  const c = document.getElementById('chartVisitors');
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
