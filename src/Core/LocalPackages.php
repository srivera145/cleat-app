<?php

declare(strict_types=1);

namespace Keel\Core;

use Composer\Script\Event;
use Composer\Util\Filesystem;
use Composer\Util\Platform;

/**
 * Points installed packages at sibling checkouts during local development, so
 * edits to a package show up in the app without a reinstall.
 *
 * composer.json and composer.lock stay pure Packagist, which is exactly what a
 * server installs. A path repository can't give both: the lock it writes pins
 * the package to the path, and a clone without that path fails to install.
 * Instead, after each dev-mode install or update, every package listed here
 * whose path exists has its vendor/ directory swapped for a junction (a
 * symlink off Windows) to that path:
 *
 *   "extra": {
 *     "local-packages": {
 *       "echodial/cleat": "../cleat-package"
 *     }
 *   }
 *
 * Where the path is missing, or under --no-dev, nothing happens. Composer
 * removes junctions and symlinks without following them, so a later update of
 * the package replaces the link and never touches the checkout.
 */
final class LocalPackages
{
    public static function link(Event $event): void
    {
        if (! $event->isDevMode()) {
            return;
        }

        $composer = $event->getComposer();
        $io       = $event->getIO();
        $root     = dirname((string) $composer->getConfig()->get('vendor-dir'));
        $fs       = new Filesystem();

        foreach ($composer->getPackage()->getExtra()['local-packages'] ?? [] as $name => $path) {
            $source = realpath($fs->isAbsolutePath($path) ? $path : $root . '/' . $path);

            if ($source === false || ! is_file($source . '/composer.json')) {
                continue;
            }

            $json = json_decode((string) file_get_contents($source . '/composer.json'), true);

            if (($json['name'] ?? null) !== $name) {
                $io->writeError(sprintf('<warning>Local packages: %s is not %s, left %s as installed.</warning>', $path, $name, $name));

                continue;
            }

            $package = $composer->getRepositoryManager()->getLocalRepository()->findPackage($name, '*');

            if ($package === null) {
                continue;
            }

            $target = (string) $composer->getInstallationManager()->getInstallPath($package);

            if (realpath($target) !== $source) {
                $fs->removeDirectory($target);

                if (Platform::isWindows()) {
                    $fs->junction($source, $target);
                } else {
                    $fs->relativeSymlink($source, $target);
                }
            }

            $io->write(sprintf('<info>Local packages: %s -> %s</info> (live)', $name, $path));
        }
    }
}
