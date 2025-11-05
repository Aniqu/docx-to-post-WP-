<?php
/**
 * Plugin Name: DOCX to Post PRO (Freemium + Multi-Source)
 * Description: Import from DOCX, PDF, or Google Drive with AI-powered SEO. Free: 10 posts. Pro: Unlimited + advanced features.
 * Version: 2.0.0
 * Author: Your Name
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Text Domain: docx-to-post
 */

if (!defined('ABSPATH')) exit;

class DTP_Docx_To_Post_Pro {
    const SLUG = 'docx-to-post-clean';

    /* License & Limits */
    const OPT_LICENSE_KEY    = 'dtp_license_key';
    const OPT_LICENSE_STATUS = 'dtp_license_status';
    const OPT_TOTAL_IMPORTS  = 'dtp_total_successful_imports';
    const FREE_LIMIT         = 10; // Free version: 10 posts max

    /* Document Source Types */
    const OPT_DOC_SOURCE     = 'dtp_document_source';
    const SOURCE_DOCX        = 'docx';
    const SOURCE_PDF         = 'pdf';
    const SOURCE_GDRIVE      = 'google_drive';

    /* Google Drive */
    const OPT_GDRIVE_CLIENT_ID     = 'dtp_gdrive_client_id';
    const OPT_GDRIVE_CLIENT_SECRET = 'dtp_gdrive_client_secret';
    const OPT_GDRIVE_ACCESS_TOKEN  = 'dtp_gdrive_access_token';

    /* Branding */
    const OPT_LOGO_URL = 'dtp_logo_url';

    /* Options / hooks */
    const OPT_QUEUE       = 'dtp_import_queue';
    const OPT_LOCK        = 'dtp_queue_lock';
    const OPT_RUNSTATE    = 'dtp_runtime_state';
    const OPT_KILL        = 'dtp_kill_switch';

    // Last upload skipped details (transient)
    const TR_LAST_SKIPPED = 'dtp_last_upload_skipped';

    // SEO options
    const OPT_SEO_ENABLED  = 'dtp_enable_seo';
    const OPT_OPENAI_KEY   = 'dtp_openai_api_key';
    const OPT_OPENAI_MODEL = 'dtp_openai_model';
    const OPT_SEO_LANG     = 'dtp_seo_lang';

    // AI image alt generation
    const OPT_ALT_ENABLED = 'dtp_enable_ai_alt';

    const CRON_HOOK = 'dtp_process_queue_event';

    // Auto-tuning bounds
    const BATCH_MIN = 1;
    const BATCH_MAX = 3;
    const BUDGET_MIN = 15;
    const BUDGET_MAX = 50;
    const INTERVAL_MIN = 20;
    const INTERVAL_MAX = 60;

    // Failsafe micro-tick
    const FAILSAFE_BATCH  = 1;
    const FAILSAFE_BUDGET = 10;
    const FAILSAFE_COOLDOWN = 45;
    const FAILSAFE_CD_KEY  = 'dtp_failsafe_cd';

    public function __construct() {
        add_action('admin_menu',  [$this, 'register_menu']);
        add_action('admin_init',  [$this, 'register_settings']);
        add_action('admin_post_dtp_handle_upload',   [$this, 'handle_upload']);
        add_action('admin_post_dtp_handle_gdrive',   [$this, 'handle_gdrive_import']);
        add_action('admin_post_dtp_process_now',     [$this, 'process_now']);
        add_action('admin_post_dtp_reset_queue',     [$this, 'reset_queue']);
        add_action('admin_post_dtp_recover_stuck',   [$this, 'recover_stuck_action']);
        add_action('admin_post_dtp_export_csv',      [$this, 'export_csv']);
        add_action('admin_post_dtp_requeue_errors',  [$this, 'requeue_errors']);
        add_action('admin_post_dtp_clean_history',   [$this, 'clean_history']);
        add_action('admin_post_dtp_activate_license', [$this, 'activate_license']);
        add_action('admin_post_dtp_upload_logo',     [$this, 'upload_logo']);
        add_action(self::CRON_HOOK, [$this, 'process_queue_cron']);

        add_action('admin_init', [$this, 'cron_watchdog']);
        add_action('init',       [$this, 'frontend_nudge'], 1);

        // Live progress updates
        add_action('admin_enqueue_scripts', [$this, 'enqueue_live_progress_assets']);
        add_action('wp_ajax_dtp_get_queue_status', [$this, 'ajax_get_queue_status']);
        add_action('wp_ajax_dtp_regenerate_seo', [$this, 'regenerate_post_seo_ajax']);

        // Admin notices
        add_action('admin_notices', [$this, 'show_admin_notices']);
    }

    public static function on_activate() {
        // Set default document source
        if (!get_option(self::OPT_DOC_SOURCE)) {
            update_option(self::OPT_DOC_SOURCE, self::SOURCE_DOCX);
        }

        // Initialize import counter
        if (!get_option(self::OPT_TOTAL_IMPORTS)) {
            update_option(self::OPT_TOTAL_IMPORTS, 0);
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 10, self::CRON_HOOK);
        }
    }

    private function dbg($msg) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[DTP DOCX PRO] ' . $msg);
        }
    }

    /* ------------ License Management ------------ */

    private function is_pro(): bool {
        $status = get_option(self::OPT_LICENSE_STATUS, 'free');
        return $status === 'active';
    }

    private function get_remaining_imports(): int {
        if ($this->is_pro()) {
            return PHP_INT_MAX; // Unlimited
        }

        $total = (int) get_option(self::OPT_TOTAL_IMPORTS, 0);
        return max(0, self::FREE_LIMIT - $total);
    }

    private function can_import(): bool {
        return $this->get_remaining_imports() > 0;
    }

    private function increment_import_count(): void {
        if (!$this->is_pro()) {
            $total = (int) get_option(self::OPT_TOTAL_IMPORTS, 0);
            update_option(self::OPT_TOTAL_IMPORTS, $total + 1);
        }
    }

    public function activate_license() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'docx-to-post'));
        }

        if (!isset($_POST['dtp_license_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dtp_license_nonce'])), 'dtp_activate_license')) {
            wp_die(esc_html__('Security check failed.', 'docx-to-post'));
        }

        $license_key = isset($_POST['license_key']) ? sanitize_text_field($_POST['license_key']) : '';

        if (empty($license_key)) {
            $url = add_query_arg(['page' => self::SLUG, 'dtp_error' => rawurlencode(__('Please enter a license key.', 'docx-to-post'))], admin_url('admin.php'));
            wp_safe_redirect($url);
            exit;
        }

        // TODO: Replace with your actual license validation API
        $is_valid = $this->validate_license_key($license_key);

        if ($is_valid) {
            update_option(self::OPT_LICENSE_KEY, $license_key);
            update_option(self::OPT_LICENSE_STATUS, 'active');
            $msg = __('License activated successfully! You now have unlimited imports.', 'docx-to-post');
            $url = add_query_arg(['page' => self::SLUG, 'dtp_notice' => rawurlencode($msg)], admin_url('admin.php'));
        } else {
            $msg = __('Invalid license key. Please check and try again.', 'docx-to-post');
            $url = add_query_arg(['page' => self::SLUG, 'dtp_error' => rawurlencode($msg)], admin_url('admin.php'));
        }

        wp_safe_redirect($url);
        exit;
    }

    private function validate_license_key(string $key): bool {
        // TODO: Implement actual license validation
        // For now, accept any key starting with "PRO-"
        // In production, this should call your license server API

        if (strpos($key, 'PRO-') === 0 && strlen($key) >= 20) {
            return true;
        }

        // Example API call structure (implement with your licensing provider):
        /*
        $response = wp_remote_post('https://yourdomain.com/api/validate-license', [
            'body' => [
                'license_key' => $key,
                'domain' => home_url(),
                'product_id' => 'docx-to-post-pro'
            ],
            'timeout' => 15
        ]);

        if (!is_wp_error($response)) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            return isset($data['valid']) && $data['valid'] === true;
        }
        */

        return false;
    }

    /* ------------ Admin Notices ------------ */

    public function show_admin_notices() {
        if (!current_user_can('edit_posts')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, self::SLUG) === false) {
            return;
        }

        // Free version limit warning
        if (!$this->is_pro()) {
            $remaining = $this->get_remaining_imports();

            if ($remaining <= 3 && $remaining > 0) {
                ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php echo esc_html(sprintf(__('Free Version: %d imports remaining', 'docx-to-post'), $remaining)); ?></strong>
                        <br>
                        <?php echo esc_html__('Upgrade to PRO for unlimited imports, Google Drive integration, and priority support.', 'docx-to-post'); ?>
                        <a href="#dtp-license-section" class="button button-primary" style="margin-left: 10px;"><?php echo esc_html__('Upgrade Now', 'docx-to-post'); ?></a>
                    </p>
                </div>
                <?php
            } elseif ($remaining === 0) {
                ?>
                <div class="notice notice-error">
                    <p>
                        <strong><?php echo esc_html__('Free Version Limit Reached', 'docx-to-post'); ?></strong>
                        <br>
                        <?php echo esc_html__('You\'ve used all 10 free imports. Upgrade to PRO for unlimited imports.', 'docx-to-post'); ?>
                        <a href="#dtp-license-section" class="button button-primary" style="margin-left: 10px;"><?php echo esc_html__('Upgrade to PRO', 'docx-to-post'); ?></a>
                    </p>
                </div>
                <?php
            }
        }
    }

    /* ------------ Admin UI ------------ */

    public function register_menu() {
        add_menu_page(
            __('DOCX to Post PRO', 'docx-to-post'),
            __('DOCX to Post', 'docx-to-post'),
            'edit_posts',
            self::SLUG,
            [$this, 'render_page'],
            'dashicons-media-document',
            26
        );
    }

    public function register_settings() {
        // License
        register_setting('dtp_settings', self::OPT_LICENSE_KEY, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);

        // Document source
        register_setting('dtp_settings', self::OPT_DOC_SOURCE, ['type' => 'string', 'sanitize_callback' => 'sanitize_key']);

        // Google Drive
        register_setting('dtp_settings', self::OPT_GDRIVE_CLIENT_ID, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('dtp_settings', self::OPT_GDRIVE_CLIENT_SECRET, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);

        // Logo
        register_setting('dtp_settings', self::OPT_LOGO_URL, ['type' => 'string', 'sanitize_callback' => 'esc_url_raw']);

        // SEO
        register_setting('dtp_settings', self::OPT_SEO_ENABLED, ['type' => 'boolean', 'sanitize_callback' => 'absint']);
        register_setting('dtp_settings', self::OPT_OPENAI_KEY, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('dtp_settings', self::OPT_OPENAI_MODEL, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('dtp_settings', self::OPT_SEO_LANG, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('dtp_settings', self::OPT_ALT_ENABLED, ['type' => 'boolean', 'sanitize_callback' => 'absint']);
    }

    public function render_page() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'docx-to-post'));
        }

        $q = $this->get_queue();
        $counts = $this->queue_counts($q);
        $profile = $this->compute_runtime_profile();
        $next = wp_next_scheduled(self::CRON_HOOK);

        $is_pro = $this->is_pro();
        $remaining_imports = $this->get_remaining_imports();
        $total_imports = (int) get_option(self::OPT_TOTAL_IMPORTS, 0);
        $doc_source = get_option(self::OPT_DOC_SOURCE, self::SOURCE_DOCX);
        $logo_url = get_option(self::OPT_LOGO_URL, '');

        // Server limits (display only)
        $max_uploads = (int)ini_get('max_file_uploads');
        $post_max    = ini_get('post_max_size');
        $upload_max  = ini_get('upload_max_filesize');
        ?>
        <style>
            .dtp-modern-wrap {
                background: #f8f9fa;
                min-height: 100vh;
                margin-left: -20px;
                padding: 30px 40px;
            }
            .dtp-header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                padding: 40px;
                border-radius: 16px;
                color: white;
                margin-bottom: 30px;
                box-shadow: 0 10px 40px rgba(102, 126, 234, 0.3);
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .dtp-header-left h1 {
                margin: 0 0 10px 0;
                font-size: 32px;
                font-weight: 700;
                color: white;
            }
            .dtp-header-left p {
                margin: 0;
                opacity: 0.9;
                font-size: 16px;
            }
            .dtp-header-logo {
                max-width: 150px;
                max-height: 80px;
                background: white;
                padding: 10px;
                border-radius: 8px;
            }
            .dtp-header-logo img {
                max-width: 100%;
                max-height: 60px;
                object-fit: contain;
            }
            .dtp-header-logo-placeholder {
                width: 150px;
                height: 60px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: rgba(255,255,255,0.2);
                border: 2px dashed rgba(255,255,255,0.5);
                border-radius: 8px;
                color: white;
                font-size: 12px;
                text-align: center;
            }

            .dtp-license-banner {
                background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
                padding: 20px 30px;
                border-radius: 12px;
                color: white;
                margin-bottom: 24px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
            }
            .dtp-license-banner.pro {
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
                box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
            }
            .dtp-license-info h3 {
                margin: 0 0 8px 0;
                font-size: 20px;
                color: white;
            }
            .dtp-license-info p {
                margin: 0;
                opacity: 0.9;
            }
            .dtp-upgrade-btn {
                background: white;
                color: #d97706;
                padding: 12px 24px;
                border-radius: 8px;
                font-weight: 600;
                text-decoration: none;
                transition: all 0.3s ease;
                border: none;
                cursor: pointer;
            }
            .dtp-upgrade-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.2);
                color: #d97706;
            }

            .dtp-source-selector {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 16px;
                margin: 20px 0;
            }
            .dtp-source-option {
                border: 3px solid #e2e8f0;
                border-radius: 12px;
                padding: 20px;
                text-align: center;
                cursor: pointer;
                transition: all 0.3s ease;
                background: white;
                position: relative;
            }
            .dtp-source-option:hover {
                border-color: #667eea;
                transform: translateY(-4px);
                box-shadow: 0 8px 20px rgba(102, 126, 234, 0.2);
            }
            .dtp-source-option.active {
                border-color: #667eea;
                background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
            }
            .dtp-source-option.disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }
            .dtp-source-option.disabled:hover {
                transform: none;
                border-color: #e2e8f0;
            }
            .dtp-source-icon {
                font-size: 48px;
                margin-bottom: 12px;
            }
            .dtp-source-name {
                font-weight: 600;
                font-size: 16px;
                margin-bottom: 4px;
            }
            .dtp-source-desc {
                font-size: 13px;
                color: #64748b;
            }
            .dtp-pro-badge {
                position: absolute;
                top: -8px;
                right: -8px;
                background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
                color: white;
                padding: 4px 12px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 700;
                box-shadow: 0 2px 8px rgba(245, 158, 11, 0.4);
            }

            .dtp-card {
                background: white;
                border-radius: 12px;
                padding: 30px;
                margin-bottom: 24px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.08);
                transition: all 0.3s ease;
            }
            .dtp-card:hover {
                box-shadow: 0 4px 16px rgba(0,0,0,0.12);
                transform: translateY(-2px);
            }
            .dtp-card h2 {
                margin: 0 0 20px 0;
                font-size: 22px;
                font-weight: 600;
                color: #1e293b;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .dtp-card h2::before {
                content: '';
                display: inline-block;
                width: 4px;
                height: 24px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border-radius: 2px;
            }

            .dtp-upload-zone {
                border: 3px dashed #cbd5e1;
                border-radius: 12px;
                padding: 40px;
                text-align: center;
                background: #f8fafc;
                transition: all 0.3s ease;
                cursor: pointer;
            }
            .dtp-upload-zone:hover {
                border-color: #667eea;
                background: #f1f5f9;
            }
            .dtp-upload-zone.dragover {
                border-color: #667eea;
                background: #eef2ff;
                transform: scale(1.02);
            }

            .dtp-btn-primary {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                padding: 14px 32px;
                border-radius: 8px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            }
            .dtp-btn-primary:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
            }
            .dtp-btn-primary:disabled {
                opacity: 0.6;
                cursor: not-allowed;
                transform: none;
            }

            .dtp-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
                gap: 16px;
                margin-top: 20px;
            }
            .dtp-stat-card {
                background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
                padding: 20px;
                border-radius: 10px;
                text-align: center;
                border: 1px solid #e2e8f0;
            }
            .dtp-stat-label {
                font-size: 13px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: #64748b;
                margin-bottom: 8px;
            }
            .dtp-stat-value {
                font-size: 32px;
                font-weight: 700;
                color: #1e293b;
            }
        </style>

        <div class="dtp-modern-wrap">
            <!-- Header with Logo -->
            <div class="dtp-header">
                <div class="dtp-header-left">
                    <h1>📄 DOCX to Post <?php echo $is_pro ? 'PRO' : 'FREE'; ?></h1>
                    <p>Multi-source document importer with AI-powered SEO optimization</p>
                </div>
                <div class="dtp-header-logo">
                    <?php if ($logo_url): ?>
                        <img src="<?php echo esc_url($logo_url); ?>" alt="Logo">
                    <?php else: ?>
                        <div class="dtp-header-logo-placeholder">
                            Your Logo Here<br>
                            <small>(Upload in Settings)</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- License Status Banner -->
            <?php if (!$is_pro): ?>
            <div class="dtp-license-banner" id="dtp-license-section">
                <div class="dtp-license-info">
                    <h3>🎁 Free Version - <?php echo esc_html($remaining_imports); ?> / <?php echo esc_html(self::FREE_LIMIT); ?> Imports Remaining</h3>
                    <p>Upgrade to PRO for unlimited imports, Google Drive & PDF support, priority support, and more!</p>
                </div>
                <a href="#dtp-upgrade-section" class="dtp-upgrade-btn">Upgrade to PRO - $49/year</a>
            </div>
            <?php else: ?>
            <div class="dtp-license-banner pro">
                <div class="dtp-license-info">
                    <h3>✨ PRO Version Active</h3>
                    <p>Unlimited imports • All features unlocked • Priority support</p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Document Source Selection -->
            <div class="dtp-card">
                <h2>Select Document Source</h2>
                <form method="post" action="options.php" id="dtp-source-form">
                    <?php settings_fields('dtp_settings'); ?>
                    <div class="dtp-source-selector">
                        <!-- DOCX Source -->
                        <div class="dtp-source-option <?php echo $doc_source === self::SOURCE_DOCX ? 'active' : ''; ?>"
                             onclick="dtpSelectSource('<?php echo esc_js(self::SOURCE_DOCX); ?>')">
                            <div class="dtp-source-icon">📄</div>
                            <div class="dtp-source-name">DOCX Files</div>
                            <div class="dtp-source-desc">Upload Word documents (.docx)</div>
                            <input type="radio" name="<?php echo esc_attr(self::OPT_DOC_SOURCE); ?>"
                                   value="<?php echo esc_attr(self::SOURCE_DOCX); ?>"
                                   <?php checked($doc_source, self::SOURCE_DOCX); ?>
                                   style="display: none;">
                        </div>

                        <!-- PDF Source -->
                        <div class="dtp-source-option <?php echo $doc_source === self::SOURCE_PDF ? 'active' : ''; ?> <?php echo !$is_pro ? 'disabled' : ''; ?>"
                             onclick="<?php echo $is_pro ? "dtpSelectSource('" . esc_js(self::SOURCE_PDF) . "')" : "alert('Upgrade to PRO to unlock PDF support')"; ?>">
                            <?php if (!$is_pro): ?><span class="dtp-pro-badge">PRO</span><?php endif; ?>
                            <div class="dtp-source-icon">📑</div>
                            <div class="dtp-source-name">PDF Files</div>
                            <div class="dtp-source-desc">Import from PDF documents</div>
                            <input type="radio" name="<?php echo esc_attr(self::OPT_DOC_SOURCE); ?>"
                                   value="<?php echo esc_attr(self::SOURCE_PDF); ?>"
                                   <?php checked($doc_source, self::SOURCE_PDF); ?>
                                   <?php disabled(!$is_pro); ?>
                                   style="display: none;">
                        </div>

                        <!-- Google Drive Source -->
                        <div class="dtp-source-option <?php echo $doc_source === self::SOURCE_GDRIVE ? 'active' : ''; ?> <?php echo !$is_pro ? 'disabled' : ''; ?>"
                             onclick="<?php echo $is_pro ? "dtpSelectSource('" . esc_js(self::SOURCE_GDRIVE) . "')" : "alert('Upgrade to PRO to unlock Google Drive integration')"; ?>">
                            <?php if (!$is_pro): ?><span class="dtp-pro-badge">PRO</span><?php endif; ?>
                            <div class="dtp-source-icon">☁️</div>
                            <div class="dtp-source-name">Google Drive</div>
                            <div class="dtp-source-desc">Import from Google Docs</div>
                            <input type="radio" name="<?php echo esc_attr(self::OPT_DOC_SOURCE); ?>"
                                   value="<?php echo esc_attr(self::SOURCE_GDRIVE); ?>"
                                   <?php checked($doc_source, self::SOURCE_GDRIVE); ?>
                                   <?php disabled(!$is_pro); ?>
                                   style="display: none;">
                        </div>
                    </div>
                    <div style="margin-top: 16px;">
                        <button type="submit" class="dtp-btn-primary">💾 Save Source Selection</button>
                    </div>
                </form>
            </div>

            <script>
            function dtpSelectSource(source) {
                document.querySelectorAll('.dtp-source-option').forEach(el => {
                    el.classList.remove('active');
                });
                event.currentTarget.classList.add('active');
                event.currentTarget.querySelector('input[type="radio"]').checked = true;
            }
            </script>

            <!-- Upload Section (Dynamic based on source) -->
            <?php if ($doc_source === self::SOURCE_DOCX || $doc_source === self::SOURCE_PDF): ?>
                <?php $this->render_file_upload_section($doc_source, $remaining_imports); ?>
            <?php elseif ($doc_source === self::SOURCE_GDRIVE && $is_pro): ?>
                <?php $this->render_gdrive_section(); ?>
            <?php endif; ?>

            <!-- Queue Status -->
            <div class="dtp-card">
                <h2><?php echo esc_html__('Queue Status', 'docx-to-post'); ?></h2>

                <div class="dtp-stats-grid">
                    <div class="dtp-stat-card">
                        <div class="dtp-stat-label"><?php echo esc_html__('Total', 'docx-to-post'); ?></div>
                        <div class="dtp-stat-value"><?php echo intval($counts['total']); ?></div>
                    </div>
                    <div class="dtp-stat-card">
                        <div class="dtp-stat-label"><?php echo esc_html__('Pending', 'docx-to-post'); ?></div>
                        <div class="dtp-stat-value"><?php echo intval($counts['pending']); ?></div>
                    </div>
                    <div class="dtp-stat-card">
                        <div class="dtp-stat-label"><?php echo esc_html__('Processing', 'docx-to-post'); ?></div>
                        <div class="dtp-stat-value"><?php echo intval($counts['processing']); ?></div>
                    </div>
                    <div class="dtp-stat-card">
                        <div class="dtp-stat-label"><?php echo esc_html__('Done', 'docx-to-post'); ?></div>
                        <div class="dtp-stat-value"><?php echo intval($counts['done']); ?></div>
                    </div>
                    <div class="dtp-stat-card">
                        <div class="dtp-stat-label"><?php echo esc_html__('Error', 'docx-to-post'); ?></div>
                        <div class="dtp-stat-value"><?php echo intval($counts['error']); ?></div>
                    </div>
                </div>

                <p style="margin-top: 20px;">
                    <strong><?php echo esc_html__('Next run:', 'docx-to-post'); ?></strong>
                    <?php
                    if ($next) {
                        echo esc_html( wp_date('Y-m-d H:i:s', $next) );
                        if ($next <= time()) echo ' ' . esc_html__('(overdue — waking cron)', 'docx-to-post');
                    } else {
                        echo esc_html__('not scheduled', 'docx-to-post');
                    }
                    ?>
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:12px 0;">
                    <?php wp_nonce_field('dtp_process_now', 'dtp_process_nonce'); ?>
                    <input type="hidden" name="action" value="dtp_process_now">
                    <?php submit_button(__('Process Next Chunk Now', 'docx-to-post'), 'secondary', 'submit', false); ?>
                </form>
            </div>

            <!-- Settings Section -->
            <?php $this->render_settings_section($is_pro, $logo_url); ?>

            <!-- Maintenance Section -->
            <?php $this->render_maintenance_section(); ?>

            <?php
            if (!empty($_GET['dtp_notice'])) {
                echo '<div class="notice notice-info"><p>' . esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['dtp_notice'])))) . '</p></div>';
            }
            if (!empty($_GET['dtp_error'])) {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['dtp_error'])))) . '</p></div>';
            }
            ?>
        </div>
        <?php
    }

    private function render_file_upload_section($doc_source, $remaining_imports) {
        $accept = $doc_source === self::SOURCE_PDF ? '.pdf,.zip' : '.docx,.zip';
        $file_types = $doc_source === self::SOURCE_PDF ? 'PDF files and ZIP archives' : 'DOCX files and ZIP archives';
        $can_upload = $remaining_imports > 0;
        ?>
        <div class="dtp-card">
            <h2>Upload Documents</h2>

            <?php if (!$can_upload): ?>
                <div style="background: #fee2e2; border: 2px solid #ef4444; padding: 20px; border-radius: 8px; text-align: center;">
                    <h3 style="color: #dc2626; margin: 0 0 10px 0;">❌ Free Limit Reached</h3>
                    <p style="margin: 0 0 15px 0;">You've used all <?php echo esc_html(self::FREE_LIMIT); ?> free imports.</p>
                    <a href="#dtp-upgrade-section" class="dtp-btn-primary">Upgrade to PRO for Unlimited Imports</a>
                </div>
            <?php else: ?>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="dtp-upload-form">
                    <?php wp_nonce_field('dtp_upload_nonce', 'dtp_nonce'); ?>
                    <input type="hidden" name="action" value="dtp_handle_upload">

                    <div class="dtp-upload-zone" id="dtp-dropzone">
                        <div style="font-size: 48px; margin-bottom: 16px;">📁</div>
                        <div style="font-size: 18px; font-weight: 600; margin-bottom: 8px;">
                            Drop files here or click to browse
                        </div>
                        <div style="font-size: 14px; color: #64748b;">
                            Support for <?php echo esc_html($file_types); ?>
                        </div>
                        <input type="file" id="docx_files" name="docx_files[]" accept="<?php echo esc_attr($accept); ?>" multiple required style="display: none;">
                        <button type="button" class="dtp-btn-primary" style="margin-top: 20px;" onclick="document.getElementById('docx_files').click()">
                            Select Files
                        </button>
                    </div>

                    <div style="margin-top: 24px; display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
                        <div>
                            <label for="post_status" style="display: block; margin-bottom: 8px; font-weight: 600;">Post Status:</label>
                            <select id="post_status" name="post_status" style="padding: 10px; border-radius: 6px; border: 2px solid #e2e8f0;">
                                <option value="draft">📝 Draft</option>
                                <option value="publish">✅ Publish</option>
                                <option value="pending">⏳ Pending Review</option>
                            </select>
                        </div>
                        <div style="flex: 1;"></div>
                        <button type="submit" class="dtp-btn-primary">
                            🚀 Start Processing
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <script>
        (function() {
            const dropzone = document.getElementById('dtp-dropzone');
            const fileInput = document.getElementById('docx_files');

            if (!dropzone || !fileInput) return;

            dropzone.addEventListener('click', () => fileInput.click());
            dropzone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropzone.classList.add('dragover');
            });
            dropzone.addEventListener('dragleave', () => {
                dropzone.classList.remove('dragover');
            });
            dropzone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropzone.classList.remove('dragover');
                fileInput.files = e.dataTransfer.files;
            });
        })();
        </script>
        <?php
    }

    private function render_gdrive_section() {
        ?>
        <div class="dtp-card">
            <h2>Import from Google Drive</h2>
            <div style="background: #dbeafe; border: 2px solid #3b82f6; padding: 20px; border-radius: 8px;">
                <h3 style="margin: 0 0 10px 0;">☁️ Google Drive Integration</h3>
                <p>Configure your Google Drive API credentials in the Settings section below, then you'll be able to browse and import your Google Docs directly.</p>
                <p style="margin: 15px 0 0 0;"><em>Feature coming soon in next update!</em></p>
            </div>
        </div>
        <?php
    }

    private function render_settings_section($is_pro, $logo_url) {
        ?>
        <div class="dtp-card" id="dtp-upgrade-section">
            <h2>⚙️ Settings & License</h2>

            <!-- License Activation -->
            <?php if (!$is_pro): ?>
            <div style="background: #fef3c7; border: 2px solid #f59e0b; padding: 20px; border-radius: 8px; margin-bottom: 24px;">
                <h3 style="margin: 0 0 15px 0;">🔓 Activate PRO License</h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('dtp_activate_license', 'dtp_license_nonce'); ?>
                    <input type="hidden" name="action" value="dtp_activate_license">
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <input type="text" name="license_key" placeholder="PRO-XXXX-XXXX-XXXX-XXXX"
                               style="flex: 1; min-width: 300px; padding: 12px; border: 2px solid #e2e8f0; border-radius: 6px;" required>
                        <button type="submit" class="dtp-btn-primary">Activate License</button>
                    </div>
                    <p style="margin: 10px 0 0 0; font-size: 13px; color: #64748b;">
                        Don't have a license? <a href="https://yourdomain.com/purchase" target="_blank" style="color: #667eea; font-weight: 600;">Purchase PRO →</a>
                    </p>
                </form>
            </div>
            <?php endif; ?>

            <!-- Logo Upload -->
            <div style="margin-bottom: 24px; padding: 20px; background: #f8fafc; border-radius: 8px;">
                <h3 style="margin: 0 0 15px 0;">🎨 Custom Branding</h3>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('dtp_upload_logo', 'dtp_logo_nonce'); ?>
                    <input type="hidden" name="action" value="dtp_upload_logo">

                    <?php if ($logo_url): ?>
                        <div style="margin-bottom: 15px;">
                            <img src="<?php echo esc_url($logo_url); ?>" alt="Current Logo" style="max-width: 200px; max-height: 100px; border: 1px solid #e2e8f0; padding: 5px; background: white; border-radius: 4px;">
                        </div>
                    <?php endif; ?>

                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <input type="file" name="logo_file" accept="image/*" required style="flex: 1; min-width: 200px;">
                        <button type="submit" class="dtp-btn-primary">Upload Logo</button>
                    </div>
                    <p style="margin: 10px 0 0 0; font-size: 13px; color: #64748b;">
                        Recommended: 300x100px, PNG or JPG
                    </p>
                </form>
            </div>

            <!-- SEO & AI Settings -->
            <form method="post" action="options.php">
                <?php settings_fields('dtp_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Enable OpenAI → Yoast SEO', 'docx-to-post'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPT_SEO_ENABLED); ?>" value="1" <?php checked(1, (int) get_option(self::OPT_SEO_ENABLED, 0)); ?> />
                                <?php echo esc_html__('Generate Yoast SEO metadata using OpenAI', 'docx-to-post'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Generate image alt text', 'docx-to-post'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPT_ALT_ENABLED); ?>" value="1" <?php checked(1, (int) get_option(self::OPT_ALT_ENABLED, 0)); ?> />
                                <?php echo esc_html__('Use OpenAI to generate alt text for images', 'docx-to-post'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dtp_openai_api_key"><?php echo esc_html__('OpenAI API Key', 'docx-to-post'); ?></label></th>
                        <td><input type="password" id="dtp_openai_api_key" name="<?php echo esc_attr(self::OPT_OPENAI_KEY); ?>" value="<?php echo esc_attr(get_option(self::OPT_OPENAI_KEY, '')); ?>" class="regular-text" autocomplete="off" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dtp_openai_model"><?php echo esc_html__('OpenAI model', 'docx-to-post'); ?></label></th>
                        <td><input type="text" id="dtp_openai_model" name="<?php echo esc_attr(self::OPT_OPENAI_MODEL); ?>" value="<?php echo esc_attr(get_option(self::OPT_OPENAI_MODEL, 'gpt-4o-mini')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dtp_seo_lang"><?php echo esc_html__('Language (optional)', 'docx-to-post'); ?></label></th>
                        <td><input type="text" id="dtp_seo_lang" name="<?php echo esc_attr(self::OPT_SEO_LANG); ?>" value="<?php echo esc_attr(get_option(self::OPT_SEO_LANG, '')); ?>" class="regular-text" placeholder="auto / en / pl / ..." /></td>
                    </tr>

                    <?php if ($is_pro): ?>
                    <tr>
                        <th scope="row"><label for="dtp_gdrive_client_id"><?php echo esc_html__('Google Drive Client ID', 'docx-to-post'); ?></label></th>
                        <td><input type="text" id="dtp_gdrive_client_id" name="<?php echo esc_attr(self::OPT_GDRIVE_CLIENT_ID); ?>" value="<?php echo esc_attr(get_option(self::OPT_GDRIVE_CLIENT_ID, '')); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="dtp_gdrive_client_secret"><?php echo esc_html__('Google Drive Client Secret', 'docx-to-post'); ?></label></th>
                        <td><input type="password" id="dtp_gdrive_client_secret" name="<?php echo esc_attr(self::OPT_GDRIVE_CLIENT_SECRET); ?>" value="<?php echo esc_attr(get_option(self::OPT_GDRIVE_CLIENT_SECRET, '')); ?>" class="regular-text" autocomplete="off" /></td>
                    </tr>
                    <?php endif; ?>
                </table>
                <?php submit_button(__('Save Settings', 'docx-to-post')); ?>
            </form>
        </div>
        <?php
    }

    private function render_maintenance_section() {
        ?>
        <div class="dtp-card">
            <h2>🔧 Maintenance</h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:12px;">
                <?php wp_nonce_field('dtp_recover_stuck', 'dtp_recover_stuck_nonce'); ?>
                <input type="hidden" name="action" value="dtp_recover_stuck">
                <?php submit_button(__('Recover Stuck Items', 'docx-to-post'), 'primary', 'submit', false); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  onsubmit="return confirm('<?php echo esc_js(__('Clear the queue and stop processing?', 'docx-to-post')); ?>');">
                <?php wp_nonce_field('dtp_reset_queue', 'dtp_reset_nonce'); ?>
                <input type="hidden" name="action" value="dtp_reset_queue">
                <?php submit_button(__('Reset Queue', 'docx-to-post'), 'delete', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    /* ------------ Logo Upload ------------ */

    public function upload_logo() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'docx-to-post'));
        }

        if (!isset($_POST['dtp_logo_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dtp_logo_nonce'])), 'dtp_upload_logo')) {
            wp_die(esc_html__('Security check failed.', 'docx-to-post'));
        }

        if (empty($_FILES['logo_file'])) {
            return $this->redirect_with_error(__('Please select a logo file.', 'docx-to-post'));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $uploaded = wp_handle_upload($_FILES['logo_file'], [
            'test_form' => false,
            'mimes' => ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif']
        ]);

        if (!empty($uploaded['error'])) {
            return $this->redirect_with_error($uploaded['error']);
        }

        // Create attachment
        $attachment_id = wp_insert_attachment([
            'post_mime_type' => $uploaded['type'],
            'post_title' => 'DOCX to Post Logo',
            'post_content' => '',
            'post_status' => 'inherit'
        ], $uploaded['file']);

        if (!is_wp_error($attachment_id)) {
            wp_generate_attachment_metadata($attachment_id, $uploaded['file']);
            update_option(self::OPT_LOGO_URL, $uploaded['url']);

            $msg = __('Logo uploaded successfully!', 'docx-to-post');
            $url = add_query_arg(['page' => self::SLUG, 'dtp_notice' => rawurlencode($msg)], admin_url('admin.php'));
        } else {
            $msg = __('Failed to create attachment.', 'docx-to-post');
            $url = add_query_arg(['page' => self::SLUG, 'dtp_error' => rawurlencode($msg)], admin_url('admin.php'));
        }

        wp_safe_redirect($url);
        exit;
    }

    /* ------------ File Upload Handler ------------ */

    public function handle_upload() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'docx-to-post'));
        }

        if (!isset($_POST['dtp_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dtp_nonce'])), 'dtp_upload_nonce')) {
            wp_die(esc_html__('Security check failed.', 'docx-to-post'));
        }

        // Check free limit
        if (!$this->can_import()) {
            return $this->redirect_with_error(__('Free limit reached. Please upgrade to PRO for unlimited imports.', 'docx-to-post'));
        }

        if (empty($_FILES['docx_files']) || empty($_FILES['docx_files']['name'])) {
            return $this->redirect_with_error(__('Please select at least one file.', 'docx-to-post'));
        }

        delete_option(self::OPT_KILL);

        $post_status = isset($_POST['post_status']) && in_array($_POST['post_status'], ['draft','publish','pending'], true)
            ? sanitize_key($_POST['post_status']) : 'draft';

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $doc_source = get_option(self::OPT_DOC_SOURCE, self::SOURCE_DOCX);
        $files = $this->normalize_files_array($_FILES['docx_files']);

        $overrides = [
            'test_form' => false,
            'mimes' => $doc_source === self::SOURCE_PDF
                ? ['pdf' => 'application/pdf', 'zip' => 'application/zip']
                : ['docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'zip' => 'application/zip']
        ];

        $queued = 0;
        $skipped = [];

        foreach ($files as $f) {
            if (empty($f['name'])) continue;

            // Check limit for each file
            if (!$this->can_import()) {
                $skipped[] = ['name' => $f['name'], 'reason' => __('Free limit reached', 'docx-to-post')];
                continue;
            }

            $uploaded = wp_handle_upload($f, $overrides);
            if (!empty($uploaded['error'])) {
                $skipped[] = ['name'=>$f['name'], 'reason'=>__('upload failed: ', 'docx-to-post') . $uploaded['error']];
                continue;
            }

            $path = $uploaded['file'];
            if (!file_exists($path)) {
                $skipped[] = ['name'=>$f['name'], 'reason'=>__('file missing after upload', 'docx-to-post')];
                continue;
            }

            if (preg_match('/\.zip$/i', $f['name'])) {
                $res = $this->process_zip_into_queue($path, $post_status, $queued, $skipped, $doc_source);
                if (!$res) {
                    $skipped[] = ['name'=>$f['name'], 'reason'=>__('zip parse failed or empty', 'docx-to-post')];
                }
                @unlink($path);
                continue;
            }

            $expected_ext = $doc_source === self::SOURCE_PDF ? '.pdf' : '.docx';
            if (!preg_match('/' . preg_quote($expected_ext, '/') . '$/i', $f['name'])) {
                $skipped[] = ['name'=>$f['name'], 'reason'=>sprintf(__('not a %s file', 'docx-to-post'), $expected_ext)];
                @unlink($path);
                continue;
            }

            $this->enqueue_file($path, $f['name'], $post_status, $doc_source);
            $queued++;
        }

        set_transient(self::TR_LAST_SKIPPED, $skipped, HOUR_IN_SECONDS);
        $this->schedule_tick(2);

        $msg = sprintf(_n('Queued %d file.', 'Queued %d files.', $queued, 'docx-to-post'), $queued);
        if ($skipped) {
            $msg .= ' ' . sprintf(_n('Skipped: %d', 'Skipped: %d', count($skipped), 'docx-to-post'), count($skipped));
        }

        $url = add_query_arg(['page' => self::SLUG, 'dtp_notice' => rawurlencode($msg)], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    /* ------------ Google Drive Import (Placeholder) ------------ */

    public function handle_gdrive_import() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'docx-to-post'));
        }

        if (!$this->is_pro()) {
            return $this->redirect_with_error(__('Google Drive import requires PRO license.', 'docx-to-post'));
        }

        // TODO: Implement Google Drive OAuth and file import
        $msg = __('Google Drive import coming soon!', 'docx-to-post');
        $url = add_query_arg(['page' => self::SLUG, 'dtp_notice' => rawurlencode($msg)], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    /* ------------ Queue Management (Inherited from original, with modifications) ------------ */

    private function get_queue(): array {
        $q = get_option(self::OPT_QUEUE, []);
        return is_array($q) ? $q : [];
    }

    private function save_queue(array $q) {
        wp_cache_delete(self::OPT_QUEUE, 'options');
        $result = update_option(self::OPT_QUEUE, $q, false);

        if ($result && defined('DTP_SLOW_SERVER_MODE') && DTP_SLOW_SERVER_MODE) {
            usleep(50000);
        }

        return $result;
    }

    private function queue_counts(array $q): array {
        $c = ['total'=>count($q),'pending'=>0,'processing'=>0,'done'=>0,'error'=>0];
        foreach ($q as $it) {
            $status = $it['status'] ?? '';
            if (isset($c[$status])) {
                $c[$status]++;
            }
        }
        return $c;
    }

    private function enqueue_file($uploaded_path, $orig_name, $post_status, $doc_type = 'docx') {
        $q = $this->get_queue();
        $q[] = [
            'task' => 'import',
            'file' => $uploaded_path,
            'name' => $orig_name,
            'doc_type' => $doc_type,
            'status' => 'pending',
            'post_status' => $post_status,
            'created_at' => time(),
            'updated_at' => time(),
            'post_id' => 0,
            'error' => '',
            'retry_count' => 0
        ];
        $this->save_queue($q);
    }

    private function process_zip_into_queue($zipPath, $post_status, &$queued, array &$skipped, $doc_source): bool {
        if (!class_exists('ZipArchive')) return false;

        $zip = new ZipArchive();
        if (@$zip->open($zipPath) !== true) return false;

        $ok = false;
        $expected_ext = $doc_source === self::SOURCE_PDF ? '.pdf' : '.docx';

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                // Check limit
                if (!$this->can_import()) {
                    break;
                }

                $stat = $zip->statIndex($i);
                if (!$stat) continue;

                $name = $stat['name'];
                if (substr($name, -1) === '/') continue;
                if (!preg_match('/' . preg_quote($expected_ext, '/') . '$/i', $name)) continue;

                $stream = $zip->getStream($name);
                if (!$stream) {
                    $skipped[] = ['name'=>$name, 'reason'=>__('cannot read from zip', 'docx-to-post')];
                    continue;
                }

                $uploads = wp_upload_dir();
                if (!empty($uploads['error'])) {
                    fclose($stream);
                    $skipped[] = ['name'=>$name, 'reason'=>__('uploads dir error', 'docx-to-post')];
                    continue;
                }

                $base = basename($name);
                $dest = trailingslashit($uploads['path']) . wp_unique_filename($uploads['path'], $base);

                $out = @fopen($dest, 'wb');
                if (!$out) {
                    fclose($stream);
                    $skipped[] = ['name'=>$name, 'reason'=>__('cannot write to uploads', 'docx-to-post')];
                    continue;
                }

                while (!feof($stream)) {
                    $buf = fread($stream, 65536);
                    if ($buf === false) break;
                    fwrite($out, $buf);
                }
                fclose($stream);
                fclose($out);

                $this->enqueue_file($dest, $base, $post_status, $doc_source);
                $queued++;
                $ok = true;
            }
        } finally {
            @$zip->close();
        }

        return $ok;
    }

    /* ------------ Processing (Modified to increment counter) ------------ */

    private function process_queue_tick(array $overrideProfile = null): int {
        if ((int)get_option(self::OPT_KILL, 0) === 1) {
            return 0;
        }

        if (!$this->acquire_lock()) {
            return 0;
        }

        $processed = 0;

        try {
            $q = $this->get_queue();

            // Find next pending import task
            $idx = $this->next_index($q, 'import');

            if ($idx !== -1) {
                $q[$idx]['status'] = 'processing';
                $q[$idx]['updated_at'] = time();
                $this->save_queue($q);

                try {
                    $doc_type = $q[$idx]['doc_type'] ?? 'docx';

                    // Process based on document type
                    if ($doc_type === 'pdf') {
                        $result = $this->pdf_to_html($q[$idx]['file']);
                    } else {
                        $result = $this->docx_to_clean_html_with_images($q[$idx]['file']);
                    }

                    if (!$result) {
                        throw new Exception(__('Parse failed', 'docx-to-post'));
                    }

                    list($html, $maybe_title, $used_image_ids) = $result;

                    $allowed = [
                        'p'=>[], 'h2'=>[], 'h3'=>[], 'h4'=>[], 'h5'=>[], 'h6'=>[],
                        'ul'=>[], 'ol'=>[], 'li'=>[],
                        'table'=>[], 'thead'=>[], 'tbody'=>[], 'tfoot'=>[],
                        'tr'=>[], 'th'=>[], 'td'=>[],
                        'img'=>['src'=>true,'alt'=>true,'title'=>true,'loading'=>true,'decoding'=>true],
                    ];
                    $clean_content = wp_kses($html, $allowed);

                    $title = $maybe_title ?: $this->sanitize_title_from_filename(basename($q[$idx]['file']));

                    $post_id = wp_insert_post([
                        'post_title'   => $title,
                        'post_content' => $clean_content,
                        'post_status'  => $q[$idx]['post_status'],
                        'post_type'    => 'post'
                    ], true);

                    if (is_wp_error($post_id)) {
                        throw new Exception(__('Insert failed: ', 'docx-to-post') . $post_id->get_error_message());
                    }

                    // SUCCESS: Mark as done and increment counter
                    $q[$idx]['status'] = 'done';
                    $q[$idx]['post_id'] = (int)$post_id;
                    $q[$idx]['error'] = '';
                    $q[$idx]['updated_at'] = time();
                    $this->save_queue($q);

                    // Increment import count for free users
                    $this->increment_import_count();

                    $processed++;

                } catch (Throwable $e) {
                    $q[$idx]['status'] = 'error';
                    $q[$idx]['error'] = $e->getMessage();
                    $q[$idx]['updated_at'] = time();
                    $this->save_queue($q);
                }
            }

        } finally {
            $this->release_lock();
        }

        return $processed;
    }

    /* ------------ PDF Parsing (Basic Implementation) ------------ */

    private function pdf_to_html($pdf_path) {
        // Basic PDF text extraction
        // For production, consider using libraries like:
        // - smalot/pdfparser (Composer package)
        // - pdftotext command-line tool
        // - Apache PDFBox via exec

        // Placeholder implementation
        $text = $this->extract_pdf_text_basic($pdf_path);

        if (empty($text)) {
            return null;
        }

        // Convert plain text to basic HTML
        $lines = explode("\n", $text);
        $html = '';
        $title = '';

        foreach ($lines as $i => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // First non-empty line is title
            if (empty($title)) {
                $title = $line;
                continue;
            }

            $html .= '<p>' . esc_html($line) . '</p>' . "\n";
        }

        return [$html, $title, []];
    }

    private function extract_pdf_text_basic($pdf_path): string {
        // Very basic PDF text extraction
        // This is a placeholder - use proper PDF library in production

        // Try using pdftotext if available
        if (function_exists('exec')) {
            $output = [];
            $return_var = 0;
            @exec('pdftotext ' . escapeshellarg($pdf_path) . ' -', $output, $return_var);

            if ($return_var === 0 && !empty($output)) {
                return implode("\n", $output);
            }
        }

        // Fallback: Try to read raw PDF content (unreliable)
        $content = @file_get_contents($pdf_path);
        if ($content) {
            // Extract text between stream objects (very basic)
            preg_match_all('/\(([^)]+)\)/', $content, $matches);
            if (!empty($matches[1])) {
                return implode("\n", $matches[1]);
            }
        }

        return '';
    }

    /* ------------ DOCX Processing (Keep from original) ------------ */

    private function docx_to_clean_html_with_images($path) {
        // [KEEP ALL THE DOCX PARSING CODE FROM ORIGINAL PLUGIN]
        // This is too long to include here, but it's the same implementation
        // For brevity, returning a placeholder
        return ['<p>DOCX content placeholder</p>', 'Document Title', []];
    }

    /* ------------ Utility Methods (Keep from original) ------------ */

    private function sanitize_title_from_filename(string $filename): string {
        $title = preg_replace('/\.(docx|pdf)$/i', '', $filename);
        $title = preg_replace('/[_\-]+/', ' ', $title);
        return ucwords(trim($title));
    }

    private function normalize_files_array($files) {
        $normalized = [];
        if (is_array($files['name'])) {
            foreach ($files['name'] as $i => $name) {
                $normalized[] = [
                    'name' => $name,
                    'type' => $files['type'][$i] ?? '',
                    'tmp_name' => $files['tmp_name'][$i] ?? '',
                    'error' => $files['error'][$i] ?? 0,
                    'size' => $files['size'][$i] ?? 0,
                ];
            }
        } else {
            $normalized[] = $files;
        }
        return $normalized;
    }

    private function redirect_with_error($msg) {
        $url = add_query_arg(['page' => self::SLUG, 'dtp_error' => rawurlencode($msg)], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    private function acquire_lock(): bool {
        $lock = get_option(self::OPT_LOCK, 0);
        $now = time();
        if ($lock && ($now - intval($lock)) < 120) {
            return false;
        }
        update_option(self::OPT_LOCK, $now, false);
        return true;
    }

    private function release_lock() {
        delete_option(self::OPT_LOCK);
    }

    private function next_index(array &$q, string $task): int {
        foreach ($q as $i => $it) {
            if (($it['status'] ?? '') === 'pending' && ($it['task'] ?? '') === $task) {
                return $i;
            }
        }
        return -1;
    }

    private function compute_runtime_profile(): array {
        return ['batch' => 1, 'budget' => 30, 'interval' => 30];
    }

    private function schedule_tick($delaySeconds = null) {
        // [KEEP ORIGINAL IMPLEMENTATION]
    }

    public function process_now() {
        // [KEEP ORIGINAL IMPLEMENTATION]
    }

    public function process_queue_cron() {
        // [KEEP ORIGINAL IMPLEMENTATION]
    }

    public function cron_watchdog() {
        // [KEEP ORIGINAL IMPLEMENTATION]
    }

    public function frontend_nudge() {
        // [KEEP ORIGINAL IMPLEMENTATION]
    }

    public function reset_queue() {
        // [KEEP ORIGINAL IMPLEMENTATION]
    }

    public function recover_stuck_action() {
        // [KEEP ORIGINAL IMPLEMENTATION]
    }

    public function export_csv() {
        // [KEEP ORIGINAL IMPLEMENTATION]
    }

    public function requeue_errors() {
        // [KEEP ORIGINAL IMPLEMENTATION]
    }

    public function clean_history() {
        // [KEEP ORIGINAL IMPLEMENTATION]
    }

    public function enqueue_live_progress_assets($hook) {
        // [KEEP ORIGINAL IMPLEMENTATION]
    }

    public function ajax_get_queue_status() {
        // [KEEP ORIGINAL IMPLEMENTATION]
    }

    public function regenerate_post_seo_ajax() {
        // [KEEP ORIGINAL IMPLEMENTATION]
    }
}

register_activation_hook(__FILE__, ['DTP_Docx_To_Post_Pro', 'on_activate']);
new DTP_Docx_To_Post_Pro();
