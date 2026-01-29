<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : opac_dashboard.inc.php
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
 * Helpers 
 */
if (!function_exists('od_h')) {
  function od_h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('od_url')) {
  function od_url($scope, $page, $extra = []) {
    $params = array_merge(['p' => 'opac_dashboard', 'scope' => $scope, 'page' => $page], $extra);
    return 'index.php?' . http_build_query($params);
  }
}

/**
 * Cache to ROOT/files/cache
 */
function od_cache_dir(): string
{
  $root = dirname(__DIR__, 2); // .../plugins/opac_dashboard -> .../plugins -> ROOT
  $dir  = $root . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'cache';
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  return $dir;
}

function od_cache_file(string $key): string
{
  $safe = preg_replace('~[^a-zA-Z0-9_\-\.]~', '_', $key);
  return od_cache_dir() . DIRECTORY_SEPARATOR . 'opac_dashboard_' . $safe . '.json';
}

function od_cache_get(string $key, int $ttlSeconds = 300)
{
  $file = od_cache_file($key);
  if (!is_file($file)) return null;

  $mtime = @filemtime($file);
  if (!$mtime) return null;

  if (time() - $mtime > $ttlSeconds) return null;

  $raw = @file_get_contents($file);
  if ($raw === false || $raw === '') return null;

  $data = json_decode($raw, true);
  return is_array($data) ? $data : null;
}

function od_cache_set(string $key, $value): bool
{
  $file = od_cache_file($key);
  $tmp  = $file . '.' . uniqid('tmp_', true);

  $json = json_encode($value, JSON_UNESCAPED_UNICODE);
  if ($json === false) return false;

  $fp = @fopen($tmp, 'wb');
  if (!$fp) return false;

  @flock($fp, LOCK_EX);
  fwrite($fp, $json);
  fflush($fp);
  @flock($fp, LOCK_UN);
  fclose($fp);

  return @rename($tmp, $file);
}

function od_is_valid_date(string $d): bool
{
  if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $d)) return false;
  $dt = DateTime::createFromFormat('Y-m-d', $d);
  return $dt && $dt->format('Y-m-d') === $d;
}


function od_cache_key(string $prefix, array $parts): string
{
  return $prefix . '_' . sha1(json_encode($parts));
}



/**
 * Params
 */
$start = isset($_GET['start']) ? preg_replace('~[^0-9\-]~', '', $_GET['start']) : '2016-09-14';
$end   = isset($_GET['end']) ? preg_replace('~[^0-9\-]~', '', $_GET['end']) : date('Y-m-d');

// get date
if (!od_is_valid_date($start)) $start = '2016-09-14';
if (!od_is_valid_date($end))   $end   = date('Y-m-d');

// change position
if ($start > $end) {
  $tmp = $start;
  $start = $end;
  $end = $tmp;
}


$scope  = isset($_GET['scope']) ? strtolower(trim($_GET['scope'])) : 'global';
$page   = isset($_GET['page'])  ? strtolower(trim($_GET['page']))  : 'summary';
$action = isset($_GET['action'])? strtolower(trim($_GET['action'])): '';

/**
 * Get Cache
 */
function od_get_stats(array $range, int $ttlSeconds = 300): array
{
  $start = $range['start'];
  $end   = $range['end'];

  $cacheKey = od_cache_key('stats', ['start' => $start, 'end' => $end]);
  $cached = od_cache_get($cacheKey, $ttlSeconds);
  if (is_array($cached)) return $cached;

  $db = DB::getInstance();

  // batas waktu (end inclusive -> < end+1 hari)
  $startDT = $start . ' 00:00:00';
  $endDT   = $end   . ' 00:00:00';

  /**
   * Bibliography
   */
  $qBiblio = $db->prepare("
    SELECT COUNT(*)
    FROM biblio
    WHERE input_date IS NOT NULL
      AND input_date >= :start
      AND input_date < DATE_ADD(:end, INTERVAL 1 DAY)
  ");
  $qBiblio->execute([':start' => $startDT, ':end' => $endDT]);
  $totalBiblio = (int)$qBiblio->fetchColumn();

  /**
   * Items
   */
  $qItem = $db->prepare("
    SELECT COUNT(*)
    FROM item
    WHERE input_date >= :start
      AND input_date < DATE_ADD(:end, INTERVAL 1 DAY)
  ");
  $qItem->execute([':start' => $startDT, ':end' => $endDT]);
  $totalItem = (int)$qItem->fetchColumn();

  /**
   * Members
   */
  $qMember = $db->prepare("
    SELECT COUNT(*)
    FROM member
    WHERE (
      (register_date IS NOT NULL AND register_date >= :start_d AND register_date <= :end_d)
      OR
      (register_date IS NULL AND input_date IS NOT NULL AND input_date >= :start_d AND input_date <= :end_d)
    )
  ");
  $qMember->execute([':start_d' => $start, ':end_d' => $end]);
  $totalMember = (int)$qMember->fetchColumn();

  /**
   * Files
   */
  $totalFiles = 0;
  try {
    $qFiles = $db->prepare("
      SELECT COUNT(*)
      FROM files
      WHERE input_date >= :start
        AND input_date < DATE_ADD(:end, INTERVAL 1 DAY)
    ");
    $qFiles->execute([':start' => $startDT, ':end' => $endDT]);
    $totalFiles = (int)$qFiles->fetchColumn();
  } catch (\Throwable $e) {
    $totalFiles = 0;
  }

  /**
   * Visitor
   */
  $qVisit = $db->prepare("
    SELECT COUNT(*)
    FROM visitor_count
    WHERE checkin_date >= :start
      AND checkin_date < DATE_ADD(:end, INTERVAL 1 DAY)
  ");
  $qVisit->execute([':start' => $startDT, ':end' => $endDT]);
  $totalVisits = (int)$qVisit->fetchColumn();

  /**
   * Unic Visitor
   */
  $qUnique = $db->prepare("
    SELECT COUNT(DISTINCT member_id)
    FROM visitor_count
    WHERE member_id IS NOT NULL AND member_id <> ''
      AND checkin_date >= :start
      AND checkin_date < DATE_ADD(:end, INTERVAL 1 DAY)
  ");
  $qUnique->execute([':start' => $startDT, ':end' => $endDT]);
  $uniqueVisitors = (int)$qUnique->fetchColumn();

  /**
   * Read Counter
   */
  $qPV = $db->prepare("
    SELECT COUNT(*)
    FROM read_counter
    WHERE created_at >= :start
      AND created_at < DATE_ADD(:end, INTERVAL 1 DAY)
  ");
  $qPV->execute([':start' => $startDT, ':end' => $endDT]);
  $totalPageviews = (int)$qPV->fetchColumn();

  $stats = [
    'total_document'    => $totalBiblio,     // Judul bibliografi pada periode
    'total_unique_doc'  => $totalBiblio,     // masih sama (judul unik)
    'total_library'     => $totalItem,       // Eksemplar pada periode
    'total_institution' => $totalMember,     // Anggota daftar pada periode
    'total_repository'  => $totalFiles,      // Berkas digital pada periode
    'total_visitors'    => $uniqueVisitors,  // Pengunjung unik pada periode
    'total_visits'      => $totalVisits,     // Kunjungan pada periode
    'total_pageviews'   => $totalPageviews,  // Pageviews pada periode
  ];

  od_cache_set($cacheKey, $stats);
  return $stats;
}


/**
 * Menu Groups (scope = group)
 */
$menuGroups = [
  'global' => [
    'label' => 'Global',
    'icon'  => 'fa-globe',
    'pages' => [
      'summary'   => 'Ringkasan',
      'trend'     => 'Trend',
      'visitors'  => 'Pengunjung',
      'pageviews' => 'Baca di Tempat',
      'authors'   => 'Pengarang',
      'formats'   => 'Format',
      'subjects'  => 'Subjek',
      'years'     => 'Tahun Terbit',
      'locations' => 'Lokasi',
    ],
  ],
  'bibliometric' => [
    'label' => 'Bibliometric',
    'icon'  => 'fa-users', // FontAwesome 5 solid
    'pages' => [
      'co_author'        => 'Co Author',
      'subject_network'  => 'Subject Network',
      'author_subject'   => 'Author - Subject',
      'topic_bubble'     => 'Topic Bubble Chart',
    ],
  ],
  'performa' => [
    'label' => 'Performa',
    'icon'  => 'fa-tachometer-alt',
    'pages' => [
      'circulation' => 'Sirkulasi',
    ],
  ],
];

/**
 * Validasi scope + page berdasarkan menuGroups
 */
if (!isset($menuGroups[$scope])) {
  $scope = 'global';
}
if (!isset($menuGroups[$scope]['pages'][$page])) {
  // default page per scope
  $page = array_key_first($menuGroups[$scope]['pages']) ?: 'summary';
}


/**
 * data REAL dari DB + cache
 */
$stats = od_get_stats(['start' => $start, 'end' => $end], 300);

// ==========================
// BUFFER CONTENT
// ==========================
ob_start();
?>
<div class="od-wrapper">

<!-- SIDEBAR -->
<aside class="od-sidebar">
  <div class="od-brand">
    <a class="od-brand-link" href="<?= SWB ?>index.php?p=opac_dashboard">
      <?php
      if (isset($sysconf['logo_image']) && $sysconf['logo_image'] !== ''
          && isset($imagesDisk) && $imagesDisk->isExists($path = 'default/'.$sysconf['logo_image'])) {

        echo '<img class="od-logo-img" alt="logo" src="'
          . SWB . 'lib/minigalnano/createthumb.php?filename=images/' . $path . '&width=350">';

      } elseif (file_exists(__DIR__ . '/assets/images/logo.png')) {

        echo '<img class="od-logo-img" alt="logo" src="'
          . SWB . 'plugins/opac_dashboard/assets/images/logo.png">';

      } else {

        echo '<img class="od-logo-img" alt="logo" src="'
          . SWB . 'template/default/assets/images/logo.png">';
      }
      ?>

      <div class="od-brand-text">
        <div class="od-title"><?= od_h($sysconf['library_name']); ?></div>
        <?php if (!empty($sysconf['template']['classic_library_subname'])): ?>
          <div class="od-subtitle"><?= od_h($sysconf['library_subname']); ?></div>
        <?php endif; ?>
      </div>
    </a>
  </div>

  <!-- PERIODE (CLICKABLE FILTER) -->
  <div class="od-range-box">
    <div class="od-range-label d-flex align-items-center justify-content-between">
      <span>Periode</span>
      <button type="button" class="btn btn-xs btn-outline-light" id="odOpenPeriod" style="padding:2px 8px;">
        Ubah
      </button>
    </div>
    <input type="text"
           class="form-control form-control-sm od-range-input"
           id="odPeriodDisplay"
           value="<?= od_h($start . ' - ' . $end); ?>"
           readonly
           style="cursor:pointer;">
  </div>

  <!-- MENU GROUPS -->
  <nav class="od-nav mt-3">
    <?php foreach ($menuGroups as $groupKey => $group): ?>
      <?php
        $panelId = 'nav_' . $groupKey;
        $isActiveGroup = ($scope === $groupKey);
      ?>
      <a class="od-nav-item od-nav-parent <?= $isActiveGroup ? 'active' : '' ?>"
         href="javascript:void(0)"
         data-od-toggle="collapse"
         data-od-target="#<?= $panelId ?>"
         aria-expanded="<?= $isActiveGroup ? 'true' : 'false' ?>"
         aria-controls="<?= $panelId ?>">
        <span class="od-nav-icon"><i class="fas <?= od_h($group['icon']) ?>"></i></span>
        <span class="od-nav-label"><?= od_h($group['label']) ?></span>
        <span class="od-nav-caret"><i class="fas fa-chevron-down"></i></span>
      </a>

      <div class="od-nav-children <?= $isActiveGroup ? 'is-open' : '' ?>" id="<?= $panelId ?>">
        <?php foreach ($group['pages'] as $pKey => $pLabel): ?>
          <a class="od-nav-subitem <?= ($isActiveGroup && $page === $pKey) ? 'active' : '' ?>"
             href="<?= od_url($groupKey, $pKey, ['start' => $start, 'end' => $end]) ?>">
            <?= od_h($pLabel) ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </nav>

  <!-- PERIOD MODAL (simple, no dependency) -->
  <div id="odPeriodModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:9999;">
    <div style="max-width:360px; margin:10vh auto; background:#111; border:1px solid rgba(255,255,255,.12); border-radius:14px; padding:16px;">
      <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
        <div style="font-weight:700;">Filter Periode</div>
        <button type="button" class="btn btn-sm btn-outline-light" id="odClosePeriod">Tutup</button>
      </div>

      <div style="margin-top:12px;">
        <label class="small" style="opacity:.8;">Dari</label>
        <input type="date" class="form-control form-control-sm" id="odStartDate" value="<?= od_h($start) ?>">
      </div>

      <div style="margin-top:10px;">
        <label class="small" style="opacity:.8;">Sampai</label>
        <input type="date" class="form-control form-control-sm" id="odEndDate" value="<?= od_h($end) ?>">
      </div>

      <div style="display:flex; gap:10px; margin-top:14px;">
        <button type="button" class="btn btn-sm btn-light" id="odApplyPeriod" style="flex:1;">Terapkan</button>
        <button type="button" class="btn btn-sm btn-outline-light" id="odResetPeriod">Reset</button>
      </div>

      <div class="small" style="opacity:.75; margin-top:10px;">
        Tip: setelah diterapkan, semua menu akan mengikuti periode yang sama.
      </div>
    </div>
  </div>

  <script>
  (function(){
    const modal   = document.getElementById('odPeriodModal');
    const openBtn = document.getElementById('odOpenPeriod');
    const disp    = document.getElementById('odPeriodDisplay');
    const closeBtn= document.getElementById('odClosePeriod');
    const applyBtn= document.getElementById('odApplyPeriod');
    const resetBtn= document.getElementById('odResetPeriod');
    const startEl = document.getElementById('odStartDate');
    const endEl   = document.getElementById('odEndDate');

    function openModal(){ modal.style.display='block'; }
    function closeModal(){ modal.style.display='none'; }

    openBtn && openBtn.addEventListener('click', openModal);
    disp && disp.addEventListener('click', openModal);
    closeBtn && closeBtn.addEventListener('click', closeModal);

    modal && modal.addEventListener('click', function(e){
      if (e.target === modal) closeModal();
    });

    function buildUrl(start, end){
      const url = new URL(window.location.href);
      url.searchParams.set('p', 'opac_dashboard');
      url.searchParams.set('scope', '<?= od_h($scope) ?>');
      url.searchParams.set('page',  '<?= od_h($page) ?>');
      url.searchParams.set('start', start);
      url.searchParams.set('end', end);
      url.searchParams.delete('action');
      return url.toString();
    }

    applyBtn && applyBtn.addEventListener('click', function(){
      const s = (startEl.value || '').trim();
      const e = (endEl.value || '').trim();
      if (!s || !e) return;

      // simple sanity: swap jika kebalik
      const start = (s <= e) ? s : e;
      const end   = (s <= e) ? e : s;
      window.location.href = buildUrl(start, end);
    });

    resetBtn && resetBtn.addEventListener('click', function(){
      // reset ke default (sesuaikan kalau mau)
      const start = '2016-09-14';
      const end   = new Date().toISOString().slice(0,10);
      window.location.href = buildUrl(start, end);
    });
  })();
  </script>
</aside>


  <!-- MAIN -->
  <main class="od-main">

    <!-- TOPBAR -->
    <div class="od-topbar-dark">
      <div class="od-topbar-left">
        <button class="od-burger" type="button" id="odToggleSidebar" aria-label="Toggle sidebar">
          <span></span><span></span><span></span>
        </button>

        <div class="od-topbar-title">Tingkat</div>
        <div class="od-topbar-sub"><?= od_h($menuGroups[$scope]['label']); ?></div>

      </div>

      <!-- <div class="od-topbar-actions">
        <a class="btn btn-sm btn-outline-light"
           href="<?= od_url($scope, $page, ['start'=>$start,'end'=>$end,'action'=>'export_pdf']) ?>">PDF</a>
        <a class="btn btn-sm btn-outline-light"
           href="<?= od_url($scope, $page, ['start'=>$start,'end'=>$end,'action'=>'export_csv']) ?>">CSV</a>
      </div> -->
    </div>

    <!-- CONTENT -->
    <div class="od-content">
      <div class="od-section-title">
        <?= od_h($menuGroups[$scope]['pages'][$page]) ?> | <?= od_h($menuGroups[$scope]['label']) ?>

        <span class="od-section-date"><?= od_h($start . ' - ' . $end); ?></span>
      </div>

      <?php
      $pageFile = __DIR__ . '/pages/' . $page . '.php';
      if (is_file($pageFile)) {
        require $pageFile;
      } else {
        require __DIR__ . '/pages/summary.php';
      }
      ?>

      <div class="od-footer-note">2026 © Public Reporting by Erwan Setyo Budi</div>
    </div>
  </main>

</div>
<?php
$main_content = ob_get_clean();
$page_title   = 'Dashboard OPAC | ' . $sysconf['library_name'];

$main_template_path = __DIR__ . '/templates/dashboard_layout.inc.php';
require $main_template_path;
exit;
