<?php
/**
 * Plugin Name: Hospital Inventory Management
 * Description: Hospital inventory management with admin and frontend shortcode, QR codes, Excel export, image uploads, search filters, edit tracking and bulk actions.
 * Version: 2.8.18
 * Author: Fareeza Fayyaz
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

class HIM_Hospital_Inventory_Management {
    private static $instance = null;
    private $table;
    private $owned_by = [
        'Blood Bank','BSL-II Lab','BSL-III Lab','Burn Unit','Cardiac Ward','Cardiology Department','Cath Lab','CCU','CCU Store','Chest Ward',
        'Children Complex','COD Department','Corona Ward','CSSD','Day Care','Dengue Department','Dental Department','Dialysis Department','Drug Release Lab'
    ];
    private $cities = [
        'Lahore','Faisalabad','Rawalpindi','Gujranwala','Multan','Sargodha','Sialkot','Bahawalpur','Sheikhupura','Rahim Yar Khan','Jhang','Dera Ghazi Khan','Gujrat','Sahiwal','Wah Cantonment','Kasur','Okara','Chiniot','Kamoke','Hafizabad','Sadiqabad','Burewala','Khanewal','Muzaffargarh','Mandi Bahauddin','Jhelum','Khanpur','Pakpattan','Daska','Gojra','Muridke','Bahawalnagar','Samundri','Jaranwala','Chishtian','Attock','Vehari','Kot Abdul Malik','Ferozewala','Chakwal','Gujranwala Cantonment','Kamalia','Ahmedpur East','Wazirabad','Layyah','Taxila','Khushab','Mianwali','Lodhran','Hasilpur','Bhakkar','Arifwala','Sambrial','Jatoi','Haroonabad','Narowal','Bhalwal','Mailsi','Shakargarh','Toba Tek Singh','Samundri','Shujaabad','Muridke','Mian Channu','Lala Musa','Kot Addu','Muridke','Dipalpur','Kharian','Renala Khurd','Phalia','Pattoki','Kahror Pakka','Kallar Syedan','Rajanpur','Fort Abbas','Dina','Dunyapur'
    ];
    private $makes = ['Canon','Philips','Siemens','GE Healthcare','Mindray','Toshiba','Hitachi','Samsung','Nikon','Olympus','Fujifilm','Medtronic','Drager','Other'];
    private $frontend_mode = false;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'him_inventory';
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('admin_menu', [$this, 'admin_menu'], 20);
        add_action('init', [$this, 'ensure_service_history_column']);
        add_action('init', [$this, 'ensure_tag_number_column']);
        add_action('init', [$this, 'ensure_focal_hospital_name_column']);
        add_action('admin_menu', [$this, 'force_clean_hcms_admin_menu_links'], 999999);
        add_action('admin_head', [$this, 'force_clean_hcms_admin_menu_links']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
        add_shortcode('hospital_inventory', [$this, 'frontend_shortcode']);
        add_action('admin_post_him_save_inventory', [$this, 'save_inventory']);
        add_action('admin_post_nopriv_him_save_inventory', [$this, 'save_inventory']);
        add_action('admin_post_him_delete_inventory', [$this, 'delete_inventory']);
        add_action('admin_post_nopriv_him_delete_inventory', [$this, 'delete_inventory']);
        add_action('admin_post_him_bulk_delete_inventory', [$this, 'bulk_delete_inventory']);
        add_action('admin_post_nopriv_him_bulk_delete_inventory', [$this, 'bulk_delete_inventory']);
        add_action('admin_post_him_export_inventory', [$this, 'export_inventory']);
        add_action('admin_post_nopriv_him_export_inventory', [$this, 'export_inventory']);
        add_action('wp_ajax_him_check_serial_number', [$this, 'ajax_check_serial_number']);
        add_action('wp_ajax_nopriv_him_check_serial_number', [$this, 'ajax_check_serial_number']);
    }

    public function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            inventory_name VARCHAR(255) NOT NULL,
            location VARCHAR(255) DEFAULT '',
            district VARCHAR(150) DEFAULT '',
            division VARCHAR(150) DEFAULT '',
            city VARCHAR(150) DEFAULT '',
            make VARCHAR(150) DEFAULT '',
            model VARCHAR(150) DEFAULT '',
            serial_number VARCHAR(191) NOT NULL,
            tag_number VARCHAR(191) DEFAULT '',
            year_build DATE NULL,
            warranty_from DATE NULL,
            warranty_to DATE NULL,
            installation_date DATE NULL,
            image_ids LONGTEXT NULL,
            owned_by VARCHAR(191) DEFAULT '',
            inventory_status VARCHAR(50) DEFAULT '',
            non_functional_description LONGTEXT NULL,
            repairable VARCHAR(50) DEFAULT '',
            repairable_description LONGTEXT NULL,
            service_history LONGTEXT NULL,
            is_utilized VARCHAR(10) DEFAULT '',
            qr_code_number VARCHAR(191) DEFAULT '',
            hospital_name VARCHAR(191) DEFAULT '',
            focal_hospital_name VARCHAR(191) DEFAULT '',
            focal_name VARCHAR(191) DEFAULT '',
            focal_email VARCHAR(191) DEFAULT '',
            focal_contact VARCHAR(50) DEFAULT '',
            focal_address LONGTEXT NULL,
            created_by BIGINT(20) UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_by BIGINT(20) UNSIGNED NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY serial_number (serial_number)
        ) $charset_collate;";
        dbDelta($sql);
        $this->ensure_service_history_column();
        $this->ensure_tag_number_column();
        $this->ensure_focal_hospital_name_column();
    }

    public function ensure_service_history_column() {
        global $wpdb;
        $column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$this->table} LIKE %s", 'service_history'));
        if (!$column) {
            $wpdb->query("ALTER TABLE {$this->table} ADD service_history LONGTEXT NULL AFTER repairable_description");
        }
    }


    public function ensure_tag_number_column() {
        global $wpdb;
        $column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$this->table} LIKE %s", 'tag_number'));
        if (!$column) {
            $wpdb->query("ALTER TABLE {$this->table} ADD tag_number VARCHAR(191) DEFAULT '' AFTER serial_number");
        }
    }



    public function ensure_focal_hospital_name_column() {
        global $wpdb;
        $column = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$this->table} LIKE %s", 'focal_hospital_name'));
        if (!$column) {
            $wpdb->query("ALTER TABLE {$this->table} ADD focal_hospital_name VARCHAR(191) DEFAULT '' AFTER hospital_name");
        }
    }

    private function get_next_serial_number() {
        global $wpdb;
        $this->ensure_tag_number_column();

        // Always generate a clean 4-digit sequence: 0001, 0002, 0003...
        // Old/random serials like 321 or 54646 are ignored for the sequence,
        // but we still skip any exact 4-digit serial that already exists.
        $used = $wpdb->get_col("SELECT serial_number FROM {$this->table} WHERE serial_number REGEXP '^[0-9]{4}$' ORDER BY serial_number ASC");
        $used_lookup = array_fill_keys(array_map('strval', (array) $used), true);

        for ($i = 1; $i <= 9999; $i++) {
            $candidate = str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            if (empty($used_lookup[$candidate])) {
                return $candidate;
            }
        }

        return str_pad((string) (((int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}")) + 1), 4, '0', STR_PAD_LEFT);
    }

    private function normalize_make_from_post($current_make = '') {
        $make = $this->input('make');
        $make_other = $this->input('make_other');
        if ($make === 'Other' && $make_other !== '') {
            return $make_other;
        }
        return $make;
    }

    private function get_current_user_focal_hospital_name() {
        $user_id = get_current_user_id();
        if (!$user_id) { return ''; }
        return (string) get_user_meta($user_id, '_hdims_focal_hospital_name', true);
    }



    private function get_inventory_hospital_name($record = null) {
        if ($record && isset($record->hospital_name)) {
            $hospital = trim((string) $record->hospital_name);
            if ($hospital !== '') { return $hospital; }
        }
        return '';
    }

    private function get_inventory_focal_hospital_name($record = null) {
        // Manual-only focal hospital value: do not auto-fetch from user meta.
        if ($record && isset($record->focal_hospital_name)) {
            return trim((string) $record->focal_hospital_name);
        }
        return '';
    }

    private function get_current_user_focal_person_name() {
        $user_id = get_current_user_id();
        if (!$user_id) { return ''; }
        $person = (string) get_user_meta($user_id, '_hdims_focal_person_name', true);
        if ($person !== '') { return $person; }
        $user = get_userdata($user_id);
        if (!$user) { return ''; }
        return $user->display_name ? (string) $user->display_name : (string) $user->user_login;
    }

    private function get_focal_person_hospital_options() {
        $users = get_users([
            'role__in' => ['subscriber','author'],
            'number' => 500,
            'fields' => ['ID','display_name','user_login'],
            'meta_query' => [
                'relation' => 'OR',
                ['key' => '_hdims_focal_person_name', 'compare' => 'EXISTS'],
                ['key' => '_hdims_focal_hospital_name', 'compare' => 'EXISTS'],
            ],
        ]);
        $options = [];
        foreach ($users as $user) {
            $person = get_user_meta($user->ID, '_hdims_focal_person_name', true);
            $hospital = get_user_meta($user->ID, '_hdims_focal_hospital_name', true);
            if (!$person) { $person = $user->display_name ? $user->display_name : $user->user_login; }
            if (!$person && !$hospital) { continue; }
            $options[] = ['name' => (string) $person, 'hospital' => (string) $hospital];
        }
        return $options;
    }

    private function decode_service_history($history) {
        if (empty($history)) { return []; }
        $items = json_decode((string)$history, true);
        return is_array($items) ? $items : [];
    }

    private function get_latest_service_date($history) {
        $items = $this->decode_service_history($history);
        if (!$items) { return '-'; }
        $last = end($items);
        return !empty($last['date']) ? $last['date'] : (!empty($last['datetime']) ? $last['datetime'] : '-');
    }

    private function service_history_text($history) {
        $items = $this->decode_service_history($history);
        if (!$items) { return ''; }
        $lines = [];
        foreach ($items as $item) {
            $line = (!empty($item['date']) ? $item['date'] : (!empty($item['datetime']) ? $item['datetime'] : ''));
            if (!empty($item['inventory_status'])) { $line .= ' | Inventory Status: ' . $item['inventory_status']; }
            if (!empty($item['repairable'])) { $line .= ' | Repairable: ' . $item['repairable']; }
            if (!empty($item['note'])) { $line .= ' | Note: ' . $item['note']; }
            if (!empty($item['updated_by'])) { $line .= ' | Updated By: ' . $item['updated_by']; }
            $lines[] = trim($line, ' |');
        }
        return implode("\n", $lines);
    }

    private function render_service_history_html($history) {
        $items = $this->decode_service_history($history);
        if (!$items) { return '<p>No service history found.</p>'; }
        $html = '<div class="table-scroll"><table class="widefat striped him-service-history-table"><thead><tr><th>Date</th><th>Inventory Status</th><th>Repairable</th><th>Repair Note</th><th>Updated By</th></tr></thead><tbody>';
        foreach ($items as $item) {
            $html .= '<tr>';
            $html .= '<td>' . esc_html($item['date'] ?? ($item['datetime'] ?? '')) . '</td>';
            $html .= '<td>' . esc_html($item['inventory_status'] ?? '') . '</td>';
            $html .= '<td>' . esc_html($item['repairable'] ?? '') . '</td>';
            $html .= '<td>' . nl2br(esc_html($item['note'] ?? '')) . '</td>';
            $html .= '<td>' . esc_html($item['updated_by'] ?? '') . '</td>';
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
        return $html;
    }

    private function should_add_service_history($old, $data) {
        if (!$old) { return false; }
        $old_inventory_status = isset($old->inventory_status) ? (string)$old->inventory_status : '';
        $old_repairable = isset($old->repairable) ? (string)$old->repairable : '';
        $old_repairable_description = isset($old->repairable_description) ? (string)$old->repairable_description : '';

        $became_functional = ($old_inventory_status !== 'Functional' && $data['inventory_status'] === 'Functional');
        $became_repairable_functional = ($old_repairable !== 'Functional' && $data['repairable'] === 'Functional');
        $repair_note_changed = ($old_repairable_description !== (string)$data['repairable_description'] && trim((string)$data['repairable_description']) !== '');

        return $became_functional || $became_repairable_functional || $repair_note_changed;
    }

    private function append_service_history($old, $data) {
        $history = $this->decode_service_history(isset($old->service_history) ? $old->service_history : '');
        $user = wp_get_current_user();
        $history[] = [
            'date' => current_time('Y-m-d'),
            'datetime' => current_time('mysql'),
            'inventory_status' => $data['inventory_status'],
            'repairable' => $data['repairable'],
            'note' => $data['repairable_description'],
            'updated_by' => ($user && $user->exists()) ? $user->user_login : 'Guest / Public User',
        ];
        return wp_json_encode($history);
    }

    private function capability() {
        // Author role already has the standard 'read' capability.
        // Using this keeps Inventory visible for Author without custom snippets.
        return 'read';
    }

    public function admin_menu() {
        // Remove any old Inventory menu registrations first.
        remove_menu_page('him-inventory');
        remove_submenu_page('him-inventory', 'him-inventory');
        remove_submenu_page('him-inventory', 'him-management');
        remove_submenu_page('him-inventory', 'him-search');
        remove_submenu_page('him-inventory', 'him-qr-code');

        // Register only the clean HCMS admin.php?page=... slugs.
        add_menu_page('Inventory', 'Inventory', $this->capability(), 'hcms-inventory', [$this, 'page_add_new'], 'dashicons-archive', 25);
        add_submenu_page('hcms-inventory', 'Add New', 'Add New', $this->capability(), 'hcms-inventory', [$this, 'page_add_new']);
        add_submenu_page('hcms-inventory', 'Management', 'Management', $this->capability(), 'hcms-inventory-management', [$this, 'page_management']);
        add_submenu_page('hcms-inventory', 'Search', 'Search', $this->capability(), 'hcms-inventory-search', [$this, 'page_search']);
        add_submenu_page('hcms-inventory', 'QR Code', 'QR Code', $this->capability(), 'hcms-inventory-qr-code', [$this, 'page_qr']);
    }

    public function force_clean_hcms_admin_menu_links() {
        // This is NOT a redirect. It only cleans WordPress admin menu arrays so Author does not get /wp-admin/him-* links.
        global $menu, $submenu;

        $map = [
            'him-inventory'  => 'hcms-inventory',
            'him-management' => 'hcms-inventory-management',
            'him-search'     => 'hcms-inventory-search',
            'him-qr-code'    => 'hcms-inventory-qr-code',
        ];

        if (is_array($menu)) {
            foreach ($menu as $index => $item) {
                if (!isset($item[2])) { continue; }

                if (isset($map[$item[2]])) {
                    unset($menu[$index]);
                    continue;
                }

                if ($item[2] === 'hcms-inventory') {
                    $menu[$index][1] = $this->capability();
                }
            }
        }

        if (isset($submenu['him-inventory'])) {
            unset($submenu['him-inventory']);
        }

        // Force submenu links to clean admin.php?page=hcms-* targets.
        $submenu['hcms-inventory'] = [
            ['Add New',    $this->capability(), 'hcms-inventory',            'Add New'],
            ['Management', $this->capability(), 'hcms-inventory-management', 'Management'],
            ['Search',     $this->capability(), 'hcms-inventory-search',     'Search'],
            ['QR Code',    $this->capability(), 'hcms-inventory-qr-code',    'QR Code'],
        ];
    }

    private function can_access_inventory() {
        if ($this->frontend_mode) { return true; }
        return is_user_logged_in() && current_user_can($this->capability());
    }

    public function assets($hook) {
        if (strpos($hook, 'hcms-inventory') === false && strpos($hook, 'him-') === false) { return; }
        wp_enqueue_media();
        wp_enqueue_style('him-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], '2.8.18');
        wp_enqueue_script('him-qrcode', plugin_dir_url(__FILE__) . 'assets/him-qrcode.js', [], '1.0.0', true);
        wp_enqueue_script('him-jszip', plugin_dir_url(__FILE__) . 'assets/jszip.min.js', [], '3.10.1', true);
        wp_enqueue_script('him-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', ['jquery','him-qrcode','him-jszip'], '2.8.18', true);
        wp_localize_script('him-admin', 'HIM_DATA', [
            'nonce' => wp_create_nonce('him_nonce'),
            'ajaxurl' => admin_url('admin-ajax.php'),
        ]);
    }


    public function frontend_assets() {
        wp_enqueue_media();
        wp_enqueue_style('him-admin', plugin_dir_url(__FILE__) . 'assets/admin.css', [], '2.8.18');
        wp_enqueue_script('him-qrcode', plugin_dir_url(__FILE__) . 'assets/him-qrcode.js', [], '1.0.0', true);
        wp_enqueue_script('him-jszip', plugin_dir_url(__FILE__) . 'assets/jszip.min.js', [], '3.10.1', true);
        wp_enqueue_script('him-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', ['jquery','him-qrcode','him-jszip'], '2.8.18', true);
        wp_localize_script('him-admin', 'HIM_DATA', [
            'nonce' => wp_create_nonce('him_nonce'),
            'ajaxurl' => admin_url('admin-ajax.php'),
        ]);
    }

    private function current_frontend_url($args = []) {
        $url = get_permalink();
        if (!$url) { $url = home_url(add_query_arg([], $GLOBALS['wp']->request ?? '')); }
        return $args ? add_query_arg($args, $url) : $url;
    }

    private function redirect_after_action($admin_args, $message = '') {
        if (!empty($_REQUEST['him_return_url'])) {
            $url = esc_url_raw(wp_unslash($_REQUEST['him_return_url']));
            if ($message) { $url = add_query_arg('him_success', rawurlencode($message), $url); }
            wp_safe_redirect($url); exit;
        }
        wp_redirect(add_query_arg($admin_args, admin_url('admin.php'))); exit;
    }

    public function frontend_shortcode($atts = []) {
        $this->frontend_mode = true;
        $view = isset($_GET['him_view']) ? sanitize_key(wp_unslash($_GET['him_view'])) : 'add';
        ob_start();
        echo '<div class="him-wrap him-frontend-wrap">';
        echo '<aside class="him-front-sidebar"><div class="him-front-title">Inventory</div>';
        $tabs = [
            'add' => 'Add New',
            'management' => 'Management',
            'search' => 'Search',
            'qr' => 'QR Code',
        ];
        foreach ($tabs as $key => $label) {
            $active = ($view === $key || ($view === '' && $key === 'add')) ? ' active' : '';
            echo '<a class="him-front-tab' . esc_attr($active) . '" href="' . esc_url($this->current_frontend_url(['him_view'=>$key])) . '">' . esc_html($label) . '</a>';
        }
        echo '</aside><main class="him-front-content">';
        if ($view === 'management') { $this->page_management(); }
        elseif ($view === 'search') { $this->page_search(); }
        elseif ($view === 'qr') { $this->page_qr(); }
        else { $this->page_add_new(); }
        echo '</main></div>';
        $this->frontend_mode = false;
        return ob_get_clean();
    }


    private function handle_direct_image_uploads() {
        if (empty($_FILES['him_upload_images']) || empty($_FILES['him_upload_images']['name'])) { return ''; }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $ids = [];
        $files = $_FILES['him_upload_images'];
        $count = is_array($files['name']) ? count($files['name']) : 0;
        for ($i = 0; $i < $count; $i++) {
            if (empty($files['name'][$i])) { continue; }
            $file_array = [
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i],
            ];
            $allowed = ['image/jpeg','image/png','image/jpg','image/webp'];
            if (!in_array($file_array['type'], $allowed, true)) { continue; }
            $_FILES['him_single_upload'] = $file_array;
            $attach_id = media_handle_upload('him_single_upload', 0);
            if (!is_wp_error($attach_id)) { $ids[] = absint($attach_id); }
        }
        unset($_FILES['him_single_upload']);
        return implode(',', $ids);
    }

    private function clean_date($key) {
        $val = isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : '';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $val) ? $val : null;
    }

    private function input($key, $default = '') {
        return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : $default;
    }

    private function textarea($key) {
        return isset($_POST[$key]) ? sanitize_textarea_field(wp_unslash($_POST[$key])) : '';
    }

    private function select_options($items, $selected = '') {
        foreach ($items as $item) {
            printf('<option value="%s" %s>%s</option>', esc_attr($item), selected($selected, $item, false), esc_html($item));
        }
    }

    private function status_options($selected = '') {
        $this->select_options(['Functional','Non Functional'], $selected);
    }

    private function yes_no_options($selected = '') {
        $this->select_options(['Yes','No'], $selected);
    }

    private function get_record($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id=%d", $id));
    }

    private function clean_numeric_text($value) {
        $value = is_string($value) ? $value : (string) $value;
        return preg_replace('/\D+/', '', $value);
    }

    public function ajax_check_serial_number() {
        check_ajax_referer('him_nonce', 'nonce');
        global $wpdb;
        $serial_number = isset($_POST['serial_number']) ? $this->clean_numeric_text(sanitize_text_field(wp_unslash($_POST['serial_number']))) : '';
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if ($serial_number === '') {
            wp_send_json_success(['exists' => false]);
        }
        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->table} WHERE tag_number=%s AND id<>%d", $serial_number, $id));
        wp_send_json_success([
            'exists' => $exists > 0,
            'message' => $exists > 0 ? 'This serial number already added, add new one please' : '',
        ]);
    }

    private function image_count_text($image_ids) {
        $imgs = array_filter(array_map('absint', explode(',', (string)$image_ids)));
        return $imgs ? 'Available (' . count($imgs) . ')' : 'Unavailable';
    }

    private function status_badge($status) {
        $class = (strcasecmp((string)$status, 'Functional') === 0) ? 'him-status-functional' : 'him-status-non-functional';
        return '<span class="him-status-badge ' . esc_attr($class) . '">' . esc_html($status ?: '-') . '</span>';
    }

    private function lightbox_image($attachment_id, $size = 'thumbnail') {
        $attachment_id = absint($attachment_id);
        $full = wp_get_attachment_image_url($attachment_id, 'full');
        if (!$full) { return ''; }
        return wp_get_attachment_image($attachment_id, $size, false, [
            'class' => 'him-lightbox-img',
            'data-full' => esc_url($full),
            'alt' => 'Inventory image',
        ]);
    }

    public function save_inventory() {
        if (!$this->can_access_inventory()) { wp_die('Permission denied'); }
        check_admin_referer('him_save_inventory');
        global $wpdb;
        $this->ensure_service_history_column();
        $this->ensure_tag_number_column();
        $this->ensure_focal_hospital_name_column();
        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $serial = $id ? $this->input('serial_number') : $this->get_next_serial_number();
        $posted_user_serial_number = $this->clean_numeric_text($this->input('tag_number'));
        if (!$serial || !$this->input('inventory_name')) {
            $this->redirect_after_action(['page'=>'hcms-inventory','him_error'=>'Inventory name and serial number are required'], 'Inventory name and serial number are required');
        }
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table} WHERE serial_number=%s AND id<>%d", $serial, $id));
        if ($existing) {
            $this->redirect_after_action(['page'=>'hcms-inventory','him_error'=>'Tag already exists'], 'Tag already exists');
        }
        if ($posted_user_serial_number !== '') {
            $duplicate_user_serial = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table} WHERE tag_number=%s AND id<>%d", $posted_user_serial_number, $id));
            if ($duplicate_user_serial) {
                $error_args = ['page'=>'hcms-inventory','him_error'=>'This serial number already added, add new one please'];
                if ($id) { $error_args['edit'] = $id; }
                $this->redirect_after_action($error_args, 'This serial number already added, add new one please');
            }
        }
        $posted_hospital_name = $this->input('hospital_name');
        // Focal hospital/person fields must remain manual only. Do not auto-fetch from user meta.
        $posted_focal_hospital_name = $this->input('focal_hospital_name');
        $posted_focal_name = $this->input('focal_name');

        $data = [
            'inventory_name' => $this->input('inventory_name'),
            'location' => $this->input('location'),
            'district' => $this->input('district'),
            'division' => $this->input('division'),
            'city' => $this->input('city'),
            'make' => $this->normalize_make_from_post(),
            'model' => $this->input('model'),
            'serial_number' => $serial,
            'tag_number' => $posted_user_serial_number,
            'year_build' => $this->clean_date('year_build'),
            'warranty_from' => $this->clean_date('warranty_from'),
            'warranty_to' => $this->clean_date('warranty_to'),
            'installation_date' => $this->clean_date('installation_date'),
            'image_ids' => $this->input('image_ids') ?: $this->handle_direct_image_uploads(),
            'owned_by' => $this->input('owned_by'),
            'inventory_status' => $this->input('inventory_status'),
            'non_functional_description' => $this->textarea('non_functional_description'),
            'repairable' => $this->input('repairable'),
            'repairable_description' => $this->textarea('repairable_description'),
            'is_utilized' => $this->input('is_utilized'),
            'qr_code_number' => $serial,
            'hospital_name' => $posted_hospital_name,
            'focal_hospital_name' => $posted_focal_hospital_name,
            'focal_name' => $posted_focal_name,
            'focal_email' => sanitize_email($this->input('focal_email')),
            'focal_contact' => $this->input('focal_contact'),
            'focal_address' => $this->textarea('focal_address'),
        ];
        if ($id) {
            $old = $this->get_record($id);
            $data['updated_by'] = get_current_user_id();
            $data['updated_at'] = current_time('mysql');
            if ($this->should_add_service_history($old, $data)) {
                $data['service_history'] = $this->append_service_history($old, $data);
            }
            $wpdb->update($this->table, $data, ['id'=>$id]);
            $user = wp_get_current_user();
            $admin_email = get_option('admin_email');
            $subject = 'Inventory data updated: ' . $data['inventory_name'];
            $message = "Inventory data has been updated.\n\nInventory: {$data['inventory_name']}\nSerial Number: {$data['serial_number']}\nUpdated By: {$user->user_login}\nUpdated At: {$data['updated_at']}\n";
            wp_mail($admin_email, $subject, $message);
            $this->redirect_after_action(['page'=>'hcms-inventory-management','him_success'=>'Inventory updated successfully'], 'Inventory updated successfully');
        } else {
            $data['created_by'] = get_current_user_id();
            $data['created_at'] = current_time('mysql');
            $inserted = $wpdb->insert($this->table, $data);
            if ($inserted === false) {
                $this->redirect_after_action(['page'=>'hcms-inventory','him_error'=>'Inventory could not be saved. Please try again.'], 'Inventory could not be saved. Please try again.');
            }
            $this->redirect_after_action(['page'=>'hcms-inventory-management','him_success'=>'Inventory added successfully'], 'Inventory added successfully');
        }
    }

    public function delete_inventory() {
        if (!$this->can_access_inventory()) { wp_die('Permission denied'); }
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer('him_delete_' . $id);
        global $wpdb;
        $wpdb->delete($this->table, ['id'=>$id]);
        $this->redirect_after_action(['page'=>'hcms-inventory-management','him_success'=>'Inventory deleted'], 'Inventory deleted');
    }

    public function bulk_delete_inventory() {
        if (!$this->can_access_inventory()) { wp_die('Permission denied'); }
        check_admin_referer('him_bulk_action');
        $ids = isset($_POST['inventory_ids']) ? array_map('absint', (array)$_POST['inventory_ids']) : [];
        if ($ids) {
            global $wpdb;
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM {$this->table} WHERE id IN ($placeholders)", $ids));
        }
        $this->redirect_after_action(['page'=>'hcms-inventory-management','him_success'=>'Selected inventory deleted'], 'Selected inventory deleted');
    }

    private function build_where_from_request() {
        global $wpdb;
        $where = ['1=1']; $params = [];
        $like_fields = ['inventory_name','location','district','division','city','make','model','serial_number','tag_number','hospital_name','focal_hospital_name','focal_name','focal_email','focal_contact','focal_address','qr_code_number'];
        foreach ($like_fields as $field) {
            if (!empty($_GET[$field])) { $where[] = "$field LIKE %s"; $params[] = '%' . $wpdb->esc_like(sanitize_text_field(wp_unslash($_GET[$field]))) . '%'; }
        }
        $equal_fields = ['owned_by','inventory_status','repairable','is_utilized'];
        foreach ($equal_fields as $field) {
            if (!empty($_GET[$field])) { $where[] = "$field = %s"; $params[] = sanitize_text_field(wp_unslash($_GET[$field])); }
        }
        foreach (['year_build','warranty_from','warranty_to','installation_date'] as $field) {
            if (!empty($_GET[$field])) { $where[] = "$field = %s"; $params[] = sanitize_text_field(wp_unslash($_GET[$field])); }
        }
        if (!empty($_GET['images'])) {
            if ($_GET['images'] === 'available') { $where[] = "image_ids <> '' AND image_ids IS NOT NULL"; }
            if ($_GET['images'] === 'not_available') { $where[] = "(image_ids = '' OR image_ids IS NULL)"; }
        }
        $sql = ' WHERE ' . implode(' AND ', $where);
        return [$sql, $params];
    }

    private function query_records($search = false, $limit = 500) {
        global $wpdb;
        if ($search) {
            [$where, $params] = $this->build_where_from_request();
            $sql = "SELECT * FROM {$this->table} {$where} ORDER BY id DESC";
            if ($limit) { $sql .= ' LIMIT ' . absint($limit); }
            return $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);
        }
        return $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY id DESC LIMIT " . absint($limit));
    }

    public function export_inventory() {
        if (!$this->can_access_inventory()) { wp_die('Permission denied'); }
        check_admin_referer('him_export_inventory');
        global $wpdb;
        $ids = isset($_GET['ids']) && $_GET['ids'] !== '' ? array_filter(array_map('absint', explode(',', sanitize_text_field(wp_unslash($_GET['ids']))))) : [];
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $records = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} WHERE id IN ($placeholders) ORDER BY id DESC", $ids));
        } else {
            $records = $this->query_records(!empty($_GET['search_export']), 0);
        }
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename=hospital-inventory-' . date('Y-m-d-H-i') . '.xls');
        echo '<table border="1"><tr>';
        $headers = ['Sr No','Inventory','Location','District','Division','City','Make','Model','Tag','Serial Number','Year Build','Warranty From','Warranty To','Installation Date','Owned By','Inventory Status','Repairable','Latest Service Date','Service History','Is Utilized','Hospital Name','Focal Hospital Name','Focal Name','Email','Contact','Address','Updated By','Updated At'];
        foreach ($headers as $h) { echo '<th>' . esc_html($h) . '</th>'; }
        echo '</tr>';
        foreach ($records as $r) {
            $updated_by = $r->updated_by ? get_userdata($r->updated_by) : null;
            echo '<tr>';
            $vals = [$r->id,$r->inventory_name,$r->location,$r->district,$r->division,$r->city,$r->make,$r->model,$r->serial_number,$r->tag_number,$r->year_build,$r->warranty_from,$r->warranty_to,$r->installation_date,$r->owned_by,$r->inventory_status,$r->repairable,$this->get_latest_service_date($r->service_history ?? ''),$this->service_history_text($r->service_history ?? ''),$r->is_utilized,$r->hospital_name,($r->focal_hospital_name ?? ''),$r->focal_name,$r->focal_email,$r->focal_contact,$r->focal_address,$updated_by ? $updated_by->user_login : '',$r->updated_at];
            foreach ($vals as $v) { echo '<td>' . esc_html($v) . '</td>'; }
            echo '</tr>';
        }
        echo '</table>'; exit;
    }

    private function notices() {
        if (!empty($_GET['him_success'])) { echo '<div class="notice notice-success"><p>' . esc_html(wp_unslash($_GET['him_success'])) . '</p></div>'; }
        if (!empty($_GET['him_error'])) { echo '<div class="notice notice-error"><p>' . esc_html(wp_unslash($_GET['him_error'])) . '</p></div>'; }
    }

    public function page_add_new() {
        if (!$this->can_access_inventory()) { return; }
        $id = isset($_GET['edit']) ? absint($_GET['edit']) : 0;
        $this->ensure_tag_number_column();
        $r = $id ? $this->get_record($id) : null;
        $current_user_hospital = '';
        $v = function($field) use ($r) {
            if ($r && isset($r->$field)) { return $r->$field; }
            return '';
        };
        $serial_value = $id ? $v('serial_number') : $this->get_next_serial_number();
        echo '<div class="wrap him-wrap"><h1>' . ($id ? 'Edit Inventory' : 'Add New Inventory') . '</h1>'; $this->notices();
        if ($id) {
            $back_url = $this->frontend_mode ? $this->current_frontend_url(['him_view'=>'management']) : admin_url('admin.php?page=hcms-inventory-management');
            echo '<div class="him-edit-top-actions"><a class="button him-back-management-btn" href="' . esc_url($back_url) . '">&larr; Back to Management</a></div>';
        }
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" class="him-form"' . (!$id ? ' data-him-new-inventory="1"' : '') . '>';
        wp_nonce_field('him_save_inventory');
        echo '<input type="hidden" name="action" value="him_save_inventory"><input type="hidden" name="id" value="' . esc_attr($id) . '">';
        if ($this->frontend_mode) { echo '<input type="hidden" name="him_return_url" value="' . esc_url($this->current_frontend_url(['him_view'=>'management'])) . '">'; }
        echo '<div class="him-card"><h2>Basic Inventory Information</h2><div class="him-grid">';
        $this->field('Inventory Name','inventory_name','text',$v('inventory_name'), true);
        $this->field('Hospital Name','hospital_name','text',$v('hospital_name'));
        $this->field('Location','location','text',$v('location'));
        $this->field('District','district','text',$v('district'));
        $this->field('Division','division','text',$v('division'));
        $this->select_field('City','city',$this->cities,$v('city'), false, true);
        $this->make_field($v('make'));
        $this->field('Model','model','text',$v('model'));
        $this->field('Tag','serial_number','text',$serial_value, true, ['readonly' => true]);
        $this->user_serial_field($v('tag_number'), $id);
        $this->field('Year Build','year_build','date',$v('year_build'));
        $this->field('Warranty From','warranty_from','date',$v('warranty_from'));
        $this->field('Warranty To','warranty_to','date',$v('warranty_to'));
        $this->field('Installation Date','installation_date','date',$v('installation_date'));
        $this->select_field('Owned By','owned_by',$this->owned_by,$v('owned_by'));
        $this->image_field($v('image_ids'));
        echo '</div></div><div class="him-two-col">';
        echo '<div class="him-card him-status-card"><h2>Inventory Status</h2><div class="him-status-grid">';
        $this->select_field('Inventory Status','inventory_status',['Functional','Non Functional'],$v('inventory_status'));
        $this->textarea_field('Non Functional Description','non_functional_description',$v('non_functional_description'));
        $this->select_field('Repairable','repairable',['Functional','Non Functional'],$v('repairable'));
        $this->textarea_field('Repairable Description','repairable_description',$v('repairable_description'));
        $this->select_field('Is Utilized','is_utilized',['Yes','No'],$v('is_utilized'));
        echo '<p><strong>Tag will generate automatically and QR code will use this tag.</strong></p>';
        echo '</div></div>';
        echo '<div class="him-card him-focal-card"><h2>Focal Hospital / Focal Person</h2><div class="him-focal-grid">';
        $this->field('Focal Hospital Name','focal_hospital_name','text',$v('focal_hospital_name'), false, ['class' => 'him-current-user-hospital']);
        $this->focal_name_field($v('focal_name'));
        $this->field('Contact','focal_contact','number',$v('focal_contact'));
        $this->field('Email','focal_email','email',$v('focal_email'));
        $this->textarea_field('Address','focal_address',$v('focal_address'));
        echo '</div></div></div>';
        submit_button($id ? 'Update Inventory' : 'Save Inventory');
        echo '</form></div>';
    }

    private function field($label,$name,$type,$value,$required=false,$attrs=[]) {
        $attr_html = '';
        foreach ((array) $attrs as $attr => $attr_value) {
            if ($attr_value === false || $attr_value === null) { continue; }
            if ($attr_value === true) { $attr_html .= ' ' . esc_attr($attr); }
            else { $attr_html .= ' ' . esc_attr($attr) . '="' . esc_attr($attr_value) . '"'; }
        }
        echo '<label><span>' . esc_html($label) . ($required?' *':'') . '</span><input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" ' . ($required?'required':'') . $attr_html . '></label>';
    }

    private function user_serial_field($value, $record_id = 0) {
        echo '<label class="him-user-serial-wrap"><span>Serial Number</span><input type="number" name="tag_number" value="' . esc_attr($value) . '" min="0" step="1" inputmode="numeric" pattern="[0-9]*" class="him-user-serial-number" data-record-id="' . esc_attr(absint($record_id)) . '"><small class="him-serial-check-message" aria-live="polite"></small></label>';
    }

    private function make_field($value) {
        $selected = in_array($value, $this->makes, true) ? $value : ($value ? 'Other' : '');
        $other_value = ($selected === 'Other' && $value !== 'Other') ? $value : '';
        echo '<label class="him-make-field"><span>Make</span><select name="make" class="him-make-select"><option value="">-- Select --</option>';
        $this->select_options($this->makes, $selected);
        echo '</select></label>';
        echo '<label class="him-make-other-field" style="display:none"><span>Other Make</span><input type="text" name="make_other" value="' . esc_attr($other_value) . '" placeholder="Enter make"></label>';
    }

    private function focal_name_field($value) {
        echo '<label><span>Focal Person Name</span><input type="text" name="focal_name" value="' . esc_attr($value) . '" class="him-focal-name-input" autocomplete="off"></label>';
    }

    private function select_field($label,$name,$items,$value,$required=false,$search=false) {
        echo '<label><span>' . esc_html($label) . '</span><select name="' . esc_attr($name) . '" ' . ($required?'required':'') . ' class="' . ($search?'him-searchable':'') . '"><option value="">-- Select --</option>';
        $this->select_options($items,$value); echo '</select></label>';
    }
    private function textarea_field($label,$name,$value) {
        echo '<label><span>' . esc_html($label) . '</span><textarea name="' . esc_attr($name) . '">' . esc_textarea($value) . '</textarea></label>';
    }
    private function image_field($value) {
        echo '<label class="him-image-field"><span>Upload Images</span><input type="hidden" name="image_ids" id="him_image_ids" value="' . esc_attr($value) . '"><button type="button" class="button him-upload-images">Select Images</button><button type="button" class="button him-clear-images">Clear</button><input type="file" name="him_upload_images[]" multiple accept="image/jpeg,image/png,image/webp" class="him-public-file-input"><small>Public users can upload images from this file field.</small><div class="him-image-preview">';
        $ids = array_filter(array_map('absint', explode(',', $value)));
        foreach ($ids as $id) { echo $this->lightbox_image($id, 'thumbnail'); }
        echo '</div></label>';
    }

    private function inventory_view_url($r) {
        $serial = isset($r->serial_number) ? $r->serial_number : '';
        if ($this->frontend_mode) {
            return $this->current_frontend_url(['him_view'=>'management','inventory'=>$r->id,'serial'=>rawurlencode($serial)]);
        }
        return admin_url('admin.php?page=hcms-inventory-management&inventory=' . absint($r->id) . '&serial=' . rawurlencode($serial));
    }


    private function get_user_display_name($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) { return 'Guest / Public User'; }
        $user = get_userdata($user_id);
        if (!$user) { return ''; }
        return $user->display_name ? $user->display_name : $user->user_login;
    }

    private function render_inventory_detail_page($r) {
        if (!$r) {
            echo '<div class="wrap him-wrap"><h1>Inventory Detail</h1><div class="him-card"><p>Inventory not found.</p></div></div>';
            return;
        }
        $imgs = array_filter(array_map('absint', explode(',', (string)$r->image_ids)));
        $image_html = '<div class="him-view-images him-detail-page-images">';
        if ($imgs) {
            foreach ($imgs as $img_id) { $image_html .= $this->lightbox_image($img_id, 'medium'); }
        } else {
            $image_html .= '<span>No Image</span>';
        }
        $image_html .= '</div>';
        $payload = $this->inventory_view_url($r);
        $display_hospital = $this->get_inventory_hospital_name($r);
        $display_focal_hospital = $this->get_inventory_focal_hospital_name($r);
        echo '<div class="wrap him-wrap him-detail-page-wrap">';
        echo '<h1>Inventory Detail</h1>';
        $back_url = $this->frontend_mode ? $this->current_frontend_url(['him_view'=>'management']) : admin_url('admin.php?page=hcms-inventory-management');
        echo '<div class="him-edit-top-actions him-detail-top-actions"><a class="button him-back-management-btn" href="' . esc_url($back_url) . '">&larr; Back to Management</a></div>';
        echo '<div class="him-card him-detail-page-card">';
        echo '<div class="him-detail-page-head"><div><h2>' . esc_html($r->inventory_name) . '</h2><p><strong>Tag:</strong> ' . esc_html($r->serial_number) . '</p></div>';
        echo '<div class="him-inline-qr-card him-detail-page-qr" data-qrid="' . esc_attr($r->id) . '" data-serial="' . esc_attr($r->serial_number) . '" data-name="' . esc_attr($r->inventory_name) . '" data-hospital="' . esc_attr($display_hospital) . '" data-payload="' . esc_attr($payload) . '">';
        echo '<div class="him-detail-title">QR Code</div><canvas class="him-inline-qr-canvas" width="220" height="285"></canvas>';
        echo '<br><button type="button" class="button button-primary him-download-inline-qr">Download QR Code</button>';
        echo '</div></div>';
        echo '<h2>Inventory Information</h2><div class="him-detail-grid him-detail-page-grid">';
        echo $this->detail_item('Inventory Name', $r->inventory_name);
        echo $this->detail_item('Location', $r->location);
        echo $this->detail_item('District', $r->district);
        echo $this->detail_item('Division', $r->division);
        echo $this->detail_item('City', $r->city);
        echo $this->detail_item('Make', $r->make);
        echo $this->detail_item('Model', $r->model);
        echo $this->detail_item('Tag', $r->serial_number);
        echo $this->detail_item('Serial Number', $r->tag_number ?? '');
        echo $this->detail_item('Year Build', $r->year_build);
        echo $this->detail_item('Warranty From', $r->warranty_from);
        echo $this->detail_item('Warranty To', $r->warranty_to);
        echo $this->detail_item('Installation Date', $r->installation_date);
        echo $this->detail_item('Owned By', $r->owned_by);
        echo '</div>';
        echo '<h2>Inventory Status</h2><div class="him-detail-grid him-detail-page-grid">';
        echo $this->detail_item('Inventory Status', $r->inventory_status);
        echo $this->detail_item('Non Functional Description', $r->non_functional_description);
        echo $this->detail_item('Repairable', $r->repairable);
        echo $this->detail_item('Repairable Description', $r->repairable_description);
        echo $this->detail_item('Is Utilized', $r->is_utilized);
        echo '</div>';
        echo '<h2>Service History</h2>' . $this->render_service_history_html($r->service_history ?? '');
        echo '<h2>Hospital / Focal Person</h2><div class="him-detail-grid him-detail-page-grid">';
        echo $this->detail_item('Hospital Name', $display_hospital);
        echo $this->detail_item('Focal Hospital Name', $display_focal_hospital);
        echo $this->detail_item('Focal Person Name', $r->focal_name);
        echo $this->detail_item('Email', $r->focal_email);
        echo $this->detail_item('Contact', $r->focal_contact);
        echo $this->detail_item('Address', $r->focal_address);
        echo '</div>';
        echo '<h2>Images</h2>' . $image_html;
        echo '<h2>Inventory Added Information</h2><div class="him-detail-grid him-detail-page-grid">';
        echo $this->detail_item('Added By', $this->get_user_display_name($r->created_by));
        echo $this->detail_item('Added At', $r->created_at);
        echo '</div>';
        echo '</div></div>';
    }

    public function page_management() {
        if (!$this->can_access_inventory()) { return; }
        if (isset($_GET['inventory'])) {
            global $wpdb;
            $id = absint($_GET['inventory']);
            $record = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id=%d", $id));
            $this->render_inventory_detail_page($record);
            return;
        }
        $records = $this->query_records(false, 1000);
        echo '<div class="wrap him-wrap"><h1>Inventory Management</h1>'; $this->notices();
        $export = wp_nonce_url(admin_url('admin-post.php?action=him_export_inventory'), 'him_export_inventory');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Are you sure you want to delete selected inventory?\')">';
        wp_nonce_field('him_bulk_action'); echo '<input type="hidden" name="action" value="him_bulk_delete_inventory">';
        if ($this->frontend_mode) { echo '<input type="hidden" name="him_return_url" value="' . esc_url($this->current_frontend_url(['him_view'=>'management'])) . '">'; }
        echo '<div class="him-management-actions"><button class="button button-danger him-delete-selected-top" type="submit">Delete Selected</button><a class="button button-primary him-export-selected" data-base="' . esc_url($export) . '">Export Excel</a></div>';
        echo '<div class="table-scroll"><table class="widefat striped him-table him-management-compact-table"><colgroup><col class="him-col-check"><col class="him-col-srno"><col class="him-col-tag"><col class="him-col-inventory"><col class="him-col-status"><col class="him-col-pictures"><col class="him-col-city"><col class="him-col-district"><col class="him-col-action"></colgroup><thead><tr><th><input type="checkbox" class="him-check-all"></th><th>Sr No</th><th>Tag</th><th>Inventory</th><th>Functional Status</th><th>Pictures</th><th>City</th><th>District</th><th>Action</th></tr></thead><tbody>';
        $sr_no = count($records);
        foreach ($records as $r) { $this->table_row($r, $sr_no); $sr_no--; }
        if (!$records) { echo '<tr><td colspan="9">No inventory found.</td></tr>'; }
        echo '</tbody></table></div></form></div>';
    }

    private function table_row($r, $sr_no = null) {
        $imgs = array_filter(array_map('absint', explode(',', (string)$r->image_ids)));
        $picture_text = $imgs ? 'Available(' . count($imgs) . ')' : 'Unavailable';
        $edit = $this->frontend_mode ? $this->current_frontend_url(['him_view'=>'add','edit'=>$r->id]) : admin_url('admin.php?page=hcms-inventory&edit=' . $r->id);
        $delete_base = admin_url('admin-post.php?action=him_delete_inventory&id=' . $r->id);
        if ($this->frontend_mode) { $delete_base = add_query_arg('him_return_url', rawurlencode($this->current_frontend_url(['him_view'=>'management'])), $delete_base); }
        $delete = wp_nonce_url($delete_base, 'him_delete_' . $r->id);

        $image_html = '<div class="him-view-images">';
        if ($imgs) {
            foreach ($imgs as $img_id) { $image_html .= $this->lightbox_image($img_id, 'thumbnail'); }
        } else {
            $image_html .= '<span>No Image</span>';
        }
        $image_html .= '</div>';
        $payload = $this->frontend_mode ? $this->current_frontend_url(['him_view'=>'management','inventory'=>$r->id,'serial'=>rawurlencode($r->serial_number)]) : admin_url('admin.php?page=hcms-inventory-management&inventory=' . $r->id . '&serial=' . rawurlencode($r->serial_number));
        $display_hospital = $this->get_inventory_hospital_name($r);
        $display_focal_hospital = $this->get_inventory_focal_hospital_name($r);

        $detail  = '<div class="him-inline-detail" id="him-detail-' . esc_attr($r->id) . '">';
        $detail .= '<div class="him-detail-title">Inventory Full Detail</div>';
        $detail .= '<div class="him-detail-grid">';
        $detail .= $this->detail_item('Inventory Name', $r->inventory_name);
        $detail .= $this->detail_item('Location', $r->location);
        $detail .= $this->detail_item('District', $r->district);
        $detail .= $this->detail_item('Division', $r->division);
        $detail .= $this->detail_item('City', $r->city);
        $detail .= $this->detail_item('Make', $r->make);
        $detail .= $this->detail_item('Model', $r->model);
        $detail .= $this->detail_item('Tag', $r->serial_number);
        $detail .= $this->detail_item('Serial Number', $r->tag_number ?? '');
        $detail .= $this->detail_item('Year Build', $r->year_build);
        $detail .= $this->detail_item('Warranty From', $r->warranty_from);
        $detail .= $this->detail_item('Warranty To', $r->warranty_to);
        $detail .= $this->detail_item('Installation Date', $r->installation_date);
        $detail .= $this->detail_item('Owned By', $r->owned_by);
        $detail .= $this->detail_item('Inventory Status', $r->inventory_status);
        $detail .= $this->detail_item('Non Functional Description', $r->non_functional_description);
        $detail .= $this->detail_item('Repairable', $r->repairable);
        $detail .= $this->detail_item('Repairable Description', $r->repairable_description);
        $detail .= $this->detail_item('Is Utilized', $r->is_utilized);
        $detail .= '<div class="him-detail-item him-service-history-detail"><strong>Service History</strong>' . $this->render_service_history_html($r->service_history ?? '') . '</div>';
        $detail .= $this->detail_item('Hospital Name', $display_hospital);
        $detail .= $this->detail_item('Focal Hospital Name', $display_focal_hospital);
        $detail .= $this->detail_item('Focal Person Name', $r->focal_name);
        $detail .= $this->detail_item('Email', $r->focal_email);
        $detail .= $this->detail_item('Contact', $r->focal_contact);
        $detail .= $this->detail_item('Address', $r->focal_address);
        $detail .= $this->detail_item('Added By', $this->get_user_display_name($r->created_by));
        $detail .= $this->detail_item('Added At', $r->created_at);
        $detail .= '<div class="him-detail-item him-detail-images"><strong>Images</strong>' . $image_html . '</div>';
        $detail .= '</div>';
        $detail .= '<div class="him-inline-qr-card" data-serial="' . esc_attr($r->serial_number) . '" data-name="' . esc_attr($r->inventory_name) . '" data-hospital="' . esc_attr($display_hospital) . '" data-payload="' . esc_attr($payload) . '">';
        $detail .= '<div class="him-detail-title">QR Code</div><canvas class="him-inline-qr-canvas" width="260" height="300"></canvas>';
        $detail .= '<br><button type="button" class="button button-primary him-download-inline-qr">Download QR Code</button>';
        $detail .= '</div></div>';

        $view_url = $this->inventory_view_url($r);
        $inventory_summary  = '<div class="him-inventory-summary">';
        $inventory_summary .= '<div class="him-inventory-name">' . esc_html($r->inventory_name) . '</div>';
        $inventory_summary .= '<div><strong>Location:</strong> ' . esc_html($r->location ?: '-') . '</div>';
        $inventory_summary .= '<div><strong>Make:</strong> ' . esc_html($r->make ?: '-') . '</div>';
        $inventory_summary .= '<div><strong>Model:</strong> ' . esc_html($r->model ?: '-') . '</div>';
        $inventory_summary .= '<div><strong>Serial Number:</strong> ' . esc_html(($r->tag_number ?? '') ?: '-') . '</div>';
        $inventory_summary .= '<div><strong>Hospital Name:</strong> ' . esc_html($display_hospital ?: '-') . '</div>';
        $inventory_summary .= '</div>';
        $mini_qr = '<div class="him-mini-qr-card" data-qrid="' . esc_attr($r->serial_number ?: $r->id) . '" data-serial="' . esc_attr($r->serial_number) . '" data-name="' . esc_attr($r->inventory_name) . '" data-hospital="' . esc_attr($display_hospital) . '" data-payload="' . esc_attr($payload) . '"><canvas class="him-mini-qr-canvas" width="170" height="230"></canvas></div>';
        echo '<tr><td><input type="checkbox" name="inventory_ids[]" value="' . esc_attr($r->id) . '" class="him-row-check"></td><td>' . esc_html($sr_no !== null ? $sr_no : $r->id) . '</td><td>' . esc_html($r->serial_number) . '</td><td class="him-inventory-cell">' . $inventory_summary . '</td><td>' . $this->status_badge($r->inventory_status) . '</td><td>' . esc_html($picture_text) . '</td><td>' . esc_html($r->city) . '</td><td>' . esc_html($r->district) . '</td><td class="him-action-buttons"><div class="him-action-button-row"><a class="button him-action-btn him-action-view him-view-link" target="_blank" href="' . esc_url($view_url) . '">View</a><a class="button him-action-btn him-action-edit" href="' . esc_url($edit) . '">Edit</a><a class="button him-action-btn him-action-delete" onclick="return confirm(&quot;Delete this inventory?&quot;)" href="' . esc_url($delete) . '">Delete</a></div></td></tr>';
    }

    private function detail_item($label, $value) {
        $value = ($value !== null && $value !== '') ? $value : '-';
        return '<div class="him-detail-item"><strong>' . esc_html($label) . '</strong><span>' . nl2br(esc_html($value)) . '</span></div>';
    }

    private function build_inline_detail($r) {
        $imgs = array_filter(array_map('absint', explode(',', (string)$r->image_ids)));
        $image_html = '<div class="him-view-images">';
        if ($imgs) {
            foreach ($imgs as $img_id) { $image_html .= $this->lightbox_image($img_id, 'thumbnail'); }
        } else {
            $image_html .= '<span>No Image</span>';
        }
        $image_html .= '</div>';
        $payload = $this->frontend_mode ? $this->current_frontend_url(['him_view'=>'management','inventory'=>$r->id,'serial'=>rawurlencode($r->serial_number)]) : admin_url('admin.php?page=hcms-inventory-management&inventory=' . $r->id . '&serial=' . rawurlencode($r->serial_number));
        $display_hospital = $this->get_inventory_hospital_name($r);
        $display_focal_hospital = $this->get_inventory_focal_hospital_name($r);
        $detail  = '<div class="him-inline-detail" id="him-detail-' . esc_attr($r->id) . '">';
        $detail .= '<div class="him-detail-title">Inventory Full Detail</div>';
        $detail .= '<div class="him-detail-grid">';
        $detail .= $this->detail_item('Inventory Name', $r->inventory_name);
        $detail .= $this->detail_item('Location', $r->location);
        $detail .= $this->detail_item('District', $r->district);
        $detail .= $this->detail_item('Division', $r->division);
        $detail .= $this->detail_item('City', $r->city);
        $detail .= $this->detail_item('Make', $r->make);
        $detail .= $this->detail_item('Model', $r->model);
        $detail .= $this->detail_item('Tag', $r->serial_number);
        $detail .= $this->detail_item('Serial Number', $r->tag_number ?? '');
        $detail .= $this->detail_item('Year Build', $r->year_build);
        $detail .= $this->detail_item('Warranty From', $r->warranty_from);
        $detail .= $this->detail_item('Warranty To', $r->warranty_to);
        $detail .= $this->detail_item('Installation Date', $r->installation_date);
        $detail .= $this->detail_item('Owned By', $r->owned_by);
        $detail .= $this->detail_item('Inventory Status', $r->inventory_status);
        $detail .= $this->detail_item('Non Functional Description', $r->non_functional_description);
        $detail .= $this->detail_item('Repairable', $r->repairable);
        $detail .= $this->detail_item('Repairable Description', $r->repairable_description);
        $detail .= $this->detail_item('Is Utilized', $r->is_utilized);
        $detail .= '<div class="him-detail-item him-service-history-detail"><strong>Service History</strong>' . $this->render_service_history_html($r->service_history ?? '') . '</div>';
        $detail .= $this->detail_item('Hospital Name', $display_hospital);
        $detail .= $this->detail_item('Focal Hospital Name', $display_focal_hospital);
        $detail .= $this->detail_item('Focal Person Name', $r->focal_name);
        $detail .= $this->detail_item('Email', $r->focal_email);
        $detail .= $this->detail_item('Contact', $r->focal_contact);
        $detail .= $this->detail_item('Address', $r->focal_address);
        $detail .= $this->detail_item('Added By', $this->get_user_display_name($r->created_by));
        $detail .= $this->detail_item('Added At', $r->created_at);
        $detail .= '<div class="him-detail-item him-detail-images"><strong>Images</strong>' . $image_html . '</div>';
        $detail .= '</div>';
        $detail .= '<div class="him-inline-qr-card" data-serial="' . esc_attr($r->serial_number) . '" data-name="' . esc_attr($r->inventory_name) . '" data-hospital="' . esc_attr($display_hospital) . '" data-payload="' . esc_attr($payload) . '">';
        $detail .= '<div class="him-detail-title">QR Code</div><canvas class="him-inline-qr-canvas" width="260" height="300"></canvas>';
        $detail .= '<br><button type="button" class="button button-primary him-download-inline-qr">Download QR Code</button>';
        $detail .= '</div></div>';
        return $detail;
    }

    private function search_table_row($r) {
        $imgs = array_filter(array_map('absint', explode(',', (string)$r->image_ids)));
        $view_url = $this->inventory_view_url($r);
        echo '<tr><td>' . esc_html($r->id) . '</td><td class="him-inventory-cell"><div class="him-inventory-name">' . esc_html($r->inventory_name) . '</div></td><td>' . $this->status_badge($r->inventory_status) . '</td><td>' . esc_html($this->get_latest_service_date($r->service_history ?? '')) . '</td><td>' . esc_html($this->image_count_text($r->image_ids)) . '</td><td>' . esc_html($r->city) . '</td><td>' . esc_html($r->district) . '</td><td>' . esc_html($r->division) . '</td><td><a class="button him-view-link" target="_blank" href="' . esc_url($view_url) . '">View</a></td></tr>';
    }

    public function page_search() {
        if (!$this->can_access_inventory()) { return; }
        $did_search = isset($_GET['him_do_search']);
        $records = $did_search ? $this->query_records(true, 1000) : [];
        echo '<div class="wrap him-wrap"><h1>Search Inventory</h1><form method="get" action="' . esc_url($this->frontend_mode ? $this->current_frontend_url() : admin_url('admin.php')) . '" class="him-card him-search-form">';
        if ($this->frontend_mode) { echo '<input type="hidden" name="him_view" value="search">'; } else { echo '<input type="hidden" name="page" value="hcms-inventory-search">'; }
        echo '<input type="hidden" name="him_do_search" value="1"><div class="him-grid">';
        $fields = ['inventory_name'=>'Inventory Name','location'=>'Location','district'=>'District','division'=>'Division','city'=>'City','make'=>'Make','model'=>'Model','serial_number'=>'Tag','tag_number'=>'Serial Number','year_build'=>'Year Build','warranty_from'=>'Warranty From','warranty_to'=>'Warranty To','installation_date'=>'Installation Date','qr_code_number'=>'QR Code Number','hospital_name'=>'Hospital Name','focal_hospital_name'=>'Focal Hospital Name','focal_name'=>'Focal Person Name','focal_email'=>'Email','focal_contact'=>'Contact','focal_address'=>'Address'];
        foreach ($fields as $name=>$label) { $type = in_array($name,['year_build','warranty_from','warranty_to','installation_date']) ? 'date' : 'text'; echo '<label><span>' . esc_html($label) . '</span><input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($_GET[$name] ?? '') . '"></label>'; }
        $this->search_select('Images','images',['available'=>'Available','not_available'=>'Not Available']);
        $this->search_select('Owned By','owned_by',array_combine($this->owned_by,$this->owned_by));
        $this->search_select('Inventory Status','inventory_status',['Functional'=>'Functional','Non Functional'=>'Non Functional']);
        $this->search_select('Repairable','repairable',['Functional'=>'Functional','Non Functional'=>'Non Functional']);
        $this->search_select('Is Utilized','is_utilized',['Yes'=>'Yes','No'=>'No']);
        echo '</div><p><button class="button button-primary">Search the Above Criteria</button></p></form>';
        if ($did_search) {
            $export_url = wp_nonce_url(add_query_arg(array_merge($_GET, ['action'=>'him_export_inventory','search_export'=>1]), admin_url('admin-post.php')), 'him_export_inventory');
            echo '<p><a class="button button-primary" href="' . esc_url($export_url) . '">Export Filtered Excel</a></p>';
            echo '<div class="table-scroll"><table class="widefat striped him-table"><thead><tr><th>Serial</th><th>Inventory</th><th>Status</th><th>Service History</th><th>Pictures</th><th>City</th><th>District</th><th>Division</th><th>Actions</th></tr></thead><tbody>';
            foreach ($records as $r) { $this->search_table_row($r); }
            if (!$records) { echo '<tr><td colspan="9">No matching inventory found.</td></tr>'; }
            echo '</tbody></table></div>';
        }
        echo '</div>';
    }

    private function search_select($label,$name,$options) {
        $val = isset($_GET[$name]) ? sanitize_text_field(wp_unslash($_GET[$name])) : '';
        echo '<label><span>' . esc_html($label) . '</span><select name="' . esc_attr($name) . '"><option value="">-- Any --</option>';
        foreach ($options as $k=>$v) { echo '<option value="' . esc_attr($k) . '" ' . selected($val,$k,false) . '>' . esc_html($v) . '</option>'; }
        echo '</select></label>';
    }

    public function page_qr() {
        if (!$this->can_access_inventory()) { return; }
        $records = $this->query_records(false, 2000);
        echo '<div class="wrap him-wrap him-qr-page"><h1>QR Code</h1><div class="him-qr-actions"><button type="button" class="button button-primary him-download-selected-qr">Download QR Code</button><button type="button" class="button him-download-all-qr">Download All QR Codes</button></div>';
        echo '<div class="him-qr-grid">';
        foreach ($records as $r) {
            $payload = $this->inventory_view_url($r);
            $display_hospital = $this->get_inventory_hospital_name($r);
            echo '<div class="him-qr-card" data-serial="' . esc_attr($r->serial_number) . '" data-name="' . esc_attr($r->inventory_name) . '" data-hospital="' . esc_attr($display_hospital) . '" data-payload="' . esc_attr($payload) . '"><label><input type="checkbox" class="him-qr-check"> Select</label><h3>' . esc_html($r->inventory_name) . '</h3><div class="him-qr-hospital">' . esc_html($display_hospital ?: '-') . '</div><div class="him-serial">' . esc_html($r->serial_number) . '</div><canvas class="him-qr-canvas" width="260" height="300"></canvas></div>';
        }
        if (!$records) { echo '<p>No QR codes found.</p>'; }
        echo '</div></div>';
    }
}

HIM_Hospital_Inventory_Management::instance();
