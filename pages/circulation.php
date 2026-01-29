<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : circulation.php
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

if (!function_exists('od_table_exists')) {
  function od_table_exists($db, string $table): bool {
    try { $db->query("SELECT 1 FROM `$table` LIMIT 1"); return true; }
    catch (\Throwable $e) { return false; }
  }
}

// Tambahkan fungsi od_render_kv_table di sini, setelah fungsi helper lainnya
if (!function_exists('od_render_kv_table')) {
  function od_render_kv_table(array $rows): string {
    if (empty($rows)) return '<div class="small" style="opacity:.75;">Tidak ada data.</div>';
    ob_start(); ?>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead><tr><th>Segment</th><th class="text-end">Jumlah</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= od_h((string)($r['k'] ?? '')) ?></td>
            <td class="text-end"><?= number_format((int)($r['v'] ?? 0)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php
    return ob_get_clean();
  }
}

/**
 * Ambil metrik sirkulasi: total transaksi, pinjam, kembali, aktif, rata2 durasi, overdue (estimasi)
 */
function od_get_circulation_summary(array $range, int $ttlSeconds = 300): array
{
  $cacheKey = od_cache_key('circulation_sum', ['start'=>$range['start'], 'end'=>$range['end']]);
  $cached = od_cache_get($cacheKey, $ttlSeconds);
  if (is_array($cached)) return $cached;

  $db = DB::getInstance();

  foreach (['loan','member','item','biblio'] as $t) {
    if (!od_table_exists($db, $t)) {
      $out = ['ok'=>false, 'reason'=>"Tabel `$t` tidak ditemukan", 'data'=>[]];
      od_cache_set($cacheKey, $out);
      return $out;
    }
  }

  $start = $range['start'];
  $end   = $range['end'];

  // total transaksi dalam periode (berdasarkan loan_date)
  $q = $db->prepare("
    SELECT
      COUNT(*) AS total_tx,
      SUM(CASE WHEN is_lent=1 THEN 1 ELSE 0 END) AS total_lent,
      SUM(CASE WHEN is_return=1 THEN 1 ELSE 0 END) AS total_return,
      SUM(CASE WHEN is_lent=1 AND is_return=0 THEN 1 ELSE 0 END) AS active_loan,
      SUM(CASE WHEN is_lent=1 AND is_return=0 AND due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_active,
      AVG(CASE
            WHEN is_return=1 AND return_date IS NOT NULL THEN DATEDIFF(return_date, loan_date)
            ELSE NULL
          END) AS avg_days_returned
    FROM loan
    WHERE loan_date >= :s
      AND loan_date <= :e
  ");
  $q->execute([':s'=>$start, ':e'=>$end]);
  $row = $q->fetch(PDO::FETCH_ASSOC) ?: [];

  $out = [
    'ok' => true,
    'reason' => '',
    'data' => [
      'total_tx' => (int)($row['total_tx'] ?? 0),
      'total_lent' => (int)($row['total_lent'] ?? 0),
      'total_return' => (int)($row['total_return'] ?? 0),
      'active_loan' => (int)($row['active_loan'] ?? 0),
      'overdue_active' => (int)($row['overdue_active'] ?? 0),
      'avg_days_returned' => isset($row['avg_days_returned']) ? (float)$row['avg_days_returned'] : 0.0,
    ],
  ];

  od_cache_set($cacheKey, $out);
  return $out;
}

/**
 * Trend harian/bulanan untuk transaksi pinjam & kembali
 * NOTE: pakai loan_date (date) sebagai basis.
 */
function od_get_circulation_trend(array $range, string $gran, int $ttlSeconds = 300): array
{
  $cacheKey = od_cache_key('circulation_trend', ['start'=>$range['start'], 'end'=>$range['end'], 'gran'=>$gran]);
  $cached = od_cache_get($cacheKey, $ttlSeconds);
  if (is_array($cached)) return $cached;

  $db = DB::getInstance();
  $start = $range['start'];
  $end   = $range['end'];

  $toMap = function(array $rows, string $k, string $v): array {
    $m = [];
    foreach ($rows as $r) {
      if (!isset($r[$k])) continue;
      $m[(string)$r[$k]] = (int)($r[$v] ?? 0);
    }
    return $m;
  };

  if ($gran === 'month') {
    $labels = [];
    $cursor = new DateTime($start);
    $cursor->modify('first day of this month');
    $endDT = new DateTime($end);
    $endDT->modify('first day of this month');
    while ($cursor <= $endDT) {
      $labels[] = $cursor->format('Y-m');
      $cursor->modify('+1 month');
    }

    $qL = $db->prepare("
      SELECT DATE_FORMAT(loan_date,'%Y-%m') AS k, COUNT(*) AS v
      FROM loan
      WHERE loan_date >= :s AND loan_date <= :e
        AND is_lent = 1
      GROUP BY k ORDER BY k
    ");
    $qL->execute([':s'=>$start, ':e'=>$end]);
    $mapL = $toMap($qL->fetchAll(PDO::FETCH_ASSOC), 'k', 'v');

    $qR = $db->prepare("
      SELECT DATE_FORMAT(loan_date,'%Y-%m') AS k, COUNT(*) AS v
      FROM loan
      WHERE loan_date >= :s AND loan_date <= :e
        AND is_return = 1
      GROUP BY k ORDER BY k
    ");
    $qR->execute([':s'=>$start, ':e'=>$end]);
    $mapR = $toMap($qR->fetchAll(PDO::FETCH_ASSOC), 'k', 'v');

  } else {
    $labels = [];
    $cursor = new DateTime($start);
    $endDT  = new DateTime($end);
    while ($cursor <= $endDT) {
      $labels[] = $cursor->format('Y-m-d');
      $cursor->modify('+1 day');
    }

    $qL = $db->prepare("
      SELECT loan_date AS k, COUNT(*) AS v
      FROM loan
      WHERE loan_date >= :s AND loan_date <= :e
        AND is_lent = 1
      GROUP BY k ORDER BY k
    ");
    $qL->execute([':s'=>$start, ':e'=>$end]);
    $mapL = $toMap($qL->fetchAll(PDO::FETCH_ASSOC), 'k', 'v');

    $qR = $db->prepare("
      SELECT loan_date AS k, COUNT(*) AS v
      FROM loan
      WHERE loan_date >= :s AND loan_date <= :e
        AND is_return = 1
      GROUP BY k ORDER BY k
    ");
    $qR->execute([':s'=>$start, ':e'=>$end]);
    $mapR = $toMap($qR->fetchAll(PDO::FETCH_ASSOC), 'k', 'v');
  }

  $lent = [];
  $ret  = [];
  foreach ($labels as $k) {
    $lent[] = (int)($mapL[$k] ?? 0);
    $ret[]  = (int)($mapR[$k] ?? 0);
  }

  $out = [
    'gran' => $gran,
    'labels' => $labels,
    'lent' => $lent,
    'returned' => $ret,
    'sum' => ['lent'=>array_sum($lent), 'returned'=>array_sum($ret)],
  ];
  od_cache_set($cacheKey, $out);
  return $out;
}

/**
 * Top borrowers (member) + Top items (judul)
 */
function od_get_circulation_top(array $range, int $topN = 10, int $ttlSeconds = 300): array
{
  $cacheKey = od_cache_key('circulation_top', ['start'=>$range['start'],'end'=>$range['end'],'top'=>$topN]);
  $cached = od_cache_get($cacheKey, $ttlSeconds);
  if (is_array($cached)) return $cached;

  $db = DB::getInstance();
  $start = $range['start'];
  $end   = $range['end'];
  $topN = max(5, min(50, $topN));

  // Top peminjam
  $qB = $db->prepare("
    SELECT
      l.member_id,
      m.member_name,
      COUNT(*) AS c
    FROM loan l
    JOIN member m ON m.member_id = l.member_id
    WHERE l.loan_date >= :s AND l.loan_date <= :e
      AND l.is_lent = 1
    GROUP BY l.member_id, m.member_name
    ORDER BY c DESC
    LIMIT {$topN}
  ");
  $qB->execute([':s'=>$start, ':e'=>$end]);
  $borrowers = $qB->fetchAll(PDO::FETCH_ASSOC);

  // Top judul dipinjam (join item->biblio via item_code)
  $qI = $db->prepare("
    SELECT
      b.biblio_id,
      b.title,
      COUNT(*) AS c
    FROM loan l
    JOIN item i ON i.item_code = l.item_code
    JOIN biblio b ON b.biblio_id = i.biblio_id
    WHERE l.loan_date >= :s AND l.loan_date <= :e
      AND l.is_lent = 1
    GROUP BY b.biblio_id, b.title
    ORDER BY c DESC
    LIMIT {$topN}
  ");
  $qI->execute([':s'=>$start, ':e'=>$end]);
  $titles = $qI->fetchAll(PDO::FETCH_ASSOC);

  $out = [
    'borrowers' => $borrowers ?: [],
    'titles' => $titles ?: [],
  ];
  od_cache_set($cacheKey, $out);
  return $out;
}

/**
 * Breakdown: by member_type, coll_type, location
 */
function od_get_circulation_breakdown(array $range, int $ttlSeconds = 300): array
{
  $cacheKey = od_cache_key('circulation_breakdown', ['start'=>$range['start'],'end'=>$range['end']]);
  $cached = od_cache_get($cacheKey, $ttlSeconds);
  if (is_array($cached)) return $cached;

  $db = DB::getInstance();
  $start = $range['start'];
  $end   = $range['end'];

  $hasMemberType = od_table_exists($db, 'mst_member_type');
  $hasCollType   = od_table_exists($db, 'mst_coll_type');
  $hasLocation   = od_table_exists($db, 'mst_location');

  // member type
  $byMemberType = [];
  if ($hasMemberType) {
    $q = $db->prepare("
      SELECT
        COALESCE(mt.member_type_name, 'Tidak diketahui') AS k,
        COUNT(*) AS v
      FROM loan l
      JOIN member m ON m.member_id = l.member_id
      LEFT JOIN mst_member_type mt ON mt.member_type_id = m.member_type_id
      WHERE l.loan_date >= :s AND l.loan_date <= :e
        AND l.is_lent = 1
      GROUP BY k
      ORDER BY v DESC
      LIMIT 20
    ");
    $q->execute([':s'=>$start, ':e'=>$end]);
    $byMemberType = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  // coll type
  $byCollType = [];
  if ($hasCollType) {
    $q = $db->prepare("
      SELECT
        COALESCE(ct.coll_type_name, 'Tidak diketahui') AS k,
        COUNT(*) AS v
      FROM loan l
      JOIN item i ON i.item_code = l.item_code
      LEFT JOIN mst_coll_type ct ON ct.coll_type_id = i.coll_type_id
      WHERE l.loan_date >= :s AND l.loan_date <= :e
        AND l.is_lent = 1
      GROUP BY k
      ORDER BY v DESC
      LIMIT 20
    ");
    $q->execute([':s'=>$start, ':e'=>$end]);
    $byCollType = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  // location
  $byLocation = [];
  if ($hasLocation) {
    $q = $db->prepare("
      SELECT
        COALESCE(ml.location_name, 'Tidak diketahui') AS k,
        COUNT(*) AS v
      FROM loan l
      JOIN item i ON i.item_code = l.item_code
      LEFT JOIN mst_location ml ON ml.location_id = i.location_id
      WHERE l.loan_date >= :s AND l.loan_date <= :e
        AND l.is_lent = 1
      GROUP BY k
      ORDER BY v DESC
      LIMIT 20
    ");
    $q->execute([':s'=>$start, ':e'=>$end]);
    $byLocation = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  $out = [
    'byMemberType' => $byMemberType,
    'byCollType'   => $byCollType,
    'byLocation'   => $byLocation,
  ];
  od_cache_set($cacheKey, $out);
  return $out;
}

// ======= main =======
$startTs = strtotime($start.' 00:00:00');
$endTs   = strtotime($end.' 00:00:00');
$daysDiff = (int) floor(($endTs - $startTs) / 86400) + 1;
$gran = ($daysDiff > 120) ? 'month' : 'day';

$sum   = od_get_circulation_summary(['start'=>$start,'end'=>$end], 300);
$trend = od_get_circulation_trend(['start'=>$start,'end'=>$end], $gran, 300);
$top   = od_get_circulation_top(['start'=>$start,'end'=>$end], 10, 300);
$bd    = od_get_circulation_breakdown(['start'=>$start,'end'=>$end], 300);

$labelsJson = json_encode($trend['labels'] ?? [], JSON_UNESCAPED_UNICODE);
$lentJson   = json_encode($trend['lent'] ?? [], JSON_UNESCAPED_UNICODE);
$retJson    = json_encode($trend['returned'] ?? [], JSON_UNESCAPED_UNICODE);
?>

<div class="od-panel od-panel-soft">
  <div class="row g-3">

    <div class="col-12">
      <div class="od-section-title" style="margin:0;">Sirkulasi (Loan Analytics)</div>
      <div class="small" style="opacity:.75;">
        Periode: <?= od_h($start) ?> s/d <?= od_h($end) ?> • Trend: <?= od_h(($gran==='month')?'per bulan':'per hari') ?>
      </div>
    </div>

    <?php if (!$sum['ok']): ?>
      <div class="col-12">
        <div class="alert alert-danger mb-0"><?= od_h($sum['reason'] ?? 'Gagal memuat data') ?></div>
      </div>
    <?php else: ?>
      <div class="col-lg-3">
        <div class="od-metric-card od-metric-wide">
          <div class="od-metric-label">Total Transaksi</div>
          <div class="od-metric-value"><?= number_format((int)$sum['data']['total_tx']) ?></div>
        </div>
      </div>
      <div class="col-lg-3">
        <div class="od-metric-card od-metric-wide">
          <div class="od-metric-label">Peminjaman (is_lent)</div>
          <div class="od-metric-value"><?= number_format((int)$sum['data']['total_lent']) ?></div>
        </div>
      </div>
      <div class="col-lg-3">
        <div class="od-metric-card od-metric-wide">
          <div class="od-metric-label">Pengembalian (is_return)</div>
          <div class="od-metric-value"><?= number_format((int)$sum['data']['total_return']) ?></div>
        </div>
      </div>
      <div class="col-lg-3">
        <div class="od-metric-card od-metric-wide">
          <div class="od-metric-label">Aktif Dipinjam</div>
          <div class="od-metric-value"><?= number_format((int)$sum['data']['active_loan']) ?></div>
          <div class="small" style="opacity:.75;">
            Overdue aktif: <?= number_format((int)$sum['data']['overdue_active']) ?>
          </div>
        </div>
      </div>

      <div class="col-12">
        <div class="od-panel" style="padding:14px;">
          <div class="row g-3">
            <div class="col-lg-12">
              <div class="small" style="opacity:.75; margin-bottom:8px;">Trend Peminjaman</div>
              <canvas id="odLoanLent" height="90"></canvas>
            </div>
            <div class="col-lg-12">
              <div class="small" style="opacity:.75; margin-bottom:8px;">Trend Pengembalian</div>
              <canvas id="odLoanReturn" height="90"></canvas>
            </div>
            <div class="col-12">
              <div class="small" style="opacity:.7;">
                Rata-rata durasi kembali (hari): <?= number_format((float)$sum['data']['avg_days_returned'], 1) ?>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- TOP LISTS -->
      <div class="col-lg-6">
        <div class="od-panel" style="padding:14px;">
          <div class="od-section-title" style="margin:0 0 8px 0;">Top Peminjam</div>
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead>
                <tr>
                  <th>#</th><th>Member</th><th class="text-end">Jumlah</th>
                </tr>
              </thead>
              <tbody>
              <?php if (empty($top['borrowers'])): ?>
                <tr><td colspan="3" class="small" style="opacity:.75;">Tidak ada data.</td></tr>
              <?php else: ?>
                <?php foreach ($top['borrowers'] as $i => $r): ?>
                  <tr>
                    <td><?= $i+1 ?></td>
                    <td><?= od_h(($r['member_name'] ?? '').' ('.$r['member_id'].')') ?></td>
                    <td class="text-end"><?= number_format((int)($r['c'] ?? 0)) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="col-lg-6">
        <div class="od-panel" style="padding:14px;">
          <div class="od-section-title" style="margin:0 0 8px 0;">Top Judul Dipinjam</div>
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead>
                <tr>
                  <th>#</th><th>Judul</th><th class="text-end">Jumlah</th>
                </tr>
              </thead>
              <tbody>
              <?php if (empty($top['titles'])): ?>
                <tr><td colspan="3" class="small" style="opacity:.75;">Tidak ada data.</td></tr>
              <?php else: ?>
                <?php foreach ($top['titles'] as $i => $r): ?>
                  <tr>
                    <td><?= $i+1 ?></td>
                    <td><?= od_h((string)($r['title'] ?? '')) ?></td>
                    <td class="text-end"><?= number_format((int)($r['c'] ?? 0)) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- BREAKDOWN -->
      <div class="col-12">
        <div class="od-panel" style="padding:14px;">
          <div class="od-section-title" style="margin:0 0 8px 0;">Breakdown Peminjaman</div>
          <div class="row g-3">
            <div class="col-lg-4">
              <div class="small" style="opacity:.75; margin-bottom:6px;">By Member Type</div>
              <?= od_render_kv_table($bd['byMemberType'] ?? []) ?>
            </div>
            <div class="col-lg-4">
              <div class="small" style="opacity:.75; margin-bottom:6px;">By Collection Type</div>
              <?= od_render_kv_table($bd['byCollType'] ?? []) ?>
            </div>
            <div class="col-lg-4">
              <div class="small" style="opacity:.75; margin-bottom:6px;">By Location</div>
              <?= od_render_kv_table($bd['byLocation'] ?? []) ?>
            </div>
          </div>
          <div class="small" style="opacity:.7; margin-top:8px;">
            Catatan: breakdown akan tampil lengkap jika tabel master terkait ada (mst_member_type / mst_coll_type / mst_location).
          </div>
        </div>
      </div>

    <?php endif; ?>

  </div>
</div>

<script>
(function(){
  const labels = <?= $labelsJson ?: '[]' ?>;
  const lent   = <?= $lentJson ?: '[]' ?>;
  const ret    = <?= $retJson ?: '[]' ?>;

  function drawLine(canvasId, labels, series){
    const c = document.getElementById(canvasId);
    if(!c) return;
    const ctx = c.getContext('2d');

    const w = c.width  = c.parentElement.clientWidth;
    const h = c.height = 120;

    ctx.clearRect(0,0,w,h);

    const padL = 36, padR = 10, padT = 10, padB = 20;
    const max = Math.max(1, ...series);
    ctx.fillStyle = 'rgba(255,255,255,0.02)';
    ctx.fillRect(0,0,w,h);

    // grid
    ctx.globalAlpha = 0.12;
    for(let i=0;i<=4;i++){
      const y = padT + (h-padT-padB) * (i/4);
      ctx.beginPath();
      ctx.moveTo(padL,y);
      ctx.lineTo(w-padR,y);
      ctx.strokeStyle = '#4a4a4a';
      ctx.stroke();
    }
    ctx.globalAlpha = 1;

    // y label
    ctx.font = '11px sans-serif';
    ctx.fillStyle = 'rgba(255,255,255,.7)';
    ctx.fillText(String(max), 6, padT+10);
    ctx.fillText('0', 16, h-padB);

    const n = series.length || 1;
    const xStep = (w - padL - padR) / Math.max(1, n-1);

    function x(i){ return padL + i*xStep; }
    function y(v){
      const t = (v || 0) / (max || 1);
      return (h - padB) - t*(h - padT - padB);
    }

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

    ctx.fillStyle = '#4a4a4a';
    series.forEach((v,i)=>{
      const px=x(i), py=y(v||0);
      ctx.beginPath();
      ctx.arc(px,py,2.5,0,Math.PI*2);
      ctx.fill();
    });
  }

  let t;
  function redraw(){
    drawLine('odLoanLent', labels, lent);
    drawLine('odLoanReturn', labels, ret);
  }
  window.addEventListener('resize', function(){
    clearTimeout(t);
    t=setTimeout(redraw,150);
  });
  redraw();
})();
</script>