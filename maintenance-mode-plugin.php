<?php
/*
Plugin Name: Maintenance Mode
Description: Displays a custom maintenance page when activated manually or via timers. Ability to use your own HTML code. Access to the site based on user roles.
Version: 1.5
Author: <a href="https://www.linkedin.com/in/denis-nazarow/" target="_blank" rel="noopener noreferrer">Denis Nazarow</a>
Requires at least: 4.6
Requires PHP: 5.6
Text Domain: maintenance-mode-plugin
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit; 
}

class Maintenance_Mode_Plugin {
    private $is_updating = false;

    public function __construct() {
        // Загружаем текстовый домен для переводов
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        
        add_action('admin_menu', array($this, 'create_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('template_redirect', array($this, 'check_maintenance_mode'));
        add_action('admin_notices', array($this, 'display_admin_notice'));

        add_action('upgrader_pre_install', array($this, 'start_maintenance_mode_on_update'), 10, 2);
        add_action('upgrader_process_complete', array($this, 'stop_maintenance_mode_on_update'), 10, 2);
        add_action('pre_auto_update', array($this, 'start_maintenance_mode_on_core_update'), 10, 2);
        add_action('auto_update_complete', array($this, 'stop_maintenance_mode_on_core_update'), 10, 2);

        add_filter('enable_maintenance_mode', array($this, 'disable_default_maintenance_mode'), 10, 2);

        // Добавляем ссылку "Настройки" в список действий плагина
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));

        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate_plugin'));
    }

    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=maintenance-mode') . '">' . __('Settings', 'maintenance-mode-plugin') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    public function load_textdomain() {
        load_plugin_textdomain('maintenance-mode-plugin', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    public function create_admin_menu() {
        add_options_page(
            __('Maintenance Mode', 'maintenance-mode-plugin'),
            __('Maintenance Mode', 'maintenance-mode-plugin'),
            'manage_options',
            'maintenance-mode',
            array($this, 'settings_page')
        );
    }

    public function register_settings() {
        register_setting('maintenance_mode_settings_group', 'maintenance_mode_enabled');
        register_setting('maintenance_mode_settings_group', 'maintenance_mode_custom_html');
        register_setting('maintenance_mode_settings_group', 'maintenance_mode_start_time');
        register_setting('maintenance_mode_settings_group', 'maintenance_mode_end_time');
        register_setting('maintenance_mode_settings_group', 'maintenance_mode_allowed_roles');
        register_setting('maintenance_mode_settings_group', 'maintenance_mode_completed_notice', array('default' => ''));

        add_settings_section(
            'maintenance_mode_settings_section',
            __('Maintenance Mode Settings', 'maintenance-mode-plugin'),
            null,
            'maintenance-mode'
        );

        add_settings_field(
            'maintenance_mode_enabled',
            __('Enable Maintenance Mode', 'maintenance-mode-plugin'),
            array($this, 'render_maintenance_mode_enabled_field'),
            'maintenance-mode',
            'maintenance_mode_settings_section'
        );
        add_settings_field(
            'maintenance_mode_custom_html',
            __('Custom HTML', 'maintenance-mode-plugin'),
            array($this, 'render_custom_html_field'),
            'maintenance-mode',
            'maintenance_mode_settings_section'
        );
        add_settings_field(
            'maintenance_mode_start_time',
            __('Start Time (optional)', 'maintenance-mode-plugin'),
            array($this, 'render_start_time_field'),
            'maintenance-mode',
            'maintenance_mode_settings_section'
        );
        add_settings_field(
            'maintenance_mode_end_time',
            __('End Time (optional)', 'maintenance-mode-plugin'),
            array($this, 'render_end_time_field'),
            'maintenance-mode',
            'maintenance_mode_settings_section'
        );
        add_settings_field(
            'maintenance_mode_allowed_roles',
            __('Allowed Roles', 'maintenance-mode-plugin'),
            array($this, 'render_allowed_roles_field'),
            'maintenance-mode',
            'maintenance_mode_settings_section'
        );
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <form method="post" action="options.php">
                <?php
                settings_fields('maintenance_mode_settings_group');
                do_settings_sections('maintenance-mode');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function render_maintenance_mode_enabled_field() {
        $value = get_option('maintenance_mode_enabled');
        echo '<input type="checkbox" name="maintenance_mode_enabled" value="1"' . checked(1, $value, false) . '>';
    }

    public function render_custom_html_field() {
        $value = get_option('maintenance_mode_custom_html');
        echo '<textarea name="maintenance_mode_custom_html" rows="10" cols="50" class="large-text">' . esc_textarea($value) . '</textarea>';
    }

    public function render_start_time_field() {
        $value = get_option('maintenance_mode_start_time', '');
        echo '<input type="datetime-local" name="maintenance_mode_start_time" value="' . esc_attr($value) . '">';
        echo '<p class="description">' . __('Set the start time for scheduled maintenance (optional).', 'maintenance-mode-plugin') . '</p>';
    }

    public function render_end_time_field() {
        $value = get_option('maintenance_mode_end_time', '');
        echo '<input type="datetime-local" name="maintenance_mode_end_time" value="' . esc_attr($value) . '">';
        echo '<p class="description">' . __('Set the end time for scheduled maintenance (optional).', 'maintenance-mode-plugin') . '</p>';
    }

    public function render_allowed_roles_field() {
        $allowed_roles = get_option('maintenance_mode_allowed_roles', array());
        if (!is_array($allowed_roles)) $allowed_roles = (array) $allowed_roles;
        $roles = get_editable_roles();
        foreach ($roles as $role_key => $role_data) {
            $checked = in_array($role_key, $allowed_roles) ? 'checked' : '';
            echo '<label><input type="checkbox" name="maintenance_mode_allowed_roles[]" value="' . esc_attr($role_key) . '" ' . $checked . '> ' . esc_html($role_data['name']) . '</label><br>';
        }
        echo '<p class="description">' . __('Select roles that can access the site during maintenance.', 'maintenance-mode-plugin') . '</p>';
    }

    private function get_maintenance_status() {
        $is_enabled = get_option('maintenance_mode_enabled');
        $start_time = get_option('maintenance_mode_start_time');
        $end_time = get_option('maintenance_mode_end_time');
        $current_time = current_time('timestamp');

        $wp_timezone = get_option('timezone_string');
        if ($wp_timezone) {
            $timezone = new DateTimeZone($wp_timezone);
        } else {
            $gmt_offset = get_option('gmt_offset');
            $offset_hours = floor($gmt_offset);
            $offset_minutes = ($gmt_offset - $offset_hours) * 60;
            $timezone_string = sprintf('%+03d:%02d', $offset_hours, abs($offset_minutes));
            $timezone = new DateTimeZone($timezone_string);
        }

        $is_scheduled = false;
        $start_timestamp = null;
        $end_timestamp = null;

        if ($start_time) {
            try {
                $start_date = DateTime::createFromFormat('Y-m-d\TH:i', $start_time, new DateTimeZone('UTC'));
                if ($start_date) {
                    $start_date->setTimezone($timezone);
                    $start_timestamp = $start_date->getTimestamp();
                    error_log("Start time: $start_time, Timestamp: $start_timestamp (" . date('Y-m-d H:i', $start_timestamp) . ")");
                }
            } catch (Exception $e) {
                error_log("Error parsing start time: " . $e->getMessage());
            }
        }

        if ($end_time) {
            try {
                $end_date = DateTime::createFromFormat('Y-m-d\TH:i', $end_time, new DateTimeZone('UTC'));
                if ($end_date) {
                    $end_date->setTimezone($timezone);
                    $end_timestamp = $end_date->getTimestamp();
                    error_log("End time: $end_time, Timestamp: $end_timestamp (" . date('Y-m-d H:i', $end_timestamp) . ")");
                }
            } catch (Exception $e) {
                error_log("Error parsing end time: " . $e->getMessage());
            }
        }

        error_log("Current time: $current_time (" . date('Y-m-d H:i', $current_time) . ")");

        if ($start_timestamp && !$end_timestamp) {
            $is_scheduled = ($current_time >= $start_timestamp);
            if ($is_scheduled && !$is_enabled) {
                update_option('maintenance_mode_enabled', true);
                $is_enabled = true;
            }
        } elseif ($end_timestamp && !$start_timestamp) {
            if ($current_time > $end_timestamp && $is_enabled && !$this->is_updating) {
                update_option('maintenance_mode_enabled', false);
                $is_enabled = false;
            }
        } elseif ($start_timestamp && $end_timestamp) {
            $is_scheduled = ($current_time >= $start_timestamp && $current_time <= $end_timestamp);
            if ($is_scheduled && !$is_enabled) {
                update_option('maintenance_mode_enabled', true);
                $is_enabled = true;
            } elseif ($current_time > $end_timestamp && $is_enabled && !$this->is_updating) {
                update_option('maintenance_mode_enabled', false);
                $is_enabled = false;
            }
        }

        $is_maintenance_active = $is_enabled || $this->is_updating || $is_scheduled;
        $reason = '';
        if ($this->is_updating) {
            $reason = __('The site is currently being updated.', 'maintenance-mode-plugin');
        } elseif ($is_scheduled) {
            $reason = __('The site is in scheduled maintenance mode.', 'maintenance-mode-plugin');
        } elseif ($is_enabled) {
            $reason = __('The site is in manual maintenance mode.', 'maintenance-mode-plugin');
        }

        return array(
            'active' => $is_maintenance_active,
            'reason' => $reason,
            'scheduled' => $is_scheduled,
            'start_timestamp' => $start_timestamp,
            'end_timestamp' => $end_timestamp
        );
    }

    public function check_maintenance_mode() {
        $status = $this->get_maintenance_status();
        $allowed_roles = get_option('maintenance_mode_allowed_roles', array());
        if (!is_array($allowed_roles)) $allowed_roles = (array) $allowed_roles;
        $user = wp_get_current_user();
        $user_roles = (array) $user->roles;
        $has_allowed_role = !empty(array_intersect($allowed_roles, $user_roles));

        $start_time = get_option('maintenance_mode_start_time');
        $end_time = get_option('maintenance_mode_end_time');
        $current_time = current_time('timestamp');

        if ($end_time) {
            $wp_timezone = get_option('timezone_string');
            if (!$wp_timezone) {
                $gmt_offset = get_option('gmt_offset');
                $offset_hours = floor($gmt_offset);
                $offset_minutes = ($gmt_offset - $offset_hours) * 60;
                $timezone_string = sprintf('%+03d:%02d', $offset_hours, abs($offset_minutes));
                $timezone = new DateTimeZone($timezone_string);
            } else {
                $timezone = new DateTimeZone($wp_timezone);
            }
            try {
                $end_date = DateTime::createFromFormat('Y-m-d\TH:i', $end_time, new DateTimeZone('UTC'));
                if ($end_date) {
                    $end_date->setTimezone($timezone);
                    $end_timestamp = $end_date->getTimestamp();
                    if ($current_time > $end_timestamp && !$this->is_updating) {
                        update_option('maintenance_mode_start_time', '');
                        update_option('maintenance_mode_end_time', '');
                        $completed_time = date_i18n('Y-m-d H:i', $end_timestamp);
                        update_option('maintenance_mode_completed_notice', sprintf(
                            /* translators: %s is the date and time when maintenance ended */
                            __('Scheduled maintenance completed at %s.', 'maintenance-mode-plugin'),
                            $completed_time
                        ));
                        error_log("Maintenance ended. Current: $current_time, End: $end_timestamp, Completed: $completed_time");
                    }
                }
            } catch (Exception $e) {
                error_log("Error in check_maintenance_mode: " . $e->getMessage());
            }
        }

        if ($status['active'] && !$has_allowed_role) {
            $this->display_maintenance_page();
            exit;
        }
    }

    public function display_admin_notice() {
        $status = $this->get_maintenance_status();
        $completed_notice = get_option('maintenance_mode_completed_notice', '');
        $start_time = get_option('maintenance_mode_start_time');
        $end_time = get_option('maintenance_mode_end_time');
        $current_time = current_time('timestamp');

        $wp_timezone = get_option('timezone_string');
        if ($wp_timezone) {
            $timezone = new DateTimeZone($wp_timezone);
        } else {
            $gmt_offset = get_option('gmt_offset');
            $offset_hours = floor($gmt_offset);
            $offset_minutes = ($gmt_offset - $offset_hours) * 60;
            $timezone_string = sprintf('%+03d:%02d', $offset_hours, abs($offset_minutes));
            $timezone = new DateTimeZone($timezone_string);
        }

        $start_timestamp = null;
        $end_timestamp = null;
        if ($start_time) {
            $start_date = DateTime::createFromFormat('Y-m-d\TH:i', $start_time, new DateTimeZone('UTC'));
            if ($start_date) {
                $start_date->setTimezone($timezone);
                $start_timestamp = $start_date->getTimestamp();
            }
        }
        if ($end_time) {
            $end_date = DateTime::createFromFormat('Y-m-d\TH:i', $end_time, new DateTimeZone('UTC'));
            if ($end_date) {
                $end_date->setTimezone($timezone);
                $end_timestamp = $end_date->getTimestamp();
            }
        }

        // Уведомление о предстоящем обслуживании
        if ($start_timestamp && $current_time < $start_timestamp) {
            $message = sprintf(
                /* translators: %s is the start time of scheduled maintenance */
                __('The site will be in scheduled maintenance mode. Scheduled to start at %s', 'maintenance-mode-plugin'),
                date_i18n('Y-m-d H:i', $start_timestamp)
            );
            if ($end_timestamp) {
                $message .= sprintf(
                    /* translators: %s is the end time of scheduled maintenance */
                    __(' until %s', 'maintenance-mode-plugin'),
                    date_i18n('Y-m-d H:i', $end_timestamp)
                );
            }
            $message .= '.';
            echo '<div class="notice notice-info is-dismissible"><p><strong>' . __('Maintenance Mode:', 'maintenance-mode-plugin') . '</strong> ' . esc_html($message) . ' <a href="' . admin_url('options-general.php?page=maintenance-mode') . '">' . __('View settings', 'maintenance-mode-plugin') . '</a></p></div>';
        }

        // Уведомление о текущем обслуживании
        if ($status['active']) {
            $reason = $status['reason'];
            if ($status['scheduled'] && $end_timestamp) {
                $reason = sprintf(
                    /* translators: %s is the end time of scheduled maintenance */
                    __('The site is in scheduled maintenance mode. Scheduled to end at %s.', 'maintenance-mode-plugin'),
                    date_i18n('Y-m-d H:i', $end_timestamp)
                );
            }
            echo '<div class="notice notice-error is-dismissible"><p><strong>' . __('Maintenance Mode Active:', 'maintenance-mode-plugin') . '</strong> ' . esc_html($reason) . ' <a href="' . admin_url('options-general.php?page=maintenance-mode') . '">' . __('Manage settings', 'maintenance-mode-plugin') . '</a></p></div>';
        }

        // Уведомление о завершении
        if (!empty($completed_notice)) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>' . __('Maintenance Mode:', 'maintenance-mode-plugin') . '</strong> ' . esc_html($completed_notice) . ' <a href="' . admin_url('options-general.php?page=maintenance-mode') . '">' . __('View settings', 'maintenance-mode-plugin') . '</a></p></div>';
            update_option('maintenance_mode_completed_notice', '');
        }
    }

    public function start_maintenance_mode_on_update($upgrader, $extra) {
        if (isset($extra['type']) && in_array($extra['type'], ['plugin', 'theme'])) $this->is_updating = true;
    }

    public function stop_maintenance_mode_on_update($upgrader, $extra) {
        if (isset($extra['type']) && in_array($extra['type'], ['plugin', 'theme'])) $this->is_updating = false;
    }

    public function start_maintenance_mode_on_core_update($type, $item) {
        if ($type === 'core') $this->is_updating = true;
    }

    public function stop_maintenance_mode_on_core_update($type, $item) {
        if ($type === 'core') $this->is_updating = false;
    }

    public function disable_default_maintenance_mode($enable, $context) {
        if ($context === 'install' || $context === 'update') return false;
        return $enable;
    }

    public function display_maintenance_page() {
        $custom_html = get_option('maintenance_mode_custom_html', '');
        if (!empty($custom_html)) {
            echo $custom_html;
        } else {
            // Определяем язык браузера
            $browser_lang = $this->get_browser_language();
    
            // Устанавливаем локаль на основе языка браузера
            $this->set_plugin_locale($browser_lang);

            // Получаем название сайта
            $site_name = get_bloginfo('name');
    
            // HTML-шаблон с переведенными строками
            ?>
            <!DOCTYPE html>
            <html lang="<?php echo esc_attr($browser_lang); ?>">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title><?php echo esc_html(sprintf(__('%s - under maintenance', 'maintenance-mode-plugin'), $site_name)); ?></title>
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
                    integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A=="
                    crossorigin="anonymous" referrerpolicy="no-referrer" />
                <style>
                    @import url(https://fonts.googleapis.com/css?family=Ubuntu:300,300italic,regular,italic,500,500italic,700,700italic);
    
                    body {
                        font-family: Ubuntu, 'Open Sans', system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
                        color: #eee;
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        height: 100vh;
                        margin: 0;
                        padding: 0;
                        box-sizing: border-box;
                    }
    
                    section.maintenance {
                        position: relative;
                        width: 100%;
                        height: 100vh;
                        overflow: hidden;
                        background-image: url(<?php echo esc_url(plugins_url('bg.jpg', __FILE__)); ?>);
                        background-color: #111;
                        background-size: cover;
                        background-position: center;
                    }
    
                    section.maintenance::before {
                        content: "";
                        position: absolute;
                        top: 0;
                        left: 0;
                        width: 100%;
                        height: 100%;
                        background: radial-gradient(transparent, transparent, rgba(0, 0, 0, 0.3333333333), rgba(0, 0, 0, 0.2666666667));
                        z-index: 1000;
                        pointer-events: none;
                    }
    
                    section.maintenance .content {
                        position: absolute;
                        inset: 0;
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        flex-direction: column;
                        height: 100dvh;
                        max-width: 800px;
                        margin: 0 auto;
                    }
    
                    section.maintenance .content h1,
                    section.maintenance .content h2,
                    section.maintenance .content p {
                        text-align: center;
                        text-wrap: balance;
                        text-shadow: 0 15px 10px rgba(0, 0, 0, 0.5);
                        margin: 0;
                        letter-spacing: 2px;
                        animation: flicker-text 4s infinite;
                    }
    
                    @keyframes flicker-text {
                        0%, 19%, 21%, 23%, 25%, 54%, 56%, 100% {
                            text-shadow: 0 15px 10px rgba(0, 0, 0, 0.5);
                        }
                        20%, 24%, 55% {
                            text-shadow: none;
                        }
                    }
    
                    section.maintenance .content h1,
                    section.maintenance .content h2 {
                        text-transform: uppercase;
                    }
    
                    section.maintenance .content .title {
                        font-size: 2.25em;
                        font-weight: 200;
                        margin-top: 1rem;
                    }
    
                    section.maintenance .content .subtitle {
                        font-size: 2.5em;
                        margin-bottom: 1rem;
                    }
    
                    section.maintenance .content p {
                        margin-bottom: 0.375rem;
                    }
    
                    section.maintenance .content .icon {
                        display: inline-block;
                        padding: 30px;
                        background: #eee;
                        color: #333;
                        border-radius: 50%;
                        border: 5px solid #ffaa01;
                        box-shadow: 0 0 50px rgba(246, 164, 1, 0.25);
                        animation: flicker 4s infinite;
                    }
    
                    section.maintenance .content .icon svg {
                        fill: #333;
                        width: 100px;
                        height: 100px;
                    }
    
                    @keyframes flicker {
                        0%, 19%, 21%, 23%, 25%, 54%, 56%, 100% {
                            box-shadow: 0 0 50px rgba(246, 164, 1, 0.25);
                        }
                        20%, 24%, 55% {
                            box-shadow: none;
                        }
                    }
    
                    section.maintenance .scroll {
                        --size-strip: 400px;
                        position: absolute;
                        width: calc(100% + var(--size-strip));
                        left: calc(var(--size-strip) * -1);
                        display: flex;
                        color: #fff;
                        box-shadow: 0 15px 10px rgba(0, 0, 0, 0.5);
                        transform: rotate(calc(var(--d) * 1deg)) translateY(calc(var(--y) * 1px));
                    }
    
                    section.maintenance .scroll div {
                        --animation-time: 800s;
                        background: #ffaa01;
                        color: #1d1104;
                        font-size: 2em;
                        text-transform: uppercase;
                        letter-spacing: 0.2em;
                        font-weight: 600;
                        white-space: nowrap;
                        animation: animate1 var(--animation-time) linear infinite;
                        animation-delay: calc(var(--animation-time) * -1);
                    }
    
                    section.maintenance .scroll div:nth-child(2) {
                        animation: animate2 var(--animation-time) linear infinite;
                        animation-delay: calc(var(--animation-time) * -1 / 2);
                    }
    
                    section.maintenance .scroll div span {
                        text-shadow: 0 5px 10px rgba(0, 0, 0, 0.5);
                    }
    
                    @keyframes animate1 {
                        0% {
                            transform: translateX(100%);
                        }
                        100% {
                            transform: translateX(-100%);
                        }
                    }
    
                    @keyframes animate2 {
                        0% {
                            transform: translateX(0);
                        }
                        100% {
                            transform: translateX(-200%);
                        }
                    }
                </style>
            </head>
            <body>
                <section class="maintenance">
                    <div class="content">
                        <div class="icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                                <path d="M78.6 5C69.1-2.4 55.6-1.5 47 7L7 47c-8.5 8.5-9.4 22-2.1 31.6l80 104c4.5 5.9 11.6 9.4 19 9.4l54.1 0 109 109c-14.7 29-10 65.4 14.3 89.6l112 112c12.5 12.5 32.8 12.5 45.3 0l64-64c12.5-12.5 12.5-32.8 0-45.3l-112-112c-24.2-24.2-60.6-29-89.6-14.3l-109-109 0-54.1c0-7.5-3.5-14.5-9.4-19L78.6 5zM19.9 396.1C7.2 408.8 0 426.1 0 444.1C0 481.6 30.4 512 67.9 512c18 0 35.3-7.2 48-19.9L233.7 374.3c-7.8-20.9-9-43.6-3.6-65.1l-61.7-61.7L19.9 396.1zM512 144c0-10.5-1.1-20.7-3.2-30.5c-2.4-11.2-16.1-14.1-24.2-6l-63.9 63.9c-3 3-7.1 4.7-11.3 4.7L352 176c-8.8 0-16-7.2-16-16l0-57.4c0-4.2 1.7-8.3 4.7-11.3l63.9-63.9c8.1-8.1 5.2-21.8-6-24.2C388.7 1.1 378.5 0 368 0C288.5 0 224 64.5 224 144l0 .8 85.3 85.3c36-9.1 75.8 .5 104 28.7L429 274.5c49-23 83-72.8 83-130.5zM56 432a24 24 0 1 1 48 0 24 24 0 1 1 -48 0z"/>
                            </svg>
                        </div>
                        <h2 class="title"><?php _e('Website currently', 'maintenance-mode-plugin'); ?></h2>
                        <h1 class="subtitle"><?php _e('under maintenance', 'maintenance-mode-plugin'); ?></h1>
                        <p><?php _e('We apologize for the inconvenience.', 'maintenance-mode-plugin'); ?></p>
                        <p><?php _e('We\'ll be right back. Thank you for your patience.', 'maintenance-mode-plugin'); ?></p>
                    </div>
    
                    <?php
                    // Массив параметров для бегущих строк
                    $scrolls = [
                        ['d' => 7, 'y' => 40],
                        ['d' => -5, 'y' => 840],
                        ['d' => 3, 'y' => 700],
                        ['d' => -5, 'y' => 50],
                        ['d' => -25, 'y' => 900],
                    ];
    
                    foreach ($scrolls as $scroll) {
                        ?>
                        <div class="scroll" style="--d:<?php echo esc_attr($scroll['d']); ?>; --y:<?php echo esc_attr($scroll['y']); ?>;">
                            <div>
                                <span><?php _e('The site is under maintenance and will be available soon. We apologize for the inconvenience - The site is under maintenance and will be available soon. We apologize for the inconvenience - The site is under maintenance and will be available soon. We apologize for the inconvenience', 'maintenance-mode-plugin'); ?></span>
                            </div>
                            <div>
                                <span><?php _e('The site is under maintenance and will be available soon. We apologize for the inconvenience - The site is under maintenance and will be available soon. We apologize for the inconvenience - The site is under maintenance and will be available soon. We apologize for the inconvenience', 'maintenance-mode-plugin'); ?></span>
                            </div>
                        </div>
                        <?php
                    }
                    ?>
                </section>
            </body>
            </html>
            <?php
        }
        header('HTTP/1.1 503 Service Temporarily Unavailable');
        header('Retry-After: 3600');
        exit;
    }

    /**
 * Определяет язык браузера пользователя
 * @return string Код языка (например, 'ru', 'uk', 'pl', 'en')
 */
private function get_browser_language() {
    $default_lang = 'en'; // Английский по умолчанию
    $supported_langs = ['ru', 'uk', 'pl', 'en'];

    // Получаем заголовок Accept-Language
    $accept_language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
    if (empty($accept_language)) {
        return $default_lang;
    }

    // Разбираем заголовок Accept-Language
    $languages = explode(',', $accept_language);
    $preferred_lang = 'en';

    foreach ($languages as $lang) {
        // Убираем приоритет (q=0.8) и приводим к нижнему регистру
        $lang = explode(';', $lang)[0];
        $lang = strtolower(trim($lang));

        // Проверяем первые два символа (например, 'ru', 'uk', 'pl')
        $lang_code = substr($lang, 0, 2);

        if (in_array($lang_code, $supported_langs)) {
            $preferred_lang = $lang_code;
            break;
        }
    }

    return $preferred_lang;
}

/**
 * Устанавливает локаль плагина на основе языка браузера
 * @param string $lang Код языка (например, 'ru', 'uk', 'pl', 'en')
 */
private function set_plugin_locale($lang) {
    $locale_map = [
        'ru' => 'ru_RU',
        'uk' => 'uk_UA',
        'pl' => 'pl_PL',
        'en' => 'en_US',
    ];

    $locale = isset($locale_map[$lang]) ? $locale_map[$lang] : 'en_US';

    // Загружаем переводы для выбранной локали
    unload_textdomain('maintenance-mode-plugin');
    load_textdomain('maintenance-mode-plugin', plugin_dir_path(__FILE__) . "languages/maintenance-mode-plugin-{$locale}.mo");
}

    public function activate_plugin() {
        add_option('maintenance_mode_enabled', false);
        add_option('maintenance_mode_custom_html', '');
        add_option('maintenance_mode_start_time', '');
        add_option('maintenance_mode_end_time', '');
        add_option('maintenance_mode_allowed_roles', array('administrator'));
        add_option('maintenance_mode_completed_notice', '');
    }

    public function deactivate_plugin() {
        delete_option('maintenance_mode_enabled');
        delete_option('maintenance_mode_custom_html');
        delete_option('maintenance_mode_start_time');
        delete_option('maintenance_mode_end_time');
        delete_option('maintenance_mode_allowed_roles');
        delete_option('maintenance_mode_completed_notice');
    }
}

new Maintenance_Mode_Plugin();