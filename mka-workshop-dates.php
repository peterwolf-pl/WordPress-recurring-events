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
    const META_NEXT  = '_workshop_next_datetime';

    public static function init(): void {
        add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_assets']);
        add_action('save_post', [__CLASS__, 'save_post'], 20, 2);
    }

    private static function post_types(): array {
        $defaults = ['warsztaty', 'muzeum_workshop', 'workshop'];
        $types = apply_filters('mka_wd_post_types', $defaults);
        return is_array($types) ? array_values($types) : $defaults;
    }

    private static function acf_field_name(): string {
        $name = apply_filters('mka_wd_acf_field_name', 'workshop_next_datetime');
        return is_string($name) ? $name : 'workshop_next_datetime';
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

    private static function compute_next_iso(array $rows): ?string {
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
                $candidates[] = $dt;
            }
        }

        if (!$candidates) { return null; }

        usort($candidates, fn($a, $b) => $a <=> $b);
        return $candidates[0]->format(DateTimeInterface::ATOM);
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

        $next = self::compute_next_iso($clean);
        if ($next) {
            update_post_meta($post_id, self::META_NEXT, $next);

            $acf_name = self::acf_field_name();
            if (function_exists('update_field')) {
                @update_field($acf_name, $next, $post_id);
            } else {
                update_post_meta($post_id, $acf_name, $next);
            }
        } else {
            delete_post_meta($post_id, self::META_NEXT);
        }
    }
}

MKA_Workshop_Dates_OptionC::init();
