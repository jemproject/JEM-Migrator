<?php
/**
 * @package    JEM
 * @copyright  (C) 2013-2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace Joomla\Plugin\Jem\Import\Service;

\defined('_JEXEC') or die;

/**
 * Resolves only existing Joomla-local image references. Never requests a URL.
 */
final class LocalImageSource
{
    /**
     * @return array{status: string, path: string}
     */
    public static function resolve(string $reference): array
    {
        $reference = trim($reference);

        if ($reference === '') {
            return ['status' => 'empty', 'path' => ''];
        }

        if (preg_match('~^(?:https?:)?//~i', $reference)) {
            return ['status' => 'external', 'path' => ''];
        }

        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $reference)) {
            return ['status' => 'unsupported', 'path' => ''];
        }

        // Joomla media fields may append a media-manager fragment to the path.
        $reference = explode('#', $reference, 2)[0];
        $reference = rawurldecode($reference);
        $reference = ltrim(str_replace('\\', '/', $reference), '/');

        if ($reference === '' || strpbrk($reference, "?\0\r\n") !== false) {
            return ['status' => 'unsupported', 'path' => ''];
        }

        $parts = explode('/', $reference);

        if (!\in_array($parts[0], ['images', 'media'], true)
            || \in_array('', $parts, true)
            || \in_array('.', $parts, true)
            || \in_array('..', $parts, true)) {
            return ['status' => 'unsupported', 'path' => ''];
        }

        $root = realpath(JPATH_ROOT . '/' . $parts[0]);
        $path = realpath(JPATH_ROOT . '/' . $reference);

        if ($root === false || $path === false) {
            return ['status' => 'missing', 'path' => ''];
        }

        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        $path = str_replace('\\', '/', $path);

        if (strncmp($path, $root, strlen($root)) !== 0 || !is_file($path)) {
            return ['status' => 'unsupported', 'path' => ''];
        }

        return ['status' => 'local', 'path' => $path];
    }
}
