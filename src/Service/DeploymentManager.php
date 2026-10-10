<?php
declare(strict_types=1);

namespace PushLocal\Service;

use RuntimeException;
use Throwable;
use WP_Error;

final class DeploymentManager
{
    private const MAX_FILES=1000;
    private const MAX_FILE_SIZE=10485760;
    private const MAX_TOTAL_SIZE=104857600;

    public function __construct(
        private ?PluginLocator $pluginLocator=null,
        private ?PathValidator $pathValidator=null,
        private ?DeploymentStorage $storage=null,
        private ?DeploymentLock $lock=null,
        private ?PreflightService $preflight=null,
        private ?PhpSyntaxValidator $phpSyntaxValidator=null
    ){
        $this->pluginLocator??=new PluginLocator();
        $this->pathValidator??=new PathValidator();
        $this->storage??=new DeploymentStorage();
        $this->lock??=new DeploymentLock($this->storage);
        $this->preflight??=new PreflightService($this->pluginLocator,$this->storage);
        $this->phpSyntaxValidator??=new PhpSyntaxValidator();
    }

    public function create(string $pluginFile,array $changes,int $userId)
    {
        $target=$this->pluginLocator->resolve($pluginFile);
        if(is_wp_error($target)) return $target;

        $ready=$this->preflight->assertReady($pluginFile);
        if(is_wp_error($ready)) return $ready;

        if(!$changes||count($changes)>self::MAX_FILES){
            return new WP_Error('pushlocal_invalid_change_count',__('Deployment must contain between 1 and 1000 operations.','pushlocal'),['status'=>400]);
        }

        $out=[];$seen=[];$total=0;
        foreach($changes as $change){
            if(!is_array($change)) return $this->badManifest();

            $path=(string)($change['path']??'');
            $op=(string)($change['operation']??'');
            $sha=isset($change['sha256'])&&$change['sha256']!==null?strtolower((string)$change['sha256']):null;
            $remote=isset($change['remote_sha256'])&&$change['remote_sha256']!==null?strtolower((string)$change['remote_sha256']):null;
            $size=(int)($change['size']??-1);

            $valid=$this->pathValidator->validateRelativeFile($path);
            if(is_wp_error($valid)) return $valid;
            if(isset($seen[$path])) return new WP_Error('pushlocal_duplicate_path',__('Duplicate deployment path.','pushlocal'),['status'=>400]);
            $seen[$path]=true;

            if(!in_array($op,['new','modified','delete'],true)) return $this->badManifest();
            if($op==='delete'&&$path===$target['main_file']) return new WP_Error('pushlocal_main_file_delete_blocked',__('PushLocal will not delete the target plugin main file.','pushlocal'),['status'=>400]);

            if($op==='delete'){
                if($sha!==null||!$this->hashValid($remote)) return $this->badManifest();
            }else{
                if(!$this->hashValid($sha)) return $this->badManifest();
                if($op==='modified'&&!$this->hashValid($remote)) return $this->badManifest();
            }

            if($size<0||$size>self::MAX_FILE_SIZE) return new WP_Error('pushlocal_file_too_large',sprintf(__('File exceeds PushLocal size limits: %s','pushlocal'),$path),['status'=>413]);
            if($op!=='delete'){
                $total+=$size;
                if($total>self::MAX_TOTAL_SIZE) return new WP_Error('pushlocal_deployment_too_large',__('Deployment exceeds the 100 MB upload limit.','pushlocal'),['status'=>413]);
            }

            $dest=$this->pathValidator->resolveDestination($target['root'],$path);
            if(is_wp_error($dest)) return $dest;

            if($op==='new'){
                if(file_exists($dest)) return $this->remoteChanged($path);
            }else{
                if(!is_file($dest)||is_link($dest)) return $this->remoteChanged($path);
                $now=hash_file('sha256',$dest);
                if($now===false||!hash_equals((string)$remote,$now)) return $this->remoteChanged($path);
            }

            $out[]=[
                'path'=>$path,'operation'=>$op,'sha256'=>$sha,'remote_sha256'=>$remote,
                'size'=>$size,'token'=>hash('sha256',$path),'uploaded'=>$op==='delete'
            ];
        }

        $id=wp_generate_uuid4();
        $dir=$this->storage->createDeploymentDirectory($id);
        if(is_wp_error($dir)) return $dir;

        $manifest=['id'=>$id,'plugin_file'=>$target['plugin_file'],'user_id'=>$userId,'status'=>'created','created_at'=>gmdate('c'),'changes'=>$out];
        $written=$this->storage->writeManifest($id,$manifest);
        if(is_wp_error($written)){ $this->storage->deleteStaging($id); return $written; }

        return ['id'=>$id,'status'=>'created','files'=>count($out),'bytes'=>$total];
    }

    public function upload(string $id,string $path,array $file,int $userId)
    {
        $m=$this->storage->readManifest($id); if(is_wp_error($m)) return $m;
        $ok=$this->ownerState($m,$userId,['created','uploading']); if(is_wp_error($ok)) return $ok;

        $idx=$this->changeIndex($m,$path);
        if($idx===null) return new WP_Error('pushlocal_unexpected_file',__('This file is not part of the deployment.','pushlocal'),['status'=>400]);

        $change=$m['changes'][$idx];
        if($change['operation']==='delete') return new WP_Error('pushlocal_delete_upload_not_allowed',__('Delete operations do not accept uploads.','pushlocal'),['status'=>400]);
        if(empty($file['tmp_name'])||(int)($file['error']??-1)!==UPLOAD_ERR_OK) return new WP_Error('pushlocal_upload_failed',__('The file upload failed.','pushlocal'),['status'=>400]);

        $tmp=(string)$file['tmp_name'];
        $size=filesize($tmp);
        $hash=$size===false?false:hash_file('sha256',$tmp);
        if($size!==(int)$change['size']||$hash===false||!hash_equals((string)$change['sha256'],$hash)){
            return new WP_Error('pushlocal_upload_mismatch',__('Uploaded file does not match the local manifest.','pushlocal'),['status'=>400]);
        }

        $blob=$this->storage->stagingBlobPath($id,(string)$change['token']);
        if($blob===''||(!@move_uploaded_file($tmp,$blob)&&!@copy($tmp,$blob))) return new WP_Error('pushlocal_staging_failed',__('Could not move the uploaded file into staging.','pushlocal'),['status'=>500]);
        @chmod($blob,defined('FS_CHMOD_FILE')?FS_CHMOD_FILE:0644);

        $m['changes'][$idx]['uploaded']=true;$m['status']='uploading';
        $written=$this->storage->writeManifest($id,$m);
        return is_wp_error($written)?$written:['id'=>$id,'path'=>$path,'uploaded'=>true];
    }

    public function commit(string $id,int $userId)
    {
        $m=$this->storage->readManifest($id); if(is_wp_error($m)) return $m;
        $ok=$this->ownerState($m,$userId,['created','uploading']); if(is_wp_error($ok)) return $ok;
        $ready=$this->preflight->assertReady((string)$m['plugin_file']); if(is_wp_error($ready)) return $ready;

        foreach($m['changes'] as $c){
            if($c['operation']==='delete') continue;
            if(empty($c['uploaded'])) return new WP_Error('pushlocal_files_missing',__('Not all deployment files have been uploaded.','pushlocal'),['status'=>409]);
            $blob=$this->storage->stagingBlobPath($id,(string)$c['token']);
            if(!is_readable($blob)) return new WP_Error('pushlocal_staged_file_missing',__('A staged deployment file is missing.','pushlocal'),['status'=>409]);
            $hash=hash_file('sha256',$blob);
            if($hash===false||!hash_equals((string)$c['sha256'],$hash)) return new WP_Error('pushlocal_staged_file_corrupt',__('A staged file failed SHA-256 verification.','pushlocal'),['status'=>409]);
            $syntax=$this->phpSyntaxValidator->validateFile($blob,(string)$c['path']);
            if(is_wp_error($syntax)) return $syntax;
        }

        $handle=$this->lock->acquire((string)$m['plugin_file']); if(is_wp_error($handle)) return $handle;
        try{return $this->commitLocked($id,$m);}finally{$this->lock->release($handle);}
    }

    public function abort(string $id,int $userId,string $reason='')
    {
        $m=$this->storage->readManifest($id); if(is_wp_error($m)) return $m;
        $ok=$this->ownerState($m,$userId,['created','uploading']); if(is_wp_error($ok)) return $ok;
        $m['status']='aborted';$m['aborted_at']=gmdate('c');
        if($reason!=='') $m['error']=sanitize_text_field(substr($reason,0,1000));
        $written=$this->storage->writeManifest($id,$m);
        return is_wp_error($written)?$written:['id'=>$id,'status'=>'aborted'];
    }

    public function history(int $userId,int $limit=50)
    {
        $items=$this->storage->listManifests($limit); if(is_wp_error($items)) return $items;
        $names=[]; foreach($this->pluginLocator->allTargets() as $t)$names[$t['plugin_file']]=$t['name'];
        $out=[];
        foreach($items as $m){
            if((int)($m['user_id']??0)!==$userId&&!current_user_can('manage_options'))continue;
            $counts=['new'=>0,'modified'=>0,'delete'=>0];$bytes=0;$changes=is_array($m['changes']??null)?$m['changes']:[];
            foreach($changes as $c){$op=(string)($c['operation']??'');if(isset($counts[$op]))$counts[$op]++;$bytes+=(int)($c['size']??0);}
            $pf=(string)($m['plugin_file']??'');
            $out[]=[
                'id'=>(string)($m['id']??''),'plugin_file'=>$pf,'plugin_name'=>$names[$pf]??$pf,
                'status'=>(string)($m['status']??'unknown'),'created_at'=>(string)($m['created_at']??''),
                'completed_at'=>(string)($m['completed_at']??''),'rolled_back_at'=>(string)($m['rolled_back_at']??''),
                'files'=>count($changes),'new_files'=>$counts['new'],'modified_files'=>$counts['modified'],
                'deleted_files'=>$counts['delete'],'bytes'=>$bytes,'can_rollback'=>($m['status']??'')==='completed'
            ];
        }
        return $out;
    }

    public function details(string $id,int $userId)
    {
        $m=$this->storage->readManifest($id); if(is_wp_error($m)) return $m;
        if((int)($m['user_id']??0)!==$userId&&!current_user_can('manage_options')) return new WP_Error('pushlocal_deployment_forbidden',__('You do not have access to this deployment.','pushlocal'),['status'=>403]);
        $changes=[];
        foreach(($m['changes']??[]) as $c)$changes[]=['path'=>(string)$c['path'],'operation'=>(string)$c['operation'],'size'=>(int)$c['size']];
        return [
            'id'=>(string)$m['id'],'plugin_file'=>(string)$m['plugin_file'],'status'=>(string)$m['status'],
            'created_at'=>(string)($m['created_at']??''),'completed_at'=>(string)($m['completed_at']??''),
            'rolled_back_at'=>(string)($m['rolled_back_at']??''),'error'=>(string)($m['error']??''),
            'changes'=>$changes,'can_rollback'=>($m['status']??'')==='completed'
        ];
    }

    public function rollback(string $id,int $userId)
    {
        $m=$this->storage->readManifest($id); if(is_wp_error($m)) return $m;
        if((int)($m['user_id']??0)!==$userId&&!current_user_can('manage_options')) return new WP_Error('pushlocal_deployment_forbidden',__('You do not have access to this deployment.','pushlocal'),['status'=>403]);
        if(($m['status']??'')!=='completed') return new WP_Error('pushlocal_rollback_unavailable',__('Only completed deployments can be rolled back.','pushlocal'),['status'=>409]);
        $handle=$this->lock->acquire((string)$m['plugin_file']);if(is_wp_error($handle))return $handle;
        try{return $this->rollbackLocked($id,$m);}finally{$this->lock->release($handle);}
    }

    private function commitLocked(string $id,array $m)
    {
        $target=$this->pluginLocator->resolve((string)$m['plugin_file']); if(is_wp_error($target)) return $target;

        foreach($m['changes'] as $c){
            $dest=$this->pathValidator->resolveDestination($target['root'],(string)$c['path']); if(is_wp_error($dest))return $dest;
            if($c['operation']==='new'){if(file_exists($dest))return $this->remoteChanged((string)$c['path']);continue;}
            if(!is_file($dest)||is_link($dest))return $this->remoteChanged((string)$c['path']);
            $hash=hash_file('sha256',$dest);
            if($hash===false||!hash_equals((string)$c['remote_sha256'],$hash))return $this->remoteChanged((string)$c['path']);
        }

        $backupDir=$this->storage->backupDirectory($id);if(is_wp_error($backupDir))return $backupDir;
        $m['status']='deploying';$m['commit_started_at']=gmdate('c');$this->storage->writeManifest($id,$m);
        $applied=[];

        try{
            foreach($m['changes'] as $c){
                $path=(string)$c['path'];$op=(string)$c['operation'];
                $dest=$this->pathValidator->resolveDestination($target['root'],$path);
                if(is_wp_error($dest))throw new RuntimeException($dest->get_error_message());

                if(in_array($op,['modified','delete'],true)){
                    $backup=$this->storage->backupBlobPath($id,(string)$c['token']);
                    if($backup===''||!@copy($dest,$backup))throw new RuntimeException("Could not back up {$path}");
                    $bh=hash_file('sha256',$backup);
                    if($bh===false||!hash_equals((string)$c['remote_sha256'],$bh))throw new RuntimeException("Backup verification failed for {$path}");
                }

                $applied[]=$c;
                if($op==='delete'){
                    if(!@unlink($dest)||file_exists($dest))throw new RuntimeException("Could not delete {$path}");
                    continue;
                }

                $dir=dirname($dest);if(!is_dir($dir)&&!wp_mkdir_p($dir))throw new RuntimeException("Could not create directory for {$path}");
                $blob=$this->storage->stagingBlobPath($id,(string)$c['token']);
                $tmp=$dir.'/.'.basename($dest).'.pushlocal-'.substr($id,0,8).'.tmp';
                if(!@copy($blob,$tmp))throw new RuntimeException("Could not write temporary file for {$path}");
                $th=hash_file('sha256',$tmp);
                if($th===false||!hash_equals((string)$c['sha256'],$th)){@unlink($tmp);throw new RuntimeException("Temporary verification failed for {$path}");}
                if(!@rename($tmp,$dest)){if(file_exists($dest)&&!@unlink($dest)){@unlink($tmp);throw new RuntimeException("Could not replace {$path}");}if(!@rename($tmp,$dest))throw new RuntimeException("Could not finalize {$path}");}
                $fh=hash_file('sha256',$dest);
                if($fh===false||!hash_equals((string)$c['sha256'],$fh))throw new RuntimeException("Final verification failed for {$path}");
                if(function_exists('opcache_invalidate'))@opcache_invalidate($dest,true);
            }
        }catch(Throwable $e){
            $errors=$this->restoreOriginal($id,$target['root'],$applied);
            $m['status']=$errors?'rollback_failed':'failed';$m['failed_at']=gmdate('c');$m['error']=$e->getMessage();$m['automatic_rollback_errors']=$errors;
            $this->storage->writeManifest($id,$m);
            return new WP_Error('pushlocal_commit_failed',$errors?__('Deployment failed and automatic rollback was incomplete.','pushlocal'):__('Deployment failed and applied changes were rolled back.','pushlocal'),['status'=>500,'rollback_errors'=>$errors]);
        }

        $m['status']='completed';$m['completed_at']=gmdate('c');$m['applied_files']=count($applied);
        $written=$this->storage->writeManifest($id,$m);if(is_wp_error($written))return $written;
        (new RetentionManager($this->storage))->cleanup();
        return ['id'=>$id,'status'=>'completed','files'=>count($applied)];
    }

    private function rollbackLocked(string $id,array $m)
    {
        $target=$this->pluginLocator->resolve((string)$m['plugin_file']);if(is_wp_error($target))return $target;

        foreach($m['changes'] as $c){
            $path=(string)$c['path'];$op=(string)$c['operation'];$dest=$this->pathValidator->resolveDestination($target['root'],$path);if(is_wp_error($dest))return $dest;
            if($op==='delete'){
                if(file_exists($dest))return $this->rollbackConflict($path);
                $backup=$this->storage->backupBlobPath($id,(string)$c['token']);
                if(!is_readable($backup)||!hash_equals((string)$c['remote_sha256'],(string)hash_file('sha256',$backup)))return new WP_Error('pushlocal_backup_invalid',sprintf(__('Backup verification failed for: %s','pushlocal'),$path),['status'=>409]);
                continue;
            }
            if(!is_file($dest)||is_link($dest))return $this->rollbackConflict($path);
            $hash=hash_file('sha256',$dest);if($hash===false||!hash_equals((string)$c['sha256'],$hash))return $this->rollbackConflict($path);
        }

        $m['status']='rolling_back';$m['rollback_started_at']=gmdate('c');$this->storage->writeManifest($id,$m);
        $rolled=[];
        try{
            foreach(array_reverse($m['changes']) as $c){
                $path=(string)$c['path'];$op=(string)$c['operation'];$dest=$this->pathValidator->resolveDestination($target['root'],$path);
                if(is_wp_error($dest))throw new RuntimeException($dest->get_error_message());
                $rolled[]=$c;
                if($op==='new'){if(file_exists($dest)&&!@unlink($dest))throw new RuntimeException("Could not remove {$path}");continue;}
                $backup=$this->storage->backupBlobPath($id,(string)$c['token']);
                $this->copyVerified($backup,$dest,(string)$c['remote_sha256']);
            }
        }catch(Throwable $e){
            $m['status']='rollback_failed';$m['rollback_error']=$e->getMessage();$this->storage->writeManifest($id,$m);
            return new WP_Error('pushlocal_manual_rollback_failed',__('Rollback failed. Review the affected files before continuing.','pushlocal'),['status'=>500]);
        }

        $m['status']='rolled_back';$m['rolled_back_at']=gmdate('c');$written=$this->storage->writeManifest($id,$m);
        if(is_wp_error($written))return $written;
        (new RetentionManager($this->storage))->cleanup();
        return ['id'=>$id,'status'=>'rolled_back','files'=>count($m['changes'])];
    }

    private function restoreOriginal(string $id,string $root,array $applied):array
    {
        $errors=[];
        foreach(array_reverse($applied) as $c){
            $path=(string)$c['path'];$dest=$this->pathValidator->resolveDestination($root,$path);
            if(is_wp_error($dest)){$errors[]=$path;continue;}
            if($c['operation']==='new'){if(file_exists($dest)&&!@unlink($dest))$errors[]=$path;continue;}
            $backup=$this->storage->backupBlobPath($id,(string)$c['token']);
            try{$this->copyVerified($backup,$dest,(string)$c['remote_sha256']);}catch(Throwable){$errors[]=$path;}
        }
        return $errors;
    }

    private function copyVerified(string $source,string $dest,string $expected):void
    {
        $dir=dirname($dest);if(!is_dir($dir)&&!wp_mkdir_p($dir))throw new RuntimeException('Could not create destination directory.');
        $tmp=$dir.'/.'.basename($dest).'.pushlocal-restore.tmp';
        if(!@copy($source,$tmp))throw new RuntimeException('Could not write restore file.');
        $hash=hash_file('sha256',$tmp);
        if($hash===false||!hash_equals($expected,$hash)){@unlink($tmp);throw new RuntimeException('Restore verification failed.');}
        if(!@rename($tmp,$dest)){if(file_exists($dest))@unlink($dest);if(!@rename($tmp,$dest))throw new RuntimeException('Could not finalize restore.');}
        if(function_exists('opcache_invalidate'))@opcache_invalidate($dest,true);
    }

    private function ownerState(array $m,int $userId,array $states)
    {
        if((int)($m['user_id']??0)!==$userId)return new WP_Error('pushlocal_deployment_forbidden',__('You do not own this deployment.','pushlocal'),['status'=>403]);
        if(!in_array((string)($m['status']??''),$states,true))return new WP_Error('pushlocal_invalid_deployment_state',__('Deployment is not in a valid state for this operation.','pushlocal'),['status'=>409]);
        return true;
    }

    private function changeIndex(array $m,string $path):?int
    {
        foreach(($m['changes']??[]) as $i=>$c)if(is_array($c)&&($c['path']??null)===$path)return (int)$i;
        return null;
    }

    private function hashValid(?string $hash):bool{return $hash!==null&&(bool)preg_match('/^[a-f0-9]{64}$/',$hash);}
    private function badManifest():WP_Error{return new WP_Error('pushlocal_invalid_changes',__('Deployment change manifest is invalid.','pushlocal'),['status'=>400]);}
    private function remoteChanged(string $path):WP_Error{return new WP_Error('pushlocal_remote_changed',sprintf(__('Remote file changed after comparison: %s. Run Check for Changes again.','pushlocal'),$path),['status'=>409]);}
    private function rollbackConflict(string $path):WP_Error{return new WP_Error('pushlocal_rollback_conflict',sprintf(__('Rollback stopped because this path changed after deployment: %s','pushlocal'),$path),['status'=>409]);}
}
