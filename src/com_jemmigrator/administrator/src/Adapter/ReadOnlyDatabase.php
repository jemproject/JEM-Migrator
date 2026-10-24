<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace JemProject\Component\JemMigrator\Administrator\Adapter;

\defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;

/**
 * Database access for adapters: reading only. Queries other than SELECT,
 * SHOW or DESCRIBE are refused, and there is no execute(), insert or update.
 */
final class ReadOnlyDatabase
{
    private DatabaseInterface $db;

    /** @var QueryInterface|string */
    private $query = '';
    private int $offset = 0;
    private int $limit = 0;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    public function getQuery(bool $new = false): QueryInterface
    {
        return $this->db->getQuery(true);
    }

    /**
     * @param  QueryInterface|string  $query
     */
    public function setQuery($query, int $offset = 0, int $limit = 0): self
    {
        $sql = (string) $query;

        if (!preg_match('/^\s*(SELECT|SHOW|DESCRIBE)\b/i', $sql)
            || strpos(rtrim($sql, "; \t\n\r"), ';') !== false
            || preg_match('/\bINTO\s+(OUTFILE|DUMPFILE|@)|\bFOR\s+UPDATE\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $sql)) {
            throw new \RuntimeException('JEM Migrator adapters may only read from the database.');
        }

        $this->query = $query;
        $this->offset = $offset;
        $this->limit = $limit;

        return $this;
    }

    public function loadResult()
    {
        return $this->prepare()->loadResult();
    }

    public function loadColumn(int $offset = 0): array
    {
        return $this->prepare()->loadColumn($offset);
    }

    public function loadObject()
    {
        return $this->prepare()->loadObject();
    }

    public function loadObjectList(string $key = ''): array
    {
        return $this->prepare()->loadObjectList($key);
    }

    public function loadAssoc()
    {
        return $this->prepare()->loadAssoc();
    }

    public function loadAssocList(?string $key = null, ?string $column = null): array
    {
        return $this->prepare()->loadAssocList($key, $column);
    }

    public function quote($text, bool $escape = true)
    {
        return $this->db->quote($text, $escape);
    }

    public function quoteName($name, $as = null)
    {
        return $this->db->quoteName($name, $as);
    }

    public function escape($text, bool $extra = false): string
    {
        return $this->db->escape($text, $extra);
    }

    public function getPrefix(): string
    {
        return $this->db->getPrefix();
    }

    public function getTableList(): array
    {
        return $this->db->getTableList();
    }

    public function getTableColumns(string $table, bool $typeOnly = true): array
    {
        return $this->db->getTableColumns($table, $typeOnly);
    }

    private function prepare(): DatabaseInterface
    {
        return $this->db->setQuery($this->query, $this->offset, $this->limit);
    }
}
