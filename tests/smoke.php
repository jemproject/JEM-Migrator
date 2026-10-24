<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 *
 * Framework-free unit checks for migration services that do not need Joomla.
 * Run: php tests/smoke.php
 */
namespace Joomla\CMS\Language { class Text { static function _($k) { return $k; } static function sprintf($k, ...$a) { return $k . '(' . implode(',', $a) . ')'; } } }
namespace Joomla\Database {
    interface QueryInterface {}
    interface DatabaseInterface {}
    class FakeDb implements DatabaseInterface {
        public $executed = [];
        public $result = [];
        function setQuery($q, $o = 0, $l = 0) { $this->executed[] = (string) $q; return $this; }
        function loadResult() { return 1; }
        function loadObjectList($k = '') { return $this->result; }
        function getQuery($new = false) { return new FakeQuery(); }
        function quoteName($n, $as = null) { return is_array($n) ? $n : "`$n`"; }
        function quote($t, $e = true) { return "'$t'"; }
        function getPrefix() { return 'jos_'; }
        function getTableList() { return []; }
    }
    class FakeQuery implements QueryInterface { function __toString() { return "\nSELECT 1"; } }
}
namespace Joomla\CMS {
    class Factory {
        static function getApplication() { return new class { function get($k, $d = null) { return 'Europe/Madrid'; } }; }
        static function getDate($t = 'now', $tz = null) { return new class($tz) { private $tz; function __construct($tz) { $this->tz = $tz; } function format($f, $l = false) { return (new \DateTime('now', new \DateTimeZone($this->tz)))->format($f); } }; }
    }
}
namespace {
    define('_JEXEC', 1);
    $src = dirname(__DIR__) . '/src';
    require $src . '/plg_jem_import/src/Service/BatchNormaliser.php';
    require $src . '/plg_jem_import/src/Service/BatchFailure.php';
    require $src . '/plg_jem_import/src/Service/LocalImageSource.php';
    require $src . '/com_jemmigrator/administrator/src/Adapter/ReadOnlyDatabase.php';
    require $src . '/com_jemmigrator/administrator/src/Adapter/AbstractAdapter.php';
    require $src . '/com_jemmigrator/administrator/src/Service/PayloadBuilder.php';

    use Joomla\Plugin\Jem\Import\Service\BatchNormaliser;
    use Joomla\Plugin\Jem\Import\Service\BatchFailure;
    use Joomla\Plugin\Jem\Import\Service\LocalImageSource;
    use JemProject\Component\JemMigrator\Administrator\Adapter\ReadOnlyDatabase;
    use JemProject\Component\JemMigrator\Administrator\Adapter\AbstractAdapter;
    use JemProject\Component\JemMigrator\Administrator\Service\PayloadBuilder;

    $fail = 0;
    function check($label, $ok) { global $fail; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$ok) $fail++; }

    // 1. Format version and source are enforced.
    try { BatchNormaliser::normalise(['format_version' => 3, 'source' => 'x1']); check('rejects format 3', false); } catch (InvalidArgumentException $e) { check('rejects format 3', true); }
    try { BatchNormaliser::normalise(['format_version' => 1, 'source' => 'Bad Source!']); check('rejects bad source', false); } catch (InvalidArgumentException $e) { check('rejects bad source', true); }

    // 2. Fields are normalised and unknown fields dropped.
    $b = BatchNormaliser::normalise(['format_version' => 1, 'source' => 'jevents', 'hack' => 'x', 'options' => ['category_mode' => 'evil', 'parent_catid' => -5],
        'events' => [
            ['ref' => 'a', 'dates' => '2027-02-30', 'times' => '25:00', 'published' => 9, 'created_by' => 42, 'id' => 99, 'category_refs' => ['1', '', '1'], 'venue' => ['name' => 'Studio', 'latitude' => 200]],
            ['ref' => 'b', 'dates' => '2027-06-12', 'times' => '9:5', 'endtimes' => '21:30', 'published' => 2],
            'not-an-array',
        ]]);
    check('unknown top-level key dropped', !isset($b['hack']));
    check('invalid category mode becomes tree', $b['options']['category_mode'] === 'tree');
    check('parent category at least 1', $b['options']['parent_catid'] === 1);
    check('invalid date gives error', $b['events'][0]['errors'] !== [] && $b['events'][0]['dates'] === null);
    check('invalid time is an error row', $b['events'][0]['times'] === null && count($b['events'][0]['errors']) >= 2);
    check('published 9 becomes 0', $b['events'][0]['published'] === 0);
    check('archived (2) kept', $b['events'][1]['published'] === 2);
    check('unknown event field (id) dropped', !array_key_exists('id', $b['events'][0]));
    check('category refs deduplicated', $b['events'][0]['category_refs'] === ['1']);
    check('out-of-range latitude dropped', $b['events'][0]['venue']['latitude'] === null);
    check('time 9:5 rejected (needs HH:MM)', $b['events'][1]['times'] === null && $b['events'][1]['errors'] !== []);
    check('time 21:30 normalised', $b['events'][1]['endtimes'] === '21:30:00');
    check('non-array row becomes error row', $b['events'][2]['errors'] !== []);
    check('format 1 does not accept image input', $b['options']['copy_images'] === 0 && $b['events'][0]['image_intro'] === '');

    $imageBatch = BatchNormaliser::normalise(['format_version' => 2, 'source' => 'icagenda',
        'options' => ['copy_images' => 1],
        'categories' => [['ref' => 'c', 'image' => 'images/category.png']],
        'events' => [['ref' => 'e', 'dates' => '2027-05-01', 'image_intro' => 'https://example.org/event.jpg',
            'image_full' => 'images/event.jpg', 'venue' => ['name' => 'Hall', 'image' => 'media/hall.jpg']]],
    ]);
    check('format 2 accepts image references', $imageBatch['format_version'] === 2
        && $imageBatch['options']['copy_images'] === 1
        && $imageBatch['categories'][0]['image'] === 'images/category.png'
        && $imageBatch['events'][0]['image_intro'] === 'https://example.org/event.jpg'
        && $imageBatch['events'][0]['image_full'] === 'images/event.jpg'
        && $imageBatch['events'][0]['venue']['image'] === 'media/hall.jpg');

    $manual = BatchNormaliser::normalise(['format_version' => 2, 'source' => 'jevents',
        'options' => ['category_mode' => 'manual', 'parent_catid' => 1, 'category_map' => ['58' => 10, '61' => 8]],
    ]);
    check('manual category mapping keeps explicit anchors', $manual['options']['category_mode'] === 'manual'
        && $manual['options']['category_map'] === ['58' => 10, '61' => 8]);
    try {
        BatchNormaliser::normalise(['format_version' => 2, 'source' => 'jevents',
            'options' => ['category_mode' => 'manual', 'category_map' => ['58' => 1]],
        ]);
        check('manual map rejects JEM root', false);
    } catch (InvalidArgumentException $e) {
        check('manual map rejects JEM root', true);
    }

    $imageRoot = sys_get_temp_dir() . '/jem-migrator-image-' . bin2hex(random_bytes(4));
    mkdir($imageRoot . '/images', 0777, true);
    file_put_contents($imageRoot . '/images/local.png', 'fixture');
    define('JPATH_ROOT', $imageRoot);
    check('local source resolved', LocalImageSource::resolve('images/local.png')['status'] === 'local');
    check('remote URL is not resolved', LocalImageSource::resolve('https://example.org/event.png')['status'] === 'external');
    check('protocol-relative URL is not resolved', LocalImageSource::resolve('//example.org/event.png')['status'] === 'external');
    check('missing local image reported', LocalImageSource::resolve('images/missing.png')['status'] === 'missing');
    check('path traversal rejected', LocalImageSource::resolve('images/../outside.png')['status'] === 'unsupported');
    unlink($imageRoot . '/images/local.png');
    rmdir($imageRoot . '/images');
    rmdir($imageRoot);

    $badEnd = BatchNormaliser::normalise(['format_version' => 1, 'source' => 'jevents', 'events' => [
        ['ref' => 'end', 'dates' => '2027-05-01', 'enddates' => '2027-02-30', 'endtimes' => '25:30'],
    ]]);
    check('malformed supplied end date and time are errors', count($badEnd['events'][0]['errors']) === 2);

    $omittedEnd = BatchNormaliser::normalise(['format_version' => 1, 'source' => 'jevents', 'events' => [
        ['ref' => 'open', 'dates' => '2027-05-01'],
    ]]);
    check('omitted end date and time are valid', $omittedEnd['events'][0]['errors'] === []);

    $failure = new BatchFailure('Unable to clean up', 42, false, new RuntimeException('cause'));
    check('failed batch preserves run ID', $failure->getRunId() === 42);
    check('unsafe cleanup disables retry', !$failure->isRetrySafe());

    // 3. Batch size limit.
    try { BatchNormaliser::normalise(['format_version' => 1, 'source' => 'x1', 'events' => array_fill(0, 501, [])]); check('rejects 501 events', false); } catch (InvalidArgumentException $e) { check('rejects 501 events', true); }

    // 4. Read-only database refuses writes.
    $ro = new ReadOnlyDatabase(new \Joomla\Database\FakeDb());
    foreach (['DELETE FROM jos_x', 'UPDATE jos_x SET a=1', 'INSERT INTO jos_x VALUES(1)', 'DROP TABLE jos_x', 'SELECT 1; DELETE FROM jos_x', 'SELECT * FROM jos_x INTO OUTFILE "/tmp/x"', 'SELECT * FROM jos_x FOR UPDATE'] as $sql) {
        try { $ro->setQuery($sql); check('refuses: ' . $sql, false); } catch (RuntimeException $e) { check('refuses: ' . $sql, true); }
    }
    try { $ro->setQuery("  SELECT COUNT(*) FROM jos_x;")->loadResult(); check('allows SELECT', true); } catch (RuntimeException $e) { check('allows SELECT', false); }
    try { $ro->setQuery($ro->getQuery(true))->loadResult(); check('allows query object starting with newline', true); } catch (RuntimeException $e) { check('allows query object starting with newline', false); }
    check('no execute() method', !method_exists($ro, 'execute') && !method_exists($ro, 'insertObject'));

    // 5. PayloadBuilder: series, skipped rows, used categories.
    $adapter = new class(new \Joomla\Database\FakeDb()) extends AbstractAdapter {
        function getSource(): string { return 'fake'; }
        function getLabel(): string { return 'Fake'; }
        function getElement(): string { return 'com_fake'; }
        function isAvailable(): bool { return true; }
        function getSummary(): array { return []; }
        function loadCategories(): array {
            return [
                '1' => ['src_id' => '1', 'src_parent' => '', 'catname' => 'Root A'],
                '2' => ['src_id' => '2', 'src_parent' => '1', 'catname' => 'Child'],
                '3' => ['src_id' => '3', 'src_parent' => '', 'catname' => 'Unused'],
            ];
        }
        function loadOccurrences(): array {
            return [
                ['src_key' => 'k1', 'src_series' => 's1', 'title' => 'Weekly', 'dates' => '2000-01-01', 'categories' => ['2'], 'venue' => ['venue' => 'Studio 3', 'postalCode' => '1234']],
                ['src_key' => 'k2', 'src_series' => 's1', 'title' => 'Weekly', 'dates' => '2099-01-08', 'categories' => ['2'], 'venue' => null],
                ['src_key' => 'k3', 'src_series' => 's1', 'title' => 'Weekly', 'dates' => '2099-01-15', 'categories' => ['2']],
                ['src_key' => 'k4', 'src_series' => 's2', 'title' => 'Once', 'dates' => '2099-03-01', 'categories' => ['2']],
                ['src_key' => 'k5', 'src_series' => '', 'title' => 'External', 'dates' => '2099-03-01', 'categories' => ['x'], 'status' => 'skip'],
            ];
        }
    };
    $p = (new PayloadBuilder())->build($adapter, ['category_mode' => 'tree', 'skip_past' => 1]);
    check('past date skipped', $p['counts']['skipped_past'] === 1);
    check('adapter skip row not sent', $p['counts']['skipped_source'] === 1 && count($p['events']) === 3);
    check('series with 2 remaining dates keeps series_ref', $p['events'][0]['series_ref'] === 's1' && $p['events'][1]['series_ref'] === 's1');
    check('single remaining date has no series_ref', $p['events'][2]['series_ref'] === '');
    check('only used categories plus parents, parents first', array_column($p['categories'], 'ref') === ['1', '2']);
    check('child category keeps parent ref', $p['categories'][1]['parent_ref'] === '1');
    $p2 = (new PayloadBuilder())->build($adapter, ['category_mode' => 'single', 'skip_past' => 0]);
    check('single mode sends no categories', $p2['categories'] === [] && count($p2['events']) === 4);
    $p3 = (new PayloadBuilder())->build($adapter, ['category_mode' => 'manual', 'skip_past' => 0]);
    check('manual mode includes unused source categories', array_column($p3['categories'], 'ref') === ['1', '2', '3']);
    check('venue converted to format keys', $p2['events'][0]['venue']['name'] === 'Studio 3' && $p2['events'][0]['venue']['postal_code'] === '1234');
    check('payload fingerprint is stable', PayloadBuilder::fingerprint($p) === PayloadBuilder::fingerprint($p));
    $changedPayload = $p;
    $changedPayload['events'][0]['title'] = 'Changed';
    check('payload fingerprint detects changed source', PayloadBuilder::fingerprint($p) !== PayloadBuilder::fingerprint($changedPayload));

    echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
    exit($fail ? 1 : 0);
}
