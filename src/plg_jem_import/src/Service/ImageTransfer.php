<?php
/**
 * @package    JEM
 * @copyright  (C) 2013-2026 joomlaeventmanager.net
 * @license    https://www.gnu.org/licenses/gpl-3.0 GNU/GPL
 */

namespace Joomla\Plugin\Jem\Import\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\Filesystem\File;

/**
 * Copies a local source image into a JEM profile and its configured folder.
 */
final class ImageTransfer
{
    /**
     * @return array{status: string, path: string}
     */
    public function inspect(string $reference, string $profile): array
    {
        $source = LocalImageSource::resolve($reference);

        if ($source['status'] !== 'local') {
            return $source;
        }

        $analysis = \JemImage::analyseStoredImage($source['path'], \JemHelper::config(), $profile, true);

        if (!$analysis['accepted'] || $analysis['minimum_not_met']) {
            return ['status' => 'invalid', 'path' => ''];
        }

        return $source;
    }

    /**
     * @return array{filename: string, path: string, thumbnail: string}
     */
    public function copy(string $source, string $entity, string $folder, string $profile): array
    {
        $pathClass = $this->pathClass($entity);

        $foldersReady = $entity === 'event'
            ? $pathClass::ensureEventFolders($folder)
            : $pathClass::ensureFolders($folder);

        if (!$foldersReady) {
            throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_IMAGE_COPY'));
        }

        $imageFolder = $pathClass::absoluteImageFolder($folder);
        $thumbFolder = $pathClass::absoluteThumbFolder($folder);
        $baseFolders = [
            'event' => 'events',
            'venue' => 'venues',
            'category' => 'categories',
        ];
        $base = realpath(JPATH_ROOT . '/images/jem/' . $baseFolders[$entity]);
        $resolvedFolder = realpath($imageFolder);
        $resolvedThumbFolder = realpath($thumbFolder);

        if ($base === false || $resolvedFolder === false || $resolvedThumbFolder === false) {
            throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_IMAGE_COPY'));
        }

        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';

        foreach ([$resolvedFolder, $resolvedThumbFolder] as $resolved) {
            $resolved = rtrim(str_replace('\\', '/', $resolved), '/') . '/';

            if (strncmp($resolved, $base, strlen($base)) !== 0) {
                throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_IMAGE_COPY'));
            }
        }

        $filename = \JemImage::sanitize($imageFolder, basename($source));
        $target = $imageFolder . $filename;
        $thumbnail = $thumbFolder . $filename;

        if (!\JemImage::copyForProfile($source, $target, $thumbnail, \JemHelper::config(), $profile)) {
            // The profile helper normally cleans up failed writes, but also
            // remove a partial target if a filesystem operation interrupted it.
            foreach ([$target, $thumbnail] as $path) {
                if (is_file($path)) {
                    File::delete($path);
                }
            }

            throw new \RuntimeException(Text::_('PLG_JEM_IMPORT_ERROR_IMAGE_COPY'));
        }

        return ['filename' => $filename, 'path' => $target, 'thumbnail' => $thumbnail];
    }

    private function pathClass(string $entity): string
    {
        switch ($entity) {
            case 'event':
                return \JemEventImagePath::class;

            case 'venue':
                return \JemVenueImagePath::class;

            case 'category':
                return \JemCategoryImagePath::class;
        }

        throw new \InvalidArgumentException('Unknown JEM image profile owner.');
    }
}
