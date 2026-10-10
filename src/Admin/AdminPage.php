<?php

declare(strict_types=1);

namespace PushLocal\Admin;

final class AdminPage
{
    private const PAGE_SLUG = 'pushlocal';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenu(): void
    {
        add_management_page(
            __('PushLocal', 'pushlocal'),
            __('PushLocal', 'pushlocal'),
            'update_plugins',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function enqueueAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== 'tools_page_' . self::PAGE_SLUG) {
            return;
        }

        wp_enqueue_style(
            'pushlocal-admin',
            PUSHLOCAL_URL . 'assets/css/admin.css',
            [],
            PUSHLOCAL_VERSION
        );

        wp_enqueue_script(
            'pushlocal-admin',
            PUSHLOCAL_URL . 'assets/js/app.js',
            [],
            PUSHLOCAL_VERSION,
            true
        );

        wp_add_inline_script(
            'pushlocal-admin',
            'window.PushLocalConfig = ' . wp_json_encode([
                'restBase' => esc_url_raw(rest_url('pushlocal/v1/')),
                'nonce' => wp_create_nonce('wp_rest'),
                'selfPlugin' => plugin_basename(PUSHLOCAL_FILE),
                'canManageSettings' => current_user_can('manage_options'),
            ]) . ';',
            'before'
        );
    }

    public function render(): void
    {
        if (!current_user_can('update_plugins')) {
            wp_die(esc_html__('You do not have permission to use PushLocal.', 'pushlocal'));
        }

        ?>
        <div class="wrap pushlocal">
            <header class="pushlocal-hero">
                <div class="pushlocal-brand">
                    <img
                        class="pushlocal-brand__mark"
                        src="<?php echo esc_url(PUSHLOCAL_URL . 'assets/brand/mark.svg'); ?>"
                        width="56"
                        height="56"
                        alt=""
                    >
                    <div>
                        <div class="pushlocal-brand__name">
                            <span>PushLocal</span>
                            <span class="pushlocal-brand__wp">WP</span>
                        </div>
                        <p class="pushlocal-brand__tagline"><?php echo esc_html__('Local code. Live in seconds.', 'pushlocal'); ?></p>
                    </div>
                </div>

                <div class="pushlocal-hero__meta">
                    <span class="pushlocal-pill pushlocal-pill--free"><?php echo esc_html__('Free & local-only', 'pushlocal'); ?></span>
                    <span class="pushlocal-pill">v<?php echo esc_html(PUSHLOCAL_VERSION); ?></span>
                </div>

                <div class="pushlocal-hero__copy">
                    <h1><?php echo esc_html__('Skip the ZIP. Push the change.', 'pushlocal'); ?></h1>
                    <p><?php echo esc_html__('Pair a local plugin folder once. PushLocal compares both sides and deploys only what changed—with backups, verification and rollback built in.', 'pushlocal'); ?></p>
                </div>

                <div class="pushlocal-trust-row" aria-label="<?php echo esc_attr__('PushLocal safety features', 'pushlocal'); ?>">
                    <span>SHA-256 verified</span>
                    <span>PHP syntax checked</span>
                    <span>Rollback ready</span>
                    <span>No PushLocal cloud</span>
                </div>
            </header>

            <div id="pushlocal-browser-warning" class="notice notice-warning inline" hidden>
                <p>
                    <?php echo esc_html__('This browser does not support the directory picker required by PushLocal. Use a compatible Chromium-based browser over HTTPS.', 'pushlocal'); ?>
                </p>
            </div>

            <div id="pushlocal-error" class="notice notice-error inline" hidden>
                <p id="pushlocal-error-text"></p>
            </div>

            <section id="pushlocal-onboarding" class="pushlocal-onboarding" hidden>
                <button id="pushlocal-onboarding-close" type="button" class="pushlocal-onboarding__close" aria-label="<?php echo esc_attr__('Dismiss onboarding', 'pushlocal'); ?>">×</button>
                <div class="pushlocal-onboarding__copy">
                    <span class="pushlocal-onboarding__eyebrow"><?php echo esc_html__('First deploy', 'pushlocal'); ?></span>
                    <h2><?php echo esc_html__('Three steps. No ZIP.', 'pushlocal'); ?></h2>
                    <p><?php echo esc_html__('Choose the installed plugin, pair its local source folder once, then review and deploy only the changed files.', 'pushlocal'); ?></p>
                </div>
                <ol class="pushlocal-onboarding__steps">
                    <li><span>1</span><?php echo esc_html__('Choose plugin', 'pushlocal'); ?></li>
                    <li><span>2</span><?php echo esc_html__('Pair local folder', 'pushlocal'); ?></li>
                    <li><span>3</span><?php echo esc_html__('Check & deploy', 'pushlocal'); ?></li>
                </ol>
            </section>

            <section class="pushlocal-card">
                <h2><?php echo esc_html__('Target plugin', 'pushlocal'); ?></h2>

                <label class="pushlocal-label" for="pushlocal-plugin">
                    <?php echo esc_html__('Installed plugin', 'pushlocal'); ?>
                </label>

                <select id="pushlocal-plugin" class="regular-text" disabled>
                    <option value=""><?php echo esc_html__('Loading plugins…', 'pushlocal'); ?></option>
                </select>

                <p id="pushlocal-plugin-meta" class="description"></p>

                <div id="pushlocal-preflight" class="pushlocal-preflight" hidden>
                    <div class="pushlocal-preflight__heading">
                        <div>
                            <strong><?php echo esc_html__('Environment check', 'pushlocal'); ?></strong>
                            <span id="pushlocal-preflight-status" class="pushlocal-preflight-status"></span>
                        </div>
                        <button id="pushlocal-preflight-refresh" type="button" class="button button-small">
                            <?php echo esc_html__('Run again', 'pushlocal'); ?>
                        </button>
                    </div>
                    <ul id="pushlocal-preflight-list" class="pushlocal-preflight-list"></ul>
                </div>
            </section>

            <section class="pushlocal-card">
                <h2><?php echo esc_html__('Local source', 'pushlocal'); ?></h2>

                <div class="pushlocal-folder-row">
                    <button id="pushlocal-connect" type="button" class="button button-secondary" disabled>
                        <?php echo esc_html__('Connect Local Folder', 'pushlocal'); ?>
                    </button>
                    <button id="pushlocal-forget-folder" type="button" class="button" hidden>
                        <?php echo esc_html__('Forget Folder', 'pushlocal'); ?>
                    </button>
                    <span id="pushlocal-folder-name" class="pushlocal-folder-name">
                        <?php echo esc_html__('Not connected', 'pushlocal'); ?>
                    </span>
                </div>

                <p class="description">
                    <?php echo esc_html__('PushLocal requests read-only access. The folder handle is stored only in this browser for this site/plugin pairing.', 'pushlocal'); ?>
                </p>
                <p id="pushlocal-ignore-meta" class="description pushlocal-ignore-meta">
                    <?php echo esc_html__('Default ignore rules active. Add .pushlocalignore to the local plugin root for custom patterns.', 'pushlocal'); ?>
                </p>
            </section>

            <section class="pushlocal-card">
                <h2><?php echo esc_html__('Changes', 'pushlocal'); ?></h2>

                <button id="pushlocal-scan" type="button" class="button button-primary" disabled>
                    <?php echo esc_html__('Check for Changes', 'pushlocal'); ?>
                </button>

                <span id="pushlocal-progress" class="pushlocal-progress"></span>

                <div id="pushlocal-results" class="pushlocal-results" hidden>
                    <div class="pushlocal-stats">
                        <div class="pushlocal-stat">
                            <span id="pushlocal-local-count" class="pushlocal-stat__number">0</span>
                            <span class="pushlocal-stat__label"><?php echo esc_html__('Local files', 'pushlocal'); ?></span>
                        </div>
                        <div class="pushlocal-stat">
                            <span id="pushlocal-remote-count" class="pushlocal-stat__number">0</span>
                            <span class="pushlocal-stat__label"><?php echo esc_html__('Remote files', 'pushlocal'); ?></span>
                        </div>
                        <div class="pushlocal-stat">
                            <span id="pushlocal-change-count" class="pushlocal-stat__number">0</span>
                            <span class="pushlocal-stat__label"><?php echo esc_html__('Changes', 'pushlocal'); ?></span>
                        </div>
                    </div>

                    <div class="pushlocal-diff-grid">
                        <div>
                            <h3>+ <?php echo esc_html__('New', 'pushlocal'); ?> <span id="pushlocal-new-count">0</span></h3>
                            <ul id="pushlocal-new-list" class="pushlocal-file-list"></ul>
                        </div>
                        <div>
                            <h3>~ <?php echo esc_html__('Modified', 'pushlocal'); ?> <span id="pushlocal-modified-count">0</span></h3>
                            <ul id="pushlocal-modified-list" class="pushlocal-file-list"></ul>
                        </div>
                        <div>
                            <h3>○ <?php echo esc_html__('Remote only', 'pushlocal'); ?> <span id="pushlocal-remote-only-count">0</span></h3>
                            <ul id="pushlocal-remote-only-list" class="pushlocal-file-list"></ul>
                        </div>
                    </div>

                    <div id="pushlocal-mirror-panel" class="pushlocal-mirror-panel" hidden>
                        <label class="pushlocal-mirror-toggle">
                            <input id="pushlocal-mirror-mode" type="checkbox">
                            <span>
                                <strong><?php echo esc_html__('Mirror Mode', 'pushlocal'); ?></strong>
                                <small>
                                    <?php echo esc_html__('Also delete REMOTE ONLY files so the server matches the local folder. Deletions are backed up and rollbackable.', 'pushlocal'); ?>
                                </small>
                            </span>
                        </label>

                        <div id="pushlocal-mirror-warning" class="pushlocal-mirror-warning" hidden>
                            <strong><?php echo esc_html__('Deletion enabled', 'pushlocal'); ?></strong>
                            <span id="pushlocal-mirror-warning-text"></span>
                        </div>
                    </div>

                    <p class="description">
                        <?php echo esc_html__('Remote-only files are informational unless Mirror Mode is explicitly enabled.', 'pushlocal'); ?>
                    </p>

                    <div id="pushlocal-deploy-panel" class="pushlocal-deploy-panel" hidden>
                        <div>
                            <strong id="pushlocal-deploy-summary"></strong>
                            <p class="description">
                                <?php echo esc_html__('Before commit, modified/deleted server files are backed up. Uploaded PHP files are SHA-256 verified and syntax-checked.', 'pushlocal'); ?>
                            </p>
                        </div>

                        <button id="pushlocal-deploy" type="button" class="button button-primary">
                            <?php echo esc_html__('Deploy Changes', 'pushlocal'); ?>
                        </button>
                    </div>

                    <div id="pushlocal-success" class="notice notice-success inline pushlocal-success" hidden>
                        <p id="pushlocal-success-text"></p>
                    </div>
                </div>
            </section>

            <section class="pushlocal-card pushlocal-settings-card">
                <div class="pushlocal-settings-heading">
                    <div>
                        <h2><?php echo esc_html__('Settings', 'pushlocal'); ?></h2>
                        <p class="description"><?php echo esc_html__('Retention affects only PushLocal deployment metadata and backups, never your plugin source.', 'pushlocal'); ?></p>
                    </div>
                    <span id="pushlocal-settings-status" class="pushlocal-settings-status"></span>
                </div>

                <div class="pushlocal-settings-grid">
                    <label>
                        <span><?php echo esc_html__('Keep latest deployments', 'pushlocal'); ?></span>
                        <input id="pushlocal-setting-keep" type="number" min="1" max="50" step="1" value="10">
                        <small><?php echo esc_html__('1–50', 'pushlocal'); ?></small>
                    </label>

                    <label>
                        <span><?php echo esc_html__('Maximum backup age', 'pushlocal'); ?></span>
                        <div class="pushlocal-input-unit">
                            <input id="pushlocal-setting-days" type="number" min="1" max="365" step="1" value="30">
                            <span><?php echo esc_html__('days', 'pushlocal'); ?></span>
                        </div>
                    </label>

                    <label>
                        <span><?php echo esc_html__('Abandoned upload cleanup', 'pushlocal'); ?></span>
                        <div class="pushlocal-input-unit">
                            <input id="pushlocal-setting-hours" type="number" min="1" max="168" step="1" value="24">
                            <span><?php echo esc_html__('hours', 'pushlocal'); ?></span>
                        </div>
                    </label>
                </div>

                <div class="pushlocal-settings-actions">
                    <button id="pushlocal-settings-save" type="button" class="button">
                        <?php echo esc_html__('Save Settings', 'pushlocal'); ?>
                    </button>
                </div>
            </section>

            <section class="pushlocal-card">
                <div class="pushlocal-history-heading">
                    <div>
                        <h2><?php echo esc_html__('Deployment history', 'pushlocal'); ?></h2>
                        <p class="description">
                            <?php echo esc_html__('Completed deployments can be rolled back only while their deployed files still match the deployment.', 'pushlocal'); ?>
                            <span id="pushlocal-retention-meta"></span>
                        </p>
                    </div>

                    <button id="pushlocal-history-refresh" type="button" class="button">
                        <?php echo esc_html__('Refresh', 'pushlocal'); ?>
                    </button>
                </div>

                <div id="pushlocal-history-loading" class="pushlocal-history-loading">
                    <?php echo esc_html__('Loading deployment history…', 'pushlocal'); ?>
                </div>

                <div id="pushlocal-history-empty" class="pushlocal-history-empty" hidden>
                    <?php echo esc_html__('No PushLocal deployments yet.', 'pushlocal'); ?>
                </div>

                <div id="pushlocal-history" class="pushlocal-history" hidden></div>
            </section>

            <footer class="pushlocal-footer">
                <img src="<?php echo esc_url(PUSHLOCAL_URL . 'assets/brand/mark.svg'); ?>" width="24" height="24" alt="">
                <p>
                    <?php echo esc_html__('PushLocal sends files only from this browser to this WordPress site. There is no PushLocal cloud account, telemetry service or remote code source.', 'pushlocal'); ?>
                </p>
            </footer>
        </div>
        <?php
    }
}
