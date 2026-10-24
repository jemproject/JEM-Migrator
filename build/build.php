<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 *
 * Builds the installable ZIP files into dist/:
 *   plg_jem_import_v<version>.zip   JEM import plugin (JEM side)
 *   pkg_jemmigrator_v<version>.zip  JEM Migrator component + adapter plugins
 *
 * Usage: php build/build.php
 */

$root = dirname(__DIR__);
$src = $root . '/src';
$dist = $root . '/dist';
$tmp = $dist . '/tmp';

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "The PHP zip extension is required.\n");
    exit(1);
}

@mkdir($tmp, 0775, true);

function version(string $manifest): string
{
    $xml = simplexml_load_file($manifest);

    return (string) $xml->version;
}

function zipFolder(string $folder, string $target): void
{
    $zip = new ZipArchive();

    if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create ' . $target);
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($folder) + 1));
        $zip->addFile($file->getPathname(), $relative);
    }

    $zip->close();
}

$importVersion = version($src . '/plg_jem_import/import.xml');
zipFolder($src . '/plg_jem_import', $dist . '/plg_jem_import_v' . $importVersion . '.zip');

$packageVersion = version($src . '/pkg_jemmigrator/pkg_jemmigrator.xml');
$packageDir = $tmp . '/pkg';
@mkdir($packageDir . '/packages', 0775, true);
@mkdir($packageDir . '/language/en-GB', 0775, true);

foreach (['com_jemmigrator', 'plg_jemmigrator_jevents', 'plg_jemmigrator_dpcalendar', 'plg_jemmigrator_icagenda'] as $extension) {
    zipFolder($src . '/' . $extension, $packageDir . '/packages/' . $extension . '.zip');
}

copy($src . '/pkg_jemmigrator/pkg_jemmigrator.xml', $packageDir . '/pkg_jemmigrator.xml');
copy($src . '/pkg_jemmigrator/script.php', $packageDir . '/script.php');
copy($src . '/pkg_jemmigrator/language/en-GB/pkg_jemmigrator.sys.ini', $packageDir . '/language/en-GB/pkg_jemmigrator.sys.ini');
zipFolder($packageDir, $dist . '/pkg_jemmigrator_v' . $packageVersion . '.zip');

// Clean up the temporary folder.
$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($items as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
rmdir($tmp);

echo "Built:\n";
foreach (glob($dist . '/*.zip') as $file) {
    printf("  %s (%d KB)\n", basename($file), ceil(filesize($file) / 1024));
}
