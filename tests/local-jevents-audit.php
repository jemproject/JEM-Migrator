<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 *
 * Read-only local diagnostic. Usage: php tests/local-jevents-audit.php <site-root>
 */

declare(strict_types=1);

$site = realpath((string) ($argv[1] ?? ''));

if ($site === false || !is_file($site . '/configuration.php')) {
    fwrite(STDERR, "Provide an installed Joomla site root.\n");
    exit(1);
}

require_once $site . '/configuration.php';

$config = new JConfig();
$db = new mysqli($config->host, $config->user, $config->password, $config->db);
$db->set_charset('utf8mb4');
$prefix = $config->dbprefix;
$safePrefix = preg_quote($prefix, '/');
$tables = [];

foreach ($db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) as $row) {
    $table = (string) $row[0];

    if (preg_match('/^' . $safePrefix . '(jevents_|jem_import_)/', $table)) {
        $tables[] = $table;
    }
}

foreach ($tables as $table) {
    $quoted = '`' . str_replace('`', '``', $table) . '`';
    $count = (int) $db->query('SELECT COUNT(*) FROM ' . $quoted)->fetch_row()[0];
    $fields = array_column($db->query('SHOW COLUMNS FROM ' . $quoted)->fetch_all(MYSQLI_ASSOC), 'Field');
    echo substr($table, strlen($prefix)) . ': ' . $count . ' rows; columns: ' . implode(', ', $fields) . PHP_EOL;
}

$categories = $db->prepare('SELECT COUNT(*) FROM `' . str_replace('`', '``', $prefix) . 'categories` WHERE extension = ?');
$extension = 'com_jevents';
$categories->bind_param('s', $extension);
$categories->execute();
echo 'com_jevents categories: ' . (int) $categories->get_result()->fetch_row()[0] . PHP_EOL;

foreach ($db->query(
    'SELECT id, parent_id, title, alias FROM `' . str_replace('`', '``', $prefix) . 'categories`'
    . " WHERE extension = 'com_jevents' ORDER BY lft"
)->fetch_all(MYSQLI_ASSOC) as $row) {
    echo 'source category ' . $row['id'] . ': ' . $row['title'] . ' (parent ' . $row['parent_id'] . ')' . PHP_EOL;
}

foreach ($db->query(
    'SELECT id, parent_id, catname FROM `' . str_replace('`', '``', $prefix) . 'jem_categories`'
    . " WHERE published IN (0, 1) ORDER BY lft LIMIT 60"
)->fetch_all(MYSQLI_ASSOC) as $row) {
    echo 'JEM category ' . $row['id'] . ': ' . $row['catname'] . ' (parent ' . $row['parent_id'] . ')' . PHP_EOL;
}

foreach ($db->query(
    'SELECT v.ev_id, v.catid, d.summary, r.startrepeat FROM `' . str_replace('`', '``', $prefix) . 'jevents_vevent` AS v'
    . ' JOIN `' . str_replace('`', '``', $prefix) . 'jevents_vevdetail` AS d ON d.evdet_id = v.detail_id'
    . ' JOIN `' . str_replace('`', '``', $prefix) . 'jevents_repetition` AS r ON r.eventid = v.ev_id'
    . ' ORDER BY v.ev_id, r.rp_id LIMIT 20'
)->fetch_all(MYSQLI_ASSOC) as $row) {
    echo 'source event ' . $row['ev_id'] . ': ' . $row['summary'] . ' (' . $row['startrepeat'] . ', category ' . $row['catid'] . ')' . PHP_EOL;
}

foreach ($db->query(
    'SELECT evid, catid FROM `' . str_replace('`', '``', $prefix) . 'jevents_catmap` ORDER BY evid, ordering'
)->fetch_all(MYSQLI_ASSOC) as $row) {
    echo 'category map event ' . $row['evid'] . ': ' . $row['catid'] . PHP_EOL;
}
