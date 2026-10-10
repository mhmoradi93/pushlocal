<?php

declare(strict_types=1);

namespace PushLocal\Api;

use PushLocal\Service\DeploymentManager;
use PushLocal\Service\PluginLocator;
use PushLocal\Service\PreflightService;
use PushLocal\Service\RemoteScanner;
use PushLocal\Service\RetentionManager;
use PushLocal\Service\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class Routes
{
    private PluginLocator $pluginLocator;
    private RemoteScanner $remoteScanner;
    private DeploymentManager $deploymentManager;
    private PreflightService $preflightService;
    private Settings $settings;

    public function __construct()
    {
        $this->pluginLocator = new PluginLocator();
        $this->remoteScanner = new RemoteScanner();
        $this->deploymentManager = new DeploymentManager();
        $this->preflightService = new PreflightService($this->pluginLocator);
        $this->settings = new Settings();
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('pushlocal/v1','/targets',['methods'=>'GET','callback'=>[$this,'targets'],'permission_callback'=>[$this,'canUsePushLocal']]);
        register_rest_route('pushlocal/v1','/manifest',['methods'=>'POST','callback'=>[$this,'manifest'],'permission_callback'=>[$this,'canUsePushLocal']]);
        register_rest_route('pushlocal/v1','/preflight',['methods'=>'POST','callback'=>[$this,'preflight'],'permission_callback'=>[$this,'canUsePushLocal']]);

        register_rest_route('pushlocal/v1','/deployments',[
            ['methods'=>'POST','callback'=>[$this,'createDeployment'],'permission_callback'=>[$this,'canUsePushLocal']],
            ['methods'=>'GET','callback'=>[$this,'deploymentHistory'],'permission_callback'=>[$this,'canUsePushLocal']],
        ]);

        register_rest_route('pushlocal/v1','/deployments/(?P<id>[a-f0-9\-]{36})',['methods'=>'GET','callback'=>[$this,'deploymentDetails'],'permission_callback'=>[$this,'canUsePushLocal']]);
        register_rest_route('pushlocal/v1','/deployments/(?P<id>[a-f0-9\-]{36})/files',['methods'=>'POST','callback'=>[$this,'uploadDeploymentFile'],'permission_callback'=>[$this,'canUsePushLocal']]);
        register_rest_route('pushlocal/v1','/deployments/(?P<id>[a-f0-9\-]{36})/commit',['methods'=>'POST','callback'=>[$this,'commitDeployment'],'permission_callback'=>[$this,'canUsePushLocal']]);
        register_rest_route('pushlocal/v1','/deployments/(?P<id>[a-f0-9\-]{36})/abort',['methods'=>'POST','callback'=>[$this,'abortDeployment'],'permission_callback'=>[$this,'canUsePushLocal']]);
        register_rest_route('pushlocal/v1','/deployments/(?P<id>[a-f0-9\-]{36})/rollback',['methods'=>'POST','callback'=>[$this,'rollbackDeployment'],'permission_callback'=>[$this,'canUsePushLocal']]);

        register_rest_route('pushlocal/v1','/retention',['methods'=>'GET','callback'=>[$this,'retentionPolicy'],'permission_callback'=>[$this,'canUsePushLocal']]);

        register_rest_route('pushlocal/v1','/settings',[
            ['methods'=>'GET','callback'=>[$this,'getSettings'],'permission_callback'=>[$this,'canUsePushLocal']],
            ['methods'=>'POST','callback'=>[$this,'updateSettings'],'permission_callback'=>[$this,'canManageSettings']],
        ]);
    }

    public function canUsePushLocal(): bool
    {
        return current_user_can('update_plugins');
    }

    public function canManageSettings(): bool
    {
        return current_user_can('manage_options');
    }

    public function targets(): WP_REST_Response
    {
        return new WP_REST_Response(['targets'=>$this->pluginLocator->allTargets()],200);
    }

    public function manifest(WP_REST_Request $request)
    {
        $pluginFile=(string)$request->get_param('plugin');
        $target=$this->pluginLocator->resolve($pluginFile);

        if (is_wp_error($target)) {
            return $target;
        }

        $ignorePatterns=$request->get_param('ignore');

        if (!is_array($ignorePatterns)) {
            $ignorePatterns=[];
        }

        $manifest=$this->remoteScanner->scan($target['root'],$ignorePatterns);

        if (is_wp_error($manifest)) {
            return $manifest;
        }

        return new WP_REST_Response([
            'plugin'=>$target['plugin_file'],
            'root_name'=>$target['folder'],
            'main_file'=>$target['main_file'],
            'files'=>$manifest,
            'count'=>count($manifest),
        ],200);
    }

    public function preflight(WP_REST_Request $request)
    {
        $result=$this->preflightService->inspect((string)$request->get_param('plugin'));

        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response($result,200);
    }

    public function createDeployment(WP_REST_Request $request)
    {
        $changes=$request->get_param('changes');

        if (!is_array($changes)) {
            return new WP_Error('pushlocal_invalid_changes',__('Deployment changes are required.','pushlocal'),['status'=>400]);
        }

        $result=$this->deploymentManager->create(
            (string)$request->get_param('plugin'),
            $changes,
            get_current_user_id()
        );

        return is_wp_error($result) ? $result : new WP_REST_Response($result,201);
    }

    public function uploadDeploymentFile(WP_REST_Request $request)
    {
        $files=$request->get_file_params();

        if (!isset($files['file']) || !is_array($files['file'])) {
            return new WP_Error('pushlocal_upload_missing',__('No deployment file was uploaded.','pushlocal'),['status'=>400]);
        }

        $result=$this->deploymentManager->upload(
            (string)$request['id'],
            (string)$request->get_param('path'),
            $files['file'],
            get_current_user_id()
        );

        return is_wp_error($result) ? $result : new WP_REST_Response($result,200);
    }

    public function commitDeployment(WP_REST_Request $request)
    {
        $result=$this->deploymentManager->commit((string)$request['id'],get_current_user_id());
        return is_wp_error($result) ? $result : new WP_REST_Response($result,200);
    }

    public function abortDeployment(WP_REST_Request $request)
    {
        $result=$this->deploymentManager->abort(
            (string)$request['id'],
            get_current_user_id(),
            (string)$request->get_param('reason')
        );

        return is_wp_error($result) ? $result : new WP_REST_Response($result,200);
    }

    public function deploymentHistory(): WP_REST_Response|WP_Error
    {
        $result=$this->deploymentManager->history(get_current_user_id(),50);
        return is_wp_error($result) ? $result : new WP_REST_Response(['deployments'=>$result],200);
    }

    public function deploymentDetails(WP_REST_Request $request)
    {
        $result=$this->deploymentManager->details((string)$request['id'],get_current_user_id());
        return is_wp_error($result) ? $result : new WP_REST_Response($result,200);
    }

    public function rollbackDeployment(WP_REST_Request $request)
    {
        $result=$this->deploymentManager->rollback((string)$request['id'],get_current_user_id());
        return is_wp_error($result) ? $result : new WP_REST_Response($result,200);
    }

    public function getSettings(): WP_REST_Response
    {
        return new WP_REST_Response(['settings'=>$this->settings->all()],200);
    }

    public function updateSettings(WP_REST_Request $request)
    {
        $input=$request->get_json_params();

        if (!is_array($input)) {
            $input=[];
        }

        $settings=$this->settings->update($input);
        (new RetentionManager(null,$this->settings))->cleanup();

        return new WP_REST_Response(['settings'=>$settings],200);
    }

    public function retentionPolicy(): WP_REST_Response
    {
        return new WP_REST_Response(['policy'=>(new RetentionManager())->policy()],200);
    }
}
