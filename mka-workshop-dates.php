<?php
/*
Plugin Name: WordPress recurring events for Book Art Museum
Description: Metabox z wieloma terminami . Zapis do _workshop_next_date, _workshop_next_start_time, _workshop_next_end_time (JSON) + auto aktualizacja najblizszego terminu.
Version: 1.1.31
Author: Piotrek Wilkocki  <a href="https://peterwolf.pl">peterwolf.pl</a>
License: GPLv2 or later
*/

if (!defined('ABSPATH')) { exit; }

final class MKA_Workshop_Dates_OptionC {
    const META_DATES = '_workshop_dates';
    const META_NEXT_DATE       = '_workshop_next_date';
    const META_NEXT_START_TIME = '_workshop_next_start_time';
    const META_NEXT_END_TIME   = '_workshop_next_end_time';
    const META_ROTATION_INDEX  = '_workshop_next_rotation_index';
    const OPTION_SETTINGS = 'mka_wd_settings';


    public static function init(): void {
        add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
        add_action('save_post', [__CLASS__, 'save_post'], 20, 2);
        add_action('wp_ajax_mka_wd_advance_next_date', [__CLASS__, 'ajax_advance_next_date']);
        add_action('wp_ajax_nopriv_mka_wd_advance_next_date', [__CLASS__, 'ajax_advance_next_date']);
        add_action('admin_post_mka_wd_advance_next_date_submit', [__CLASS__, 'handle_next_button_submit']);
        add_action('admin_post_nopriv_mka_wd_advance_next_date_submit', [__CLASS__, 'handle_next_button_submit']);
        add_shortcode('next-button-pw', [__CLASS__, 'render_next_button_shortcode']);
        add_shortcode('rezerwa_pw', [__CLASS__, 'render_reservation_shortcode']);
        add_action('admin_post_mka_wd_workshop_reservation', [__CLASS__, 'handle_reservation_submit']);
        add_action('admin_post_nopriv_mka_wd_workshop_reservation', [__CLASS__, 'handle_reservation_submit']);
        add_action('admin_menu', [__CLASS__, 'register_settings_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    private static function default_settings(): array {
        return [
            'organizer_recipients' => "warsztaty@mkalodz.pl\np.wilkocki@mkalodz.pl",
            'organizer_subject' => 'Nowy zapis na warsztaty: {workshop_title} ({workshop_date})',
            'organizer_message' => "Nowe zgłoszenie na warsztaty:\n\nWarsztaty: {workshop_title}\nData: {workshop_date}\nImię: {name}\nTelefon: {phone}\nE-mail: {email}\nZgoda RODO: tak",
            'client_subject' => 'Potwierdzenie zapisu: {workshop_title} ({workshop_date})',
            'client_message' => "Dziękujemy za zapis na warsztaty.\n\nWarsztaty: {workshop_title}\nData: {workshop_date}\nImię: {name}\nTelefon: {phone}\n\nTo jest automatyczne potwierdzenie zgłoszenia.",
            'button_bg_color' => '#1d4ed8',
            'button_text_color' => '#ffffff',
            'button_border_radius' => '6',
            'button_padding_y' => '10',
            'button_padding_x' => '16',
        ];
    }

    private static function get_settings(): array {
        $defaults = self::default_settings();
        $raw = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($raw)) {
            return $defaults;
        }

        return array_merge($defaults, $raw);
    }

    private static function post_types(): array {
        $defaults = ['warsztaty', 'muzeum_workshop', 'workshop'];
        $types = apply_filters('mka_wd_post_types', $defaults);
        return is_array($types) ? array_values($types) : $defaults;
    }

    private static function acf_field_names(): array {
        $defaults = [
            'date'  => 'workshop_next_date',
            'start' => 'workshop_next_start_time',
            'end'   => 'workshop_next_end_time',
        ];
        $names = apply_filters('mka_wd_acf_field_names', $defaults);
        if (!is_array($names)) {
            return $defaults;
        }

        return [
            'date'  => isset($names['date']) && is_string($names['date']) ? $names['date'] : $defaults['date'],
            'start' => isset($names['start']) && is_string($names['start']) ? $names['start'] : $defaults['start'],
            'end'   => isset($names['end']) && is_string($names['end']) ? $names['end'] : $defaults['end'],
        ];
    }

    public static function add_metabox(): void {
        foreach (self::post_types() as $pt) {
            add_meta_box(
                'mka_wd_box',
                'Terminy warsztatow',
                [__CLASS__, 'render_metabox'],
                $pt,
                'normal',
                'high'
            );
        }
    }

    public static function enqueue_admin_assets(string $hook): void {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || empty($screen->post_type)) {
            return;
        }
        if (!in_array($screen->post_type, self::post_types(), true)) {
            return;
        }

        wp_enqueue_style(
            'mka-wd-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'mka-wd-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.js',
            ['jquery'],
            '1.0.0',
            true
        );
    }

    public static function register_settings_page(): void {
        add_options_page(
            'MKA Workshop Dates',
            'MKA Workshop Dates',
            'manage_options',
            'mka-workshop-dates-settings',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings(): void {
        register_setting(
            'mka_wd_settings_group',
            self::OPTION_SETTINGS,
            [
                'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
                'default' => self::default_settings(),
            ]
        );

        add_settings_section(
            'mka_wd_mail_section',
            'Treści e-mail',
            '__return_empty_string',
            'mka-workshop-dates-settings'
        );

        add_settings_field(
            'organizer_recipients',
            'Adresy organizatorów (1 e-mail na linię)',
            [__CLASS__, 'render_textarea_field'],
            'mka-workshop-dates-settings',
            'mka_wd_mail_section',
            ['key' => 'organizer_recipients', 'rows' => 4]
        );

        add_settings_field(
            'organizer_subject',
            'Temat e-maila do organizatorów',
            [__CLASS__, 'render_text_field'],
            'mka-workshop-dates-settings',
            'mka_wd_mail_section',
            ['key' => 'organizer_subject']
        );

        add_settings_field(
            'organizer_message',
            'Treść e-maila do organizatorów',
            [__CLASS__, 'render_textarea_field'],
            'mka-workshop-dates-settings',
            'mka_wd_mail_section',
            ['key' => 'organizer_message', 'rows' => 8]
        );

        add_settings_field(
            'client_subject',
            'Temat e-maila dla klienta',
            [__CLASS__, 'render_text_field'],
            'mka-workshop-dates-settings',
            'mka_wd_mail_section',
            ['key' => 'client_subject']
        );

        add_settings_field(
            'client_message',
            'Treść e-maila dla klienta',
            [__CLASS__, 'render_textarea_field'],
            'mka-workshop-dates-settings',
            'mka_wd_mail_section',
            ['key' => 'client_message', 'rows' => 8]
        );

        add_settings_section(
            'mka_wd_buttons_section',
            'Wygląd buttonów',
            '__return_empty_string',
            'mka-workshop-dates-settings'
        );

        add_settings_field('button_bg_color', 'Kolor tła', [__CLASS__, 'render_text_field'], 'mka-workshop-dates-settings', 'mka_wd_buttons_section', ['key' => 'button_bg_color']);
        add_settings_field('button_text_color', 'Kolor tekstu', [__CLASS__, 'render_text_field'], 'mka-workshop-dates-settings', 'mka_wd_buttons_section', ['key' => 'button_text_color']);
        add_settings_field('button_border_radius', 'Zaokrąglenie rogów (px)', [__CLASS__, 'render_number_field'], 'mka-workshop-dates-settings', 'mka_wd_buttons_section', ['key' => 'button_border_radius']);
        add_settings_field('button_padding_y', 'Padding pionowy (px)', [__CLASS__, 'render_number_field'], 'mka-workshop-dates-settings', 'mka_wd_buttons_section', ['key' => 'button_padding_y']);
        add_settings_field('button_padding_x', 'Padding poziomy (px)', [__CLASS__, 'render_number_field'], 'mka-workshop-dates-settings', 'mka_wd_buttons_section', ['key' => 'button_padding_x']);
    }

    public static function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>MKA Workshop Dates - ustawienia</h1>';
        echo '<h3>wordpress plugin by peterwolf.pl</h3>';
        echo '<p>Dostępne placeholdery: <code>{workshop_title}</code>, <code>{workshop_date}</code>, <code>{name}</code>, <code>{phone}</code>, <code>{email}</code>.</p>';
        echo '<form method="post" action="options.php">';
        settings_fields('mka_wd_settings_group');
        do_settings_sections('mka-workshop-dates-settings');
        submit_button('Zapisz ustawienia');
        echo '</form>';
        echo '</div>';
    }

    public static function sanitize_settings($input): array {
        $defaults = self::default_settings();
        $input = is_array($input) ? $input : [];

        $settings = [];
        $settings['organizer_recipients'] = sanitize_textarea_field((string)($input['organizer_recipients'] ?? $defaults['organizer_recipients']));
        $settings['organizer_subject'] = sanitize_text_field((string)($input['organizer_subject'] ?? $defaults['organizer_subject']));
        $settings['organizer_message'] = sanitize_textarea_field((string)($input['organizer_message'] ?? $defaults['organizer_message']));
        $settings['client_subject'] = sanitize_text_field((string)($input['client_subject'] ?? $defaults['client_subject']));
        $settings['client_message'] = sanitize_textarea_field((string)($input['client_message'] ?? $defaults['client_message']));

        $settings['button_bg_color'] = sanitize_hex_color((string)($input['button_bg_color'] ?? $defaults['button_bg_color'])) ?: $defaults['button_bg_color'];
        $settings['button_text_color'] = sanitize_hex_color((string)($input['button_text_color'] ?? $defaults['button_text_color'])) ?: $defaults['button_text_color'];
        $settings['button_border_radius'] = (string)max(0, absint($input['button_border_radius'] ?? $defaults['button_border_radius']));
        $settings['button_padding_y'] = (string)max(0, absint($input['button_padding_y'] ?? $defaults['button_padding_y']));
        $settings['button_padding_x'] = (string)max(0, absint($input['button_padding_x'] ?? $defaults['button_padding_x']));

        return $settings;
    }

    public static function render_text_field(array $args): void {
        $settings = self::get_settings();
        $key = isset($args['key']) ? (string)$args['key'] : '';
        $value = isset($settings[$key]) ? (string)$settings[$key] : '';

        echo '<input type="text" class="regular-text" name="' . esc_attr(self::OPTION_SETTINGS) . '[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" />';
    }

    public static function render_number_field(array $args): void {
        $settings = self::get_settings();
        $key = isset($args['key']) ? (string)$args['key'] : '';
        $value = isset($settings[$key]) ? (string)$settings[$key] : '0';

        echo '<input type="number" min="0" class="small-text" name="' . esc_attr(self::OPTION_SETTINGS) . '[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" />';
    }

    public static function render_textarea_field(array $args): void {
        $settings = self::get_settings();
        $key = isset($args['key']) ? (string)$args['key'] : '';
        $rows = isset($args['rows']) ? max(2, absint($args['rows'])) : 5;
        $value = isset($settings[$key]) ? (string)$settings[$key] : '';

        echo '<textarea class="large-text" rows="' . esc_attr((string)$rows) . '" name="' . esc_attr(self::OPTION_SETTINGS) . '[' . esc_attr($key) . ']">' . esc_textarea($value) . '</textarea>';
    }

    private static function get_dates(int $post_id): array {
        $raw = get_post_meta($post_id, self::META_DATES, true);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            return [];
        }
        $out = [];
        foreach ($arr as $row) {
            if (!is_array($row)) { continue; }
            $out[] = [
                'date'  => isset($row['date']) ? (string)$row['date'] : '',
                'start' => isset($row['start']) ? (string)$row['start'] : '',
                'end'   => isset($row['end']) ? (string)$row['end'] : '',
            ];
        }
        return $out;
    }

    public static function render_metabox(WP_Post $post): void {
        wp_nonce_field('mka_wd_save', 'mka_wd_nonce');

        $rows = self::get_dates((int)$post->ID);
        if (!$rows) {
            $rows = [['date' => '', 'start' => '', 'end' => '']];
        }

        echo '<div class="mka-wd">';
        echo '<div id="mka-wd-wrapper">';

        foreach ($rows as $i => $row) {
            $date  = esc_attr($row['date'] ?? '');
            $start = esc_attr($row['start'] ?? '');
            $end   = esc_attr($row['end'] ?? '');

            echo '<div class="mka-wd-row">';
            echo '  <div class="mka-wd-col"><label>Data</label><input type="date" name="mka_workshop_dates['.$i.'][date]" value="'.$date.'" /></div>';
            echo '  <div class="mka-wd-col"><label>Start</label><input type="time" name="mka_workshop_dates['.$i.'][start]" value="'.$start.'" /></div>';
            echo '  <div class="mka-wd-col"><label>Koniec</label><input type="time" name="mka_workshop_dates['.$i.'][end]" value="'.$end.'" /></div>';
            echo '  <div class="mka-wd-actions"><button type="button" class="button mka-wd-remove">Usun</button></div>';
            echo '</div>';
        }

        echo '</div>'; // wrapper

        echo '<div class="mka-wd-toolbar">';
        echo '  <button type="button" class="button button-primary" id="mka-wd-add">Dodaj termin</button>';
        echo '</div>';

        echo '<p class="description">';
        echo 'Zapisywane do meta <code>' . esc_html(self::META_DATES) . '</code> jako JSON.';
        echo '</p>';

        echo '</div>';
    }

    private static function compute_next_event(array $rows): ?array {
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Europe/Warsaw');
        $now = new DateTimeImmutable('now', $tz);
        $candidates = [];

        foreach ($rows as $row) {
            if (!is_array($row)) { continue; }
            $date = (string)($row['date'] ?? '');
            if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { continue; }
            $start = (string)($row['start'] ?? '00:00');
            if ($start !== '' && !preg_match('/^\d{2}:\d{2}$/', $start)) { $start = '00:00'; }

            try {
                $dt = new DateTimeImmutable($date . ' ' . $start . ':00', $tz);
            } catch (Exception $e) {
                continue;
            }
            if ($dt >= $now) {
                $candidates[] = [
                    'datetime' => $dt,
                    'date'     => $date,
                    'start'    => $start,
                    'end'      => (string)($row['end'] ?? ''),
                ];
            }
        }

        if (!$candidates) { return null; }

        usort($candidates, fn($a, $b) => $a['datetime'] <=> $b['datetime']);
        return $candidates[0];
    }

    private static function update_acf_or_meta(int $post_id, string $field_name, string $value): void {
        if (function_exists('update_field')) {
            @update_field($field_name, $value, $post_id);
            return;
        }
        update_post_meta($post_id, $field_name, $value);
    }

    private static function get_upcoming_events_for_post(int $post_id): array {
        $rows = self::get_dates($post_id);
        if (!$rows) {
            return [];
        }

        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Europe/Warsaw');
        $now = new DateTimeImmutable('now', $tz);
        $upcoming = [];

        foreach ($rows as $row) {
            $date = (string)($row['date'] ?? '');
            if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }

            $start = (string)($row['start'] ?? '00:00');
            if ($start !== '' && !preg_match('/^\d{2}:\d{2}$/', $start)) {
                $start = '00:00';
            }

            $end = (string)($row['end'] ?? '');
            if ($end !== '' && !preg_match('/^\d{2}:\d{2}$/', $end)) {
                $end = '';
            }

            try {
                $dt = new DateTimeImmutable($date . ' ' . $start . ':00', $tz);
            } catch (Exception $e) {
                continue;
            }

            if ($dt >= $now) {
                $upcoming[] = [
                    'datetime' => $dt,
                    'date' => $date,
                    'start' => $start,
                    'end' => $end,
                ];
            }
        }

        if (!$upcoming) {
            return [];
        }

        usort($upcoming, fn($a, $b) => $a['datetime'] <=> $b['datetime']);

        return array_values($upcoming);
    }

    private static function normalize_time_for_compare(string $time): string {
        $time = trim($time);
        if ($time === '') {
            return '00:00';
        }

        if (preg_match('/^(\d{2}:\d{2})(?::\d{2})?$/', $time, $matches)) {
            return $matches[1];
        }

        return '00:00';
    }

    private static function get_button_style_attr(): string {
        $settings = self::get_settings();

        $styles = [
            'background-color:' . ($settings['button_bg_color'] ?? '#1d4ed8'),
            'color:' . ($settings['button_text_color'] ?? '#ffffff'),
            'border-radius:' . absint($settings['button_border_radius'] ?? 6) . 'px',
            'padding:' . absint($settings['button_padding_y'] ?? 10) . 'px ' . absint($settings['button_padding_x'] ?? 16) . 'px',
            'border:none',
            'cursor:pointer',
        ];

        return implode(';', $styles);
    }

    private static function apply_template_placeholders(string $template, array $replacements): string {
        $search = [];
        $replace = [];

        foreach ($replacements as $key => $value) {
            $search[] = '{' . $key . '}';
            $replace[] = (string)$value;
        }

        return str_replace($search, $replace, $template);
    }

    private static function parse_recipients(string $raw): array {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $emails = [];

        foreach ($lines as $line) {
            $email = sanitize_email(trim((string)$line));
            if ($email === '' || !is_email($email)) {
                continue;
            }
            $emails[] = $email;
        }

        return array_values(array_unique($emails));
    }

    private static function apply_next_event_to_post(int $post_id, array $event, ?int $rotation_index = null): void {
        $next_date = (string)($event['date'] ?? '');
        $next_start = (string)($event['start'] ?? '');
        $next_end = (string)($event['end'] ?? '');

        update_post_meta($post_id, self::META_NEXT_DATE, $next_date);
        update_post_meta($post_id, self::META_NEXT_START_TIME, $next_start);
        update_post_meta($post_id, self::META_NEXT_END_TIME, $next_end);

        if ($rotation_index !== null) {
            update_post_meta($post_id, self::META_ROTATION_INDEX, (string)$rotation_index);
        }

        $acf_names = self::acf_field_names();
        self::update_acf_or_meta($post_id, $acf_names['date'], $next_date);
        self::update_acf_or_meta($post_id, $acf_names['start'], $next_start);
        self::update_acf_or_meta($post_id, $acf_names['end'], $next_end);

        delete_post_meta($post_id, '_workshop_next_datetime');
        delete_post_meta($post_id, 'workshop_next_datetime');
    }

    private static function get_next_event_after_current(int $post_id): ?array {
        $upcoming = self::get_upcoming_events_for_post($post_id);
        if (!$upcoming) {
            return null;
        }

        $count = count($upcoming);
        $stored_index_raw = get_post_meta($post_id, self::META_ROTATION_INDEX, true);
        $stored_index = is_numeric($stored_index_raw) ? (int)$stored_index_raw : null;

        if ($stored_index !== null && $stored_index >= 0 && $stored_index < $count) {
            $next_index = $stored_index + 1;
            if ($next_index >= $count) {
                $next_index = 0;
            }
            $event = $upcoming[$next_index];
            $event['rotation_index'] = $next_index;
            return $event;
        }

        $current_date = (string)get_post_meta($post_id, self::META_NEXT_DATE, true);
        $current_start = self::normalize_time_for_compare((string)get_post_meta($post_id, self::META_NEXT_START_TIME, true));

        foreach ($upcoming as $index => $event) {
            $event_date = (string)($event['date'] ?? '');
            $event_start = self::normalize_time_for_compare((string)($event['start'] ?? ''));

            if ($event_date === $current_date && $event_start === $current_start) {
                $next_index = $index + 1;
                if ($next_index >= $count) {
                    $next_index = 0;
                }
                $next_event = $upcoming[$next_index];
                $next_event['rotation_index'] = $next_index;
                return $next_event;
            }
        }

        $event = $upcoming[0];
        $event['rotation_index'] = 0;
        return $event;
    }

    public static function render_next_button_shortcode(array $atts = []): string {
        $atts = shortcode_atts([
            'post_id' => 0,
            'label' => 'następny termin',
        ], $atts, 'next-button-pw');

        $post_id = absint($atts['post_id']);
        if ($post_id <= 0) {
            $post_id = get_the_ID() ?: 0;
        }
        if ($post_id <= 0) {
            return '';
        }

        if (!self::get_upcoming_events_for_post($post_id)) {
            return '';
        }

        $uid = wp_unique_id('mka-next-date-');
        $button_label = esc_html((string)$atts['label']);
        $action_url = admin_url('admin-post.php');
        $nonce = wp_create_nonce('mka_wd_advance_next_date_' . $post_id);
        $button_style = self::get_button_style_attr();

        $output  = '<div class="mka-next-button-wrap" id="' . esc_attr($uid) . '">';
        $output .= '  <form method="post" action="' . esc_url($action_url) . '">';
        $output .= '      <input type="hidden" name="action" value="mka_wd_advance_next_date_submit" />';
        $output .= '      <input type="hidden" name="post_id" value="' . esc_attr((string)$post_id) . '" />';
        $output .= '      <input type="hidden" name="nonce" value="' . esc_attr($nonce) . '" />';
        $output .= '      <button type="submit" class="mka-next-button" style="' . esc_attr($button_style) . '">' . $button_label . '</button>';
        $output .= '  </form>';
        $output .= '</div>';

        return $output;
    }

    private static function format_date_for_display(string $date): string {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        $ts = strtotime($date);
        if ($ts === false) {
            return $date;
        }

        return wp_date('d.m.Y', $ts);
    }

    public static function render_reservation_shortcode(array $atts = []): string {
        $atts = shortcode_atts([
            'post_id' => 0,
            'label' => 'Zapisz się na warsztaty',
            'title' => 'Zapisy na warsztaty',
            'submit_label' => 'Zapisz się',
        ], $atts, 'rezerwa_pw');

        $post_id = absint($atts['post_id']);
        if ($post_id <= 0) {
            $post_id = get_the_ID() ?: 0;
        }
        if ($post_id <= 0) {
            return '';
        }

        $workshop_title = get_the_title($post_id);
        if (!is_string($workshop_title)) {
            $workshop_title = '';
        }

        $workshop_date = (string)get_post_meta($post_id, 'workshop_next_date', true);
        if ($workshop_date === '') {
            $workshop_date = (string)get_post_meta($post_id, self::META_NEXT_DATE, true);
        }
        if ($workshop_date === '') {
            $upcoming_events = self::get_upcoming_events_for_post($post_id);
            if (!empty($upcoming_events[0]['date']) && is_string($upcoming_events[0]['date'])) {
                $workshop_date = $upcoming_events[0]['date'];
            }
        }

        $status_raw = isset($_GET['mka_reservation']) ? sanitize_text_field((string)$_GET['mka_reservation']) : '';
        $status = in_array($status_raw, ['success', 'error', 'missing'], true) ? $status_raw : '';

        $uid = wp_unique_id('mka-rezerwa-');
        $nonce = wp_create_nonce('mka_wd_workshop_reservation_' . $post_id);
        $button_style = self::get_button_style_attr();

        $output = '<div class="mka-reservation-wrap" id="' . esc_attr($uid) . '">';
        if ($status === 'success') {
            $output .= '<p class="mka-reservation-message mka-reservation-message--success">Dziękujemy, zgłoszenie zostało wysłane.</p>';
        } elseif ($status === 'missing') {
            $output .= '<p class="mka-reservation-message mka-reservation-message--error">Uzupełnij wymagane pola i zaakceptuj zgodę RODO.</p>';
        } elseif ($status === 'error') {
            $output .= '<p class="mka-reservation-message mka-reservation-message--error">Nie udało się wysłać zgłoszenia. Spróbuj ponownie.</p>';
        }

        $output .= '<details class="mka-reservation-details">';
        $output .= '  <summary class="mka-reservation-button" style="' . esc_attr($button_style) . '">' . esc_html((string)$atts['label']) . '</summary>';
        $output .= '  <div class="mka-reservation-form-wrap">';
        $output .= '      <h3>' . esc_html((string)$atts['title']) . '</h3>';
        $output .= '      <form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $output .= '          <input type="hidden" name="action" value="mka_wd_workshop_reservation" />';
        $output .= '          <input type="hidden" name="post_id" value="' . esc_attr((string)$post_id) . '" />';
        $output .= '          <input type="hidden" name="nonce" value="' . esc_attr($nonce) . '" />';
        $output .= '          <input type="hidden" name="workshop_title" value="' . esc_attr($workshop_title) . '" />';
        $output .= '          <input type="hidden" name="workshop_date" value="' . esc_attr($workshop_date) . '" />';
        $output .= '          <p><strong>Warsztaty:</strong> ' . esc_html($workshop_title) . '</p>';
        $output .= '          <p><strong>Termin:</strong> ' . esc_html(self::format_date_for_display($workshop_date)) . '</p>';
        $output .= '          <p><label>Imię:<br /><input type="text" name="name" required /></label></p>';
        $output .= '          <p><label>Telefon:<br /><input type="tel" name="phone" required /></label></p>';
        $output .= '          <p><label>Mail:<br /><input type="email" name="email" required /></label></p>';
        $output .= '          <p><label><input type="checkbox" name="rodo" value="1" required /> Wyrażam zgodę na przetwarzanie danych osobowych (<a href="https://mkalodz.pl/polityka-prywatnosci/">RODO</a>).</label></p>';
        $output .= '          <p><button type="submit" class="mka-reservation-submit" style="' . esc_attr($button_style) . '">' . esc_html((string)$atts['submit_label']) . '</button></p>';
        $output .= '      </form>';
        $output .= '  </div>';
        $output .= '</details>';
        $output .= '</div>';

        return $output;
    }

    private static function redirect_after_reservation(int $post_id, string $status): void {
        $redirect = wp_get_referer();
        if (!is_string($redirect) || $redirect === '') {
            $redirect = get_permalink($post_id);
        }
        if (!is_string($redirect) || $redirect === '') {
            $redirect = home_url('/');
        }

        $redirect = add_query_arg('mka_reservation', $status, $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    public static function handle_reservation_submit(): void {
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if ($post_id <= 0) {
            wp_die('Invalid post ID.', 400);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field((string)$_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'mka_wd_workshop_reservation_' . $post_id)) {
            wp_die('Invalid nonce.', 403);
        }

        $name = isset($_POST['name']) ? sanitize_text_field((string)$_POST['name']) : '';
        $phone = isset($_POST['phone']) ? sanitize_text_field((string)$_POST['phone']) : '';
        $email = isset($_POST['email']) ? sanitize_email((string)$_POST['email']) : '';
        $rodo = isset($_POST['rodo']) ? sanitize_text_field((string)$_POST['rodo']) : '';
        $workshop_title = isset($_POST['workshop_title']) ? sanitize_text_field((string)$_POST['workshop_title']) : '';
        $workshop_date = isset($_POST['workshop_date']) ? sanitize_text_field((string)$_POST['workshop_date']) : '';

        if ($name === '' || $phone === '' || $email === '' || !is_email($email) || $rodo !== '1') {
            self::redirect_after_reservation($post_id, 'missing');
        }

        if ($workshop_title === '') {
            $title = get_the_title($post_id);
            $workshop_title = is_string($title) ? $title : '';
        }

        if ($workshop_date === '') {
            $workshop_date = (string)get_post_meta($post_id, 'workshop_next_date', true);
            if ($workshop_date === '') {
                $workshop_date = (string)get_post_meta($post_id, self::META_NEXT_DATE, true);
            }
        }

        $formatted_date = self::format_date_for_display($workshop_date);
        $settings = self::get_settings();
        $template_vars = [
            'workshop_title' => $workshop_title,
            'workshop_date' => $formatted_date,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
        ];

        $subject = self::apply_template_placeholders((string)$settings['organizer_subject'], $template_vars);
        $message = self::apply_template_placeholders((string)$settings['organizer_message'], $template_vars);

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'Reply-To: ' . $name . ' <' . $email . '>',
        ];

        $organizer_recipients = self::parse_recipients((string)$settings['organizer_recipients']);
        if (!$organizer_recipients) {
            $organizer_recipients = self::parse_recipients((string)self::default_settings()['organizer_recipients']);
        }

        $organizer_sent = wp_mail($organizer_recipients, $subject, $message, $headers);

        $confirmation_subject = self::apply_template_placeholders((string)$settings['client_subject'], $template_vars);
        $confirmation_message = self::apply_template_placeholders((string)$settings['client_message'], $template_vars);

        $user_sent = wp_mail($email, $confirmation_subject, $confirmation_message, $headers);

        self::redirect_after_reservation($post_id, ($organizer_sent || $user_sent) ? 'success' : 'error');
    }

    public static function handle_next_button_submit(): void {
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if ($post_id <= 0) {
            wp_die('Invalid post ID.', 400);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field((string)$_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'mka_wd_advance_next_date_' . $post_id)) {
            wp_die('Invalid nonce.', 403);
        }

        $next_event = self::get_next_event_after_current($post_id);
        if ($next_event) {
            $rotation_index = isset($next_event['rotation_index']) ? (int)$next_event['rotation_index'] : null;
            self::apply_next_event_to_post($post_id, $next_event, $rotation_index);
        }

        $redirect = wp_get_referer();
        if (!is_string($redirect) || $redirect === '') {
            $redirect = get_permalink($post_id);
        }
        if (!is_string($redirect) || $redirect === '') {
            $redirect = home_url('/');
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public static function ajax_advance_next_date(): void {
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if ($post_id <= 0) {
            wp_send_json_error(['message' => 'invalid_post_id'], 400);
        }


        $nonce = isset($_POST['nonce']) ? sanitize_text_field((string)$_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'mka_wd_advance_next_date_' . $post_id)) {
            wp_send_json_error(['message' => 'invalid_nonce'], 403);
        }

        $next_event = self::get_next_event_after_current($post_id);
        if (!$next_event) {
            wp_send_json_error(['message' => 'no_upcoming_events'], 404);
        }

        $rotation_index = isset($next_event['rotation_index']) ? (int)$next_event['rotation_index'] : null;
        self::apply_next_event_to_post($post_id, $next_event, $rotation_index);

        wp_send_json_success([
            'date' => (string)($next_event['date'] ?? ''),
            'start' => (string)($next_event['start'] ?? ''),
            'end' => (string)($next_event['end'] ?? ''),
        ]);
    }

    public static function save_post(int $post_id, WP_Post $post): void {
        if (!in_array($post->post_type, self::post_types(), true)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!isset($_POST['mka_wd_nonce']) || !wp_verify_nonce((string)$_POST['mka_wd_nonce'], 'mka_wd_save')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $submitted = $_POST['mka_workshop_dates'] ?? [];
        if (!is_array($submitted)) { $submitted = []; }

        $clean = [];
        foreach ($submitted as $row) {
            if (!is_array($row)) { continue; }
            $date  = sanitize_text_field((string)($row['date'] ?? ''));
            $start = sanitize_text_field((string)($row['start'] ?? ''));
            $end   = sanitize_text_field((string)($row['end'] ?? ''));

            if ($date === '' && $start === '' && $end === '') { continue; }
            if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { continue; }
            if ($start !== '' && !preg_match('/^\d{2}:\d{2}$/', $start)) { $start = ''; }
            if ($end !== '' && !preg_match('/^\d{2}:\d{2}$/', $end)) { $end = ''; }

            $clean[] = ['date' => $date, 'start' => $start, 'end' => $end];
        }

        update_post_meta($post_id, self::META_DATES, wp_json_encode($clean, JSON_UNESCAPED_UNICODE));

        $next = self::compute_next_event($clean);
        if ($next) {
            self::apply_next_event_to_post($post_id, $next, 0);
        } else {
            update_post_meta($post_id, self::META_NEXT_DATE, '');
            update_post_meta($post_id, self::META_NEXT_START_TIME, '');
            update_post_meta($post_id, self::META_NEXT_END_TIME, '');

            $acf_names = self::acf_field_names();
            self::update_acf_or_meta($post_id, $acf_names['date'], '');
            self::update_acf_or_meta($post_id, $acf_names['start'], '');
            self::update_acf_or_meta($post_id, $acf_names['end'], '');
            delete_post_meta($post_id, self::META_ROTATION_INDEX);
        }
    }
}

MKA_Workshop_Dates_OptionC::init();
