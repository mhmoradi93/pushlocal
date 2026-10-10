<?php

declare(strict_types=1);

namespace PushLocal\Service;

use WP_Error;

final class DeploymentLock
{
    private DeploymentStorage $storage;

    public function __construct(?DeploymentStorage $storage = null)
    {
        $this->storage = $storage ?? new DeploymentStorage();
    }

    public function acquire(string $pluginFile)
    {
        $base = $this->storage->baseDirectory();

        if (is_wp_error($base)) {
            return $base;
        }

        $lockDirectory = $base . '/locks';

        if (!is_dir($lockDirectory) && !wp_mkdir_p($lockDirectory)) {
            return new WP_Error(
                'pushlocal_lock_directory_failed',
                __('Could not create the PushLocal lock directory.', 'pushlocal'),
                ['status' => 500]
            );
        }

        $lockPath = $lockDirectory . '/' . hash('sha256', $pluginFile) . '.lock';
        $handle = @fopen($lockPath, 'c+');

        if ($handle === false) {
            return new WP_Error(
                'pushlocal_lock_failed',
                __('Could not create a deployment lock.', 'pushlocal'),
                ['status' => 500]
            );
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);

            return new WP_Error(
                'pushlocal_deployment_locked',
                __('Another PushLocal operation is already running for this plugin.', 'pushlocal'),
                ['status' => 409]
            );
        }

        @ftruncate($handle, 0);
        @fwrite($handle, (string) getmypid());
        @fflush($handle);

        return $handle;
    }

    public function release($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}
