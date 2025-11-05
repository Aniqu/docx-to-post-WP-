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

// Load Composer autoloader for PDF parsing
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

class DTP_Docx_To_Post_Pro {
    const SLUG = 'docx-to-post-pro';

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

    const TR_LAST_SKIPPED = 'dtp_last_upload_skipped';

    /* SEO options */
    const OPT_SEO_ENABLED  = 'dtp_enable_seo';
    const OPT_OPENAI_KEY   = 'dtp_openai_api_key';
    const OPT_OPENAI_MODEL = 'dtp_openai_model';
    const OPT_SEO_LANG     = 'dtp_seo_lang';
    const OPT_ALT_ENABLED  = 'dtp_enable_ai_alt';

    const CRON_HOOK = 'dtp_process_queue_event';

    /* Auto-tuning bounds */
    const BATCH_MIN = 1;
    const BATCH_MAX = 3;
    const BUDGET_MIN = 15;
    const BUDGET_MAX = 50;
    const INTERVAL_MIN = 20;
    const INTERVAL_MAX = 60;

    /* Failsafe micro-tick */
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
        if (!get_option(self::OPT_DOC_SOURCE)) {
            update_option(self::OPT_DOC_SOURCE, self::SOURCE_DOCX);
        }
        if (!get_option(self::OPT_TOTAL_IMPORTS)) {
            update_option(self::OPT_TOTAL_IMPORTS, 0);
        }
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 10, self::CRON_HOOK);
        }
    }

    private function dbg($msg) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[DTP PRO] ' . $msg);
        }
    }

    /* ==================== LICENSE MANAGEMENT ==================== */

    private function is_pro(): bool {
        $status = get_option(self::OPT_LICENSE_STATUS, 'free');
        return $status === 'active';
    }

    private function get_remaining_imports(): int {
        if ($this->is_pro()) {
            return PHP_INT_MAX;
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
        // Demo mode: Accept keys starting with PRO-
        if (strpos($key, 'PRO-') === 0 && strlen($key) >= 20) {
            return true;
        }

        // TODO: Implement real API validation
        // Example implementation commented below:
        /*
        $response = wp_remote_post('https://yourdomain.com/api/validate-license', [
            'body' => [
                'license_key' => $key,
                'domain' => home_url(),
                'product_id' => 'docx-to-post-pro'
            ],
            'timeout' => 15
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return isset($data['valid']) && $data['valid'] === true;
        */

        return false;
    }

    /* ==================== ADMIN UI ==================== */

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
        register_setting('dtp_settings', self::OPT_LICENSE_KEY, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('dtp_settings', self::OPT_DOC_SOURCE, ['type' => 'string', 'sanitize_callback' => 'sanitize_key']);
        register_setting('dtp_settings', self::OPT_GDRIVE_CLIENT_ID, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('dtp_settings', self::OPT_GDRIVE_CLIENT_SECRET, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('dtp_settings', self::OPT_LOGO_URL, ['type' => 'string', 'sanitize_callback' => 'esc_url_raw']);
        register_setting('dtp_settings', self::OPT_SEO_ENABLED, ['type' => 'boolean', 'sanitize_callback' => 'absint']);
        register_setting('dtp_settings', self::OPT_OPENAI_KEY, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('dtp_settings', self::OPT_OPENAI_MODEL, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('dtp_settings', self::OPT_SEO_LANG, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('dtp_settings', self::OPT_ALT_ENABLED, ['type' => 'boolean', 'sanitize_callback' => 'absint']);
    }

    public function show_admin_notices() {
        if (!current_user_can('edit_posts')) return;

        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, self::SLUG) === false) return;

        if (!$this->is_pro()) {
            $remaining = $this->get_remaining_imports();
            if ($remaining <= 3 && $remaining > 0) {
                echo '<div class="notice notice-warning"><p>';
                echo '<strong>' . esc_html(sprintf(__('Free Version: %d imports remaining', 'docx-to-post'), $remaining)) . '</strong><br>';
                echo esc_html__('Upgrade to PRO for unlimited imports, PDF support, and Google Drive integration.', 'docx-to-post');
                echo ' <a href="#dtp-license-section" class="button button-primary" style="margin-left: 10px;">' . esc_html__('Upgrade Now', 'docx-to-post') . '</a>';
                echo '</p></div>';
            } elseif ($remaining === 0) {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>' . esc_html__('Free Version Limit Reached', 'docx-to-post') . '</strong><br>';
                echo esc_html__('You\'ve used all 10 free imports. Upgrade to PRO for unlimited imports.', 'docx-to-post');
                echo ' <a href="#dtp-license-section" class="button button-primary" style="margin-left: 10px;">' . esc_html__('Upgrade to PRO', 'docx-to-post') . '</a>';
                echo '</p></div>';
            }
        }
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

        include __DIR__ . '/admin-ui.php';
    }

    /* ==================== LOGO UPLOAD ==================== */

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

    /* ==================== FILE UPLOAD HANDLER ==================== */

    public function handle_upload() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'docx-to-post'));
        }
        if (!isset($_POST['dtp_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dtp_nonce'])), 'dtp_upload_nonce')) {
            wp_die(esc_html__('Security check failed.', 'docx-to-post'));
        }

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

    public function handle_gdrive_import() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'docx-to-post'));
        }
        if (!$this->is_pro()) {
            return $this->redirect_with_error(__('Google Drive import requires PRO license.', 'docx-to-post'));
        }

        $msg = __('Google Drive import coming soon!', 'docx-to-post');
        $url = add_query_arg(['page' => self::SLUG, 'dtp_notice' => rawurlencode($msg)], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    /* ==================== QUEUE MANAGEMENT ==================== */

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

    private function enqueue_seo($post_id, $title, $excerpt) {
        $q = $this->get_queue();
        $q[] = [
            'task' => 'seo',
            'file' => '',
            'name' => sprintf(__('SEO for post %d', 'docx-to-post'), (int)$post_id),
            'status' => 'pending',
            'post_status' => 'ignore',
            'created_at' => time(),
            'updated_at' => time(),
            'post_id' => (int)$post_id,
            'title' => (string) $title,
            'excerpt' => (string) $excerpt,
            'error' => ''
        ];
        $this->save_queue($q);
    }

    private function enqueue_img_alt($post_id) {
        $q = $this->get_queue();
        $q[] = [
            'task'       => 'img_alt',
            'file'       => '',
            'name'       => sprintf(__('ALT for post %d', 'docx-to-post'), (int)$post_id),
            'status'     => 'pending',
            'post_status'=> 'ignore',
            'created_at' => time(),
            'updated_at' => time(),
            'post_id'    => (int)$post_id,
            'error'      => ''
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
                if (!$this->can_import()) break;

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

    /* ==================== PROCESSING TICK ==================== */

    private function process_queue_tick(array $overrideProfile = null): int {
        if ((int)get_option(self::OPT_KILL, 0) === 1) return 0;
        if (!$this->acquire_lock()) return 0;

        @ini_set('memory_limit', '512M');
        if (function_exists('set_time_limit')) @set_time_limit(60);

        $profile = $overrideProfile ?: $this->compute_runtime_profile();
        $start = microtime(true);
        $processed = 0;
        $ranLong = false;

        try {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $recovered = $this->recover_stuck_items();
            if ($recovered > 0) $this->dbg("Auto-recovered {$recovered} stuck item(s)");

            $q = $this->get_queue();

            $rounds = [
                function (&$q) { return $this->next_index($q, 'import'); },
                function (&$q) { return $this->next_index($q, 'seo'); },
                function (&$q) { return $this->next_index($q, 'img_alt'); },
            ];

            foreach ($rounds as $finder) {
                while (true) {
                    if ((int)get_option(self::OPT_KILL, 0) === 1) break;
                    if ($processed >= $profile['batch']) break;

                    $elapsed = microtime(true) - $start;
                    if ($elapsed >= $profile['budget']) {
                        $ranLong = true;
                        break;
                    }

                    $idx = $finder($q);
                    if ($idx === -1) break;

                    $q[$idx]['status'] = 'processing';
                    $q[$idx]['updated_at'] = time();
                    $this->save_queue($q);

                    try {
                        if ($q[$idx]['task'] === 'import') {
                            $post_id = null;

                            try {
                                $doc_type = $q[$idx]['doc_type'] ?? 'docx';

                                if ($doc_type === 'pdf') {
                                    $result = $this->pdf_to_clean_html($q[$idx]['file']);
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
                                $title = $this->clean_title($title);

                                $post_id = wp_insert_post([
                                    'post_title'   => $title,
                                    'post_content' => $clean_content,
                                    'post_status'  => $q[$idx]['post_status'],
                                    'post_type'    => 'post'
                                ], true);

                                if (is_wp_error($post_id)) {
                                    throw new Exception(__('Insert failed: ', 'docx-to-post') . $post_id->get_error_message());
                                }

                                $q[$idx]['status'] = 'done';
                                $q[$idx]['post_id'] = (int)$post_id;
                                $q[$idx]['error'] = '';
                                $q[$idx]['updated_at'] = time();
                                $this->save_queue($q);

                                // Increment counter for free users
                                $this->increment_import_count();

                                // Post-processing
                                try {
                                    if (!empty($used_image_ids)) {
                                        set_post_thumbnail($post_id, $used_image_ids[0]);
                                        foreach ($used_image_ids as $aid) {
                                            wp_update_post(['ID' => $aid, 'post_parent' => $post_id], false, false);
                                        }
                                    }

                                    if (!empty($used_image_ids)) {
                                        $primary_kw = $this->get_primary_keyword_for_post((int)$post_id, $title);
                                        if ($primary_kw !== '') {
                                            $this->apply_featured_alt((int)$post_id, (int)$used_image_ids[0], $primary_kw);
                                        }
                                    }

                                    $aiAvailable = ((int) get_option(self::OPT_SEO_ENABLED, 0) === 1 && get_option(self::OPT_OPENAI_KEY));
                                    $plain_preview = mb_substr(wp_strip_all_tags($clean_content), 0, 8000);

                                    $elapsed = microtime(true) - $start;
                                    $timeLeft = $profile['budget'] - $elapsed;

                                    if ($timeLeft >= 6 && (int)get_option(self::OPT_KILL, 0) !== 1) {
                                        $this->apply_seo_metadata((int)$post_id, $title, $plain_preview, $aiAvailable);
                                    } else {
                                        $this->enqueue_seo((int)$post_id, $title, $plain_preview);
                                    }

                                    $altEnabled = (int) get_option(self::OPT_ALT_ENABLED, 0) === 1 && get_option(self::OPT_OPENAI_KEY);
                                    if ($altEnabled) {
                                        $elapsed = microtime(true) - $start;
                                        $timeLeft = $profile['budget'] - $elapsed;

                                        if ($timeLeft >= 6 && (int)get_option(self::OPT_KILL, 0) !== 1) {
                                            $this->generate_image_alts_for_post((int)$post_id);
                                        } else {
                                            $this->enqueue_img_alt((int)$post_id);
                                        }
                                    }
                                } catch (Throwable $e) {
                                    $this->dbg('Post-processing error for post '.$post_id.': ' . $e->getMessage());
                                }

                                $processed++;

                            } catch (Throwable $e) {
                                if ($post_id && is_numeric($post_id) && $post_id > 0) {
                                    $q[$idx]['status'] = 'done';
                                    $q[$idx]['post_id'] = (int)$post_id;
                                    $q[$idx]['error'] = 'Post created but with warnings: ' . $e->getMessage();
                                    $q[$idx]['updated_at'] = time();
                                    $this->save_queue($q);
                                    $this->increment_import_count();
                                    $processed++;
                                } else {
                                    throw $e;
                                }
                            }

                        } elseif ($q[$idx]['task'] === 'seo') {
                            $elapsed = microtime(true) - $start;
                            if ($elapsed >= max(5, $profile['budget'] - 5)) {
                                $ranLong = true;
                                break;
                            }

                            $pid = (int)$q[$idx]['post_id'];
                            $t = (string)($q[$idx]['title'] ?? '');
                            $preview = (string)($q[$idx]['excerpt'] ?? '');

                            if ($pid > 0) {
                                $useAI = ((int) get_option(self::OPT_SEO_ENABLED, 0) === 1 && get_option(self::OPT_OPENAI_KEY));
                                $this->apply_seo_metadata($pid, $t, $preview, $useAI);
                            }

                            $q[$idx]['status'] = 'done';
                            $q[$idx]['updated_at'] = time();
                            $this->save_queue($q);
                            $processed++;

                        } elseif ($q[$idx]['task'] === 'img_alt') {
                            if (!((int)get_option(self::OPT_ALT_ENABLED, 0) === 1 && get_option(self::OPT_OPENAI_KEY))) {
                                $q[$idx]['status'] = 'done';
                                $q[$idx]['updated_at'] = time();
                                $this->save_queue($q);
                                $processed++;
                                continue;
                            }

                            $elapsed = microtime(true) - $start;
                            if ($elapsed >= max(5, $profile['budget'] - 5)) {
                                $ranLong = true;
                                break;
                            }

                            $this->generate_image_alts_for_post((int)$q[$idx]['post_id']);

                            $q[$idx]['status'] = 'done';
                            $q[$idx]['updated_at'] = time();
                            $this->save_queue($q);
                            $processed++;
                        }

                    } catch (Throwable $e) {
                        $retry_count = (int)($q[$idx]['retry_count'] ?? 0);
                        $max_retries = 3;

                        if ($retry_count >= $max_retries) {
                            $q[$idx]['status'] = 'error';
                            $q[$idx]['error'] = sprintf('Failed after %d attempts: %s', $retry_count + 1, $e->getMessage());
                        } else {
                            $q[$idx]['status'] = 'pending';
                            $q[$idx]['retry_count'] = $retry_count + 1;
                            $q[$idx]['error'] = sprintf('Retry %d/%d: %s', $retry_count + 1, $max_retries, $e->getMessage());
                        }

                        $q[$idx]['updated_at'] = time();
                        $this->save_queue($q);
                        $this->dbg('Error: ' . $e->getMessage());
                    }

                    if (function_exists('gc_collect_cycles')) @gc_collect_cycles();
                }

                if ($ranLong) break;
            }

            $elapsed = microtime(true) - $start;
            $this->update_runtime_state($processed, $elapsed, $profile, $ranLong);

        } catch (Throwable $e) {
            $this->dbg('Critical error in process_queue_tick: ' . $e->getMessage());
        } finally {
            $this->release_lock();
        }

        return $processed;
    }

    private function next_index(array &$q, string $task): int {
        foreach ($q as $i => $it) {
            if (($it['status'] ?? '') === 'pending' && ($it['task'] ?? '') === $task) {
                return $i;
            }
        }
        return -1;
    }

    /* ==================== RECOVERY SYSTEM ==================== */

    private function recover_stuck_items(): int {
        $q = $this->get_queue();
        $recovered = 0;
        $now = time();
        $max_retries = 3;

        foreach ($q as $idx => $item) {
            if ($item['status'] === 'processing') {
                $stuck_time = $now - ($item['updated_at'] ?? 0);

                if ($stuck_time > 300) {
                    $retry_count = (int)($item['retry_count'] ?? 0);

                    if (!empty($item['post_id']) && get_post($item['post_id'])) {
                        $q[$idx]['status'] = 'done';
                        $q[$idx]['error'] = 'Auto-recovered: post was created successfully';
                        $q[$idx]['updated_at'] = $now;
                        $recovered++;
                    } else {
                        if ($retry_count >= $max_retries) {
                            $q[$idx]['status'] = 'error';
                            $q[$idx]['error'] = sprintf('Failed after %d retry attempts.', $retry_count);
                            $q[$idx]['updated_at'] = $now;
                            $recovered++;
                        } else {
                            $q[$idx]['status'] = 'pending';
                            $q[$idx]['retry_count'] = $retry_count + 1;
                            $q[$idx]['error'] = sprintf('Retry %d/%d after timeout', $retry_count + 1, $max_retries);
                            $q[$idx]['updated_at'] = $now;
                            $recovered++;
                        }
                    }
                }
            }
        }

        if ($recovered > 0) {
            $this->save_queue($q);
        }

        return $recovered;
    }

    public function recover_stuck_action() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'docx-to-post'));
        }
        if (!isset($_POST['dtp_recover_stuck_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dtp_recover_stuck_nonce'])), 'dtp_recover_stuck')) {
            wp_die(esc_html__('Security check failed.', 'docx-to-post'));
        }

        $recovered = $this->recover_stuck_items();
        $msg = $recovered > 0 
            ? sprintf(__('Successfully recovered %d stuck item(s).', 'docx-to-post'), $recovered)
            : __('No stuck items found.', 'docx-to-post');

        $url = add_query_arg(['page' => self::SLUG, 'dtp_notice' => rawurlencode($msg)], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    /* ==================== PDF PARSING ==================== */

    private function pdf_to_clean_html($pdf_path) {
        if (!class_exists('\Smalot\PdfParser\Parser')) {
            $this->dbg('PDF Parser library not found');
            return $this->pdf_fallback_text_extraction($pdf_path);
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($pdf_path);
            
            $text = $pdf->getText();
            if (empty($text)) {
                return $this->pdf_fallback_text_extraction($pdf_path);
            }

            // Extract title from first line or metadata
            $title = '';
            $details = $pdf->getDetails();
            if (isset($details['Title']) && !empty($details['Title'])) {
                $title = $details['Title'];
            }

            // Split into lines
            $lines = explode("\n", trim($text));
            $html = '';
            
            // If no title from metadata, use first meaningful line
            if (empty($title)) {
                foreach ($lines as $i => $line) {
                    $line = trim($line);
                    if (!empty($line) && strlen($line) > 5 && strlen($line) < 200) {
                        $title = $line;
                        unset($lines[$i]);
                        break;
                    }
                }
            }

            // Convert remaining lines to paragraphs
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Detect headings (lines in ALL CAPS or starting with numbers)
                if (preg_match('/^[A-Z\s]{10,}$/', $line) || preg_match('/^\d+\.?\s/', $line)) {
                    $html .= '<h2>' . esc_html($line) . '</h2>' . "\n";
                } else {
                    $html .= '<p>' . esc_html($line) . '</p>' . "\n";
                }
            }

            $title = $this->clean_title($title ?: 'PDF Document');
            
            return [$html, $title, []]; // [html, title, images]

        } catch (Exception $e) {
            $this->dbg('PDF parsing error: ' . $e->getMessage());
            return $this->pdf_fallback_text_extraction($pdf_path);
        }
    }

    private function pdf_fallback_text_extraction($pdf_path) {
        // Try pdftotext command if available
        if (function_exists('exec')) {
            $temp_file = tempnam(sys_get_temp_dir(), 'pdf_');
            $command = sprintf('pdftotext %s %s 2>&1', escapeshellarg($pdf_path), escapeshellarg($temp_file));
            
            $output = [];
            $return_var = 0;
            @exec($command, $output, $return_var);

            if ($return_var === 0 && file_exists($temp_file)) {
                $text = file_get_contents($temp_file);
                @unlink($temp_file);
                
                if (!empty($text)) {
                    $lines = explode("\n", trim($text));
                    $title = array_shift($lines) ?: 'PDF Document';
                    $html = '';
                    
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (!empty($line)) {
                            $html .= '<p>' . esc_html($line) . '</p>' . "\n";
                        }
                    }
                    
                    return [$html, $this->clean_title($title), []];
                }
            }
        }

        // Last resort: basic text extraction
        $content = @file_get_contents($pdf_path);
        if ($content) {
            preg_match_all('/\(([^)]+)\)/', $content, $matches);
            if (!empty($matches[1])) {
                $text = implode("\n", array_filter($matches[1]));
                $lines = explode("\n", $text);
                $title = array_shift($lines) ?: 'PDF Document';
                $html = '';
                
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (!empty($line)) {
                        $html .= '<p>' . esc_html($line) . '</p>' . "\n";
                    }
                }
                
                return [$html, $this->clean_title($title), []];
            }
        }

        return null;
    }

    /* ==================== Continue in next part... ==================== */

    /* ==================== DOCX PARSING (From Original) ==================== */

    private function docx_to_clean_html_with_images($path) {
        if (!class_exists('ZipArchive')) {
            $this->dbg('ZipArchive missing');
            return null;
        }

        $zip = new ZipArchive();
        $opened = @$zip->open($path);
        if ($opened !== true) {
            $this->dbg('Zip open failed');
            return null;
        }

        try {
            $docXml  = $zip->getFromName('word/document.xml');
            $relsXml = $zip->getFromName('word/_rels/document.xml.rels');

            if ($docXml === false || $docXml === '') return null;

            $relInfo = $this->parse_image_relationships($relsXml);
            $relToAttachment = [];

            foreach ($relInfo as $rid => $info) {
                if (!is_array($info)) continue;

                if (!empty($info['external'])) {
                    $url = $info['target'];
                    if ($url && preg_match('#^https?://#i', $url)) {
                        $upload = $this->download_external_image_stream($url);
                        if (!$upload || !empty($upload['error'])) continue;

                        $filetype = wp_check_filetype($upload['file'], null);
                        $aid = wp_insert_attachment([
                            'post_mime_type' => $filetype['type'] ?: 'image/jpeg',
                            'post_title'     => preg_replace('/\.[^.]+$/', '', basename($upload['file'])),
                            'post_content'   => '',
                            'post_status'    => 'inherit'
                        ], $upload['file']);

                        if (is_wp_error($aid)) continue;

                        $attach_data = wp_generate_attachment_metadata($aid, $upload['file']);
                        wp_update_attachment_metadata($aid, $attach_data);
                        $relToAttachment[$rid] = ['id'=>$aid, 'url'=>$upload['url']];
                    }
                    continue;
                }

                $targetRel = ltrim((string)($info['target'] ?? ''), '/');
                if ($targetRel === '') continue;

                $candidates = ['word/' . $targetRel, $targetRel];
                $inner = '';

                foreach ($candidates as $cand) {
                    $cand = preg_replace('#(^|/)\./#', '$1', $cand);
                    $cand = preg_replace('#[^/]+/\.\./#', '', $cand);
                    if ($zip->locateName($cand) !== false) {
                        $inner = $cand;
                        break;
                    }
                }

                if ($inner === '') continue;

                $stream = $zip->getStream($inner);
                if (!$stream) continue;

                $uploads = wp_upload_dir();
                if (!empty($uploads['error'])) {
                    fclose($stream);
                    continue;
                }

                $filename = basename($inner) ?: ('image-' . uniqid() . '.bin');
                $dest = trailingslashit($uploads['path']) . wp_unique_filename($uploads['path'], $filename);

                $out = @fopen($dest, 'wb');
                if (!$out) {
                    fclose($stream);
                    continue;
                }

                while (!feof($stream)) {
                    $buf = fread($stream, 65536);
                    if ($buf === false) break;
                    fwrite($out, $buf);
                }
                fclose($stream);
                fclose($out);

                $filetype = wp_check_filetype($dest);
                $aid = wp_insert_attachment([
                    'post_mime_type' => $filetype['type'] ?: 'image/jpeg',
                    'post_title'     => preg_replace('/\.[^.]+$/', '', basename($dest)),
                    'post_content'   => '',
                    'post_status'    => 'inherit'
                ], $dest);

                if (is_wp_error($aid)) {
                    @unlink($dest);
                    continue;
                }

                $attach_data = wp_generate_attachment_metadata($aid, $dest);
                wp_update_attachment_metadata($aid, $attach_data);
                $relToAttachment[$rid] = ['id'=>$aid, 'url'=> trailingslashit($uploads['url']) . basename($dest)];
            }
        } finally {
            if ($zip instanceof ZipArchive) {
                @$zip->close();
            }
        }

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        libxml_use_internal_errors(true);
        if (!@$dom->loadXML($docXml)) {
            libxml_clear_errors();
            return null;
        }
        libxml_clear_errors();

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w',  'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xp->registerNamespace('a',  'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xp->registerNamespace('r',  'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $xp->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');
        $xp->registerNamespace('v',  'urn:schemas-microsoft-com:vml');

        $numXml = $this->get_zip_file($path, 'word/numbering.xml');
        $numMap = $this->parse_numbering_map($numXml);

        $parts = [];
        $maybe_title = null;
        $used_image_ids = [];
        $listOpen = false;
        $listType = null;
        $first_meaningful_text = null;

        $bodyChildren = $xp->query('//w:document/w:body/*');

        foreach ($bodyChildren as $node) {
            $local = $node->localName;

            if ($local === 'tbl') {
                if ($listOpen) {
                    $parts[] = "</{$listType}>";
                    $listOpen = false;
                    $listType = null;
                }

                $tblHtml = $this->render_table_with_images($node, $xp, $relToAttachment, $used_image_ids);
                if ($tblHtml !== '') {
                    $parts[] = $tblHtml;
                }
                continue;
            }

            if ($local !== 'p') continue;

            $tag = 'p';
            $isTitle = false;
            $styleNode = $xp->query('.//w:pPr/w:pStyle', $node)->item(0);
            if ($styleNode && $styleNode->hasAttribute('w:val')) {
                $styleVal = $styleNode->getAttribute('w:val');

                if (preg_match('/^Title$/i', $styleVal)) {
                    $isTitle = true;
                    $tag = 'h1';
                } elseif (preg_match('/^Heading([1-6])$/i', $styleVal, $m)) {
                    $tag = 'h' . $m[1];
                }
            }

            $isList = false;
            $thisNumType = null;
            $numPr = $xp->query('.//w:pPr/w:numPr', $node)->item(0);

            if ($numPr) {
                $numIdNode = $xp->query('.//w:numId', $numPr)->item(0);
                if ($numIdNode && $numIdNode->hasAttribute('w:val')) {
                    $numId = $numIdNode->getAttribute('w:val');
                    $fmt = isset($numMap[$numId]) ? $numMap[$numId] : null;
                    if ($fmt) {
                        $thisNumType = strtolower($fmt) === 'bullet' ? 'ul' : 'ol';
                        $isList = true;
                    }
                }
            }

            $inlineHTML = $this->build_inline_html($node, $xp, $relToAttachment, $used_image_ids);
            $textOnly   = trim(strip_tags($inlineHTML));
            $hasImg     = (bool) preg_match('/<img\b/i', $inlineHTML);

            if ($inlineHTML === '' && !$hasImg) continue;

            if ($first_meaningful_text === null && $textOnly !== '' && strlen($textOnly) > 10 && strlen($textOnly) < 200) {
                $first_meaningful_text = $textOnly;
            }

            if ($isList) {
                if (!$listOpen || $listType !== $thisNumType) {
                    if ($listOpen) {
                        $parts[] = "</{$listType}>";
                    }
                    $listType = $thisNumType;
                    $parts[] = "<{$listType}>";
                    $listOpen = true;
                }
                $parts[] = '<li>' . $inlineHTML . '</li>';
                continue;
            } else {
                if ($listOpen) {
                    $parts[] = "</{$listType}>";
                    $listOpen = false;
                    $listType = null;
                }
            }

            if ($tag === 'h1' || $isTitle) {
                if ($maybe_title === null && $textOnly !== '') {
                    $maybe_title = $this->clean_title($textOnly);
                }
                continue;
            }

            if ($maybe_title === null && $tag === 'p' && $textOnly !== '' && count($parts) === 0) {
                if (strlen($textOnly) < 150 && !preg_match('/[.!?]$/', $textOnly)) {
                    $maybe_title = $this->clean_title($textOnly);
                    continue;
                }
            }

            if (preg_match('/^h[2-6]$/', $tag)) {
                $inlineHTML = preg_replace('/<img\b[^>]*>/i', '', $inlineHTML);
                $inlineHTML = trim($inlineHTML);
                if ($inlineHTML === '') continue;
            }

            $parts[] = sprintf('<%1$s>%2$s</%1$s>', $tag, $inlineHTML);
        }

        if ($listOpen) {
            $parts[] = "</{$listType}>";
        }

        $html = implode("\n\n", $parts);
        return [$html, $maybe_title, array_values(array_unique($used_image_ids))];
    }

    private function build_inline_html(DOMNode $root, DOMXPath $xp, $relToAttachment, array &$used_image_ids) {
        $rNS  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $wpNS = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
        $buf = '';

        $walker = function($node) use (&$walker, &$buf, $relToAttachment, &$used_image_ids, $rNS, $wpNS) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $text = $node->nodeValue;
                if ($text !== null && $text !== '') {
                    $buf .= esc_html($text);
                }
                return;
            }

            if ($node->nodeType !== XML_ELEMENT_NODE) return;

            $local = $node->localName;

            if ($local === 't') {
                $text = $node->nodeValue;
                if ($text !== null && $text !== '') {
                    $buf .= esc_html($text);
                }
                return;
            }

            if ($local === 'tab') {
                $buf .= ' ';
                return;
            }

            if ($local === 'br') {
                $buf .= ' ';
                return;
            }

            if ($local === 'blip') {
                $rid = $node->getAttributeNS($rNS, 'embed');
                if (!$rid) {
                    $rid = $node->getAttributeNS($rNS, 'link');
                }

                $alt = '';
                $title = '';
                $p = $node;

                while ($p) {
                    if ($p->nodeType === XML_ELEMENT_NODE && $p->localName === 'docPr' && $p->namespaceURI === $wpNS) {
                        $alt   = $p->getAttribute('descr') ?: '';
                        $title = $p->getAttribute('name') ?: '';
                        break;
                    }
                    $p = $p->parentNode;
                }

                if ($rid && isset($relToAttachment[$rid])) {
                    $att = $relToAttachment[$rid];
                    $attrs = ' src="' . esc_url($att['url']) . '" loading="lazy" decoding="async"';
                    $attrs .= ' alt="' . esc_attr($alt) . '"';
                    if ($title !== '') {
                        $attrs .= ' title="' . esc_attr($title) . '"';
                    }
                    $buf .= '<img' . $attrs . ' />';
                    if (isset($att['id'])) {
                        $used_image_ids[] = (int) $att['id'];
                    }
                }
                return;
            }

            if ($local === 'imagedata') {
                $rid = $node->getAttributeNS($rNS, 'id');
                if ($rid && isset($relToAttachment[$rid])) {
                    $att = $relToAttachment[$rid];
                    $buf .= '<img src="' . esc_url($att['url']) . '" alt="" loading="lazy" decoding="async" />';
                    if (isset($att['id'])) {
                        $used_image_ids[] = (int) $att['id'];
                    }
                }
                return;
            }

            foreach ($node->childNodes as $child) {
                $walker($child);
            }
        };

        $walker($root);

        $buf = preg_replace('/\x{00A0}/u', ' ', $buf);
        $buf = preg_replace('/[\t\r\n]+/u', ' ', $buf);
        $buf = preg_replace('/  +/u', ' ', $buf);
        $buf = trim($buf);

        return $buf;
    }

    private function render_table_with_images(DOMNode $tbl, DOMXPath $xp, $relToAttachment, array &$used_image_ids) {
        $rows = $xp->query('.//w:tr', $tbl);
        if ($rows->length === 0) return '';

        $outRows = [];

        foreach ($rows as $tr) {
            $cells = $xp->query('.//w:tc', $tr);
            if ($cells->length === 0) continue;

            $outCells = [];
            $hasContent = false;

            foreach ($cells as $tc) {
                $cellHTML = $this->build_inline_html($tc, $xp, $relToAttachment, $used_image_ids);
                $textOnly = trim(strip_tags($cellHTML));
                $hasImg   = (bool) preg_match('/<img\b/i', $cellHTML);

                if ($textOnly !== '' || $hasImg) {
                    $hasContent = true;
                }

                $outCells[] = '<td>' . $cellHTML . '</td>';
            }

            if ($hasContent) {
                $outRows[] = '<tr>' . implode('', $outCells) . '</tr>';
            }
        }

        if (empty($outRows)) return '';

        return "<table>\n<tbody>\n" . implode("\n", $outRows) . "\n</tbody>\n</table>";
    }

    private function parse_image_relationships($relsXml) {
        $map = [];
        if (!$relsXml) return $map;

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        libxml_use_internal_errors(true);
        if (!@$dom->loadXML($relsXml)) {
            libxml_clear_errors();
            return $map;
        }
        libxml_clear_errors();

        foreach ($dom->getElementsByTagName('Relationship') as $rel) {
            $type   = $rel->getAttribute('Type');
            $id     = $rel->getAttribute('Id');
            $target = $rel->getAttribute('Target');
            $mode   = strtolower($rel->getAttribute('TargetMode'));

            if (!$id || !$type || !preg_match('#/image$#', $type)) continue;

            if ($mode !== 'external') {
                $target = preg_replace('#^(\.\./)+#', '', $target ?: '');
                $target = ltrim($target, '/');
            }

            $map[$id] = ['target'=>$target, 'external'=>($mode === 'external')];
        }

        return $map;
    }

    private function get_zip_file($zipPath, $innerPath) {
        if (!class_exists('ZipArchive')) return null;

        $zip = new ZipArchive();
        $opened = @$zip->open($zipPath);
        if ($opened !== true) return null;

        try {
            $data = $zip->getFromName($innerPath);
        } finally {
            if ($zip instanceof ZipArchive) {
                @$zip->close();
            }
        }

        return ($data === false) ? null : $data;
    }

    private function parse_numbering_map($numXml) {
        $map = [];
        if (!$numXml) return $map;

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        libxml_use_internal_errors(true);
        if (!@$dom->loadXML($numXml)) {
            libxml_clear_errors();
            return $map;
        }
        libxml_clear_errors();

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $abstractMap = [];

        foreach ($xp->query('//w:abstractNum') as $abs) {
            $absId = $abs->getAttribute('w:abstractNumId');
            $fmtNode = $xp->query('.//w:lvl[@w:ilvl="0"]//w:numFmt', $abs)->item(0);
            if ($fmtNode && $fmtNode->hasAttribute('w:val')) {
                $abstractMap[$absId] = $fmtNode->getAttribute('w:val');
            }
        }

        foreach ($xp->query('//w:num') as $num) {
            $numId = $num->getAttribute('w:numId');
            $absNode = $xp->query('.//w:abstractNumId', $num)->item(0);
            if ($absNode && $absNode->hasAttribute('w:val')) {
                $absId = $absNode->getAttribute('w:val');
                if (isset($abstractMap[$absId])) {
                    $map[$numId] = $abstractMap[$absId];
                }
            }
        }

        return $map;
    }

    private function download_external_image_stream(string $url) {
        if (!preg_match('#^https?://#i', $url)) {
            return ['error' => 'invalid url'];
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $tmp = download_url($url, 30);
        if (is_wp_error($tmp)) {
            return ['error' => $tmp->get_error_message()];
        }

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            @unlink($tmp);
            return ['error' => 'uploads dir error'];
        }

        $name = basename(parse_url($url, PHP_URL_PATH) ?: 'image');
        if (!preg_match('/\.(jpe?g|png|gif|webp|bmp|tiff?)$/i', $name)) {
            $name .= '.jpg';
        }

        $dest = trailingslashit($uploads['path']) . wp_unique_filename($uploads['path'], $name);
        $moved = @rename($tmp, $dest);

        if (!$moved) {
            $copied = @copy($tmp, $dest);
            @unlink($tmp);
            if (!$copied) {
                return ['error' => 'move failed'];
            }
        }

        $urlOut = trailingslashit($uploads['url']) . basename($dest);
        return ['file' => $dest, 'url' => $urlOut];
    }

    /* ==================== Continue to Part 3... ==================== */

    /* ==================== SEO GENERATION ==================== */

    private function apply_seo_metadata(int $pid, string $title, string $preview, bool $useAI): void {
        $seo = $useAI ? $this->generate_yoast_seo($title, $preview) : null;

        if (!$seo || (empty($seo['seo_title']) && empty($seo['meta_description']) && empty($seo['focus_keyword']))) {
            $seo = $this->generate_seo_fallback($title, $preview);
            $this->dbg('SEO fallback used for post '.$pid);
        }

        $seo = $this->validate_and_enhance_seo($seo, $title, $preview);

        if (!empty($seo['seo_title'])) {
            update_post_meta($pid, '_yoast_wpseo_title', wp_strip_all_tags($seo['seo_title']));
        }
        if (!empty($seo['meta_description'])) {
            update_post_meta($pid, '_yoast_wpseo_metadesc', wp_strip_all_tags($seo['meta_description']));
        }
        if (!empty($seo['focus_keyword'])) {
            update_post_meta($pid, '_yoast_wpseo_focuskw', wp_strip_all_tags($seo['focus_keyword']));
        }

        $this->maybe_refresh_yoast_indexable($pid);
    }

    private function check_post_seo_complete(int $post_id): array {
        $meta_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
        $focus_kw = get_post_meta($post_id, '_yoast_wpseo_focuskw', true);

        return [
            'has_meta_desc' => !empty($meta_desc),
            'has_focus_kw' => !empty($focus_kw),
            'is_complete' => !empty($meta_desc) && !empty($focus_kw),
            'meta_desc' => $meta_desc,
            'focus_kw' => $focus_kw
        ];
    }

    public function regenerate_post_seo_ajax() {
        check_ajax_referer('dtp_regenerate_seo', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        if (!$post_id || !get_post($post_id)) {
            wp_send_json_error(['message' => 'Invalid post ID']);
        }

        try {
            $post = get_post($post_id);
            $title = $post->post_title;
            $content = wp_strip_all_tags($post->post_content);
            $preview = mb_substr($content, 0, 8000);

            $aiAvailable = ((int) get_option(self::OPT_SEO_ENABLED, 0) === 1 && get_option(self::OPT_OPENAI_KEY));
            $this->apply_seo_metadata($post_id, $title, $preview, $aiAvailable);

            $seo_check = $this->check_post_seo_complete($post_id);

            wp_send_json_success([
                'message' => 'SEO metadata regenerated successfully',
                'seo' => $seo_check
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    private function generate_yoast_seo($title, $plain_text) {
        $apiKey = trim((string) get_option(self::OPT_OPENAI_KEY, ''));
        $model  = trim((string) get_option(self::OPT_OPENAI_MODEL, 'gpt-4o-mini'));
        $lang   = trim((string) get_option(self::OPT_SEO_LANG, ''));

        if (!$apiKey || !$model) return null;

        $sys = "You are an SEO expert generating WordPress/Yoast SEO metadata. Respond ONLY with valid JSON containing these exact keys: seo_title, meta_description, focus_keyword.\n\n" .
               "CRITICAL RULES:\n" .
               "1. focus_keyword MUST be a 2-4 word phrase extracted directly from the title or main topic\n" .
               "2. focus_keyword should appear in BOTH seo_title AND meta_description\n" .
               "3. seo_title: max 60 characters, must include the focus_keyword\n" .
               "4. meta_description: 140-160 characters, must include focus_keyword naturally, compelling call-to-action style\n" .
               "5. No quotes inside JSON values, no special characters\n" .
               "6. Analyze the full content to identify the PRIMARY topic, then extract keyword from title related to that topic";

        if ($lang !== '') {
            $sys .= "\n7. Generate all metadata in language: {$lang}";
        }

        $content_preview = mb_substr($plain_text, 0, 4000);
        $word_count = str_word_count($content_preview);

        $prompt = "TITLE: {$title}\n\n" .
                  "CONTENT ({$word_count} words preview):\n{$content_preview}\n\n" .
                  "TASK: Identify the main keyword from the TITLE that best represents this content, then create SEO metadata that prominently features this keyword.";

        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $sys],
                ['role' => 'user',   'content' => $prompt]
            ],
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object']
        ];

        $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json'
            ],
            'timeout' => 45,
            'body' => wp_json_encode($body)
        ]);

        if (is_wp_error($resp)) {
            $this->dbg('OpenAI SEO error: ' . $resp->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($resp);
        $bodyStr = wp_remote_retrieve_body($resp);

        if ($code === 429) {
            $this->dbg('OpenAI rate limit hit (SEO) - will use fallback');
            return null;
        }

        if ($code !== 200) {
            $this->dbg('OpenAI SEO non-200: ' . $code);
            return null;
        }

        $json = json_decode($bodyStr, true);
        if (!is_array($json) || !isset($json['choices'][0]['message']['content'])) {
            $this->dbg('OpenAI SEO bad payload structure');
            return null;
        }

        $data = json_decode($json['choices'][0]['message']['content'], true);
        if (!is_array($data)) {
            $this->dbg('OpenAI SEO response not valid JSON object');
            return null;
        }

        $seo_title = isset($data['seo_title']) ? wp_strip_all_tags($data['seo_title']) : '';
        $meta_desc = isset($data['meta_description']) ? wp_strip_all_tags($data['meta_description']) : '';
        $focus_kw  = isset($data['focus_keyword']) ? wp_strip_all_tags($data['focus_keyword']) : '';

        $seo_title = mb_substr($seo_title, 0, 60);
        $meta_desc = mb_substr($meta_desc, 0, 160);

        return [
            'seo_title'        => $seo_title,
            'meta_description' => $meta_desc,
            'focus_keyword'    => $focus_kw
        ];
    }

    private function generate_seo_fallback(string $title, string $preview): array {
        $focus_kw = $this->extract_keyword_from_title($title);

        $seo_title = $title;
        if (mb_strlen($seo_title) > 60) {
            $seo_title = mb_substr($title, 0, 57) . '...';
        }

        $meta_desc = $this->generate_meta_description($preview, $focus_kw, 160);

        return [
            'seo_title'        => $seo_title,
            'meta_description' => $meta_desc,
            'focus_keyword'    => $focus_kw,
        ];
    }

    private function extract_keyword_from_title(string $title): string {
        $stop_words = ['the', 'a', 'an', 'to', 'of', 'for', 'and', 'or', 'on', 'in', 'with', 'from', 'by', 'at', 'as', 'is', 'are', 'be', 'how', 'what', 'why', 'when', 'where'];

        $words = preg_split('/[^a-z0-9]+/ui', mb_strtolower($title), -1, PREG_SPLIT_NO_EMPTY);
        $filtered = [];

        foreach ($words as $word) {
            if (!in_array($word, $stop_words, true) && mb_strlen($word) > 2) {
                $filtered[] = $word;
            }
        }

        $keyword_parts = array_slice($filtered, 0, 4);

        if (empty($keyword_parts)) {
            $keyword_parts = array_slice($words, 0, 3);
        }

        return ucwords(implode(' ', $keyword_parts));
    }

    private function generate_meta_description(string $content, string $keyword, int $max_length = 160): string {
        $content = wp_strip_all_tags($content);

        $sentences = preg_split('/[.!?]+/', $content);
        $best_sentence = '';

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (stripos($sentence, $keyword) !== false && mb_strlen($sentence) >= 50) {
                $best_sentence = $sentence;
                break;
            }
        }

        if (empty($best_sentence)) {
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);
                if (mb_strlen($sentence) >= 30) {
                    $best_sentence = $sentence;
                    break;
                }
            }
        }

        if (empty($best_sentence)) {
            $best_sentence = mb_substr($content, 0, $max_length);
        }

        if (stripos($best_sentence, $keyword) === false && mb_strlen($best_sentence) + mb_strlen($keyword) + 10 < $max_length) {
            $best_sentence = $keyword . ': ' . $best_sentence;
        }

        $description = mb_substr($best_sentence, 0, $max_length);

        if (mb_strlen($best_sentence) > $max_length) {
            $description = mb_substr($description, 0, $max_length - 3) . '...';
        }

        return $description;
    }

    private function validate_and_enhance_seo(array $seo, string $title, string $preview): array {
        $focus_kw = $seo['focus_keyword'] ?? '';
        $seo_title = $seo['seo_title'] ?? '';
        $meta_desc = $seo['meta_description'] ?? '';

        if (empty($focus_kw)) {
            $focus_kw = $this->extract_keyword_from_title($title);
            $seo['focus_keyword'] = $focus_kw;
        }

        if (!empty($focus_kw) && stripos($seo_title, $focus_kw) === false) {
            if (mb_strlen($focus_kw . ' - ' . $seo_title) <= 60) {
                $seo['seo_title'] = $focus_kw . ' - ' . $seo_title;
            }
        }

        if (!empty($focus_kw) && stripos($meta_desc, $focus_kw) === false) {
            if (mb_strlen($focus_kw . ': ' . $meta_desc) <= 160) {
                $seo['meta_description'] = $focus_kw . ': ' . $meta_desc;
            } else {
                $seo['meta_description'] = $this->generate_meta_description($preview, $focus_kw, 160);
            }
        }

        return $seo;
    }

    private function maybe_refresh_yoast_indexable(int $post_id): void {
        try {
            if (function_exists('YoastSEO')
                && class_exists('\Yoast\WP\SEO\Repositories\Indexable_Repository')
                && class_exists('\Yoast\WP\SEO\Builders\Indexable_Builder')) {

                $repo    = \YoastSEO()->classes->get(\Yoast\WP\SEO\Repositories\Indexable_Repository::class);
                $builder = \YoastSEO()->classes->get(\Yoast\WP\SEO\Builders\Indexable_Builder::class);

                $repo->for_post($post_id);
                $builder->build_for_post($post_id);
            }
        } catch (\Throwable $e) {
            $this->dbg('Yoast indexable refresh skipped: '.$e->getMessage());
        }
    }

    /* ==================== IMAGE ALT TEXT GENERATION ==================== */

    private function get_primary_keyword_for_post(int $post_id, string $title = ''): string {
        $yoast = (string) get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
        if ($yoast !== '') return trim($yoast);

        $title = $title !== '' ? $title : get_the_title($post_id);
        return $this->extract_keyword_from_title($title);
    }

    private function apply_featured_alt(int $post_id, int $attachment_id, string $keyword): void {
        if ($attachment_id <= 0 || $keyword === '') return;

        update_post_meta($attachment_id, '_wp_attachment_image_alt', $keyword);

        $src = wp_get_attachment_image_url($attachment_id, 'full');
        if (!$src) return;

        $post = get_post($post_id);
        if (!$post) return;

        $html = $post->post_content;
        if (stripos($html, $src) === false) return;

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);

        if (!@$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            libxml_clear_errors();
            return;
        }
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $imgs  = $xpath->query('//img[@src]');
        $changed = false;

        foreach ($imgs as $img) {
            if ($img->getAttribute('src') === $src) {
                $img->setAttribute('alt', $keyword);
                $changed = true;
            }
        }

        if ($changed) {
            $bodyNode = $dom->getElementsByTagName('body')->item(0);
            $newHtml = '';

            if ($bodyNode) {
                foreach ($bodyNode->childNodes as $child) {
                    $newHtml .= $dom->saveHTML($child);
                }
            }

            if ($newHtml) {
                wp_update_post(['ID'=>$post_id, 'post_content'=>$newHtml], false, false);
            }
        }
    }

    private function generate_image_alts_for_post(int $post_id): bool {
        $apiKey = trim((string) get_option(self::OPT_OPENAI_KEY, ''));
        $model  = trim((string) get_option(self::OPT_OPENAI_MODEL, 'gpt-4o-mini'));
        $lang   = trim((string) get_option(self::OPT_SEO_LANG, ''));
        $useAI  = ($apiKey && $model);

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') return false;

        $html = $post->post_content;
        if (trim($html) === '') return true;

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);

        if (!@$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
            libxml_clear_errors();
            return false;
        }
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $imgs  = $xpath->query('//img');

        if (!$imgs || $imgs->length === 0) return true;

        $featured_id  = (int) get_post_thumbnail_id($post_id);
        $primary_kw   = $this->get_primary_keyword_for_post($post_id, $post->post_title);

        if ($featured_id > 0 && $primary_kw !== '') {
            $this->apply_featured_alt($post_id, $featured_id, $primary_kw);
        }

        $targets = [];

        foreach ($imgs as $img) {
            $src = $img->getAttribute('src');
            if (!$src) continue;

            $aid = (int) attachment_url_to_postid($src);
            if ($aid > 0 && $aid === $featured_id) continue;

            $alt = trim($img->getAttribute('alt') ?? '');
            if ($alt !== '') continue;

            $ctx = $img->parentNode ? $img->parentNode->textContent : '';
            $ctx = mb_substr(preg_replace('/\s+/u', ' ', $ctx), 0, 240);
            $targets[] = ['node'=>$img, 'src'=>$src, 'aid'=>$aid, 'ctx'=>$ctx];
        }

        if (empty($targets)) return true;

        if (!$useAI) {
            foreach ($targets as $t) {
                $base = basename(parse_url($t['src'], PHP_URL_PATH));
                $base = preg_replace('/\.[^.]+$/', '', $base);
                $base = preg_replace('/[-_]+/', ' ', $base);
                $alt  = trim($primary_kw . ' ' . $base);
                $alt  = mb_substr($alt, 0, 120);

                if ($t['aid'] > 0) {
                    update_post_meta($t['aid'], '_wp_attachment_image_alt', $alt);
                }
                $t['node']->setAttribute('alt', $alt);
            }
        } else {
            $batch = array_slice($targets, 0, 12);
            $instructions =
                "You generate SEO-friendly HTML image alt text as short keyword-like phrases (2–5 words), ".
                "NOT full sentences, <= 120 characters. No surrounding quotes, no trailing punctuation. ".
                "Each phrase should be a related keyword to the primary topic, refined by the local context. ";

            if ($lang !== '') {
                $instructions .= "Language: {$lang}. ";
            }

            $inputs = [];
            foreach ($batch as $t) {
                $inputs[] = [
                    'primary_keyword' => $primary_kw,
                    'filename'        => basename(parse_url($t['src'], PHP_URL_PATH)),
                    'local_context'   => $t['ctx'],
                    'post_title'      => $post->post_title
                ];
            }

            $body = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $instructions],
                    ['role' => 'user',   'content' => "Return a JSON object with an 'alts' array containing ".count($inputs)." strings with related keyword phrases for these images:\n". wp_json_encode($inputs)]
                ],
                'temperature' => 0.2,
                'response_format' => ['type' => 'json_object']
            ];

            $resp = wp_remote_post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json'
                ],
                'timeout' => 45,
                'body'    => wp_json_encode($body)
            ]);

            $alts = [];

            if (!is_wp_error($resp)) {
                $code = wp_remote_retrieve_response_code($resp);
                $bodyStr = wp_remote_retrieve_body($resp);

                if ($code === 429) {
                    $this->dbg('OpenAI rate limit hit (ALT) - will use fallback');
                } elseif ($code === 200) {
                    $json = json_decode($bodyStr, true);

                    if (is_array($json) && isset($json['choices'][0]['message']['content'])) {
                        $data = json_decode($json['choices'][0]['message']['content'], true);

                        if (is_array($data)) {
                            if (isset($data['alts']) && is_array($data['alts'])) {
                                $alts = $data['alts'];
                            } elseif (isset($data[0])) {
                                $alts = array_values($data);
                            }
                        }
                    }
                }
            }

            for ($i = 0; $i < count($batch); $i++) {
                $t = $batch[$i];
                $alt = isset($alts[$i]) ? trim(wp_strip_all_tags((string)$alts[$i])) : '';

                if ($alt === '') {
                    $base = basename(parse_url($t['src'], PHP_URL_PATH));
                    $base = preg_replace('/\.[^.]+$/', '', $base);
                    $base = preg_replace('/[-_]+/', ' ', $base);
                    $alt  = trim($primary_kw . ' ' . $base);
                }

                $alt = mb_substr($alt, 0, 120);

                if ($t['aid'] > 0) {
                    update_post_meta($t['aid'], '_wp_attachment_image_alt', $alt);
                }
                $t['node']->setAttribute('alt', $alt);
            }
        }

        $bodyNode = $dom->getElementsByTagName('body')->item(0);
        if ($bodyNode) {
            $newHtml = '';
            foreach ($bodyNode->childNodes as $child) {
                $newHtml .= $dom->saveHTML($child);
            }

            if ($newHtml) {
                wp_update_post(['ID'=>$post_id, 'post_content'=>$newHtml], false, false);
            }
        }

        return true;
    }

    /* ==================== Continue to Part 4... ==================== */

    /* ==================== UTILITY FUNCTIONS ==================== */

    private function clean_title(string $title): string {
        $title = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $title);
        $title = preg_replace('/[\s\x{00A0}\x{1680}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', ' ', $title);
        $title = trim($title);
        $title = mb_substr($title, 0, 200);
        return $title ?: __('Untitled Document', 'docx-to-post');
    }

    private function sanitize_title_from_filename(string $filename): string {
        $title = preg_replace('/\.(docx|pdf)$/i', '', $filename);
        $title = preg_replace('/[_\-]+/', ' ', $title);
        return $this->clean_title($title);
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

    /* ==================== CRON & SCHEDULING ==================== */

    private function schedule_tick($delaySeconds = null) {
        if ((int)get_option(self::OPT_KILL, 0) === 1) return;

        $profile  = $this->compute_runtime_profile();
        $interval = $profile['interval'];
        $when     = time() + ($delaySeconds !== null ? max(1, (int)$delaySeconds) : $interval);

        $next = wp_next_scheduled(self::CRON_HOOK);
        if (!$next || $next <= time()) {
            if ($next) {
                wp_clear_scheduled_hook(self::CRON_HOOK);
            }
            wp_schedule_single_event($when, self::CRON_HOOK);
        }
        $this->kick_cron_now();
    }

    private function kick_cron_now() {
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
        wp_remote_post(site_url('wp-cron.php'), [
            'timeout'   => 0.01,
            'blocking'  => false,
            'user-agent'=> 'dtp-docx-importer',
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ]);
    }

    public function cron_watchdog() {
        if (!current_user_can('edit_posts')) return;
        if ((int)get_option(self::OPT_KILL, 0) === 1) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
            return;
        }

        $q = $this->get_queue();
        $hasPending = false;
        foreach ($q as $it) {
            if ($it['status'] === 'pending') {
                $hasPending = true;
                break;
            }
        }
        if (!$hasPending) return;

        $next = wp_next_scheduled(self::CRON_HOOK);
        if (!$next || $next <= time()) {
            $this->schedule_tick(1);
            $this->maybe_failsafe_tick();
        }
    }

    public function frontend_nudge() {
        if (is_admin()) return;
        if ((int)get_option(self::OPT_KILL, 0) === 1) return;

        $q = $this->get_queue();
        foreach ($q as $it) {
            if ($it['status'] === 'pending') {
                $next = wp_next_scheduled(self::CRON_HOOK);
                if (!$next || $next <= time()) {
                    $this->schedule_tick(2);
                }
                break;
            }
        }
    }

    private function maybe_failsafe_tick() {
        if (!current_user_can('edit_posts')) return;
        if ((int)get_option(self::OPT_KILL, 0) === 1) return;
        if (get_transient(self::FAILSAFE_CD_KEY)) return;

        set_transient(self::FAILSAFE_CD_KEY, 1, self::FAILSAFE_COOLDOWN);
        $override = ['batch' => self::FAILSAFE_BATCH, 'budget' => self::FAILSAFE_BUDGET, 'interval' => 30];
        $this->process_queue_tick($override);
    }

    public function process_now() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'docx-to-post'));
        }
        if (!isset($_POST['dtp_process_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dtp_process_nonce'])), 'dtp_process_now')) {
            wp_die(esc_html__('Security check failed.', 'docx-to-post'));
        }

        $processed = $this->process_queue_tick();
        $msg = sprintf(__('Processed %d file(s).', 'docx-to-post'), $processed);
        $url = add_query_arg(['page' => self::SLUG, 'dtp_notice' => rawurlencode($msg)], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    public function process_queue_cron() {
        $processed = $this->process_queue_tick();
        $q = $this->get_queue();

        foreach ($q as $it) {
            if ($it['status'] === 'pending') {
                $this->schedule_tick();
                break;
            }
        }
    }

    /* ==================== RUNTIME AUTO-TUNING ==================== */

    private function parse_bytes($val) {
        if (is_numeric($val)) return (int)$val;

        $val = trim((string)$val);
        if ($val === '') return 0;

        $last = strtolower(substr($val, -1));
        $num = (int)$val;

        switch ($last) {
            case 'g': return $num * 1024 * 1024 * 1024;
            case 'm': return $num * 1024 * 1024;
            case 'k': return $num * 1024;
            default:  return (int)$val;
        }
    }

    private function get_php_limits() {
        $met = (int)ini_get('max_execution_time');
        if ($met <= 0) $met = 60;

        $mem = $this->parse_bytes(ini_get('memory_limit'));
        if ($mem <= 0) $mem = 128 * 1024 * 1024;

        return ['met'=>$met,'mem'=>$mem];
    }

    private function compute_runtime_profile(): array {
        $limits = $this->get_php_limits();
        $budget = (int) floor(min(self::BUDGET_MAX, max(self::BUDGET_MIN, $limits['met'] * 0.7)));

        if ($limits['mem'] < 160*1024*1024) {
            $batch = 1;
        } elseif ($limits['mem'] < 320*1024*1024) {
            $batch = ($budget >= 40 ? 2 : 1);
        } else {
            $batch = ($budget >= 45 ? 3 : 2);
        }

        $st = get_option(self::OPT_RUNSTATE, []);
        $fast = (int)($st['fast_streak'] ?? 0);
        $slow = (int)($st['slow_streak'] ?? 0);
        $lastBatch = (int)($st['last_batch'] ?? $batch);

        if ($fast >= 3) {
            $batch = min(self::BATCH_MAX, $batch + 1);
        }
        if ($slow >= 1) {
            $batch = max(self::BATCH_MIN, min($batch, $lastBatch, self::BATCH_MAX));
        }

        $q = $this->get_queue();
        $pending = 0;
        foreach ($q as $it) {
            if (($it['status'] ?? '') === 'pending') {
                $pending++;
            }
        }

        if ($pending >= 10) {
            $interval = max(self::INTERVAL_MIN, 25);
        } elseif ($pending >= 3) {
            $interval = 30;
        } else {
            $interval = min(self::INTERVAL_MAX, 40);
        }

        return ['batch'=>$batch, 'budget'=>$budget, 'interval'=>$interval];
    }

    private function update_runtime_state(int $processed, float $elapsed, array $profile, bool $ranLong) {
        $st = get_option(self::OPT_RUNSTATE, []);
        $st = is_array($st) ? $st : [];

        $fastThisTick = ($processed >= $profile['batch'] && $elapsed <= ($profile['budget'] * 0.5));
        $slowThisTick = $ranLong || ($elapsed >= ($profile['budget'] * 0.95));

        $st['fast_streak'] = $fastThisTick ? (int)($st['fast_streak'] ?? 0) + 1 : 0;
        $st['slow_streak'] = $slowThisTick ? (int)($st['slow_streak'] ?? 0) + 1 : 0;
        $st['last_batch']  = $profile['batch'];
        $st['last_budget'] = $profile['budget'];
        $st['last_interval'] = $profile['interval'];
        $st['last_processed'] = $processed;
        $st['last_elapsed'] = $elapsed;

        update_option(self::OPT_RUNSTATE, $st, false);
    }

    /* ==================== MAINTENANCE ACTIONS ==================== */

    public function reset_queue() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'docx-to-post'));
        }
        if (!isset($_POST['dtp_reset_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dtp_reset_nonce'])), 'dtp_reset_queue')) {
            wp_die(esc_html__('Security check failed.', 'docx-to-post'));
        }

        update_option(self::OPT_KILL, 1, false);
        wp_clear_scheduled_hook(self::CRON_HOOK);
        delete_option(self::OPT_QUEUE);
        delete_option(self::OPT_LOCK);
        delete_option(self::OPT_RUNSTATE);
        delete_transient(self::FAILSAFE_CD_KEY);
        delete_transient(self::TR_LAST_SKIPPED);

        $msg = __('Queue cleared and stopped.', 'docx-to-post');
        $url = add_query_arg(['page' => self::SLUG, 'dtp_notice' => rawurlencode($msg)], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    public function export_csv() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'docx-to-post'));
        }
        check_admin_referer('dtp_export_csv');

        $type = isset($_GET['type']) ? sanitize_key($_GET['type']) : 'all';
        $rows = $this->get_queue();

        if ($type === 'errors') {
            $rows = array_values(array_filter($rows, fn($r) => ($r['status'] ?? '') === 'error'));
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=docx-import-report-' . gmdate('Ymd-His') . '-' . sanitize_file_name($type) . '.csv');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

        fputcsv($out, ['name','status','post_id','error','created_at','updated_at','file']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['name'] ?? '',
                $r['status'] ?? '',
                $r['post_id'] ?? '',
                $r['error'] ?? '',
                isset($r['created_at']) ? gmdate('c', $r['created_at']) : '',
                isset($r['updated_at']) ? gmdate('c', $r['updated_at']) : '',
                $r['file'] ?? ''
            ]);
        }
        fclose($out);
        exit;
    }

    public function requeue_errors() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'docx-to-post'));
        }
        check_admin_referer('dtp_requeue_errors');

        $q = $this->get_queue();
        $changed = 0;

        foreach ($q as &$r) {
            if (($r['status'] ?? '') === 'error') {
                $r['status'] = 'pending';
                $r['updated_at'] = time();
                $r['error'] = '';
                $changed++;
            }
        }
        unset($r);

        $this->save_queue($q);
        $this->schedule_tick(2);

        $msg = sprintf(__('Requeued %d error item(s).', 'docx-to-post'), $changed);
        $url = add_query_arg(['page' => self::SLUG, 'dtp_notice' => rawurlencode($msg)], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    public function clean_history() {
        if (!current_user_can('edit_posts')) {
            wp_die(esc_html__('You do not have sufficient permissions.', 'docx-to-post'));
        }
        if (!isset($_POST['dtp_clean_history_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dtp_clean_history_nonce'])), 'dtp_clean_history')) {
            wp_die(esc_html__('Security check failed.', 'docx-to-post'));
        }

        $q = $this->get_queue();
        $deleted = 0;

        foreach ($q as &$it) {
            $path = $it['file'] ?? '';
            if (!$path || !is_string($path)) continue;

            $status  = $it['status'] ?? '';
            $isDone = ($status === 'done');

            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $isSource = in_array($ext, ['docx','zip','pdf'], true);

            if ($isDone && $isSource && file_exists($path)) {
                if (@unlink($path)) {
                    $deleted++;
                    $it['file'] = '';
                }
            }
        }
        unset($it);

        $this->save_queue($q);

        $msg = sprintf(__('Cleaned %d file(s).', 'docx-to-post'), $deleted);
        $url = add_query_arg(['page' => self::SLUG, 'dtp_notice' => rawurlencode($msg)], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    /* ==================== LIVE PROGRESS (AJAX) ==================== */

    public function enqueue_live_progress_assets($hook) {
        if (strpos($hook, self::SLUG) === false) return;

        add_action('admin_footer', function() {
            include __DIR__ . '/admin-live-progress.php';
        });
    }

    public function ajax_get_queue_status() {
        check_ajax_referer('dtp_live_status', 'nonce');

        $q = $this->get_queue();
        $counts = $this->queue_counts($q);

        $formatted_queue = array_map(function($item) {
            $seo_check = null;
            if (!empty($item['post_id']) && $item['status'] === 'done') {
                $seo_check = $this->check_post_seo_complete($item['post_id']);
            }

            return [
                'file' => $item['file'] ?? '',
                'name' => $item['name'] ?? basename($item['file'] ?? ''),
                'status' => $item['status'] ?? '',
                'post_id' => $item['post_id'] ?? 0,
                'error' => $item['error'] ?? '',
                'created_at' => $item['created_at'] ? wp_date('Y-m-d H:i:s', $item['created_at']) : '',
                'updated_at' => $item['updated_at'] ? wp_date('Y-m-d H:i:s', $item['updated_at']) : '',
                'seo_complete' => $seo_check ? $seo_check['is_complete'] : true,
                'seo_details' => $seo_check,
                'retry_count' => (int)($item['retry_count'] ?? 0)
            ];
        }, $q);

        wp_send_json_success([
            'counts' => $counts,
            'queue' => $formatted_queue,
            'timestamp' => time()
        ]);
    }
}

register_activation_hook(__FILE__, ['DTP_Docx_To_Post_Pro', 'on_activate']);
new DTP_Docx_To_Post_Pro();
