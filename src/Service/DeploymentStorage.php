<?php

declare(strict_types=1);

namespace PushLocal\Service;

use WP_Error;

final class DeploymentStorage
{
    public function baseDirectory()
    {
        $upload = wp_upload_dir();

        if (!empty($upload['error'])) {
            return new WP_Error('pushlocal_upload_dir_error',(string)$upload['error'],['status'=>500]);
        }

        $base = trailingslashit((string)$upload['basedir']) . 'pushlocal';

        if (!$this->ensureProtectedDirectory($base)) {
            return new WP_Error('pushlocal_storage_unavailable',__('PushLocal could not create its protected storage directory.','pushlocal'),['status'=>500]);
        }

        foreach (['staging','backups','locks'] as $child) {
            if (!$this->ensureProtectedDirectory($base . '/' . $child)) {
                return new WP_Error('pushlocal_storage_unavailable',__('PushLocal could not prepare its storage directories.','pushlocal'),['status'=>500]);
            }
        }

        return $base;
    }

    public function createDeploymentDirectory(string $deploymentId)
    {
        $base=$this->baseDirectory();
        if (is_wp_error($base)) return $base;

        $directory=$base . '/staging/' . $deploymentId;

        if (!$this->ensureProtectedDirectory($directory)) {
            return new WP_Error('pushlocal_staging_failed',__('Could not create the deployment staging directory.','pushlocal'),['status'=>500]);
        }

        if (!wp_mkdir_p($directory . '/files')) {
            return new WP_Error('pushlocal_staging_failed',__('Could not create the deployment staging files directory.','pushlocal'),['status'=>500]);
        }

        return $directory;
    }

    public function backupDirectory(string $deploymentId)
    {
        $base=$this->baseDirectory();
        if (is_wp_error($base)) return $base;

        $directory=$base . '/backups/' . $deploymentId;

        if (!$this->ensureProtectedDirectory($directory)) {
            return new WP_Error('pushlocal_backup_failed',__('Could not create the deployment backup directory.','pushlocal'),['status'=>500]);
        }

        if (!wp_mkdir_p($directory . '/files')) {
            return new WP_Error('pushlocal_backup_failed',__('Could not create the deployment backup files directory.','pushlocal'),['status'=>500]);
        }

        return $directory;
    }

    public function readManifest(string $deploymentId)
    {
        $base=$this->baseDirectory();
        if (is_wp_error($base)) return $base;

        $path=$base . '/staging/' . $deploymentId . '/deployment.json';

        if (!is_readable($path)) {
            return new WP_Error('pushlocal_deployment_not_found',__('Deployment not found.','pushlocal'),['status'=>404]);
        }

        $decoded=json_decode((string)file_get_contents($path),true);

        if (!is_array($decoded)) {
            return new WP_Error('pushlocal_deployment_corrupt',__('Deployment metadata is invalid.','pushlocal'),['status'=>500]);
        }

        return $decoded;
    }

    public function listManifests(int $limit=50)
    {
        $base=$this->baseDirectory();
        if (is_wp_error($base)) return $base;

        $directories=glob($base . '/staging/*',GLOB_ONLYDIR);

        if ($directories===false) return [];

        $manifests=[];

        foreach ($directories as $directory) {
            $id=basename($directory);

            if (!preg_match('/^[a-f0-9\-]{36}$/',$id)) continue;

            $manifest=$this->readManifest($id);

            if (is_wp_error($manifest)) continue;

            $manifests[]=$manifest;
        }

        usort($manifests,static fn(array $a,array $b):int=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??'')));

        return array_slice($manifests,0,max(1,min($limit,1000)));
    }

    public function writeManifest(string $deploymentId,array $manifest)
    {
        $base=$this->baseDirectory();
        if (is_wp_error($base)) return $base;

        $path=$base . '/staging/' . $deploymentId . '/deployment.json';
        $json=wp_json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);

        if ($json===false || file_put_contents($path,$json,LOCK_EX)===false) {
            return new WP_Error('pushlocal_manifest_write_failed',__('Could not write deployment metadata.','pushlocal'),['status'=>500]);
        }

        @chmod($path,defined('FS_CHMOD_FILE')?FS_CHMOD_FILE:0644);

        return true;
    }

    public function stagingBlobPath(string $deploymentId,string $token):string
    {
        $base=$this->baseDirectory();
        if (is_wp_error($base)) return '';
        return $base . '/staging/' . $deploymentId . '/files/' . $token . '.blob';
    }

    public function backupBlobPath(string $deploymentId,string $token):string
    {
        $base=$this->baseDirectory();
        if (is_wp_error($base)) return '';
        return $base . '/backups/' . $deploymentId . '/files/' . $token . '.blob';
    }

    public function deleteDeploymentData(string $deploymentId):bool
    {
        $base=$this->baseDirectory();
        if (is_wp_error($base)) return false;

        $this->deleteTree($base . '/staging/' . $deploymentId);
        $this->deleteTree($base . '/backups/' . $deploymentId);

        return true;
    }

    public function deleteStaging(string $deploymentId):void
    {
        $base=$this->baseDirectory();
        if (is_wp_error($base)) return;
        $this->deleteTree($base . '/staging/' . $deploymentId);
    }

    private function ensureProtectedDirectory(string $directory):bool
    {
        if (!is_dir($directory) && !wp_mkdir_p($directory)) return false;

        $files=[
            'index.php'=>"<?php\n// Silence is golden.\n",
            '.htaccess'=>"Require all denied\nDeny from all\n",
            'web.config'=>"<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
        ];

        foreach ($files as $name=>$contents) {
            $path=$directory . '/' . $name;

            if (!file_exists($path)) {
                @file_put_contents($path,$contents,LOCK_EX);
            }
        }

        return true;
    }

    private function deleteTree(string $directory):void
    {
        if (!is_dir($directory)) return;

        $items=scandir($directory);

        if ($items===false) return;

        foreach ($items as $item) {
            if ($item==='.' || $item==='..') continue;

            $path=$directory . '/' . $item;

            if (is_dir($path) && !is_link($path)) {
                $this->deleteTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
