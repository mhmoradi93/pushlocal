<?php
/**
 * Plugin Name: PushLocal
 * Description: Deploy local WordPress plugin changes safely without ZIP, Git, FTP, or repeated manual uploads.
 * Version: 1.0.1
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: PushLocal
 * License: GPL-2.0-or-later
 * Text Domain: pushlocal
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('PUSHLOCAL_VERSION', '1.0.1');
define('PUSHLOCAL_FILE', __FILE__);
define('PUSHLOCAL_DIR', plugin_dir_path(__FILE__));
define('PUSHLOCAL_URL', plugin_dir_url(__FILE__));

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'PushLocal\\';

        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = PUSHLOCAL_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';

        if (is_readable($path)) {
            require_once $path;
        }
    }
);

add_action(
    'plugins_loaded',
    static function (): void {
        (new PushLocal\Plugin())->boot();
    }
);
