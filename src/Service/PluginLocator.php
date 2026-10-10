<?php

declare(strict_types=1);

namespace PushLocal\Service;

use WP_Error;

final class PluginLocator
{
    public function allTargets(): array
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $plugins = get_plugins();
        $self = plugin_basename(PUSHLOCAL_FILE);
        $targets = [];

        foreach ($plugins as $pluginFile => $data) {
            if (!str_contains($pluginFile, '/')) {
                continue;
            }

            if ($pluginFile === $self) {
                continue;
            }

            [$folder, $mainFile] = explode('/', $pluginFile, 2);

            if ($folder === '' || $mainFile === '' || str_contains($mainFile, '/')) {
                continue;
            }

            $targets[] = [
                'plugin_file' => $pluginFile,
                'name' => (string) ($data['Name'] ?? $pluginFile),
                'version' => (string) ($data['Version'] ?? ''),
                'folder' => $folder,
                'main_file' => $mainFile,
                'active' => is_plugin_active($pluginFile),
            ];
        }

        usort(
            $targets,
            static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name'])
        );

        return $targets;
    }

    public function resolve(string $pluginFile)
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        if ($pluginFile === '' || validate_file($pluginFile) !== 0) {
            return new WP_Error(
                'pushlocal_invalid_plugin',
                __('Invalid plugin target.', 'pushlocal'),
                ['status' => 400]
            );
        }

        $plugins = get_plugins();

        if (!isset($plugins[$pluginFile])) {
            return new WP_Error(
                'pushlocal_plugin_not_found',
                __('The selected plugin is not installed.', 'pushlocal'),
                ['status' => 404]
            );
        }

        if ($pluginFile === plugin_basename(PUSHLOCAL_FILE)) {
            return new WP_Error(
                'pushlocal_self_target',
                __('PushLocal cannot target itself.', 'pushlocal'),
                ['status' => 400]
            );
        }

        if (!str_contains($pluginFile, '/')) {
            return new WP_Error(
                'pushlocal_single_file_unsupported',
                __('Single-file plugins are not supported in PushLocal 1.0.', 'pushlocal'),
                ['status' => 400]
            );
        }

        [$folder, $mainFile] = explode('/', $pluginFile, 2);

        if ($folder === '' || $mainFile === '' || str_contains($mainFile, '/')) {
            return new WP_Error(
                'pushlocal_plugin_structure_unsupported',
                __('This plugin structure is not supported in PushLocal 1.0.', 'pushlocal'),
                ['status' => 400]
            );
        }

        $root = trailingslashit(WP_PLUGIN_DIR) . $folder;
        $realPluginRoot = realpath(WP_PLUGIN_DIR);
        $realTargetRoot = realpath($root);

        if (
            $realPluginRoot === false ||
            $realTargetRoot === false ||
            !is_dir($realTargetRoot)
        ) {
            return new WP_Error(
                'pushlocal_plugin_root_missing',
                __('The plugin directory could not be resolved.', 'pushlocal'),
                ['status' => 404]
            );
        }

        $pluginPrefix = rtrim(wp_normalize_path($realPluginRoot), '/') . '/';
        $targetNormalized = rtrim(wp_normalize_path($realTargetRoot), '/') . '/';

        if (!str_starts_with($targetNormalized, $pluginPrefix)) {
            return new WP_Error(
                'pushlocal_invalid_plugin_root',
                __('The plugin directory is outside the WordPress plugin directory.', 'pushlocal'),
                ['status' => 400]
            );
        }

        return [
            'plugin_file' => $pluginFile,
            'folder' => $folder,
            'main_file' => $mainFile,
            'root' => $realTargetRoot,
        ];
    }
}
