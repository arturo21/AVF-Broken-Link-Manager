<?php
/**
 * Plugin Name:       AVF Broken Link Manager
 * Plugin URI:        https://example.com/avf-broken-link-manager
 * Description:       Plugin profesional para la detección y gestión por lotes de enlaces rotos con sistema de redirección 301 integrado, escaneos automáticos vía WP-Cron, registro de logs de auditoría, soporte multilingüe (WPML/Polylang) y exportación a CSV.
 * Version:           1.5.0
 * Author:            AVFDigital
 * Text Domain:       avf-broken-link-manager
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Clase Principal del Plugin AVF Broken Link Manager
 */
final class AVF_Broken_Link_Manager {

    /**
     * Instancia única (Singleton)
     */
    private static $instance = null;

    /**
     * Versión del plugin
     */
    const VERSION = '1.5.0';

    /**
     * Obtener instancia singleton
     */
    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor privado
     */
    private function __construct() {
        $this->define_constants();
        $this->init_hooks();
    }

    /**
     * Definición de constantes internas
     */
    private function define_constants() {
        define('AVF_BLM_VERSION', self::VERSION);
        define('AVF_BLM_PATH', plugin_dir_path(__FILE__));
        define('AVF_BLM_URL', plugin_dir_url(__FILE__));
        define('AVF_BLM_BASENAME', plugin_basename(__FILE__));
    }

    /**
     * Inicialización de Hooks de WordPress
     */
    private function init_hooks() {
        // Hooks de activación y desactivación
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Menú de Administración
        add_action('admin_menu', array($this, 'register_admin_menu'));

        // Carga de Scripts y Estilos de Administración
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Handlers de AJAX para el escaneo y gestión
        add_action('wp_ajax_avf_blm_init_scan', array($this, 'ajax_init_scan'));
        add_action('wp_ajax_avf_blm_process_batch', array($this, 'ajax_process_batch'));
        add_action('wp_ajax_avf_blm_add_redirect', array($this, 'ajax_add_redirect'));
        add_action('wp_ajax_avf_blm_ignore_link', array($this, 'ajax_ignore_link'));
        add_action('wp_ajax_avf_blm_delete_link', array($this, 'ajax_delete_link'));
        add_action('wp_ajax_avf_blm_delete_redirect', array($this, 'ajax_delete_redirect'));
        add_action('wp_ajax_avf_blm_reset_all', array($this, 'ajax_reset_all'));
        add_action('wp_ajax_avf_blm_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_avf_blm_clear_logs', array($this, 'ajax_clear_logs'));

        // Handlers de Exportación y Descarga
        add_action('admin_post_avf_blm_export_csv', array($this, 'export_broken_links_csv'));
        add_action('admin_post_avf_blm_download_logs', array($this, 'download_redirect_logs'));

        // WP-Cron Event
        add_action('avf_blm_cron_event', array($this, 'run_cron_scan'));
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));

        // Dashboard Widget
        add_action('wp_dashboard_setup', array($this, 'register_dashboard_widget'));

        // Hook de Redirección Automática en el Frontend
        add_action('template_redirect', array($this, 'handle_template_redirect'), 1);
    }

    /**
     * Añadir frecuencias personalizadas a WP-Cron (Mensual)
     */
    public function add_cron_schedules($schedules) {
        if (!isset($schedules['monthly'])) {
            $schedules['monthly'] = array(
                'interval' => 30 * DAY_IN_SECONDS,
                'display'  => __('Una vez al mes', 'avf-broken-link-manager')
            );
        }
        return $schedules;
    }

    /**
     * Activación del Plugin: Creación de tablas personalizadas con índices optimizados
     */
    public function activate() {
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $charset_collate = $wpdb->get_charset_collate();

        // 1. Tabla de Enlaces Rotos con índice compuesto para búsquedas ultra rápidas
        $table_broken = $wpdb->prefix . 'avf_broken_links';
        $sql_broken = "CREATE TABLE {$table_broken} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            url VARCHAR(2083) NOT NULL,
            http_code VARCHAR(50) NOT NULL,
            error_desc VARCHAR(255) NOT NULL,
            detected_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'broken',
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY status (status),
            KEY post_url (post_id, url(191))
        ) {$charset_collate};";
        dbDelta($sql_broken);

        // 2. Tabla de Redirecciones
        $table_redirects = $wpdb->prefix . 'avf_redirects';
        $sql_redirects = "CREATE TABLE {$table_redirects} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_url VARCHAR(2083) NOT NULL,
            target_url VARCHAR(2083) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY source_url (source_url(191))
        ) {$charset_collate};";
        dbDelta($sql_redirects);

        // Inicializar opciones predeterminadas
        if (!get_option('avf_blm_last_scan')) {
            add_option('avf_blm_last_scan', 'Nunca');
        }
        if (!get_option('avf_blm_total_scanned_links')) {
            add_option('avf_blm_total_scanned_links', 0);
        }
        if (!get_option('avf_blm_enable_logs')) {
            add_option('avf_blm_enable_logs', '1');
        }

        $this->ensure_log_dir_exists();
    }

    /**
     * Desactivación del Plugin
     */
    public function deactivate() {
        // Limpieza de estados de escaneo y tareas programadas
        delete_transient('avf_blm_last_id');
        delete_transient('avf_blm_total_posts');
        delete_transient('avf_blm_scanned_count');
        delete_transient('avf_blm_cron_lock');

        wp_clear_scheduled_hook('avf_blm_cron_event');
    }

    /**
     * Crea y asegura el directorio de logs plano con protección .htaccess e index.php
     */
    private function ensure_log_dir_exists() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/avf-blm-logs';

        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        // Archivos de seguridad
        $htaccess = $log_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all
");
        }

        $index = $log_dir . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php // Silence is golden
");
        }

        return $log_dir;
    }

    /**
     * Obtiene la ruta física del archivo de log
     */
    private function get_log_file_path() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/avf-blm-logs/redirects.log';
    }

    /**
     * Registra evento de redirección 301 en archivo plano con rotación automática a 5MB
     */
    private function log_redirect_event($source_url, $target_url) {
        if (get_option('avf_blm_enable_logs', '1') !== '1') {
            return;
        }

        $log_dir = $this->ensure_log_dir_exists();
        $log_file = $log_dir . '/redirects.log';

        // Rotación de Log si supera 5MB
        if (file_exists($log_file) && filesize($log_file) > 5 * 1024 * 1024) {
            @rename($log_file, $log_dir . '/redirects-' . date('Y-m-d-His') . '.log.bak');
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '0.0.0.0';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(substr($_SERVER['HTTP_USER_AGENT'], 0, 150)) : 'Unknown';
        $timestamp = current_time('Y-m-d H:i:s');

        $log_line = sprintf(
            "[%s] IP: %s | ORIGEN: %s => DESTINO: %s | UA: %s
",
            $timestamp,
            $ip,
            $source_url,
            $target_url,
            $ua
        );

        @file_put_contents($log_file, $log_line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Obtiene las últimas N líneas del archivo de log mediante lectura por bloques O(1) desde el final
     */
    private function get_recent_log_lines($lines_count = 50) {
        $log_file = $this->get_log_file_path();
        if (!file_exists($log_file) || !is_readable($log_file)) {
            return array();
        }

        $file = @fopen($log_file, 'rb');
        if (!$file) return array();

        $buffer = '';
        $chunk_size = 4096;
        fseek($file, 0, SEEK_END);
        $pos = ftell($file);

        while ($pos > 0 && substr_count($buffer, "
") <= $lines_count) {
            $read_size = min($pos, $chunk_size);
            $pos -= $read_size;
            fseek($file, $pos);
            $buffer = fread($file, $read_size) . $buffer;
        }

        fclose($file);

        $lines = array_filter(array_map('trim', explode("
", $buffer)));
        return array_slice($lines, -$lines_count);
    }

    /**
     * Detección automática del idioma del post (WPML / Polylang)
     */
    private function get_post_language($post_id) {
        if (function_exists('pll_get_post_language')) {
            $lang = pll_get_post_language($post_id, 'slug');
            if (!empty($lang)) return strtoupper($lang);
        }
        if (has_filter('wpml_post_language_details')) {
            $details = apply_filters('wpml_post_language_details', null, $post_id);
            if (!empty($details['language_code'])) return strtoupper($details['language_code']);
        }
        return '';
    }

    /**
     * Actualiza la programación del WP-Cron
     */
    private function update_cron_schedule($freq) {
        wp_clear_scheduled_hook('avf_blm_cron_event');

        if (in_array($freq, array('daily', 'weekly', 'monthly'), true)) {
            if (!wp_next_scheduled('avf_blm_cron_event')) {
                wp_schedule_event(time() + 60, $freq, 'avf_blm_cron_event');
            }
        }
    }

    /**
     * Registrar Widget en el Escritorio (Dashboard Widget)
     */
    public function register_dashboard_widget() {
        if (current_user_can('manage_options')) {
            wp_add_dashboard_widget(
                'avf_blm_dashboard_widget',
                __('AVF Broken Link Manager - Estado', 'avf-broken-link-manager'),
                array($this, 'render_dashboard_widget')
            );
        }
    }

    /**
     * Renderizado del Widget en el Escritorio
     */
    public function render_dashboard_widget() {
        global $wpdb;
        $table_broken = $wpdb->prefix . 'avf_broken_links';
        $table_redirects = $wpdb->prefix . 'avf_redirects';

        $total_broken = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_broken} WHERE status = 'broken'");
        $total_redirects = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_redirects}");
        $last_scan = get_option('avf_blm_last_scan', 'Nunca');

        $latest_broken = $wpdb->get_results("SELECT * FROM {$table_broken} WHERE status = 'broken' ORDER BY detected_at DESC LIMIT 3");
        $admin_url = admin_url('tools.php?page=avf-broken-link-manager');
        ?>
        <div class="avf-db-widget">
            <div style="display: flex; gap: 10px; margin-bottom: 15px; text-align: center;">
                <div style="flex: 1; background: #f0f6fc; padding: 10px; border-radius: 4px; border-top: 3px solid #2271b1;">
                    <span style="font-size: 20px; font-weight: bold; display: block; color: #1d2327;"><?php echo esc_html($total_broken); ?></span>
                    <span style="font-size: 11px; text-transform: uppercase; color: #646970;"><?php _e('Enlaces Rotos', 'avf-broken-link-manager'); ?></span>
                </div>
                <div style="flex: 1; background: #f0f6fc; padding: 10px; border-radius: 4px; border-top: 3px solid #00a32a;">
                    <span style="font-size: 20px; font-weight: bold; display: block; color: #1d2327;"><?php echo esc_html($total_redirects); ?></span>
                    <span style="font-size: 11px; text-transform: uppercase; color: #646970;"><?php _e('Redirecciones 301', 'avf-broken-link-manager'); ?></span>
                </div>
            </div>

            <p style="font-size: 12px; color: #646970; margin-bottom: 10px;">
                <strong><?php _e('Último Escaneo:', 'avf-broken-link-manager'); ?></strong> <?php echo esc_html($last_scan); ?>
            </p>

            <?php if (!empty($latest_broken)): ?>
                <h4 style="margin: 10px 0 5px 0; font-size: 12px; font-weight: 600;"><?php _e('Últimos enlaces detectados:', 'avf-broken-link-manager'); ?></h4>
                <ul style="margin: 0 0 15px 0; padding: 0; list-style: none;">
                    <?php foreach ($latest_broken as $item): ?>
                        <li style="padding: 6px 0; border-bottom: 1px solid #f0f0f1; font-size: 12px;">
                            <span style="background: #d63638; color: #fff; padding: 1px 5px; border-radius: 3px; font-size: 10px; font-weight: bold;"><?php echo esc_html($item->http_code); ?></span>
                            <?php $lang = $this->get_post_language($item->post_id); ?>
                            <?php if (!empty($lang)): ?>
                                <span style="background: #2271b1; color: #fff; padding: 1px 4px; border-radius: 3px; font-size: 9px; font-weight: bold;"><?php echo esc_html($lang); ?></span>
                            <?php endif; ?>
                            <a href="<?php echo esc_url($item->url); ?>" target="_blank" style="word-break: break-all; text-decoration: none;"><?php echo esc_html(mb_strimwidth($item->url, 0, 45, '...')); ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p style="font-size: 12px; color: #00a32a; font-weight: 500; margin-bottom: 15px;">
                    <span class="dashicons dashicons-yes-alt" style="vertical-align: middle;"></span> <?php _e('¡Genial! No hay enlaces rotos pendientes.', 'avf-broken-link-manager'); ?>
                </p>
            <?php endif; ?>

            <a href="<?php echo esc_url($admin_url); ?>" class="button button-primary" style="width: 100%; text-align: center; box-sizing: border-box;">
                <?php _e('Ir al Panel de Gestión', 'avf-broken-link-manager'); ?>
            </a>
        </div>
        <?php
    }

    /**
     * Registrar subpágina bajo el menú "Herramientas" (Tools)
     */
    public function register_admin_menu() {
        add_management_page(
            __('AVF Broken Link Manager', 'avf-broken-link-manager'),
            __('AVF Link Manager', 'avf-broken-link-manager'),
            'manage_options',
            'avf-broken-link-manager',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Encola Assets de Administración (CSS y JavaScript)
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'tools_page_avf-broken-link-manager') {
            return;
        }

        $custom_css = "
            .avf-wrap { margin: 20px 20px 0 0; }
            .avf-header { background: #fff; padding: 20px; border-left: 4px solid #2271b1; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; border-radius: 4px; }
            .avf-header h1 { margin: 0 0 10px 0; font-size: 22px; color: #1d2327; }
            .avf-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 25px; }
            .avf-stat-card { background: #fff; padding: 15px 20px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); border-top: 3px solid #2271b1; }
            .avf-stat-card.red { border-top-color: #d63638; }
            .avf-stat-card.green { border-top-color: #00a32a; }
            .avf-stat-card .number { font-size: 26px; font-weight: 700; color: #1d2327; margin-top: 5px; }
            .avf-stat-card .label { font-size: 12px; font-weight: 600; text-transform: uppercase; color: #646970; }
            .avf-progress-bar-bg { background: #e0e0e0; height: 22px; border-radius: 11px; overflow: hidden; margin: 15px 0 10px 0; }
            .avf-progress-bar-fill { background: #2271b1; height: 100%; width: 0%; transition: width 0.3s ease; text-align: center; color: #fff; font-size: 11px; line-height: 22px; font-weight: bold; }
            .avf-card { background: #fff; padding: 20px; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); margin-bottom: 25px; }
            .avf-settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px; }
            @media (max-width: 960px) { .avf-settings-grid { grid-template-columns: 1fr; } }
            .avf-badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; color: #fff; }
            .avf-badge-danger { background-color: #d63638; }
            .avf-badge-warning { background-color: #dba617; color: #1d2327; }
            .avf-badge-success { background-color: #00a32a; }
            .avf-badge-info { background-color: #2271b1; }
            .avf-actions-btn { gap: 5px; display: flex; flex-wrap: wrap; }
            .tab-content { display: none; }
            .tab-content.active { display: block; }
            .nav-tab-wrapper { margin-bottom: 20px; }
            .avf-log-box { background: #1e1e1e; color: #00ff66; font-family: monospace; font-size: 11px; padding: 12px; border-radius: 4px; max-height: 180px; overflow-y: auto; white-space: pre-wrap; line-height: 1.4; }
        ";
        wp_register_style('avf-blm-admin-css', false);
        wp_enqueue_style('avf-blm-admin-css');
        wp_add_inline_style('avf-blm-admin-css', $custom_css);

        wp_enqueue_script('jquery');
    }

    /**
     * Renderizado del Panel de Administración
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes suficientes permisos para acceder a esta página.', 'avf-broken-link-manager'));
        }

        global $wpdb;
        $table_broken = $wpdb->prefix . 'avf_broken_links';
        $table_redirects = $wpdb->prefix . 'avf_redirects';

        // Obtención de Estadísticas
        $total_posts = $this->count_scanable_posts();
        $total_broken = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_broken} WHERE status = 'broken'");
        $total_redirects = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_redirects}");
        $last_scan = get_option('avf_blm_last_scan', 'Nunca');
        $total_scanned_links = get_option('avf_blm_total_scanned_links', 0);
        $cron_freq = get_option('avf_blm_cron_freq', 'disabled');
        $email_notify = get_option('avf_blm_email_notify', '1');
        $enable_logs = get_option('avf_blm_enable_logs', '1');

        // Obtener Lista con límite de seguridad (250)
        $broken_links = $wpdb->get_results("SELECT * FROM {$table_broken} WHERE status = 'broken' ORDER BY detected_at DESC LIMIT 250");
        $redirects = $wpdb->get_results("SELECT * FROM {$table_redirects} ORDER BY created_at DESC LIMIT 250");

        $recent_logs = $this->get_recent_log_lines(50);

        $nonce = wp_create_nonce('avf_blm_nonce');
        $export_nonce = wp_create_nonce('avf_blm_export_csv_nonce');
        $log_nonce = wp_create_nonce('avf_blm_log_nonce');
        ?>
        <div class="wrap avf-wrap">
            <div class="avf-header">
                <h1><span class="dashicons dashicons-admin-links" style="font-size:26px; height:26px; width:26px; vertical-align:middle; margin-right:5px;"></span> AVF Broken Link Manager</h1>
                <p><?php _e('Monitorea, detecta enlaces rotos en tu contenido y gestiona redirecciones 301 de manera segura y eficiente con soporte multilingüe.', 'avf-broken-link-manager'); ?></p>
            </div>

            <!-- Tarjetas de Estadísticas -->
            <div class="avf-stats-grid">
                <div class="avf-stat-card">
                    <div class="label"><?php _e('Entradas y Páginas', 'avf-broken-link-manager'); ?></div>
                    <div class="number"><?php echo esc_html($total_posts); ?></div>
                </div>
                <div class="avf-stat-card">
                    <div class="label"><?php _e('Enlaces Analizados', 'avf-broken-link-manager'); ?></div>
                    <div class="number" id="avf-stat-scanned"><?php echo esc_html($total_scanned_links); ?></div>
                </div>
                <div class="avf-stat-card red">
                    <div class="label"><?php _e('Enlaces Rotos', 'avf-broken-link-manager'); ?></div>
                    <div class="number" id="avf-stat-broken"><?php echo esc_html($total_broken); ?></div>
                </div>
                <div class="avf-stat-card green">
                    <div class="label"><?php _e('Redirecciones Activas', 'avf-broken-link-manager'); ?></div>
                    <div class="number" id="avf-stat-redirects"><?php echo esc_html($total_redirects); ?></div>
                </div>
                <div class="avf-stat-card">
                    <div class="label"><?php _e('Último Escaneo', 'avf-broken-link-manager'); ?></div>
                    <div class="number" style="font-size: 15px; margin-top: 10px;" id="avf-stat-lastscan"><?php echo esc_html($last_scan); ?></div>
                </div>
            </div>

            <div class="avf-settings-grid">
                <!-- Control del Escáner por AJAX -->
                <div class="avf-card">
                    <h2><?php _e('Escaneo Manual por Lotes', 'avf-broken-link-manager'); ?></h2>
                    <p><?php _e('Ejecuta un escaneo bajo demanda procesando entradas por lotes para no saturar el servidor.', 'avf-broken-link-manager'); ?></p>
                    
                    <div style="margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
                        <button id="btn-start-scan" class="button button-primary button-large">
                            <span class="dashicons dashicons-search" style="vertical-align: text-bottom;"></span> <?php _e('Iniciar Escaneo Completo', 'avf-broken-link-manager'); ?>
                        </button>
                        
                        <a href="<?php echo esc_url(admin_url('admin-post.php?action=avf_blm_export_csv&_wpnonce=' . $export_nonce)); ?>" class="button button-secondary button-large">
                            <span class="dashicons dashicons-download" style="vertical-align: text-bottom;"></span> <?php _e('Exportar CSV', 'avf-broken-link-manager'); ?>
                        </a>

                        <button id="btn-reset-all" class="button button-secondary button-large">
                            <span class="dashicons dashicons-trash" style="vertical-align: text-bottom;"></span> <?php _e('Limpiar Registros', 'avf-broken-link-manager'); ?>
                        </button>
                    </div>

                    <div id="avf-progress-container" style="display:none; margin-top: 20px;">
                        <strong><?php _e('Progreso del Escaneo:', 'avf-broken-link-manager'); ?> <span id="avf-progress-text">0%</span></strong>
                        <div class="avf-progress-bar-bg">
                            <div id="avf-progress-bar-fill" class="avf-progress-bar-fill">0%</div>
                        </div>
                        <p id="avf-status-message" style="font-style: italic; color: #646970; font-size: 13px;"></p>
                    </div>
                </div>

                <!-- Configuración de Escaneo Automático y Logs -->
                <div class="avf-card">
                    <h2><?php _e('Escaneo Automático y Logs 301', 'avf-broken-link-manager'); ?></h2>
                    <p><?php _e('Programa revisiones en segundo plano y configura la auditoría de redirecciones.', 'avf-broken-link-manager'); ?></p>
                    
                    <form id="form-avf-settings" style="margin-top: 15px;">
                        <p>
                            <label for="avf_blm_cron_freq"><strong><?php _e('Frecuencia de Escaneo (WP-Cron):', 'avf-broken-link-manager'); ?></strong></label><br>
                            <select id="avf_blm_cron_freq" name="cron_freq" class="regular-text">
                                <option value="disabled" <?php selected($cron_freq, 'disabled'); ?>><?php _e('Desactivado (Solo Manual)', 'avf-broken-link-manager'); ?></option>
                                <option value="daily" <?php selected($cron_freq, 'daily'); ?>><?php _e('Diario', 'avf-broken-link-manager'); ?></option>
                                <option value="weekly" <?php selected($cron_freq, 'weekly'); ?>><?php _e('Semanal', 'avf-broken-link-manager'); ?></option>
                                <option value="monthly" <?php selected($cron_freq, 'monthly'); ?>><?php _e('Mensual', 'avf-broken-link-manager'); ?></option>
                            </select>
                        </p>
                        <p>
                            <label for="avf_blm_email_notify">
                                <input type="checkbox" id="avf_blm_email_notify" name="email_notify" value="1" <?php checked($email_notify, '1'); ?>>
                                <?php _e('Enviar alerta por correo electrónico al detectar nuevos enlaces rotos.', 'avf-broken-link-manager'); ?>
                            </label>
                        </p>
                        <p>
                            <label for="avf_blm_enable_logs">
                                <input type="checkbox" id="avf_blm_enable_logs" name="enable_logs" value="1" <?php checked($enable_logs, '1'); ?>>
                                <?php _e('Activar registro de auditoría de redirecciones 301 en archivo plano (.log).', 'avf-broken-link-manager'); ?>
                            </label>
                        </p>
                        <button type="submit" class="button button-secondary">
                            <span class="dashicons dashicons-saved" style="vertical-align: text-bottom;"></span> <?php _e('Guardar Configuración', 'avf-broken-link-manager'); ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Visor de Logs de Auditoría 301 -->
            <div class="avf-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h3 style="margin: 0;"><?php _e('Registro de Auditoría de Redirecciones 301 (Últimos Eventos)', 'avf-broken-link-manager'); ?></h3>
                    <div>
                        <a href="<?php echo esc_url(admin_url('admin-post.php?action=avf_blm_download_logs&_wpnonce=' . $log_nonce)); ?>" class="button button-small">
                            <span class="dashicons dashicons-download" style="vertical-align: text-bottom;"></span> <?php _e('Descargar .log', 'avf-broken-link-manager'); ?>
                        </a>
                        <button id="btn-clear-logs" class="button button-small button-link-delete">
                            <?php _e('Vaciar Logs', 'avf-broken-link-manager'); ?>
                        </button>
                    </div>
                </div>
                <div class="avf-log-box">
                    <?php if (!empty($recent_logs)): ?>
                        <?php foreach ($recent_logs as $log_line): ?>
                            <?php echo esc_html($log_line) . "
"; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php _e('No hay eventos registrados en el log actualmente.', 'avf-broken-link-manager'); ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pestañas de Navegación -->
            <h2 class="nav-tab-wrapper">
                <a href="#tab-broken-links" class="nav-tab nav-tab-active" onclick="avfSwitchTab(event, 'tab-broken-links')"><?php _e('Enlaces Rotos Detectados', 'avf-broken-link-manager'); ?> (<span id="count-broken-tab"><?php echo count($broken_links); ?></span>)</a>
                <a href="#tab-redirects" class="nav-tab" onclick="avfSwitchTab(event, 'tab-redirects')"><?php _e('Redirecciones Integradas (301)', 'avf-broken-link-manager'); ?> (<span id="count-redirects-tab"><?php echo count($redirects); ?></span>)</a>
            </h2>

            <!-- Pestaña 1: Enlaces Rotos -->
            <div id="tab-broken-links" class="tab-content active">
                <div class="avf-card">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 20%;"><?php _e('Origen (Post / Página)', 'avf-broken-link-manager'); ?></th>
                                <th style="width: 30%;"><?php _e('URL Rota', 'avf-broken-link-manager'); ?></th>
                                <th style="width: 15%;"><?php _e('Código / Estado', 'avf-broken-link-manager'); ?></th>
                                <th style="width: 15%;"><?php _e('Detectado', 'avf-broken-link-manager'); ?></th>
                                <th style="width: 20%;"><?php _e('Acciones', 'avf-broken-link-manager'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="avf-broken-links-tbody">
                            <?php if (empty($broken_links)): ?>
                                <tr class="no-items"><td colspan="5"><?php _e('No se han encontrado enlaces rotos.', 'avf-broken-link-manager'); ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($broken_links as $item): ?>
                                    <tr id="row-link-<?php echo esc_attr($item->id); ?>">
                                        <td>
                                            <strong><a href="<?php echo esc_url(get_edit_post_link($item->post_id)); ?>" target="_blank"><?php echo esc_html(get_the_title($item->post_id)); ?></a></strong>
                                            <?php $lang = $this->get_post_language($item->post_id); ?>
                                            <?php if (!empty($lang)): ?>
                                                <span class="avf-badge avf-badge-info"><?php echo esc_html($lang); ?></span>
                                            <?php endif; ?>
                                            <br><small>ID: <?php echo esc_html($item->post_id); ?></small>
                                        </td>
                                        <td>
                                            <a href="<?php echo esc_url($item->url); ?>" target="_blank" rel="noopener" style="word-break: break-all;"><?php echo esc_html($item->url); ?></a>
                                        </td>
                                        <td>
                                            <span class="avf-badge avf-badge-danger"><?php echo esc_html($item->http_code); ?></span>
                                            <br><small style="color:#646970;"><?php echo esc_html($item->error_desc); ?></small>
                                        </td>
                                        <td><?php echo esc_html($item->detected_at); ?></td>
                                        <td class="avf-actions-btn">
                                            <button class="button button-small button-primary" onclick="avfPromptRedirect('<?php echo esc_js($item->url); ?>', <?php echo esc_attr($item->id); ?>)">
                                                <?php _e('Redirigir', 'avf-broken-link-manager'); ?>
                                            </button>
                                            <button class="button button-small" onclick="avfIgnoreLink(<?php echo esc_attr($item->id); ?>)">
                                                <?php _e('Ignorar', 'avf-broken-link-manager'); ?>
                                            </button>
                                            <button class="button button-small button-link-delete" onclick="avfDeleteLink(<?php echo esc_attr($item->id); ?>)">
                                                <?php _e('Eliminar', 'avf-broken-link-manager'); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pestaña 2: Redirecciones 301 -->
            <div id="tab-redirects" class="tab-content">
                <div class="avf-card">
                    <h3><?php _e('Añadir Nueva Redirección Manual', 'avf-broken-link-manager'); ?></h3>
                    <div style="display:flex; gap:10px; margin-bottom: 20px; align-items:center;">
                        <input type="url" id="manual-source-url" placeholder="https://tusitio.com/url-origen-rota" style="width:40%;" />
                        <span class="dashicons dashicons-arrow-right-alt"></span>
                        <input type="url" id="manual-target-url" placeholder="https://tusitio.com/url-destino-valida" style="width:40%;" />
                        <button class="button button-primary" onclick="avfAddManualRedirect()"><?php _e('Guardar Redirección 301', 'avf-broken-link-manager'); ?></button>
                    </div>

                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 40%;"><?php _e('URL Origen (Rota)', 'avf-broken-link-manager'); ?></th>
                                <th style="width: 40%;"><?php _e('URL Destino (Redirección 301)', 'avf-broken-link-manager'); ?></th>
                                <th style="width: 10%;"><?php _e('Fecha', 'avf-broken-link-manager'); ?></th>
                                <th style="width: 10%;"><?php _e('Acción', 'avf-broken-link-manager'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="avf-redirects-tbody">
                            <?php if (empty($redirects)): ?>
                                <tr class="no-items"><td colspan="4"><?php _e('No hay redirecciones registradas.', 'avf-broken-link-manager'); ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($redirects as $red): ?>
                                    <tr id="row-redirect-<?php echo esc_attr($red->id); ?>">
                                        <td style="word-break: break-all;"><?php echo esc_html($red->source_url); ?></td>
                                        <td style="word-break: break-all;"><a href="<?php echo esc_url($red->target_url); ?>" target="_blank"><?php echo esc_html($red->target_url); ?></a></td>
                                        <td><?php echo esc_html($red->created_at); ?></td>
                                        <td>
                                            <button class="button button-small button-link-delete" onclick="avfDeleteRedirect(<?php echo esc_attr($red->id); ?>)">
                                                <?php _e('Eliminar', 'avf-broken-link-manager'); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Inline JavaScript -->
        <script type="text/javascript">
            const avfNonce = '<?php echo esc_js($nonce); ?>';

            function avfExtractErrorMessage(data) {
                if (typeof data === 'string') return data;
                if (data && typeof data === 'object' && data.message) return data.message;
                return 'Error desconocido en el servidor.';
            }

            function avfDecrementBrokenCounter() {
                let countElem = jQuery('#count-broken-tab');
                let current = parseInt(countElem.text(), 10);
                if (!isNaN(current) && current > 0) countElem.text(current - 1);
                
                let statElem = jQuery('#avf-stat-broken');
                let statCurrent = parseInt(statElem.text(), 10);
                if (!isNaN(statCurrent) && statCurrent > 0) statElem.text(statCurrent - 1);
            }

            function avfSwitchTab(evt, tabId) {
                evt.preventDefault();
                jQuery('.nav-tab').removeClass('nav-tab-active');
                jQuery(evt.currentTarget).addClass('nav-tab-active');
                jQuery('.tab-content').removeClass('active');
                jQuery('#' + tabId).addClass('active');
            }

            jQuery(document).ready(function($) {

                // Guardar Configuración
                $('#form-avf-settings').on('submit', function(e) {
                    e.preventDefault();
                    $.post(ajaxurl, {
                        action: 'avf_blm_save_settings',
                        security: avfNonce,
                        cron_freq: $('#avf_blm_cron_freq').val(),
                        email_notify: $('#avf_blm_email_notify').is(':checked') ? 1 : 0,
                        enable_logs: $('#avf_blm_enable_logs').is(':checked') ? 1 : 0
                    }, function(res) {
                        if (res.success) alert('Configuración guardada correctamente.');
                        else alert('Error: ' + avfExtractErrorMessage(res.data));
                    });
                });

                // Vaciar Logs
                $('#btn-clear-logs').on('click', function() {
                    if (!confirm('¿Seguro que deseas borrar el archivo de logs de auditoría?')) return;
                    $.post(ajaxurl, { action: 'avf_blm_clear_logs', security: avfNonce }, function(res) {
                        if (res.success) location.reload();
                    });
                });

                // Iniciar Escaneo por Lotes
                $('#btn-start-scan').on('click', function() {
                    if (!confirm('¿Deseas iniciar el escaneo completo?')) return;

                    $('#btn-start-scan').prop('disabled', true);
                    $('#avf-progress-container').show();
                    $('#avf-progress-bar-fill').css('width', '0%').text('0%');
                    $('#avf-progress-text').text('0%');
                    $('#avf-status-message').text('Inicializando cola de análisis...');

                    $.post(ajaxurl, {
                        action: 'avf_blm_init_scan',
                        security: avfNonce
                    }, function(response) {
                        if (response.success) {
                            avfProcessBatch(response.data.total);
                        } else {
                            alert('Error: ' + avfExtractErrorMessage(response.data));
                            $('#btn-start-scan').prop('disabled', false);
                        }
                    });
                });

                function avfProcessBatch(totalPosts) {
                    $.post(ajaxurl, {
                        action: 'avf_blm_process_batch',
                        security: avfNonce
                    }, function(response) {
                        if (response.success) {
                            let data = response.data;
                            let percent = totalPosts > 0 ? Math.min(100, Math.round((data.scanned / totalPosts) * 100)) : 100;

                            $('#avf-progress-bar-fill').css('width', percent + '%').text(percent + '%');
                            $('#avf-progress-text').text(percent + '%');
                            $('#avf-status-message').text('Procesadas ' + data.scanned + ' de ' + totalPosts + ' entradas. Enlaces rotos: ' + data.broken_found);

                            if (data.remaining > 0) {
                                avfProcessBatch(totalPosts);
                            } else {
                                $('#avf-progress-bar-fill').css('background-color', '#00a32a');
                                $('#avf-status-message').text('¡Escaneo completado exitosamente!');
                                $('#btn-start-scan').prop('disabled', false);
                                setTimeout(function() { location.reload(); }, 1200);
                            }
                        } else {
                            alert('Error: ' + avfExtractErrorMessage(response.data));
                            $('#btn-start-scan').prop('disabled', false);
                        }
                    });
                }

                $('#btn-reset-all').on('click', function() {
                    if (!confirm('¿Seguro que deseas vaciar los registros de enlaces rotos?')) return;

                    $.post(ajaxurl, {
                        action: 'avf_blm_reset_all',
                        security: avfNonce
                    }, function(res) {
                        if (res.success) location.reload();
                        else alert('Error al reiniciar.');
                    });
                });
            });

            function avfPromptRedirect(sourceUrl, linkId) {
                let targetUrl = prompt('Ingresa la nueva URL de destino para la redirección 301:', 'https://');
                if (targetUrl && targetUrl !== 'https://') {
                    jQuery.post(ajaxurl, {
                        action: 'avf_blm_add_redirect',
                        security: avfNonce,
                        source_url: sourceUrl,
                        target_url: targetUrl,
                        link_id: linkId
                    }, function(response) {
                        if (response.success) {
                            alert('Redirección creada correctamente.');
                            jQuery('#row-link-' + linkId).fadeOut();
                            avfDecrementBrokenCounter();
                        } else {
                            alert('Error: ' + avfExtractErrorMessage(response.data));
                        }
                    });
                }
            }

            function avfAddManualRedirect() {
                let source = jQuery('#manual-source-url').val();
                let target = jQuery('#manual-target-url').val();

                if (!source || !target) {
                    alert('Ingresa las URLs de origen y destino.');
                    return;
                }

                jQuery.post(ajaxurl, {
                    action: 'avf_blm_add_redirect',
                    security: avfNonce,
                    source_url: source,
                    target_url: target,
                    link_id: 0
                }, function(response) {
                    if (response.success) {
                        alert('Redirección guardada correctamente.');
                        location.reload();
                    } else {
                        alert('Error: ' + avfExtractErrorMessage(response.data));
                    }
                });
            }

            function avfIgnoreLink(linkId) {
                if (!confirm('¿Marcar como ignorado?')) return;
                jQuery.post(ajaxurl, { action: 'avf_blm_ignore_link', security: avfNonce, link_id: linkId }, function(res) {
                    if (res.success) {
                        jQuery('#row-link-' + linkId).fadeOut();
                        avfDecrementBrokenCounter();
                    }
                });
            }

            function avfDeleteLink(linkId) {
                if (!confirm('¿Eliminar registro?')) return;
                jQuery.post(ajaxurl, { action: 'avf_blm_delete_link', security: avfNonce, link_id: linkId }, function(res) {
                    if (res.success) {
                        jQuery('#row-link-' + linkId).fadeOut();
                        avfDecrementBrokenCounter();
                    }
                });
            }

            function avfDeleteRedirect(redirectId) {
                if (!confirm('¿Eliminar redirección 301?')) return;
                jQuery.post(ajaxurl, { action: 'avf_blm_delete_redirect', security: avfNonce, redirect_id: redirectId }, function(res) {
                    if (res.success) jQuery('#row-redirect-' + redirectId).fadeOut();
                });
            }
        </script>
        <?php
    }

    /**
     * AJAX 1: Inicialización del Escaneo (Cursor ID = 0)
     */
    public function ajax_init_scan() {
        check_ajax_referer('avf_blm_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permisos insuficientes.', 'avf-broken-link-manager'));
        }

        $total = $this->count_scanable_posts();

        set_transient('avf_blm_last_id', 0, HOUR_IN_SECONDS);
        set_transient('avf_blm_total_posts', $total, HOUR_IN_SECONDS);
        set_transient('avf_blm_scanned_count', 0, HOUR_IN_SECONDS);

        wp_send_json_success(array('total' => $total));
    }

    /**
     * AJAX 2: Procesamiento por Lotes mediante Paginación por Cursor
     */
    public function ajax_process_batch() {
        check_ajax_referer('avf_blm_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permisos insuficientes.', 'avf-broken-link-manager'));
        }

        $last_id = (int) get_transient('avf_blm_last_id');
        $total_posts = (int) get_transient('avf_blm_total_posts');
        $scanned_count = (int) get_transient('avf_blm_scanned_count');

        $post_types = get_post_types(array('public' => true), 'names');
        if (empty($post_types)) {
            wp_send_json_success(array('scanned' => $total_posts, 'remaining' => 0, 'broken_found' => 0));
        }

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        $sql_args = array_merge(array_values($post_types), array($last_id));

        $query = $wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts} 
            WHERE post_type IN ({$placeholders}) 
            AND post_status = 'publish' 
            AND ID > %d 
            ORDER BY ID ASC LIMIT 10",
            $sql_args
        );

        $posts = $wpdb->get_results($query);

        if (empty($posts)) {
            update_option('avf_blm_last_scan', current_time('mysql'));
            delete_transient('avf_blm_last_id');
            wp_send_json_success(array(
                'scanned' => $total_posts,
                'remaining' => 0,
                'broken_found' => $this->get_broken_count()
            ));
        }

        $table_broken = $wpdb->prefix . 'avf_broken_links';
        $total_scanned_links = (int) get_option('avf_blm_total_scanned_links', 0);
        $new_last_id = $last_id;

        foreach ($posts as $post_obj) {
            $new_last_id = (int) $post_obj->ID;
            if (empty($post_obj->post_content)) continue;

            $links = $this->extract_links_from_html($post_obj->post_content);

            foreach ($links as $url) {
                $total_scanned_links++;
                $check = $this->check_url_http_status($url);

                if ($check['is_broken']) {
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$table_broken} WHERE post_id = %d AND url = %s",
                        $post_obj->ID,
                        $url
                    ));

                    if (!$exists) {
                        $wpdb->insert(
                            $table_broken,
                            array(
                                'post_id' => $post_obj->ID,
                                'url' => esc_url_raw($url),
                                'http_code' => sanitize_text_field($check['code']),
                                'error_desc' => sanitize_text_field($check['desc']),
                                'detected_at' => current_time('mysql'),
                                'status' => 'broken'
                            ),
                            array('%d', '%s', '%s', '%s', '%s', '%s')
                        );
                    }
                }
            }
        }

        $scanned_count += count($posts);
        set_transient('avf_blm_last_id', $new_last_id, HOUR_IN_SECONDS);
        set_transient('avf_blm_scanned_count', $scanned_count, HOUR_IN_SECONDS);
        update_option('avf_blm_total_scanned_links', $total_scanned_links);

        $remaining = max(0, $total_posts - $scanned_count);

        if ($remaining === 0) {
            update_option('avf_blm_last_scan', current_time('mysql'));
        }

        wp_send_json_success(array(
            'scanned' => $scanned_count,
            'remaining' => $remaining,
            'broken_found' => $this->get_broken_count()
        ));
    }

    /**
     * AJAX: Guardar Configuración de Cron
     */
    public function ajax_save_settings() {
        check_ajax_referer('avf_blm_nonce', 'security');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Permisos insuficientes.', 'avf-broken-link-manager'));

        $freq = isset($_POST['cron_freq']) ? sanitize_text_field($_POST['cron_freq']) : 'disabled';
        $email = isset($_POST['email_notify']) ? intval($_POST['email_notify']) : 0;
        $logs = isset($_POST['enable_logs']) ? intval($_POST['enable_logs']) : 0;

        update_option('avf_blm_cron_freq', $freq);
        update_option('avf_blm_email_notify', $email ? '1' : '0');
        update_option('avf_blm_enable_logs', $logs ? '1' : '0');

        $this->update_cron_schedule($freq);

        wp_send_json_success(__('Configuración guardada.', 'avf-broken-link-manager'));
    }

    /**
     * AJAX: Vaciar Logs
     */
    public function ajax_clear_logs() {
        check_ajax_referer('avf_blm_nonce', 'security');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Permisos insuficientes.', 'avf-broken-link-manager'));

        $log_file = $this->get_log_file_path();
        if (file_exists($log_file)) {
            @unlink($log_file);
        }

        wp_send_json_success(__('Logs limpiados.', 'avf-broken-link-manager'));
    }

    /**
     * Descarga del archivo plano de logs
     */
    public function download_redirect_logs() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Acceso denegado.', 'avf-broken-link-manager'));
        }

        check_admin_referer('avf_blm_log_nonce');

        $log_file = $this->get_log_file_path();
        if (!file_exists($log_file)) {
            wp_die(__('No existe archivo de logs disponible.', 'avf-broken-link-manager'));
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="redirects-' . date('Y-m-d') . '.log"');
        header('Content-Length: ' . filesize($log_file));
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($log_file);
        exit;
    }

    /**
     * Sanitización estricta de campos para prevenir inyección de fórmulas CSV
     */
    private function sanitize_csv_field($field) {
        $str = (string) $field;
        if (preg_match('/^[=\+\-@	
]/', $str)) {
            return "'" . $str;
        }
        return $str;
    }

    /**
     * Exportación de Enlaces Rotos a Formato CSV por Lotes (Streaming)
     */
    public function export_broken_links_csv() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Acceso denegado.', 'avf-broken-link-manager'));
        }

        check_admin_referer('avf_blm_export_csv_nonce');

        global $wpdb;
        $table_broken = $wpdb->prefix . 'avf_broken_links';

        $filename = 'enlaces-rotos-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        // BOM UTF-8 directo en bytes binarios para compatibilidad nativa con Excel
        fputs($output, "ï»¿");

        fputcsv($output, array('ID Registro', 'ID Post', 'Idioma', 'Titulo Post', 'URL Post', 'URL Rota', 'Codigo HTTP', 'Descripcion Error', 'Fecha Detectado', 'Estado'));

        $last_id = 0;
        $batch_size = 500;

        do {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table_broken} WHERE status = 'broken' AND id > %d ORDER BY id ASC LIMIT %d",
                $last_id,
                $batch_size
            ));

            if (empty($rows)) break;

            foreach ($rows as $row) {
                $last_id = (int) $row->id;
                fputcsv($output, array(
                    $this->sanitize_csv_field($row->id),
                    $this->sanitize_csv_field($row->post_id),
                    $this->sanitize_csv_field($this->get_post_language($row->post_id)),
                    $this->sanitize_csv_field(get_the_title($row->post_id)),
                    $this->sanitize_csv_field(get_permalink($row->post_id)),
                    $this->sanitize_csv_field($row->url),
                    $this->sanitize_csv_field($row->http_code),
                    $this->sanitize_csv_field($row->error_desc),
                    $this->sanitize_csv_field($row->detected_at),
                    $this->sanitize_csv_field($row->status)
                ));
            }
        } while (count($rows) === $batch_size);

        fclose($output);
        exit;
    }

    /**
     * Ejecución en Segundo Plano vía WP-Cron (con Race Lock)
     */
    public function run_cron_scan() {
        // Evitar ejecuciones simultáneas mediante Lock
        if (get_transient('avf_blm_cron_lock')) {
            return;
        }

        set_transient('avf_blm_cron_lock', 1, 600); // Bloqueo de 10 minutos
        @set_time_limit(300);

        global $wpdb;
        $post_types = get_post_types(array('public' => true), 'names');
        if (empty($post_types)) {
            delete_transient('avf_blm_cron_lock');
            return;
        }

        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        $table_broken = $wpdb->prefix . 'avf_broken_links';
        $new_broken_count = 0;
        $last_id = 0;
        $batch_size = 50;

        do {
            $sql_args = array_merge(array_values($post_types), array($last_id, $batch_size));
            $query = $wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts} 
                WHERE post_type IN ({$placeholders}) 
                AND post_status = 'publish' 
                AND ID > %d 
                ORDER BY ID ASC LIMIT %d",
                $sql_args
            );

            $posts = $wpdb->get_results($query);

            if (empty($posts)) break;

            foreach ($posts as $p) {
                $last_id = (int) $p->ID;
                if (empty($p->post_content)) continue;

                $links = $this->extract_links_from_html($p->post_content);

                foreach ($links as $url) {
                    $check = $this->check_url_http_status($url);

                    if ($check['is_broken']) {
                        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table_broken} WHERE post_id = %d AND url = %s", $p->ID, $url));
                        if (!$exists) {
                            $wpdb->insert(
                                $table_broken,
                                array(
                                    'post_id' => $p->ID,
                                    'url' => esc_url_raw($url),
                                    'http_code' => sanitize_text_field($check['code']),
                                    'error_desc' => sanitize_text_field($check['desc']),
                                    'detected_at' => current_time('mysql'),
                                    'status' => 'broken'
                                ),
                                array('%d', '%s', '%s', '%s', '%s', '%s')
                            );
                            $new_broken_count++;
                        }
                    }
                }
            }
        } while (count($posts) === $batch_size);

        update_option('avf_blm_last_scan', current_time('mysql'));
        delete_transient('avf_blm_cron_lock');

        // Enviar notificación por Email si está activo y hay nuevos hallazgos
        if ($new_broken_count > 0 && get_option('avf_blm_email_notify', '1') === '1') {
            $admin_email = get_option('admin_email');
            $subject = sprintf('[%s] Alerta: Se han detectado %d enlaces rotos nuevos', get_bloginfo('name'), $new_broken_count);
            $message = sprintf("Hola Admin,

El escaneo automático de AVF Broken Link Manager ha detectado %d enlace(s) roto(s) nuevo(s) en tu sitio web.

Puedes gestionarlos y configurarlos desde el panel de administración:
%s

Saludos,
AVF Broken Link Manager", $new_broken_count, admin_url('tools.php?page=avf-broken-link-manager'));
            wp_mail($admin_email, $subject, $message);
        }
    }

    /**
     * Extractor Limpio con DOMDocument y Expansión de Shortcodes
     */
    private function extract_links_from_html($content) {
        if (empty($content)) return array();

        // Expansión de Shortcodes de maquetadores visuales (Elementor, Divi, etc.)
        if (function_exists('do_shortcode')) {
            $content = do_shortcode($content);
        }

        $links = array();
        $dom = new DOMDocument();
        $previous_state = libxml_use_internal_errors(true);

        // Inyección de Meta UTF-8 compatible con PHP 8.2+
        $dom->loadHTML('<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous_state);

        // Determinación de la URL base considerando multilingüe (WPML / Polylang)
        if (function_exists('pll_home_url')) {
            $base = pll_home_url();
        } elseif (has_filter('wpml_home_url')) {
            $base = apply_filters('wpml_home_url', get_option('home'));
        } else {
            $base = site_url();
        }

        $base_path = parse_url($base, PHP_URL_PATH);

        $anchors = $dom->getElementsByTagName('a');
        foreach ($anchors as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            if (empty($href)) continue;

            // Filtrar esquemas no deseados (mailto, tel, javascript, anclajes)
            if (preg_match('/^(mailto:|tel:|javascript:|#)/i', $href)) continue;

            $scheme = wp_parse_url($href, PHP_URL_SCHEME);
            if (!empty($scheme) && !in_array(strtolower($scheme), array('http', 'https'), true)) {
                continue;
            }

            if (strpos($href, 'http://') !== 0 && strpos($href, 'https://') !== 0 && strpos($href, '//') !== 0) {
                if (!empty($base_path) && $base_path !== '/' && strpos($href, $base_path) === 0) {
                    $scheme_host = parse_url($base, PHP_URL_SCHEME) . '://' . parse_url($base, PHP_URL_HOST);
                    $href = $scheme_host . $href;
                } else {
                    $href = rtrim($base, '/') . '/' . ltrim($href, '/');
                }
            } elseif (strpos($href, '//') === 0) {
                $href = (is_ssl() ? 'https:' : 'http:') . $href;
            }

            $links[] = esc_url_raw($href);
        }

        return array_unique($links);
    }

    /**
     * Comprobar Estado HTTP con User-Agent Dual Anti-WAF
     */
    private function check_url_http_status($url) {
        $args = array(
            'timeout'     => 5,
            'redirection' => 3,
            'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 (WordPress/' . get_bloginfo('version') . '; AVF-Broken-Link-Manager/' . self::VERSION . ')',
            'sslverify'   => false
        );

        $response = wp_remote_head($url, $args);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 400) {
            $response = wp_remote_get($url, $args);
        }

        if (is_wp_error($response)) {
            return array('is_broken' => true, 'code' => 'TIMEOUT/FAIL', 'desc' => $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        if ($code >= 400 || $code === 0) {
            $message = wp_remote_retrieve_response_message($response);
            return array('is_broken' => true, 'code' => (string) $code, 'desc' => !empty($message) ? $message : 'HTTP Error ' . $code);
        }

        return array('is_broken' => false, 'code' => (string) $code, 'desc' => 'OK');
    }

    /**
     * AJAX 3: Guardar/Crear Redirección 301 (con prevención de bucles circulares)
     */
    public function ajax_add_redirect() {
        check_ajax_referer('avf_blm_nonce', 'security');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Permisos insuficientes.', 'avf-broken-link-manager'));

        $source_url = isset($_POST['source_url']) ? esc_url_raw(wp_unslash($_POST['source_url'])) : '';
        $target_url = isset($_POST['target_url']) ? esc_url_raw(wp_unslash($_POST['target_url'])) : '';
        $link_id = isset($_POST['link_id']) ? absint($_POST['link_id']) : 0;

        if (empty($source_url) || empty($target_url)) {
            wp_send_json_error(__('Las URLs de origen y destino son obligatorias.', 'avf-broken-link-manager'));
        }

        // Prevención de bucles infinitos de redirección (protocolo-agnóstico)
        $clean_source = preg_replace('/^https?:\/\//i', '//', rtrim(strtolower($source_url), '/'));
        $clean_target = preg_replace('/^https?:\/\//i', '//', rtrim(strtolower($target_url), '/'));

        if ($clean_source === $clean_target) {
            wp_send_json_error(__('La URL de origen y destino no pueden ser idénticas ni provocar un bucle de redirección.', 'avf-broken-link-manager'));
        }

        global $wpdb;
        $table_redirects = $wpdb->prefix . 'avf_redirects';

        // Verificación de redirección en cadena / circular
        $exists_reverse = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_redirects} WHERE source_url = %s OR REPLACE(REPLACE(source_url, 'https://', '//'), 'http://', '//') = %s",
            $target_url,
            $clean_target
        ));

        if ($exists_reverse) {
            wp_send_json_error(__('Esa URL de destino ya está registrada como origen en otra redirección (evitando redirecciones en cadena/bucle).', 'avf-broken-link-manager'));
        }

        $inserted = $wpdb->insert(
            $table_redirects,
            array('source_url' => $source_url, 'target_url' => $target_url, 'created_at' => current_time('mysql')),
            array('%s', '%s', '%s')
        );

        if (!$inserted) {
            wp_send_json_error(__('Error al guardar la redirección en la base de datos.', 'avf-broken-link-manager'));
        }

        if ($link_id > 0) {
            $table_broken = $wpdb->prefix . 'avf_broken_links';
            $wpdb->delete($table_broken, array('id' => $link_id), array('%d'));
        }

        wp_send_json_success(__('Redirección guardada correctamente.', 'avf-broken-link-manager'));
    }

    /**
     * AJAX Handlers Adicionales
     */
    public function ajax_ignore_link() {
        check_ajax_referer('avf_blm_nonce', 'security');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Permisos insuficientes.', 'avf-broken-link-manager'));

        $link_id = isset($_POST['link_id']) ? absint($_POST['link_id']) : 0;
        if ($link_id <= 0) wp_send_json_error(__('ID no válido.', 'avf-broken-link-manager'));

        global $wpdb;
        $wpdb->update($wpdb->prefix . 'avf_broken_links', array('status' => 'ignored'), array('id' => $link_id), array('%s'), array('%d'));
        wp_send_json_success();
    }

    public function ajax_delete_link() {
        check_ajax_referer('avf_blm_nonce', 'security');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Permisos insuficientes.', 'avf-broken-link-manager'));

        $link_id = isset($_POST['link_id']) ? absint($_POST['link_id']) : 0;
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'avf_broken_links', array('id' => $link_id), array('%d'));
        wp_send_json_success();
    }

    public function ajax_delete_redirect() {
        check_ajax_referer('avf_blm_nonce', 'security');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Permisos insuficientes.', 'avf-broken-link-manager'));

        $redirect_id = isset($_POST['redirect_id']) ? absint($_POST['redirect_id']) : 0;
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'avf_redirects', array('id' => $redirect_id), array('%d'));
        wp_send_json_success();
    }

    public function ajax_reset_all() {
        check_ajax_referer('avf_blm_nonce', 'security');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Permisos insuficientes.', 'avf-broken-link-manager'));

        global $wpdb;
        $table_broken = $wpdb->prefix . 'avf_broken_links';
        $wpdb->query("DELETE FROM {$table_broken}");
        $wpdb->query("ALTER TABLE {$table_broken} AUTO_INCREMENT = 1");

        update_option('avf_blm_total_scanned_links', 0);
        update_option('avf_blm_last_scan', 'Nunca');

        wp_send_json_success();
    }

    /**
     * Interceptor Frontend de Redirecciones 301 (con soporte multilingüe y UTMs)
     */
    public function handle_template_redirect() {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        $scheme = is_ssl() ? 'https://' : 'http://';
        $current_url = $scheme . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $clean_url = esc_url_raw($current_url);
        $request_path = parse_url($clean_url, PHP_URL_PATH);

        $clean_url_no_proto = preg_replace('/^https?:/i', '', $clean_url);

        global $wpdb;
        $table_redirects = $wpdb->prefix . 'avf_redirects';

        $trimmed_path = rtrim($request_path, '/');
        if (empty($trimmed_path)) $trimmed_path = '/';

        // Ruta sin prefijo de idioma (WPML / Polylang)
        $lang_stripped_path = preg_replace('/^\/(?:[a-z]{2}(?:-[a-z]{2})?)(?=\/|$)/i', '', $request_path);
        if (empty($lang_stripped_path)) $lang_stripped_path = '/';
        $lang_stripped_trimmed = rtrim($lang_stripped_path, '/');
        if (empty($lang_stripped_trimmed)) $lang_stripped_trimmed = '/';

        $redirect = $wpdb->get_row($wpdb->prepare(
            "SELECT target_url FROM {$table_redirects} 
            WHERE REPLACE(REPLACE(source_url, 'https://', '//'), 'http://', '//') = %s 
               OR source_url = %s 
               OR source_url = %s 
               OR source_url = %s 
               OR source_url = %s 
            LIMIT 1",
            $clean_url_no_proto,
            $request_path,
            $trimmed_path,
            $lang_stripped_path,
            $lang_stripped_trimmed
        ));

        if ($redirect && !empty($redirect->target_url)) {
            $target = esc_url_raw($redirect->target_url);

            if (strpos($target, 'http://') !== 0 && strpos($target, 'https://') !== 0 && strpos($target, '//') !== 0) {
                $target = site_url($target);
            }

            if (!empty($_GET)) {
                $target = add_query_arg($_GET, $target);
            }

            $this->log_redirect_event($clean_url, $target);

            wp_redirect($target, 301);
            exit;
        }
    }

    /**
     * Métodos Auxiliares
     */
    private function count_scanable_posts() {
        $post_types = get_post_types(array('public' => true), 'names');
        if (empty($post_types)) return 0;

        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders}) AND post_status = 'publish'",
            array_values($post_types)
        ));
    }

    private function get_broken_count() {
        global $wpdb;
        $table_broken = $wpdb->prefix . 'avf_broken_links';
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_broken} WHERE status = 'broken'");
    }
}

// Inicializar Plugin
function avf_broken_link_manager_init() {
    return AVF_Broken_Link_Manager::get_instance();
}
avf_broken_link_manager_init();
