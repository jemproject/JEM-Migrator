<?php
/**
 * @package    JEM Migrator
 * @copyright  (C) 2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace Joomla\Plugin\Jem\Import\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** Resolve same-name venues without silently merging different places. */
final class VenueIdentity
{
    private const FIELDS = ['street', 'postalCode', 'city', 'state', 'country'];

    public static function key(array $venue): string
    {
        $parts = [self::normalise((string) ($venue['venue'] ?? '')), (string) (int) ($venue['access'] ?? 1)];

        foreach (self::FIELDS as $field) {
            $parts[] = self::normalise((string) ($venue[$field] ?? ''));
        }

        return hash('sha256', implode("\x1f", $parts));
    }

    /** Return a matching JEM ID, zero for a distinct new venue, or reject ambiguity. */
    public static function resolve(array $source, array $candidates): int
    {
        if (!$candidates) {
            return 0;
        }

        $exact = [];
        $incomplete = false;
        $accessConflict = false;

        foreach ($candidates as $candidate) {
            $different = false;
            $provedDistinct = false;

            foreach (self::FIELDS as $field) {
                $left = self::normalise((string) ($source[$field] ?? ''));
                $right = self::normalise((string) ($candidate[$field] ?? ''));

                if ($left !== $right) {
                    $different = true;
                    $provedDistinct = $provedDistinct || ($left !== '' && $right !== '');
                }
            }

            if ((int) ($source['access'] ?? 1) !== (int) ($candidate['access'] ?? 1)) {
                if (!$provedDistinct) {
                    $accessConflict = true;
                }

                continue;
            }

            if (!$different) {
                $exact[] = (int) $candidate['id'];
            } elseif (!$provedDistinct) {
                $incomplete = true;
            }
        }

        if (\count($exact) > 1) {
            throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_VENUE_DUPLICATE'));
        }

        if ($exact) {
            return $exact[0];
        }

        if ($accessConflict) {
            throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_VENUE_ACL'));
        }

        if ($incomplete) {
            throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_VENUE_AMBIGUOUS'));
        }

        return 0;
    }

    private static function normalise(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
