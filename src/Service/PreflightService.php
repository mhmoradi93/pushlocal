<?php

declare(strict_types=1);

namespace PushLocal\Service;

use Throwable;
use WP_Error;

final class PreflightService
{
    private PluginLocator $pluginLocator;
    private DeploymentStorage $storage;

    public function __construct(?PluginLocator $pluginLocator=null,?DeploymentStorage $storage=null)
    {
        $this->pluginLocator=$pluginLocator??new PluginLocator();
        $this->storage=$storage??new DeploymentStorage();
    }

    public function inspect(string $pluginFile)
    {
        try {
            $target=$this->pluginLocator->resolve($pluginFile);
        } catch (Throwable $error) {
            return $this->unexpectedError('target_resolution',__('Could not resolve the target plugin.','pushlocal'),$error);
        }

        if (is_wp_error($target)) return $target;

        $checks=[];
        $hardFailure=false;

        try {
            $fileModsAllowed=!function_exists('wp_is_file_mod_allowed')||wp_is_file_mod_allowed('pushlocal');
            $checks[]=$this->check(
                'file_mods',
                $fileModsAllowed,
                $fileModsAllowed?__('WordPress allows file modifications.','pushlocal'):__('WordPress file modifications are disabled (for example by DISALLOW_FILE_MODS).','pushlocal'),
                true
            );
            $hardFailure=$hardFailure||!$fileModsAllowed;
        } catch (Throwable $error) {
            $checks[]=$this->exceptionCheck('file_mods',__('Could not evaluate the WordPress file-modification policy.','pushlocal'),true,$error);
            $hardFailure=true;
        }

        $requiredFunctions=['file_put_contents','file_get_contents','rename','unlink','copy','hash_file','is_readable','is_writable'];
        $missingFunctions=array_values(array_filter($requiredFunctions,static fn(string $function):bool=>!function_exists($function)));
        $filesystemFunctionsReady=$missingFunctions===[];

        $checks[]=$this->check(
            'filesystem_functions',
            $filesystemFunctionsReady,
            $filesystemFunctionsReady?__('Required PHP filesystem functions are available.','pushlocal'):sprintf(__('Required PHP filesystem functions are disabled/unavailable: %s','pushlocal'),implode(', ',$missingFunctions)),
            true
        );
        $hardFailure=$hardFailure||!$filesystemFunctionsReady;

        try {
            $rootReadable=is_dir($target['root'])&&is_readable($target['root']);
        } catch (Throwable) {
            $rootReadable=false;
        }

        $checks[]=$this->check(
            'plugin_readable',
            $rootReadable,
            $rootReadable?__('Target plugin directory is readable.','pushlocal'):__('Target plugin directory is not readable.','pushlocal'),
            true
        );
        $hardFailure=$hardFailure||!$rootReadable;

        try {
            $rootWritable=is_dir($target['root'])&&is_writable($target['root']);
        } catch (Throwable) {
            $rootWritable=false;
        }

        $checks[]=$this->check(
            'plugin_writable',
            $rootWritable,
            $rootWritable?__('Target plugin directory is writable.','pushlocal'):__('Target plugin directory is not writable by PHP.','pushlocal'),
            true
        );
        $hardFailure=$hardFailure||!$rootWritable;

        if ($rootWritable&&$filesystemFunctionsReady) {
            try {
                $writeProbe=$this->writeProbe($target['root']);
                $checks[]=$this->check(
                    'plugin_write_probe',
                    $writeProbe,
                    $writeProbe?__('Temporary write/rename/delete probe succeeded in the plugin directory.','pushlocal'):__('Temporary write probe failed in the plugin directory.','pushlocal'),
                    true
                );
                $hardFailure=$hardFailure||!$writeProbe;
            } catch (Throwable $error) {
                $checks[]=$this->exceptionCheck('plugin_write_probe',__('Temporary filesystem probe raised an error.','pushlocal'),true,$error);
                $hardFailure=true;
            }
        } else {
            $checks[]=$this->check('plugin_write_probe',false,__('Temporary write probe was skipped because required write access/functions are unavailable.','pushlocal'),true);
            $hardFailure=true;
        }

        try {
            $base=$this->storage->baseDirectory();
            $storageReady=!is_wp_error($base)&&is_dir($base)&&is_writable($base);

            $checks[]=$this->check(
                'storage',
                $storageReady,
                $storageReady?__('Protected PushLocal staging/backup storage is writable.','pushlocal'):__('PushLocal staging/backup storage is unavailable or not writable.','pushlocal'),
                true
            );
            $hardFailure=$hardFailure||!$storageReady;
        } catch (Throwable $error) {
            $checks[]=$this->exceptionCheck('storage',__('Could not validate PushLocal staging/backup storage.','pushlocal'),true,$error);
            $hardFailure=true;
        }

        if (!function_exists('disk_free_space')) {
            $checks[]=['id'=>'disk_space','status'=>'info','hard'=>false,'message'=>__('Disk free-space check is unavailable on this hosting environment; PushLocal will not block deployment for this.','pushlocal')];
        } else {
            try {
                $freeBytes=@disk_free_space($target['root']);

                if ($freeBytes===false) {
                    $checks[]=['id'=>'disk_space','status'=>'info','hard'=>false,'message'=>__('Disk free space could not be measured; PushLocal will not block deployment for this.','pushlocal')];
                } else {
                    $diskOkay=$freeBytes>=50*1024*1024;
                    $checks[]=[
                        'id'=>'disk_space',
                        'status'=>$diskOkay?'pass':'warning',
                        'hard'=>false,
                        'message'=>sprintf(
                            __('Approximately %s free on the target filesystem.','pushlocal'),
                            function_exists('size_format')?size_format((int)$freeBytes):$this->formatBytes((float)$freeBytes)
                        ),
                    ];
                }
            } catch (Throwable $error) {
                $checks[]=['id'=>'disk_space','status'=>'info','hard'=>false,'message'=>sprintf(__('Disk free-space diagnostic is unavailable: %s','pushlocal'),$this->safeThrowableMessage($error))];
            }
        }

        $checks[]=['id'=>'php_limits','status'=>'info','hard'=>false,'message'=>$this->phpLimitsMessage()];

        return ['ready'=>!$hardFailure,'plugin_file'=>$target['plugin_file'],'checks'=>$checks];
    }

    public function assertReady(string $pluginFile)
    {
        $result=$this->inspect($pluginFile);

        if (is_wp_error($result)) return $result;

        if (empty($result['ready'])) {
            return new WP_Error(
                'pushlocal_preflight_failed',
                __('PushLocal preflight failed. Resolve the blocking server checks before deploying.','pushlocal'),
                ['status'=>409,'checks'=>$result['checks']]
            );
        }

        return true;
    }

    private function check(string $id,bool $passed,string $message,bool $hard):array
    {
        return ['id'=>$id,'status'=>$passed?'pass':($hard?'fail':'warning'),'hard'=>$hard,'message'=>$message];
    }

    private function exceptionCheck(string $id,string $prefix,bool $hard,Throwable $error):array
    {
        return ['id'=>$id,'status'=>$hard?'fail':'warning','hard'=>$hard,'message'=>$prefix.' '.$this->safeThrowableMessage($error)];
    }

    private function writeProbe(string $root):bool
    {
        $token=function_exists('wp_generate_password')?wp_generate_password(12,false,false):bin2hex(random_bytes(6));
        $separator=DIRECTORY_SEPARATOR;
        $root=rtrim($root,'/\\');
        $source=$root.$separator.'.pushlocal-probe-'.$token.'.tmp';
        $destination=$root.$separator.'.pushlocal-probe-'.$token.'.done';
        $payload='pushlocal:'.$token;

        try {
            if (file_put_contents($source,$payload,LOCK_EX)===false) return false;
            if (file_get_contents($source)!==$payload) return false;
            if (!rename($source,$destination)) return false;
            if (!is_file($destination)) return false;
            return unlink($destination);
        } finally {
            if (file_exists($source)) @unlink($source);
            if (file_exists($destination)) @unlink($destination);
        }
    }

    private function phpLimitsMessage():string
    {
        if (!function_exists('ini_get')) {
            return __('PHP runtime limits cannot be inspected because ini_get() is disabled on this hosting environment.','pushlocal');
        }

        try {
            return sprintf(
                __('PHP upload_max_filesize: %1$s · post_max_size: %2$s · memory_limit: %3$s · max_execution_time: %4$s','pushlocal'),
                (string)ini_get('upload_max_filesize'),
                (string)ini_get('post_max_size'),
                (string)ini_get('memory_limit'),
                (string)ini_get('max_execution_time')
            );
        } catch (Throwable $error) {
            return sprintf(__('PHP runtime-limit diagnostic is unavailable: %s','pushlocal'),$this->safeThrowableMessage($error));
        }
    }

    private function safeThrowableMessage(Throwable $error):string
    {
        $message=trim(strip_tags($error->getMessage()));

        if ($message==='') return __('Unknown server error.','pushlocal');
        if (function_exists('mb_substr')) return mb_substr($message,0,300);
        return substr($message,0,300);
    }

    private function formatBytes(float $bytes):string
    {
        $units=['B','KB','MB','GB','TB'];
        $index=0;

        while ($bytes>=1024&&$index<count($units)-1) {
            $bytes/=1024;
            $index++;
        }

        return number_format($bytes,$index===0?0:1).' '.$units[$index];
    }

    private function unexpectedError(string $code,string $message,Throwable $error):WP_Error
    {
        return new WP_Error('pushlocal_preflight_'.$code,$message.' '.$this->safeThrowableMessage($error),['status'=>500]);
    }
}
