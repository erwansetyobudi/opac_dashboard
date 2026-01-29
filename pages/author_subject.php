<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : author_subject.php
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
 * Author - Subject Network (Bibliometrik)
 * Node: author (circle), topic (hexagon)
 * Edge: co-occurrence author - topic dalam judul yang sama
 * Link.value: jumlah judul (distinct biblio_id)
 * Topic.year: tahun publikasi terbaru pada periode (untuk gradasi)
 */
function od_get_author_subject_network(array $range, int $limitLinks = 500, int $ttlSeconds = 300): array
{
  $cacheKey = od_cache_key('author_subject', [
    'start' => $range['start'],
    'end'   => $range['end'],
    'limit' => $limitLinks
  ]);
  $cached = od_cache_get($cacheKey, $ttlSeconds);
  if (is_array($cached)) return $cached;

  $db = DB::getInstance();

  foreach (['biblio','biblio_author','mst_author','biblio_topic','mst_topic'] as $t) {
    if (!od_table_exists($db, $t)) {
      $out = [
        'ok' => false,
        'reason' => "Tabel `$t` tidak ditemukan",
        'nodes' => [], 'links' => [],
        'minYear' => 0, 'maxYear' => 0,
        'meta' => ['authors'=>0,'topics'=>0,'links'=>0,'titles'=>0]
      ];
      od_cache_set($cacheKey, $out);
      return $out;
    }
  }

  $start = $range['start'];
  $end   = $range['end'];

  // total judul pada periode (berdasarkan input_date)
  $qTitles = $db->prepare("
    SELECT COUNT(*)
    FROM biblio
    WHERE input_date >= :s
      AND input_date < DATE_ADD(:e, INTERVAL 1 DAY)
  ");
  $qTitles->execute([':s'=>$start.' 00:00:00', ':e'=>$end.' 00:00:00']);
  $totalTitles = (int)$qTitles->fetchColumn();

  // min/max publish_year pada periode (opsional untuk legend)
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

  $limitLinks = max(50, min(5000, $limitLinks));

  /**
   * Ambil TOP author-topic links dulu (agar ringan)
   * w = jumlah judul (distinct biblio_id)
   * lasty = tahun publikasi terbaru untuk pasangan itu (buat info topic)
   */
  $qLinks = $db->prepare("
    SELECT
      ba.author_id AS aid,
      bt.topic_id  AS tid,
      COUNT(DISTINCT b.biblio_id) AS w,
      MAX(CAST(TRIM(b.publish_year) AS UNSIGNED)) AS lasty
    FROM biblio b
    JOIN biblio_author ba ON ba.biblio_id = b.biblio_id
    JOIN biblio_topic  bt ON bt.biblio_id = b.biblio_id
    WHERE b.input_date >= :s
      AND b.input_date < DATE_ADD(:e, INTERVAL 1 DAY)
      AND TRIM(b.publish_year) REGEXP '^[0-9]{4}$'
    GROUP BY ba.author_id, bt.topic_id
    HAVING w > 0
    ORDER BY w DESC
    LIMIT {$limitLinks}
  ");
  $qLinks->execute([':s'=>$start.' 00:00:00', ':e'=>$end.' 00:00:00']);
  $rawLinks = $qLinks->fetchAll(PDO::FETCH_ASSOC);

  if (!$rawLinks) {
    $out = [
      'ok' => true, 'reason' => '',
      'nodes' => [], 'links' => [],
      'minYear' => $minYear, 'maxYear' => $maxYear,
      'meta' => ['authors'=>0,'topics'=>0,'links'=>0,'titles'=>$totalTitles]
    ];
    od_cache_set($cacheKey, $out);
    return $out;
  }

  // collect ids
  $authorIds = [];
  $topicIds  = [];
  $topicLastYear = []; // tid => lasty max (di top links)
  foreach ($rawLinks as $r) {
    $aid = (int)$r['aid'];
    $tid = (int)$r['tid'];
    $authorIds[$aid] = true;
    $topicIds[$tid]  = true;
    $ly = (int)($r['lasty'] ?? 0);
    if (!isset($topicLastYear[$tid]) || $ly > $topicLastYear[$tid]) $topicLastYear[$tid] = $ly;
  }
  $authorIdList = array_keys($authorIds);
  $topicIdList  = array_keys($topicIds);

  // fetch names
  $phA = implode(',', array_fill(0, count($authorIdList), '?'));
  $phT = implode(',', array_fill(0, count($topicIdList),  '?'));

  $idToAuthor = [];
  $qA = $db->prepare("SELECT author_id, author_name FROM mst_author WHERE author_id IN ($phA)");
  foreach ($authorIdList as $i => $id) $qA->bindValue($i+1, (int)$id, PDO::PARAM_INT);
  $qA->execute();
  foreach ($qA->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $idToAuthor[(int)$row['author_id']] = (string)$row['author_name'];
  }

  $idToTopic = [];
  $qT = $db->prepare("SELECT topic_id, topic FROM mst_topic WHERE topic_id IN ($phT)");
  foreach ($topicIdList as $i => $id) $qT->bindValue($i+1, (int)$id, PDO::PARAM_INT);
  $qT->execute();
  foreach ($qT->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $idToTopic[(int)$row['topic_id']] = (string)$row['topic'];
  }

  /**
   * produktivitas author (jumlah judul di periode) -> ukuran node author
   */
  $qProdA = $db->prepare("
    SELECT ba.author_id, COUNT(DISTINCT ba.biblio_id) AS c
    FROM biblio_author ba
    JOIN biblio b ON b.biblio_id = ba.biblio_id
    WHERE b.input_date >= ?
      AND b.input_date < DATE_ADD(?, INTERVAL 1 DAY)
      AND ba.author_id IN ($phA)
    GROUP BY ba.author_id
  ");
  $qProdA->bindValue(1, $start.' 00:00:00');
  $qProdA->bindValue(2, $end.' 00:00:00');
  $off = 3;
  foreach ($authorIdList as $i => $id) $qProdA->bindValue($off+$i, (int)$id, PDO::PARAM_INT);
  $qProdA->execute();
  $aCount = [];
  foreach ($qProdA->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $aCount[(int)$row['author_id']] = (int)$row['c'];
  }

  /**
   * frekuensi topic (jumlah judul di periode) -> ukuran node topic
   */
  $qProdT = $db->prepare("
    SELECT bt.topic_id, COUNT(DISTINCT bt.biblio_id) AS c
    FROM biblio_topic bt
    JOIN biblio b ON b.biblio_id = bt.biblio_id
    WHERE b.input_date >= ?
      AND b.input_date < DATE_ADD(?, INTERVAL 1 DAY)
      AND bt.topic_id IN ($phT)
    GROUP BY bt.topic_id
  ");
  $qProdT->bindValue(1, $start.' 00:00:00');
  $qProdT->bindValue(2, $end.' 00:00:00');
  $off = 3;
  foreach ($topicIdList as $i => $id) $qProdT->bindValue($off+$i, (int)$id, PDO::PARAM_INT);
  $qProdT->execute();
  $tCount = [];
  foreach ($qProdT->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $tCount[(int)$row['topic_id']] = (int)$row['c'];
  }

  // build nodes
  $nodes = [];
  foreach ($authorIdList as $aid) {
    $aid = (int)$aid;
    $nodes[] = [
      'id'    => 'a'.$aid,
      'name'  => $idToAuthor[$aid] ?? ('Author#'.$aid),
      'group' => 'author',
      'count' => (int)($aCount[$aid] ?? 0),
    ];
  }
  foreach ($topicIdList as $tid) {
    $tid = (int)$tid;
    $nodes[] = [
      'id'    => 't'.$tid,
      'name'  => $idToTopic[$tid] ?? ('Topic#'.$tid),
      'group' => 'topic',
      'count' => (int)($tCount[$tid] ?? 0),
      'year'  => (int)($topicLastYear[$tid] ?? 0),
    ];
  }

  // build links
  $links = [];
  foreach ($rawLinks as $r) {
    $links[] = [
      'source' => 'a'.(int)$r['aid'],
      'target' => 't'.(int)$r['tid'],
      'value'  => (int)$r['w']
    ];
  }

  $out = [
    'ok' => true, 'reason' => '',
    'nodes' => $nodes,
    'links' => $links,
    'minYear' => $minYear,
    'maxYear' => $maxYear,
    'meta' => [
      'authors' => count($authorIdList),
      'topics'  => count($topicIdList),
      'links'   => count($links),
      'titles'  => $totalTitles
    ]
  ];
  od_cache_set($cacheKey, $out);
  return $out;
}

// limit dari URL
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 500;
if ($limit < 50 || $limit > 5000) $limit = 500;

$graph = od_get_author_subject_network(['start'=>$start,'end'=>$end], $limit, 300);
$graphJson = json_encode($graph, JSON_UNESCAPED_UNICODE);
?>

<div class="od-panel od-panel-soft">
  <div class="row g-3">

    <div class="col-12">
      <div class="od-section-title" style="margin:0;">Author - Subject Network (Bibliometrik)</div>
      <div class="small" style="opacity:.75;">
        Node author (circle) ↔ node subject (hexagon). Periode: <?= od_h($start) ?> s/d <?= od_h($end) ?>.
      </div>
    </div>

    <div class="col-lg-3">
      <div class="od-metric-card">
        <div class="od-metric-label">Judul (periode)</div>
        <div class="od-metric-value"><?= number_format((int)($graph['meta']['titles'] ?? 0)) ?></div>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="od-metric-card">
        <div class="od-metric-label">Author</div>
        <div class="od-metric-value"><?= number_format((int)($graph['meta']['authors'] ?? 0)) ?></div>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="od-metric-card">
        <div class="od-metric-label">Subject</div>
        <div class="od-metric-value"><?= number_format((int)($graph['meta']['topics'] ?? 0)) ?></div>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="od-metric-card">
        <div class="od-metric-label">Link (top <?= (int)$limit ?>)</div>
        <div class="od-metric-value"><?= number_format((int)($graph['meta']['links'] ?? 0)) ?></div>
      </div>
    </div>

    <div class="col-12">
      <div class="od-panel" style="padding:14px;">
        <div style="display:flex; gap:10px; align-items:center; justify-content:space-between; flex-wrap:wrap;">
          <div class="small" style="opacity:.75;">
            Tip: kalau graph terlalu padat, kecilkan “Limit Link”.
          </div>

          <form method="get" style="display:flex; gap:8px; align-items:center; margin:0;">
            <input type="hidden" name="p" value="opac_dashboard">
            <input type="hidden" name="scope" value="<?= od_h($scope) ?>">
            <input type="hidden" name="page" value="author_subject">
            <input type="hidden" name="start" value="<?= od_h($start) ?>">
            <input type="hidden" name="end" value="<?= od_h($end) ?>">

            <label class="small" style="opacity:.75;">Limit Link</label>
            <input class="form-control form-control-sm" type="number" name="limit" value="<?= (int)$limit ?>"
                   min="50" max="5000" style="width:120px;">
            <button class="btn btn-sm btn-light" type="submit">Update</button>
          </form>
        </div>

        <?php if (!$graph['ok']): ?>
          <div class="alert alert-danger mt-3 mb-0">
            <?= od_h($graph['reason'] ?? 'Gagal memuat data') ?>
          </div>
        <?php elseif (empty($graph['nodes']) || empty($graph['links'])): ?>
          <div class="alert alert-warning mt-3 mb-0">
            Data author-subject kosong pada periode ini.
          </div>
        <?php else: ?>
          <div id="odASBox" style="margin-top:12px; background:#0b1220; border:1px solid rgba(255,255,255,0.12); border-radius:14px; padding:10px;">
            <div style="display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap; margin-bottom:8px;">
              <button class="btn btn-sm btn-outline-light" type="button" id="odASZoomIn">Zoom In</button>
              <button class="btn btn-sm btn-outline-light" type="button" id="odASZoomOut">Zoom Out</button>
              <button class="btn btn-sm btn-outline-light" type="button" id="odASShare">Share</button>
            </div>

            <div id="odASGraph" style="width:100%; height:760px; position:relative;">
              <div id="odASTooltip"
                   style="position:absolute; display:none; pointer-events:none; background:#fff; color:#000; padding:6px 10px; border-radius:8px; font-size:13px; z-index:10;"></div>
            </div>

            <div class="small" style="opacity:.7; margin-top:8px;">
              Ukuran node = frekuensi pada periode. Warna subject = gradasi tahun publikasi terbaru (<?= (int)$graph['minYear'] ?>–<?= (int)$graph['maxYear'] ?>).
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
  const el = document.getElementById('odASGraph');
  if(!el || !payload.nodes || !payload.links) return;

  const tooltip = document.getElementById('odASTooltip');
  const width  = el.clientWidth;
  const height = el.clientHeight;

  const svg = d3.select(el).append('svg')
    .attr('width', width)
    .attr('height', height);

  const g = svg.append('g');

  const zoom = d3.zoom().on("zoom", (event) => g.attr("transform", event.transform));
  svg.call(zoom);

  document.getElementById('odASZoomIn')?.addEventListener('click', () => zoom.scaleBy(svg.transition().duration(250), 1.2));
  document.getElementById('odASZoomOut')?.addEventListener('click', () => zoom.scaleBy(svg.transition().duration(250), 0.8));
  document.getElementById('odASShare')?.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(window.location.href); alert('Link halaman berhasil disalin!'); }
    catch(e){ alert('Gagal menyalin link (browser membatasi clipboard).'); }
  });

  const maxNode = d3.max(payload.nodes, d => +d.count || 0) || 1;
  const maxLink = d3.max(payload.links, d => +d.value || 0) || 1;

  const rScale = d3.scaleSqrt().domain([0, maxNode]).range([6, 26]);
  const wScale = d3.scaleLinear().domain([1, maxLink]).range([1, 6]);

  const minY = +payload.minYear || 2016;
  const maxY = +payload.maxYear || (new Date().getFullYear());

  const yearScale = d3.scaleLinear()
    .domain([minY, maxY])
    .range(["#08306b", "#f6f91c"]);

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

  // simulation
  const simulation = d3.forceSimulation(payload.nodes)
    .force("link", d3.forceLink(payload.links).id(d => d.id).distance(130))
    .force("charge", d3.forceManyBody().strength(-320))
    .force("center", d3.forceCenter(width/2, height/2))
    .force("x", d3.forceX(width/2).strength(0.05))
    .force("y", d3.forceY(height/2).strength(0.05));

  const link = g.selectAll("line")
    .data(payload.links)
    .enter().append("line")
    .attr("stroke", "rgba(255,255,255,0.65)")
    .attr("stroke-width", d => wScale(+d.value || 1));

  const node = g.selectAll(".node")
    .data(payload.nodes)
    .enter().append("g")
    .attr("class", "node")
    .call(d3.drag()
      .on("start", (event,d) => { if (!event.active) simulation.alphaTarget(0.3).restart(); d.fx = d.x; d.fy = d.y; })
      .on("drag",  (event,d) => { d.fx = event.x; d.fy = event.y; })
      .on("end",   (event,d) => { if (!event.active) simulation.alphaTarget(0); d.fx = null; d.fy = null; })
    );

  // draw shapes (circle for author, hexagon for topic)
  node.each(function(d){
    const sel = d3.select(this);
    const r = rScale(+d.count || 0);

    if (d.group === 'topic') {
      const sides = 6;
      const pts = [];
      const step = (2*Math.PI)/sides;
      for(let i=0;i<sides;i++){
        const a = step*i;
        pts.push([r*Math.cos(a), r*Math.sin(a)]);
      }
      const y = (+d.year || minY);
      sel.append("polygon")
        .attr("points", pts.map(p => p.join(",")).join(" "))
        .attr("fill", yearScale(Math.max(minY, Math.min(maxY, y))))
        .attr("stroke", "rgba(0,0,0,0.35)")
        .attr("stroke-width", 1);
    } else {
      sel.append("circle")
        .attr("r", r)
        .attr("fill", colorFromString(d.id))
        .attr("stroke", "rgba(0,0,0,0.35)")
        .attr("stroke-width", 1);
    }

    sel.on("mouseover", (event) => {
      if(!tooltip) return;
      tooltip.style.display = 'block';
      const isTopic = d.group === 'topic';
      tooltip.innerHTML =
        `<strong>${escapeHtml(d.name || d.id)}</strong><br>` +
        `${isTopic ? 'Subject' : 'Author'} • Judul: ${Number(d.count||0).toLocaleString()}` +
        (isTopic ? `<br>Tahun terbaru: ${escapeHtml(String(d.year||''))}` : '');
    })
    .on("mousemove", (event) => {
      if(!tooltip) return;
      tooltip.style.left = (event.offsetX + 14) + 'px';
      tooltip.style.top  = (event.offsetY - 12) + 'px';
    })
    .on("mouseout", () => { if(tooltip) tooltip.style.display='none'; });
  });

  // label: tampilkan yang penting saja
  const threshold = Math.max(2, Math.floor(maxNode * 0.25));
  node.append("text")
    .text(d => (+d.count || 0) >= threshold ? (d.name || '') : '')
    .attr("font-size", "11px")
    .attr("fill", "rgba(255,255,255,0.90)")
    .attr("stroke", "rgba(0,0,0,0.55)")
    .attr("stroke-width", 3)
    .attr("paint-order", "stroke")
    .attr("dx", 10)
    .attr("dy", 4);

  simulation.on("tick", () => {
    link
      .attr("x1", d => d.source.x)
      .attr("y1", d => d.source.y)
      .attr("x2", d => d.target.x)
      .attr("y2", d => d.target.y);

    node.attr("transform", d => `translate(${d.x},${d.y})`);
  });

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  }

  // resize sederhana: reload (biar layout rapi)
  let rt;
  window.addEventListener('resize', () => {
    clearTimeout(rt);
    rt = setTimeout(() => window.location.reload(), 250);
  });
})();
</script>
<?php endif; ?>
