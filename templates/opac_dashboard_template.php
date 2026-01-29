<?php
/**
 * @Created by          : Erwan Setyo Budi (erwans818@gmail.com)
 * @Date                : 29/01/2026 10:03
 * @File name           : opac_dashboard.template.php
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

// Pastikan variabel ini tersedia untuk header.php:
if (!isset($metadata)) $metadata = '';
if (!isset($js)) $js = '';

// Panggil HEAD + asset SLiMS (bootstrap, jquery, dll)
require SB . $sysconf['template']['dir'] . '/' . $sysconf['template']['theme'] . '/parts/header.php';

// Tambahkan CSS/JS plugin (opsional)
?>
<link rel="stylesheet" href="<?php echo SWB; ?>plugins/opac_dashboard/assets/css/dashboard.css?v=<?php echo date('YmdHis'); ?>">
<script src="<?php echo SWB; ?>plugins/opac_dashboard/assets/js/dashboard.js?v=<?php echo date('YmdHis'); ?>"></script>

<style>
  /* memastikan tidak ada padding/margin dari body template */
  body { margin: 0; }
</style>

<?php
// Render konten utama dari controller
echo $main_content;

// Tutup HTML secara mandiri (karena kita tidak pakai footer OPAC)
?>
</body>
</html>
