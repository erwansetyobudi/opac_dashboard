<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : co_author.php
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
 * helper kecil: cek tabel ada/tidak (biar tidak fatal error)
 */
if (!function_exists('od_table_exists')) {
  function od_table_exists($db, string $table): bool
  {
    try {
      $db->query("SELECT 1 FROM `$table` LIMIT 1");
      return true;
    } catch (\Throwable $e) {
      return false;
    }
  }
}

/**
 * Parse tahun dari string (fallback aman)
 */
if (!function_exists('od_year_from_date')) {
  function od_year_from_date($s, int $fallback = 2000): int
  {
    $ts = @strtotime((string)$s);
    if ($ts) return (int)date('Y', $ts);
    return $fallback;
  }
}

/**
 * Co-author Network (bibliometrik) - berbasis publish_year
 * - node: author
 * - link: author A - author B (co-occur pada biblio_id yang sama)
 * - link.value: jumlah judul kolaborasi (COUNT DISTINCT biblio_id)
 * - node.count: jumlah judul author pada periode (produktifitas)
 */
function od_get_coauthor_network_year(array $range, int $limitLinks = 200, int $ttlSeconds = 300): array
{
  $yStart = (int)($range['yStart'] ?? 0);
  $yEnd   = (int)($range['yEnd'] ?? 0);

  $cacheKey = od_cache_key('coauthor_net_year', [
    'yStart' => $yStart,
    'yEnd'   => $yEnd,
    'limit'  => $limitLinks
  ]);

  $cached = od_cache_get($cacheKey, $ttlSeconds);
  if (is_array($cached)) return $cached;

  $db = DB::getInstance();

  foreach (['biblio', 'biblio_author', 'mst_author'] as $t) {
    if (!od_table_exists($db, $t)) {
      $out = [
        'ok' => false,
        'reason' => "Tabel `$t` tidak ditemukan",
        'nodes' => [],
        'links' => [],
        'minYear' => 0,
        'maxYear' => 0,
        'meta' => ['authors' => 0, 'links' => 0, 'titles' => 0]
      ];
      od_cache_set($cacheKey, $out);
      return $out;
    }
  }

  // normalisasi limit
  $limitLinks = max(10, min(1000, (int)$limitLinks));

  // Total judul periode (berdasarkan publish_year)
  $qTitles = $db->prepare("
    SELECT COUNT(*)
    FROM biblio
    WHERE publish_year REGEXP '^[0-9]{4}$'
      AND CAST(publish_year AS UNSIGNED) BETWEEN :y1 AND :y2
  ");
  $qTitles->execute([':y1' => $yStart, ':y2' => $yEnd]);
  $totalTitles = (int)$qTitles->fetchColumn();

  // Min/max tahun untuk legend/info
  $minYear = 0; $maxYear = 0;
  try {
    $qYear = $db->prepare("
      SELECT
        MIN(CAST(publish_year AS UNSIGNED)) AS miny,
        MAX(CAST(publish_year AS UNSIGNED)) AS maxy
      FROM biblio
      WHERE publish_year REGEXP '^[0-9]{4}$'
        AND CAST(publish_year AS UNSIGNED) BETWEEN :y1 AND :y2
    ");
    $qYear->execute([':y1'=>$yStart, ':y2'=>$yEnd]);
    $yr = $qYear->fetch(PDO::FETCH_ASSOC);
    $minYear = (int)($yr['miny'] ?? 0);
    $maxYear = (int)($yr['maxy'] ?? 0);
  } catch (\Throwable $e) {
    $minYear = 0; $maxYear = 0;
  }

  /**
   * 1) Ambil TOP LINKS dulu (graph tidak berat)
   * Co-occurrence dihitung per judul (COUNT DISTINCT biblio_id)
   */
  $qLinks = $db->prepare("
    SELECT
      a1.author_id AS a1,
      a2.author_id AS a2,
      COUNT(DISTINCT a1.biblio_id) AS w
    FROM biblio_author a1
    JOIN biblio_author a2
      ON a1.biblio_id = a2.biblio_id
     AND a1.author_id < a2.author_id
    JOIN biblio b
      ON b.biblio_id = a1.biblio_id
    WHERE b.publish_year REGEXP '^[0-9]{4}$'
      AND CAST(b.publish_year AS UNSIGNED) BETWEEN :y1 AND :y2
    GROUP BY a1.author_id, a2.author_id
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
      'meta' => ['authors' => 0, 'links' => 0, 'titles' => $totalTitles]
    ];
    od_cache_set($cacheKey, $out);
    return $out;
  }

  // kumpulkan author_id yang muncul pada top links
  $authorIds = [];
  foreach ($rawLinks as $r) {
    $authorIds[(int)$r['a1']] = true;
    $authorIds[(int)$r['a2']] = true;
  }
  $authorIdList = array_keys($authorIds);

  // ambil nama author untuk id yang kepakai
  $placeholders = implode(',', array_fill(0, count($authorIdList), '?'));
  $qNames = $db->prepare("SELECT author_id, author_name FROM mst_author WHERE author_id IN ($placeholders)");
  foreach ($authorIdList as $i => $aid) {
    $qNames->bindValue($i+1, (int)$aid, PDO::PARAM_INT);
  }
  $qNames->execute();
  $nameRows = $qNames->fetchAll(PDO::FETCH_ASSOC);

  $idToName = [];
  foreach ($nameRows as $r) {
    $idToName[(int)$r['author_id']] = (string)$r['author_name'];
  }

  /**
   * 2) Hitung produktivitas (jumlah judul) untuk author yang muncul pada graph
   * Pakai statement positional biar stabil (PDO + IN list)
   */
  $qProd = $db->prepare("
    SELECT
      ba.author_id,
      COUNT(DISTINCT ba.biblio_id) AS c
    FROM biblio_author ba
    JOIN biblio b ON b.biblio_id = ba.biblio_id
    WHERE b.publish_year REGEXP '^[0-9]{4}$'
      AND CAST(b.publish_year AS UNSIGNED) BETWEEN ? AND ?
      AND ba.author_id IN ($placeholders)
    GROUP BY ba.author_id
  ");
  $qProd->bindValue(1, $yStart, PDO::PARAM_INT);
  $qProd->bindValue(2, $yEnd,   PDO::PARAM_INT);
  $offset = 3;
  foreach ($authorIdList as $i => $aid) {
    $qProd->bindValue($offset + $i, (int)$aid, PDO::PARAM_INT);
  }
  $qProd->execute();
  $prodRows = $qProd->fetchAll(PDO::FETCH_ASSOC);

  $idToCount = [];
  foreach ($prodRows as $r) {
    $idToCount[(int)$r['author_id']] = (int)$r['c'];
  }

  // 3) build nodes + links (pakai id string agar unik)
  $nodes = [];
  foreach ($authorIdList as $aid) {
    $aid = (int)$aid;
    $nodes[] = [
      'id'    => 'a'.$aid,
      'name'  => $idToName[$aid] ?? ('Author#'.$aid),
      'group' => 'author',
      'count' => (int)($idToCount[$aid] ?? 0),
    ];
  }

  $links = [];
  foreach ($rawLinks as $r) {
    $a1 = (int)$r['a1'];
    $a2 = (int)$r['a2'];
    $w  = (int)$r['w'];
    $links[] = [
      'source' => 'a'.$a1,
      'target' => 'a'.$a2,
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
      'authors' => count($nodes),
      'links'   => count($links),
      'titles'  => $totalTitles
    ]
  ];

  od_cache_set($cacheKey, $out);
  return $out;
}

/**
 * =========================
 * INPUT & RENDER
 * =========================
 * Variabel dari opac_dashboard.inc.php:
 * - $start, $end, $scope
 * - helper: od_cache_key/get/set, od_h()
 */

// ambil tahun dari start/end (yang awalnya tanggal)
$yStart = od_year_from_date($start ?? date('Y-01-01'), 2000);
$yEnd   = od_year_from_date($end ?? date('Y-m-d'), (int)date('Y'));
if ($yStart > $yEnd) { $tmp=$yStart; $yStart=$yEnd; $yEnd=$tmp; }

// limit link bisa dari URL
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
if ($limit < 10 || $limit > 1000) $limit = 200;

$graph = od_get_coauthor_network_year(['yStart'=>$yStart,'yEnd'=>$yEnd], $limit, 300);
$graphJson = json_encode($graph, JSON_UNESCAPED_UNICODE);
?>

<div class="od-panel od-panel-soft">
  <div class="row g-3">

    <div class="col-12">
      <div class="od-section-title" style="margin:0;">Co Author Network (Bibliometrik)</div>
      <div class="small" style="opacity:.75;">
        Node = pengarang, Edge = kolaborasi (co-occurrence dalam judul yang sama),
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
        <div class="od-metric-label">Jumlah Author (graph)</div>
        <div class="od-metric-value"><?= number_format((int)($graph['meta']['authors'] ?? 0)) ?></div>
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
            Tip: tambah/kurangi kepadatan graph pakai “Limit Link”.
          </div>

          <form method="get" style="display:flex; gap:8px; align-items:center; margin:0;">
            <input type="hidden" name="p" value="opac_dashboard">
            <input type="hidden" name="scope" value="<?= od_h($scope ?? 'all') ?>">
            <input type="hidden" name="page" value="co_author">
            <input type="hidden" name="start" value="<?= od_h($start ?? '') ?>">
            <input type="hidden" name="end" value="<?= od_h($end ?? '') ?>">

            <label class="small" style="opacity:.75;">Limit Link</label>
            <input class="form-control form-control-sm" type="number" name="limit" value="<?= (int)$limit ?>"
                   min="10" max="1000" style="width:110px;">
            <button class="btn btn-sm btn-light" type="submit">Update</button>
          </form>
        </div>

        <?php if (!$graph['ok']): ?>
          <div class="alert alert-danger mt-3 mb-0">
            <?= od_h($graph['reason'] ?? 'Gagal memuat data') ?>
          </div>
        <?php elseif (empty($graph['nodes']) || empty($graph['links'])): ?>
          <div class="alert alert-warning mt-3 mb-0">
            Data co-author kosong pada periode tahun ini (atau publish_year tidak berbentuk 4 digit).
          </div>
        <?php else: ?>
          <div id="odCoAuthorBox" style="margin-top:12px; background:#0f172a; border:1px solid rgba(255,255,255,0.12); border-radius:14px; padding:10px;">

            <div style="display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap; margin-bottom:8px;">
              <button class="btn btn-sm btn-outline-light" type="button" id="odCAZoomIn">Zoom In</button>
              <button class="btn btn-sm btn-outline-light" type="button" id="odCAZoomOut">Zoom Out</button>
              <button class="btn btn-sm btn-outline-light" type="button" id="odCAShare">Share</button>
            </div>

            <div id="odCoAuthorGraph" style="width:100%; height:720px; position:relative;">
              <div id="odCATooltip"
                   style="position:absolute; display:none; pointer-events:none; background:#fff; color:#000; padding:6px 10px; border-radius:8px; font-size:13px; z-index:10;"></div>
            </div>

            <div class="small" style="opacity:.7; margin-top:8px;">
              Ukuran node = produktivitas (jumlah judul author pada periode). Ketebalan garis = kekuatan kolaborasi (jumlah judul bersama).
            </div>
          </div>
        <?php endif; ?>

      </div>
    </div>

  </div>
</div>

<?php if ($graph['ok'] && !empty($graph['nodes']) && !empty($graph['links'])): ?>
  <!-- D3 lokal -->
  <script src="<?= SWB ?>plugins/opac_dashboard/assets/js/d3.v6.min.js"></script>

  <script>
  (function(){
    const payload = <?= $graphJson ?: '{}' ?>;
    const el = document.getElementById('odCoAuthorGraph');
    if(!el || !payload.nodes || !payload.links) return;

    const tooltip = document.getElementById('odCATooltip');

    const width  = el.clientWidth;
    const height = el.clientHeight;

    const svg = d3.select(el).append('svg')
      .attr('width', width)
      .attr('height', height);

    const g = svg.append('g');

    // zoom
    const zoom = d3.zoom().on("zoom", (event) => {
      g.attr("transform", event.transform);
    });
    svg.call(zoom);

    // tombol zoom
    const zIn  = document.getElementById('odCAZoomIn');
    const zOut = document.getElementById('odCAZoomOut');
    zIn && zIn.addEventListener('click', () => zoom.scaleBy(svg.transition().duration(250), 1.2));
    zOut && zOut.addEventListener('click', () => zoom.scaleBy(svg.transition().duration(250), 0.8));

    // share
    const shareBtn = document.getElementById('odCAShare');
    shareBtn && shareBtn.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(window.location.href);
        alert('Link halaman berhasil disalin!');
      } catch(e) {
        alert('Gagal menyalin link (browser membatasi clipboard).');
      }
    });

    // scale node/link
    const maxNode = d3.max(payload.nodes, d => +d.count || 0) || 1;
    const maxLink = d3.max(payload.links, d => +d.value || 0) || 1;

    const rScale = d3.scaleSqrt().domain([0, maxNode]).range([6, 26]);
    const wScale = d3.scaleLinear().domain([1, maxLink]).range([1, 6]);

    // simulation
    const simulation = d3.forceSimulation(payload.nodes)
      .force("link", d3.forceLink(payload.links).id(d => d.id).distance(120))
      .force("charge", d3.forceManyBody().strength(-320))
      .force("center", d3.forceCenter(width/2, height/2))
      .force("x", d3.forceX(width/2).strength(0.05))
      .force("y", d3.forceY(height/2).strength(0.05));

    // links
    const link = g.selectAll("line")
      .data(payload.links)
      .enter().append("line")
      .attr("stroke", "rgba(255,255,255,1)")
      .attr("stroke-width", d => wScale(+d.value || 1));

    // nodes
    const node = g.selectAll(".node")
      .data(payload.nodes)
      .enter().append("g")
      .attr("class", "node")
      .call(d3.drag()
        .on("start", (event,d) => {
          if (!event.active) simulation.alphaTarget(0.3).restart();
          d.fx = d.x; d.fy = d.y;
        })
        .on("drag", (event,d) => {
          d.fx = event.x; d.fy = event.y;
        })
        .on("end", (event,d) => {
          if (!event.active) simulation.alphaTarget(0);
          d.fx = null; d.fy = null;
        })
      );

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

    node.append("circle")
      .attr("r", d => rScale(+d.count || 0))
      .attr("fill", d => colorFromString(d.id))
      .attr("stroke", "rgba(0,0,0,0.35)")
      .attr("stroke-width", 1)
      .on("mouseover", (event,d) => {
        if(!tooltip) return;
        tooltip.style.display = 'block';
        tooltip.innerHTML = `<strong>${escapeHtml(d.name || d.id)}</strong><br>Judul (periode): ${Number(d.count||0).toLocaleString()}`;
      })
      .on("mousemove", (event) => {
        if(!tooltip) return;
        tooltip.style.left = (event.offsetX + 14) + 'px';
        tooltip.style.top  = (event.offsetY - 12) + 'px';
      })
      .on("mouseout", () => {
        if(!tooltip) return;
        tooltip.style.display = 'none';
      });

    // label: tampilkan author produktif saja (biar ringan)
    const threshold = Math.max(2, Math.floor(maxNode * 0.25));
    node.append("text")
      .text(d => (+d.count || 0) >= threshold ? (d.name || '') : '')
      .attr("font-size", "11px")
      .attr("fill", "rgba(255, 255, 255, 1)")
      
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

    // resize sederhana: reload
    let rt;
    window.addEventListener('resize', () => {
      clearTimeout(rt);
      rt = setTimeout(() => window.location.reload(), 250);
    });
  })();
  </script>
<?php endif; ?>
