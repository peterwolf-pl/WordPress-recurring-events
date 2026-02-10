<?php
/*
Plugin Name: MKA Workshop Dates (Option C)
Description: Metabox z wieloma terminami (repeater bez ACF PRO). Zapis do _workshop_dates (JSON) + auto aktualizacja najblizszego terminu.
Version: 1.0.0
Author: MKA
License: GPLv2 or later
*/

if (!defined('ABSPATH')) { exit; }

final class MKA_Workshop_Dates_OptionC {
    const META_DATES = '_workshop_dates';
    const META_NEXT_DATE       = '_workshop_next_date';
    const META_NEXT_START_TIME = '_workshop_next_start_time';
    const META_NEXT_END_TIME   = '_workshop_next_end_time';
    const META_ROTATION_INDEX  = '_workshop_next_rotation_index';


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

        $output  = '<div class="mka-next-button-wrap" id="' . esc_attr($uid) . '">';
        $output .= '  <form method="post" action="' . esc_url($action_url) . '">';
        $output .= '      <input type="hidden" name="action" value="mka_wd_advance_next_date_submit" />';
        $output .= '      <input type="hidden" name="post_id" value="' . esc_attr((string)$post_id) . '" />';
        $output .= '      <input type="hidden" name="nonce" value="' . esc_attr($nonce) . '" />';
        $output .= '      <button type="submit" class="mka-next-button">' . $button_label . '</button>';
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

        $output = '<div class="mka-reservation-wrap" id="' . esc_attr($uid) . '">';
        if ($status === 'success') {
            $output .= '<p class="mka-reservation-message mka-reservation-message--success">Dziękujemy, zgłoszenie zostało wysłane.</p>';
        } elseif ($status === 'missing') {
            $output .= '<p class="mka-reservation-message mka-reservation-message--error">Uzupełnij wymagane pola i zaakceptuj zgodę RODO.</p>';
        } elseif ($status === 'error') {
            $output .= '<p class="mka-reservation-message mka-reservation-message--error">Nie udało się wysłać zgłoszenia. Spróbuj ponownie.</p>';
        }

        $output .= '<details class="mka-reservation-details">';
        $output .= '  <summary class="mka-reservation-button">' . esc_html((string)$atts['label']) . '</summary>';
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
        $output .= '          <p><label>Imię<br /><input type="text" name="name" required /></label></p>';
        $output .= '          <p><label>Telefon<br /><input type="tel" name="phone" required /></label></p>';
        $output .= '          <p><label>Mail<br /><input type="email" name="email" required /></label></p>';
        $output .= '          <p><label><input type="checkbox" name="rodo" value="1" required /> Wyrażam zgodę na przetwarzanie danych osobowych (RODO).</label></p>';
        $output .= '          <p><button type="submit" class="mka-reservation-submit">' . esc_html((string)$atts['submit_label']) . '</button></p>';
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
        $subject = sprintf('Nowy zapis na warsztaty: %s (%s)', $workshop_title, $formatted_date);
        $message_lines = [
            'Nowe zgłoszenie na warsztaty:',
            '',
            'Warsztaty: ' . $workshop_title,
            'Data: ' . $formatted_date,
            'Imię: ' . $name,
            'Telefon: ' . $phone,
            'E-mail: ' . $email,
            'Zgoda RODO: tak',
        ];
        $message = implode("\n", $message_lines);

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'Reply-To: ' . $name . ' <' . $email . '>',
        ];

        $recipients = [
            $email,
            'warsztaty@mkalodz.pl',
            'p.wilkocki@mkalodz.pl',
        ];

        $sent = wp_mail($recipients, $subject, $message, $headers);
        self::redirect_after_reservation($post_id, $sent ? 'success' : 'error');
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
