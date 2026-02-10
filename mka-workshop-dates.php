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

    private static $next_button_script_rendered = false;

    public static function init(): void {
        add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
        add_action('save_post', [__CLASS__, 'save_post'], 20, 2);
        add_action('wp_ajax_mka_wd_advance_next_date', [__CLASS__, 'ajax_advance_next_date']);
        add_action('wp_ajax_nopriv_mka_wd_advance_next_date', [__CLASS__, 'ajax_advance_next_date']);
        add_action('wp_footer', [__CLASS__, 'render_next_button_script'], 99);
        add_shortcode('next-button-pw', [__CLASS__, 'render_next_button_shortcode']);
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

    private static function apply_next_event_to_post(int $post_id, array $event): void {
        $next_date = (string)($event['date'] ?? '');
        $next_start = (string)($event['start'] ?? '');
        $next_end = (string)($event['end'] ?? '');

        update_post_meta($post_id, self::META_NEXT_DATE, $next_date);
        update_post_meta($post_id, self::META_NEXT_START_TIME, $next_start);
        update_post_meta($post_id, self::META_NEXT_END_TIME, $next_end);

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

        $current_date = (string)get_post_meta($post_id, self::META_NEXT_DATE, true);
        $current_start = (string)get_post_meta($post_id, self::META_NEXT_START_TIME, true);
        $current_end = (string)get_post_meta($post_id, self::META_NEXT_END_TIME, true);

        foreach ($upcoming as $index => $event) {
            $matches = (
                (string)($event['date'] ?? '') === $current_date &&
                (string)($event['start'] ?? '') === $current_start &&
                (string)($event['end'] ?? '') === $current_end
            );

            if ($matches) {
                $next_index = $index + 1;
                if ($next_index >= count($upcoming)) {
                    $next_index = 0;
                }
                return $upcoming[$next_index];
            }
        }

        return $upcoming[0];
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

        $output  = '<div class="mka-next-button-wrap" id="' . esc_attr($uid) . '">';
        $output .= '  <button type="button" class="mka-next-button" data-post-id="' . esc_attr((string)$post_id) . '">' . $button_label . '</button>';
        $output .= '</div>';

        return $output;
    }

    public static function render_next_button_script(): void {
        if (self::$next_button_script_rendered) {
            return;
        }

        self::$next_button_script_rendered = true;
        $ajax_url = admin_url('admin-ajax.php');

        echo '<script>';
        echo '(function(){';
        echo 'var ajaxUrl=' . wp_json_encode($ajax_url) . ';';
        echo 'var buttons=document.querySelectorAll(".mka-next-button[data-post-id]");';
        echo 'if(!buttons||buttons.length===0){return;}';
        echo 'buttons.forEach(function(button){';
        echo 'button.addEventListener("click",function(){';
        echo 'var postId=button.getAttribute("data-post-id")||"";';
        echo 'if(!postId){return;}';
        echo 'button.disabled=true;';
        echo 'var formData=new FormData();';
        echo 'formData.append("action","mka_wd_advance_next_date");';
        echo 'formData.append("post_id",postId);';
        echo 'fetch(ajaxUrl,{method:"POST",credentials:"same-origin",body:formData})';
        echo '.then(function(response){return response.json();})';
        echo '.catch(function(){} )';
        echo '.finally(function(){button.disabled=false;});';
        echo '});';
        echo '});';
        echo '})();';
        echo '</script>';
    }

    public static function ajax_advance_next_date(): void {
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if ($post_id <= 0) {
            wp_send_json_error(['message' => 'invalid_post_id'], 400);
        }


        $next_event = self::get_next_event_after_current($post_id);
        if (!$next_event) {
            wp_send_json_error(['message' => 'no_upcoming_events'], 404);
        }

        self::apply_next_event_to_post($post_id, $next_event);

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
            self::apply_next_event_to_post($post_id, $next);
        } else {
            update_post_meta($post_id, self::META_NEXT_DATE, '');
            update_post_meta($post_id, self::META_NEXT_START_TIME, '');
            update_post_meta($post_id, self::META_NEXT_END_TIME, '');

            $acf_names = self::acf_field_names();
            self::update_acf_or_meta($post_id, $acf_names['date'], '');
            self::update_acf_or_meta($post_id, $acf_names['start'], '');
            self::update_acf_or_meta($post_id, $acf_names['end'], '');
        }
    }
}

MKA_Workshop_Dates_OptionC::init();
