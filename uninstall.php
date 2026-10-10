<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('pushlocal_settings');
delete_transient('pushlocal_last_cleanup');

// PushLocal intentionally does not delete /wp-content/uploads/pushlocal/
// during uninstall. That directory may contain rollback backups.
// A future cleanup tool can remove it only with explicit administrator action.
