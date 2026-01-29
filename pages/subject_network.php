<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : subject_network.php
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
  function od_table_exists($db, string $table): bool
  {
    try { $db->query("SELECT 1 FROM `$table` LIMIT 1"); return true; }
    catch (\Throwable $e) { return false; }
  }
}

if (!function_exists('od_year_from_date')) {
  function od_year_from_date($s, int $fallback = 2000): int
  {
    $ts = @strtotime((string)$s);
    if ($ts) return (int)date('Y', $ts);
    return $fallback;
  }
}

/**
 * Subject/Topic Network (bibliometrik) - berbasis publish_year
 * - node: topic
 * - link: topic A - topic B (co-occur pada biblio_id yang sama)
 * - link.value: jumlah judul co-occurrence (COUNT DISTINCT biblio_id)
 * - node.count: jumlah judul yang memuat topic tsb pada periode
 */
function od_get_subject_network_year(array $range, int $limitLinks = 250, int $ttlSeconds = 300): array
{
  $yStart = (int)($range['yStart'] ?? 0);
  $yEnd   = (int)($range['yEnd'] ?? 0);

  $cacheKey = od_cache_key('subject_net_year', [
    'yStart' => $yStart,
    'yEnd'   => $yEnd,
    'limit'  => $limitLinks
  ]);

  $cached = od_cache_get($cacheKey, $ttlSeconds);
  if (is_array($cached)) return $cached;

  $db = DB::getInstance();

  foreach (['biblio', 'biblio_topic', 'mst_topic'] as $t) {
    if (!od_table_exists($db, $t)) {
      $out = [
        'ok' => false,
        'reason' => "Tabel `$t` tidak ditemukan",
        'nodes' => [],
        'links' => [],
        'minYear' => 0,
        'maxYear' => 0,
        'meta' => ['topics' => 0, 'links' => 0, 'titles' => 0]
      ];
      od_cache_set($cacheKey, $out);
      return $out;
    }
  }

  $limitLinks = max(10, min(1500, (int)$limitLinks));

  // Total judul periode (publish_year 4 digit)
  $qTitles = $db->prepare("
    SELECT COUNT(*)
    FROM biblio
    WHERE publish_year REGEXP '^[0-9]{4}$'
      AND CAST(publish_year AS UNSIGNED) BETWEEN :y1 AND :y2
  ");
  $qTitles->execute([':y1' => $yStart, ':y2' => $yEnd]);
  $totalTitles = (int)$qTitles->fetchColumn();

  // min/max tahun
  $minYear = 0; $maxYear = 0;
  try {
    $qYear = $db->prepare("
      SELECT
        MIN(CAST(publish_year AS UNSIGNED)) AS miny,
        MAX(CAST(publish_year AS UNSIGNED)) AS maxy
      FROM biblio
      WHERE TRIM(b.publish_year) REGEXP '^[0-9]{4}$'
  AND CAST(TRIM(b.publish_year) AS UNSIGNED) BETWEEN :y1 AND :y2

    ");
    $qYear->execute([':y1'=>$yStart, ':y2'=>$yEnd]);
    $yr = $qYear->fetch(PDO::FETCH_ASSOC);
    $minYear = (int)($yr['miny'] ?? 0);
    $maxYear = (int)($yr['maxy'] ?? 0);
  } catch (\Throwable $e) {}

  /**
   * 1) TOP LINKS: co-occurrence topic-topic dalam judul yang sama
   */
  $qLinks = $db->prepare("
    SELECT
      t1.topic_id AS t1,
      t2.topic_id AS t2,
      COUNT(DISTINCT t1.biblio_id) AS w
    FROM biblio_topic t1
    JOIN biblio_topic t2
      ON t1.biblio_id = t2.biblio_id
     AND t1.topic_id < t2.topic_id
    JOIN biblio b
      ON b.biblio_id = t1.biblio_id
    WHERE b.publish_year REGEXP '^[0-9]{4}$'
      AND CAST(b.publish_year AS UNSIGNED) BETWEEN :y1 AND :y2
    GROUP BY t1.topic_id, t2.topic_id
    HAVING w > 0
    ORDER BY w DESC
    LIMIT {$limitLinks}
  ");
  $qLinks->execute([':y1'=>$yStart, ':y2'=>$yEnd]);
  $rawLinks = $qLinks->fetchAll(PDO::FETCH_ASSOC);

  if (!$rawLinks) {
    $out = [
      'ok' => true,
      'reason' => '',
      'nodes' => [],
      'links' => [],
      'minYear' => $minYear,
      'maxYear' => $maxYear,
      'meta' => ['topics' => 0, 'links' => 0, 'titles' => $totalTitles]
    ];
    od_cache_set($cacheKey, $out);
    return $out;
  }

  // kumpulkan topic_id yang muncul
  $topicIds = [];
  foreach ($rawLinks as $r) {
    $topicIds[(int)$r['t1']] = true;
    $topicIds[(int)$r['t2']] = true;
  }
  $topicIdList = array_keys($topicIds);

  // ambil nama topic
  $placeholders = implode(',', array_fill(0, count($topicIdList), '?'));
  $qNames = $db->prepare("SELECT topic_id, topic FROM mst_topic WHERE topic_id IN ($placeholders)");
  foreach ($topicIdList as $i => $tid) {
    $qNames->bindValue($i+1, (int)$tid, PDO::PARAM_INT);
  }
  $qNames->execute();
  $nameRows = $qNames->fetchAll(PDO::FETCH_ASSOC);

  $idToName = [];
  foreach ($nameRows as $r) {
    $idToName[(int)$r['topic_id']] = (string)$r['topic'];
  }

  /**
   * 2) produktivitas topic (jumlah judul yang memuat topic)
   */
  $qProd = $db->prepare("
    SELECT
      bt.topic_id,
      COUNT(DISTINCT bt.biblio_id) AS c
    FROM biblio_topic bt
    JOIN biblio b ON b.biblio_id = bt.biblio_id
    WHERE b.publish_year REGEXP '^[0-9]{4}$'
      AND CAST(b.publish_year AS UNSIGNED) BETWEEN ? AND ?
      AND bt.topic_id IN ($placeholders)
    GROUP BY bt.topic_id
  ");
  $qProd->bindValue(1, $yStart, PDO::PARAM_INT);
  $qProd->bindValue(2, $yEnd,   PDO::PARAM_INT);
  $offset = 3;
  foreach ($topicIdList as $i => $tid) {
    $qProd->bindValue($offset + $i, (int)$tid, PDO::PARAM_INT);
  }
  $qProd->execute();
  $prodRows = $qProd->fetchAll(PDO::FETCH_ASSOC);

  $idToCount = [];
  foreach ($prodRows as $r) {
    $idToCount[(int)$r['topic_id']] = (int)$r['c'];
  }

  // build nodes + links
  $nodes = [];
  foreach ($topicIdList as $tid) {
    $tid = (int)$tid;
    $nodes[] = [
      'id'    => 't'.$tid,
      'name'  => $idToName[$tid] ?? ('Topic#'.$tid),
      'group' => 'topic',
      'count' => (int)($idToCount[$tid] ?? 0),
    ];
  }

  $links = [];
  foreach ($rawLinks as $r) {
    $t1 = (int)$r['t1'];
    $t2 = (int)$r['t2'];
    $w  = (int)$r['w'];
    $links[] = [
      'source' => 't'.$t1,
      'target' => 't'.$t2,
      'value'  => $w
    ];
  }

  $out = [
    'ok' => true,
    'reason' => '',
    'nodes' => $nodes,
    'links' => $links,
    'minYear' => $minYear,
    'maxYear' => $maxYear,
    'meta' => [
      'topics' => count($nodes),
      'links'  => count($links),
      'titles' => $totalTitles
    ]
  ];

  od_cache_set($cacheKey, $out);
  return $out;
}

/**
 * INPUT
 */
$yStart = od_year_from_date($start ?? date('Y-01-01'), 2000);
$yEnd   = od_year_from_date($end ?? date('Y-m-d'), (int)date('Y'));
if ($yStart > $yEnd) { $tmp=$yStart; $yStart=$yEnd; $yEnd=$tmp; }

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 250;
if ($limit < 10 || $limit > 1500) $limit = 250;

$graph = od_get_subject_network_year(['yStart'=>$yStart,'yEnd'=>$yEnd], $limit, 300);
$graphJson = json_encode($graph, JSON_UNESCAPED_UNICODE);
?>

<div class="od-panel od-panel-soft">
  <div class="row g-3">

    <div class="col-12">
      <div class="od-section-title" style="margin:0;">Subject / Topic Network (Bibliometrik)</div>
      <div class="small" style="opacity:.75;">
        Node = subject/topik, Edge = kemunculan bersamaan (co-occurrence dalam judul yang sama),
        Periode Tahun Terbit: <?= (int)$yStart ?> s/d <?= (int)$yEnd ?>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="od-metric-card">
        <div class="od-metric-label">Jumlah Judul (periode)</div>
        <div class="od-metric-value"><?= number_format((int)($graph['meta']['titles'] ?? 0)) ?></div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="od-metric-card">
        <div class="od-metric-label">Jumlah Topic (graph)</div>
        <div class="od-metric-value"><?= number_format((int)($graph['meta']['topics'] ?? 0)) ?></div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="od-metric-card">
        <div class="od-metric-label">Jumlah Link (top <?= (int)$limit ?>)</div>
        <div class="od-metric-value"><?= number_format((int)($graph['meta']['links'] ?? 0)) ?></div>
      </div>
    </div>

    <div class="col-12">
      <div class="od-panel" style="padding:14px;">
        <div style="display:flex; gap:10px; align-items:center; justify-content:space-between; flex-wrap:wrap;">
          <div class="small" style="opacity:.75;">
            Tip: bila graph terlalu ramai, kecilkan “Limit Link”.
          </div>

          <form method="get" style="display:flex; gap:8px; align-items:center; margin:0;">
            <input type="hidden" name="p" value="opac_dashboard">
            <input type="hidden" name="scope" value="<?= od_h($scope ?? 'all') ?>">
            <input type="hidden" name="page" value="subject_network">
            <input type="hidden" name="start" value="<?= od_h($start ?? '') ?>">
            <input type="hidden" name="end" value="<?= od_h($end ?? '') ?>">

            <label class="small" style="opacity:.75;">Limit Link</label>
            <input class="form-control form-control-sm" type="number" name="limit" value="<?= (int)$limit ?>"
                   min="10" max="1500" style="width:110px;">
            <button class="btn btn-sm btn-light" type="submit">Update</button>
          </form>
        </div>

        <?php if (!$graph['ok']): ?>
          <div class="alert alert-danger mt-3 mb-0">
            <?= od_h($graph['reason'] ?? 'Gagal memuat data') ?>
          </div>
        <?php elseif (empty($graph['nodes']) || empty($graph['links'])): ?>
          <div class="alert alert-warning mt-3 mb-0">
            Data topic network kosong pada periode tahun ini (atau publish_year tidak 4 digit).
          </div>
        <?php else: ?>
          <div id="odSubjectBox" style="margin-top:12px; background:#0f172a; border:1px solid rgba(255,255,255,0.12); border-radius:14px; padding:10px;">

            <div style="display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap; margin-bottom:8px;">
              <button class="btn btn-sm btn-outline-light" type="button" id="odSTZoomIn">Zoom In</button>
              <button class="btn btn-sm btn-outline-light" type="button" id="odSTZoomOut">Zoom Out</button>
              <button class="btn btn-sm btn-outline-light" type="button" id="odSTShare">Share</button>
            </div>

            <div id="odSubjectGraph" style="width:100%; height:720px; position:relative;">
              <div id="odSTTooltip"
                   style="position:absolute; display:none; pointer-events:none; background:#fff; color:#000; padding:6px 10px; border-radius:8px; font-size:13px; z-index:10;"></div>
            </div>

            <div class="small" style="opacity:.7; margin-top:8px;">
              Ukuran node = frekuensi topik (jumlah judul). Ketebalan garis = kekuatan ko-kemunculan (jumlah judul yang memuat pasangan topik).
            </div>
          </div>
        <?php endif; ?>

      </div>
    </div>

  </div>
</div>

<?php if ($graph['ok'] && !empty($graph['nodes']) && !empty($graph['links'])): ?>
<script src="<?= SWB ?>plugins/opac_dashboard/assets/js/d3.v6.min.js"></script>

<script>
(function(){
  const payload = <?= $graphJson ?: '{}' ?>;
  const el = document.getElementById('odSubjectGraph');
  if(!el || !payload.nodes || !payload.links) return;

  const tooltip = document.getElementById('odSTTooltip');
  const width  = el.clientWidth;
  const height = el.clientHeight;

  const svg = d3.select(el).append('svg')
    .attr('width', width)
    .attr('height', height);

  const g = svg.append('g');

  const zoom = d3.zoom().on('zoom', (event) => g.attr('transform', event.transform));
  svg.call(zoom);

  const zIn  = document.getElementById('odSTZoomIn');
  const zOut = document.getElementById('odSTZoomOut');
  zIn && zIn.addEventListener('click', () => zoom.scaleBy(svg.transition().duration(250), 1.2));
  zOut && zOut.addEventListener('click', () => zoom.scaleBy(svg.transition().duration(250), 0.8));

  const shareBtn = document.getElementById('odSTShare');
  shareBtn && shareBtn.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(window.location.href); alert('Link halaman berhasil disalin!'); }
    catch(e){ alert('Gagal menyalin link (browser membatasi clipboard).'); }
  });

  const maxNode = d3.max(payload.nodes, d => +d.count || 0) || 1;
  const maxLink = d3.max(payload.links, d => +d.value || 0) || 1;

  const rScale = d3.scaleSqrt().domain([0, maxNode]).range([6, 26]);
  const wScale = d3.scaleLinear().domain([1, maxLink]).range([1, 6]);

  const simulation = d3.forceSimulation(payload.nodes)
    .force('link', d3.forceLink(payload.links).id(d => d.id).distance(110))
    .force('charge', d3.forceManyBody().strength(-320))
    .force('center', d3.forceCenter(width/2, height/2))
    .force('x', d3.forceX(width/2).strength(0.05))
    .force('y', d3.forceY(height/2).strength(0.05));

  const link = g.selectAll('line')
    .data(payload.links)
    .enter().append('line')
    .attr('stroke', 'rgba(255,255,255,1)')
    .attr('stroke-width', d => wScale(+d.value || 1));

  function colorFromString(str){
    let hash = 0;
    for (let i=0; i<str.length; i++) hash = str.charCodeAt(i) + ((hash<<5) - hash);
    let c = '#';
    for (let i=0; i<3; i++){
      const v = (hash >> (i*8)) & 0xff;
      c += ('00' + v.toString(16)).slice(-2);
    }
    return c;
  }
  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  }

  const node = g.selectAll('.node')
    .data(payload.nodes)
    .enter().append('g')
    .attr('class','node')
    .call(d3.drag()
      .on('start', (event,d) => {
        if (!event.active) simulation.alphaTarget(0.3).restart();
        d.fx = d.x; d.fy = d.y;
      })
      .on('drag', (event,d) => { d.fx = event.x; d.fy = event.y; })
      .on('end', (event,d) => {
        if (!event.active) simulation.alphaTarget(0);
        d.fx = null; d.fy = null;
      })
    );

  node.append('circle')
    .attr('r', d => rScale(+d.count || 0))
    .attr('fill', d => colorFromString(d.id))
    .attr('stroke', 'rgba(0,0,0,0.35)')
    .attr('stroke-width', 1)
    .on('mouseover', (event,d) => {
      if(!tooltip) return;
      tooltip.style.display = 'block';
      tooltip.innerHTML = `<strong>${escapeHtml(d.name || d.id)}</strong><br>Judul (periode): ${Number(d.count||0).toLocaleString()}`;
    })
    .on('mousemove', (event) => {
      if(!tooltip) return;
      tooltip.style.left = (event.offsetX + 14) + 'px';
      tooltip.style.top  = (event.offsetY - 12) + 'px';
    })
    .on('mouseout', () => { if(tooltip) tooltip.style.display = 'none'; });

  // label: tampilkan topik yang cukup sering muncul
  const threshold = Math.max(3, Math.floor(maxNode * 0.25));
  node.append('text')
    .text(d => (+d.count || 0) >= threshold ? (d.name || '') : '')
    .attr('font-size','11px')
    .attr('fill','rgba(0,0,0,0.75)')
    .attr('stroke','rgba(255,255,255,0.85)')
    .attr('stroke-width', 3)
    .attr('paint-order','stroke')
    .attr('dx', 10)
    .attr('dy', 4);

  simulation.on('tick', () => {
    link
      .attr('x1', d => d.source.x)
      .attr('y1', d => d.source.y)
      .attr('x2', d => d.target.x)
      .attr('y2', d => d.target.y);
    node.attr('transform', d => `translate(${d.x},${d.y})`);
  });

  let rt;
  window.addEventListener('resize', () => {
    clearTimeout(rt);
    rt = setTimeout(() => window.location.reload(), 250);
  });
})();
</script>
<?php endif; ?>
