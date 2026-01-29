<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : dashboard_layout.inc.php
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

$page_title = $page_title ?? 'OPAC Dashboard';
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap + FontAwesome bawaan template SLiMS -->
  <link rel="stylesheet" href="<?= SWB ?>template/default/assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= SWB ?>template/default/assets/plugin/font-awesome/css/fontawesome-all.min.css">

  <!-- CSS plugin -->
  <link rel="stylesheet" href="<?= SWB ?>plugins/opac_dashboard/assets/css/dashboard.css?v=<?= date('YmdHis') ?>">
</head>

<body class="od-body">
  <?= $main_content ?>

  <script src="<?= SWB ?>template/default/assets/js/jquery.min.js"></script>
  <script src="<?= SWB ?>template/default/assets/js/bootstrap.bundle.min.js"></script>

  <!-- JS plugin -->
  <script src="<?= SWB ?>plugins/opac_dashboard/assets/js/dashboard.js?v=<?= date('YmdHis') ?>"></script>
</body>
</html>
