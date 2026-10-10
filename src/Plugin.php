<?php

declare(strict_types=1);

namespace PushLocal;

use PushLocal\Admin\AdminPage;
use PushLocal\Api\Routes;
use PushLocal\Service\RetentionManager;

final class Plugin
{
    public function boot(): void
    {
        if (is_admin()) {
            (new AdminPage())->register();

            add_filter(
                'plugin_action_links_' . plugin_basename(PUSHLOCAL_FILE),
                static function (array $links): array {
                    $url = admin_url('tools.php?page=pushlocal');

                    array_unshift(
                        $links,
                        '<a href="' . esc_url($url) . '">' . esc_html__('Open PushLocal', 'pushlocal') . '</a>'
                    );

                    return $links;
                }
            );

            add_action(
                'admin_init',
                static function (): void {
                    if (!current_user_can('update_plugins')) {
                        return;
                    }

                    $last = (int) get_transient('pushlocal_last_cleanup');

                    if ($last > 0 && (time() - $last) < HOUR_IN_SECONDS) {
                        return;
                    }

                    (new RetentionManager())->cleanup();
                    set_transient('pushlocal_last_cleanup', time(), HOUR_IN_SECONDS);
                }
            );
        }

        (new Routes())->register();
    }
}
