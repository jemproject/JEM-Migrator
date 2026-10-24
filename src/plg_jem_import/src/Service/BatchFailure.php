<?php
/**
 * @package    JEM
 * @copyright  (C) 2013-2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace Joomla\Plugin\Jem\Import\Service;

\defined('_JEXEC') or die;

/**
 * Carries the run ID and cleanup result across a failed batch request.
 */
final class BatchFailure extends \RuntimeException
{
    private int $runId;
    private bool $retrySafe;

    public function __construct(string $message, int $runId, bool $retrySafe, \Throwable $previous)
    {
        parent::__construct($message, 0, $previous);
        $this->runId = $runId;
        $this->retrySafe = $retrySafe;
    }

    public function getRunId(): int
    {
        return $this->runId;
    }

    public function isRetrySafe(): bool
    {
        return $this->retrySafe;
    }
}
