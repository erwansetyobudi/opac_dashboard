<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : topic_bubble.php
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

/**
 * Topic Bubble Chart (bibliometrik)
 * - bubble.size = jumlah judul per topic (distinct biblio_id)
 * - bubble.year = tahun publikasi terbaru per topic (max publish_year)
 */
function od_get_topic_bubble(array $range, int $topN = 50, int $ttlSeconds = 300): array
{
  $cacheKey = od_cache_key('topic_bubble', [
    'start' => $range['start'],
    'end'   => $range['end'],
    'top'   => $topN
  ]);
  $cached = od_cache_get($cacheKey, $ttlSeconds);
  if (is_array($cached)) return $cached;

  $db = DB::getInstance();

  foreach (['biblio','biblio_topic','mst_topic'] as $t) {
    if (!od_table_exists($db, $t)) {
      $out = [
        'ok' => false,
        'reason' => "Tabel `$t` tidak ditemukan",
        'items' => [],
        'minYear' => 0,
        'maxYear' => 0,
        'meta' => ['titles'=>0,'topics'=>0]
      ];
      od_cache_set($cacheKey, $out);
      return $out;
    }
  }

  $start = $range['start'];
  $end   = $range['end'];

  // total judul (periode) berdasarkan input_date
  $qTitles = $db->prepare("
    SELECT COUNT(*)
    FROM biblio
    WHERE input_date >= :s
      AND input_date < DATE_ADD(:e, INTERVAL 1 DAY)
  ");
  $qTitles->execute([':s'=>$start.' 00:00:00', ':e'=>$end.' 00:00:00']);
  $totalTitles = (int)$qTitles->fetchColumn();

  // min/max publish_year periode (untuk gradasi)
  $minYear = 0; $maxYear = 0;
  try {
    $qYear = $db->prepare("
      SELECT
        MIN(CAST(TRIM(publish_year) AS UNSIGNED)) AS miny,
        MAX(CAST(TRIM(publish_year) AS UNSIGNED)) AS maxy
      FROM biblio
      WHERE input_date >= :s
        AND input_date < DATE_ADD(:e, INTERVAL 1 DAY)
        AND TRIM(publish_year) REGEXP '^[0-9]{4}$'
    ");
    $qYear->execute([':s'=>$start.' 00:00:00', ':e'=>$end.' 00:00:00']);
    $yr = $qYear->fetch(PDO::FETCH_ASSOC);
    $minYear = (int)($yr['miny'] ?? 0);
    $maxYear = (int)($yr['maxy'] ?? 0);
  } catch (\Throwable $e) {
    $minYear = 0; $maxYear = 0;
  }
  if ($minYear <= 0) $minYear = 2016;
  if ($maxYear <= 0) $maxYear = (int)date('Y');

  $topN = max(10, min(200, $topN));

  // TOP topics (distinct biblio_id), plus tahun terbaru
  $q = $db->prepare("
    SELECT
      t.topic_id,
      t.topic,
      COUNT(DISTINCT bt.biblio_id) AS c,
      MAX(CAST(TRIM(b.publish_year) AS UNSIGNED)) AS lasty
    FROM biblio_topic bt
    JOIN mst_topic t ON t.topic_id = bt.topic_id
    JOIN biblio b ON b.biblio_id = bt.biblio_id
    WHERE b.input_date >= :s
      AND b.input_date < DATE_ADD(:e, INTERVAL 1 DAY)
      AND TRIM(b.publish_year) REGEXP '^[0-9]{4}$'
    GROUP BY t.topic_id, t.topic
    HAVING c > 0
    ORDER BY c DESC
    LIMIT {$topN}
  ");
  $q->execute([':s'=>$start.' 00:00:00', ':e'=>$end.' 00:00:00']);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);

  $items = [];
  foreach ($rows as $r) {
    $items[] = [
      'id' => 't'.(int)$r['topic_id'],
      'name' => (string)$r['topic'],
      'value' => (int)$r['c'],
      'year' => (int)($r['lasty'] ?? 0),
    ];
  }

  $out = [
    'ok' => true,
    'reason' => '',
    'items' => $items,
    'minYear' => $minYear,
    'maxYear' => $maxYear,
    'meta' => [
      'titles' => $totalTitles,
      'topics' => count($items),
    ]
  ];

  od_cache_set($cacheKey, $out);
  return $out;
}

// top N dari URL
$top = isset($_GET['top']) ? (int)$_GET['top'] : 50;
if ($top < 10 || $top > 200) $top = 50;

$data = od_get_topic_bubble(['start'=>$start,'end'=>$end], $top, 300);
$json = json_encode($data, JSON_UNESCAPED_UNICODE);
?>

<div class="od-panel od-panel-soft">
  <div class="row g-3">
    <div class="col-12">
      <div class="od-section-title" style="margin:0;">Topic Bubble Chart (Bibliometrik)</div>
      <div class="small" style="opacity:.75;">
        Ukuran bubble = jumlah judul (topic paling sering). Warna = tahun publikasi terbaru (<?= (int)($data['minYear'] ?? 0) ?>–<?= (int)($data['maxYear'] ?? 0) ?>).
        Periode: <?= od_h($start) ?> s/d <?= od_h($end) ?>.
      </div>
    </div>

    <div class="col-lg-4">
      <div class="od-metric-card">
        <div class="od-metric-label">Judul (periode)</div>
        <div class="od-metric-value"><?= number_format((int)($data['meta']['titles'] ?? 0)) ?></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="od-metric-card">
        <div class="od-metric-label">Topic ditampilkan</div>
        <div class="od-metric-value"><?= number_format((int)($data['meta']['topics'] ?? 0)) ?></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="od-metric-card">
        <div class="od-metric-label">Top N</div>
        <div class="od-metric-value"><?= (int)$top ?></div>
      </div>
    </div>

    <div class="col-12">
      <div class="od-panel" style="padding:14px;">
        <div style="display:flex; gap:10px; align-items:center; justify-content:space-between; flex-wrap:wrap;">
          <div class="small" style="opacity:.75;">
            Tip: kalau label terlalu padat, kecilkan “Top N”.
          </div>

          <form method="get" style="display:flex; gap:8px; align-items:center; margin:0;">
            <input type="hidden" name="p" value="opac_dashboard">
            <input type="hidden" name="scope" value="<?= od_h($scope) ?>">
            <input type="hidden" name="page" value="topic_bubble">
            <input type="hidden" name="start" value="<?= od_h($start) ?>">
            <input type="hidden" name="end" value="<?= od_h($end) ?>">

            <label class="small" style="opacity:.75;">Top N</label>
            <input class="form-control form-control-sm" type="number" name="top" value="<?= (int)$top ?>"
                   min="10" max="200" style="width:110px;">
            <button class="btn btn-sm btn-light" type="submit">Update</button>
          </form>
        </div>

        <?php if (!$data['ok']): ?>
          <div class="alert alert-danger mt-3 mb-0">
            <?= od_h($data['reason'] ?? 'Gagal memuat data') ?>
          </div>
        <?php elseif (empty($data['items'])): ?>
          <div class="alert alert-warning mt-3 mb-0">
            Data bubble topic kosong pada periode ini.
          </div>
        <?php else: ?>
          <div id="odTBBox" style="margin-top:12px; background:#0b1220; border:1px solid rgba(255,255,255,0.12); border-radius:14px; padding:10px;">
            <div style="display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap; margin-bottom:8px;">
              <button class="btn btn-sm btn-outline-light" type="button" id="odTBZoomIn">Zoom In</button>
              <button class="btn btn-sm btn-outline-light" type="button" id="odTBZoomOut">Zoom Out</button>
              <button class="btn btn-sm btn-outline-light" type="button" id="odTBShare">Share</button>
            </div>

            <div id="odTBChart" style="width:100%; height:760px; position:relative;">
              <div id="odTBTooltip"
                   style="position:absolute; display:none; pointer-events:none; background:#fff; color:#000; padding:6px 10px; border-radius:8px; font-size:13px; z-index:10;"></div>
            </div>
          </div>
        <?php endif; ?>

      </div>
    </div>

  </div>
</div>

<?php if ($data['ok'] && !empty($data['items'])): ?>
<script src="<?= SWB ?>plugins/opac_dashboard/assets/js/d3.v6.min.js"></script>
<script>
(function(){
  const payload = <?= $json ?: '{}' ?>;
  const items = payload.items || [];
  const el = document.getElementById('odTBChart');
  if(!el || !items.length) return;

  const tooltip = document.getElementById('odTBTooltip');
  const width  = el.clientWidth;
  const height = el.clientHeight;

  const svg = d3.select(el).append('svg')
    .attr('width', width)
    .attr('height', height);

  const g = svg.append('g');

  // zoom
  const zoom = d3.zoom().on("zoom", (event) => g.attr("transform", event.transform));
  svg.call(zoom);

  document.getElementById('odTBZoomIn')?.addEventListener('click', () => zoom.scaleBy(svg.transition().duration(250), 1.2));
  document.getElementById('odTBZoomOut')?.addEventListener('click', () => zoom.scaleBy(svg.transition().duration(250), 0.8));
  document.getElementById('odTBShare')?.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(window.location.href); alert('Link halaman berhasil disalin!'); }
    catch(e){ alert('Gagal menyalin link (browser membatasi clipboard).'); }
  });

  const minY = +payload.minYear || 2016;
  const maxY = +payload.maxYear || (new Date().getFullYear());

  const yearScale = d3.scaleLinear()
    .domain([minY, maxY])
    .range(["#08306b", "#f6f91c"]);

  // data hierarchy untuk pack
  const root = d3.hierarchy({children: items})
    .sum(d => +d.value || 0)
    .sort((a,b) => (b.value||0) - (a.value||0));

  const pack = d3.pack()
    .size([width, height])
    .padding(6);

  pack(root);

  const nodes = g.selectAll("g.bubble")
    .data(root.leaves())
    .enter().append("g")
    .attr("class", "bubble")
    .attr("transform", d => `translate(${d.x},${d.y})`);

  nodes.append("circle")
    .attr("r", d => d.r)
    .attr("fill", d => {
      const y = +d.data.year || minY;
      return yearScale(Math.max(minY, Math.min(maxY, y)));
    })
    .attr("stroke", "rgba(0,0,0,0.35)")
    .attr("stroke-width", 1)
    .on("mouseover", (event, d) => {
      if(!tooltip) return;
      tooltip.style.display = 'block';
      tooltip.innerHTML =
        `<strong>${escapeHtml(d.data.name)}</strong><br>` +
        `Judul: ${Number(d.data.value||0).toLocaleString()}<br>` +
        `Tahun terbaru: ${escapeHtml(String(d.data.year||''))}`;
    })
    .on("mousemove", (event) => {
      if(!tooltip) return;
      tooltip.style.left = (event.offsetX + 14) + 'px';
      tooltip.style.top  = (event.offsetY - 12) + 'px';
    })
    .on("mouseout", () => { if(tooltip) tooltip.style.display = 'none'; });

  // label: tampilkan kalau bubble cukup besar
  nodes.append("text")
    .attr("text-anchor", "middle")
    .style("pointer-events", "none")
    .attr("fill", "rgba(255,255,255,0.95)")
    .attr("stroke", "rgba(0,0,0,0.55)")
    .attr("stroke-width", 3)
    .attr("paint-order", "stroke")
    .attr("font-size", d => Math.max(10, Math.min(16, d.r / 4)))
    .text(d => {
      if (d.r < 28) return ""; // kecil -> jangan tampilkan label
      return truncate(d.data.name, Math.floor(d.r / 2.2));
    });

  function truncate(s, n){
    s = String(s||'');
    if (s.length <= n) return s;
    return s.slice(0, Math.max(3, n-1)) + '…';
  }

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  }

  // resize sederhana: reload biar pack rapi
  let rt;
  window.addEventListener('resize', () => {
    clearTimeout(rt);
    rt = setTimeout(() => window.location.reload(), 250);
  });
})();
</script>
<?php endif; ?>
