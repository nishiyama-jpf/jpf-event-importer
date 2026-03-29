<?php
/*
Plugin Name: JPF イベント一括登録プラグイン
Description: CSVファイルからイベント（投稿）、ACF、およびアイキャッチ画像を一括で登録・更新します。
Version: 1.6
Author: プロのWordPressエンジニア
*/

// 直接アクセスを禁止
if ( ! defined( 'ABSPATH' ) ) exit;

const JPF_CLOSE_SETTINGS_OPTION = 'jpf_track_close_settings';
const JPF_CLOSE_CRON_HOOK = 'jpf_track_usage_close_event';
const JPF_CLOSE_MINUTE_CRON_HOOK = 'jpf_track_usage_close_event_minute_tick';
const JPF_CLOSE_LOG_OPTION = 'jpf_track_close_logs';
const JPF_CLOSE_DISPATCH_STATE_OPTION = 'jpf_track_close_dispatch_state';
const JPF_CLOSE_LOG_LIMIT = 100;
const JPF_CLOSE_COMMON_THUMBNAIL_URL = 'https://chiba-jpf-dome.com/wp-content/uploads/2022/09/slide1.jpg';
const JPF_CLOSE_RUN_TIMES = array( '03:00', '03:05', '03:10' );

register_activation_hook( __FILE__, 'jpf_event_csv_importer_activate' );
register_deactivation_hook( __FILE__, 'jpf_event_csv_importer_deactivate' );
add_action( JPF_CLOSE_CRON_HOOK, 'jpf_run_track_usage_close_job' );
add_action( JPF_CLOSE_MINUTE_CRON_HOOK, 'jpf_dispatch_due_close_jobs' );
add_action( 'init', 'jpf_ensure_close_cron_runner' );
add_filter( 'cron_schedules', 'jpf_add_every_minute_schedule' );

/**
 * 1. 管理画面にメニューを追加
 */
add_action( 'admin_menu', 'jpf_event_csv_importer_menu' );
function jpf_event_csv_importer_menu() {
    add_submenu_page(
        'edit.php',
        'イベントCSV一括登録',
        'CSV一括登録',
        'manage_options',
        'jpf-event-csv-importer',
        'jpf_event_csv_importer_page'
    );
}

function jpf_event_csv_importer_activate() {
    wp_clear_scheduled_hook( JPF_CLOSE_CRON_HOOK );
    wp_clear_scheduled_hook( JPF_CLOSE_MINUTE_CRON_HOOK );
    delete_option( JPF_CLOSE_DISPATCH_STATE_OPTION );
    jpf_ensure_close_cron_runner();
}

function jpf_event_csv_importer_deactivate() {
    wp_clear_scheduled_hook( JPF_CLOSE_CRON_HOOK );
    wp_clear_scheduled_hook( JPF_CLOSE_MINUTE_CRON_HOOK );
}

function jpf_add_every_minute_schedule( $schedules ) {
    if ( ! isset( $schedules['every_minute'] ) ) {
        $schedules['every_minute'] = array(
            'interval' => MINUTE_IN_SECONDS,
            'display'  => __( 'Every Minute' ),
        );
    }

    return $schedules;
}

function jpf_ensure_close_cron_runner() {
    if ( ! wp_next_scheduled( JPF_CLOSE_MINUTE_CRON_HOOK ) ) {
        wp_schedule_event( time() + 10, 'every_minute', JPF_CLOSE_MINUTE_CRON_HOOK );
    }
}

function jpf_get_due_close_slots( DateTimeImmutable $now ) {
    $timezone = $now->getTimezone();
    $today = $now->format( 'Y-m-d' );
    $due_slots = array();

    foreach ( JPF_CLOSE_RUN_TIMES as $run_time ) {
        $run_datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $today . ' ' . $run_time . ':00', $timezone );
        if ( ! $run_datetime ) {
            continue;
        }

        if ( $run_datetime->getTimestamp() <= $now->getTimestamp() ) {
            $due_slots[] = $run_datetime->format( 'Y-m-d H:i:s' );
        }
    }

    return $due_slots;
}

function jpf_dispatch_due_close_jobs() {
    $timezone = wp_timezone();
    $now = new DateTimeImmutable( 'now', $timezone );
    $today = $now->format( 'Y-m-d' );
    $due_slots = jpf_get_due_close_slots( $now );
    if ( empty( $due_slots ) ) {
        return;
    }

    $dispatch_state = get_option( JPF_CLOSE_DISPATCH_STATE_OPTION, array() );
    if ( ! is_array( $dispatch_state ) || ! isset( $dispatch_state['date'] ) || $dispatch_state['date'] !== $today ) {
        $dispatch_state = array(
            'date'      => $today,
            'processed' => array(),
        );
    }

    $processed_slots = isset( $dispatch_state['processed'] ) && is_array( $dispatch_state['processed'] )
        ? $dispatch_state['processed']
        : array();

    foreach ( $due_slots as $due_slot ) {
        if ( in_array( $due_slot, $processed_slots, true ) ) {
            continue;
        }

        /**
         * minute実行が遅延しても、未処理スロットは必ず補填実行する。
         */
        do_action( JPF_CLOSE_CRON_HOOK, $due_slot );
        $processed_slots[] = $due_slot;
    }

    $dispatch_state['processed'] = array_values( array_unique( $processed_slots ) );
    update_option( JPF_CLOSE_DISPATCH_STATE_OPTION, $dispatch_state, false );
}

function jpf_is_valid_close_scheduled_time( $scheduled_time, DateTimeZone $timezone ) {
    $scheduled_value = trim( (string) $scheduled_time );
    if ( $scheduled_value === '' ) {
        return false;
    }

    $scheduled_datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $scheduled_value, $timezone );
    if ( ! $scheduled_datetime ) {
        return false;
    }

    return in_array( $scheduled_datetime->format( 'H:i' ), JPF_CLOSE_RUN_TIMES, true );
}

function jpf_set_common_close_thumbnail( $post_id ) {
    $attachment_id = attachment_url_to_postid( JPF_CLOSE_COMMON_THUMBNAIL_URL );
    if ( empty( $attachment_id ) ) {
        jpf_add_close_log( 'warning', '共通アイキャッチ画像がメディアに見つからないため設定をスキップしました。', array(
            'post_id'        => (int) $post_id,
            'thumbnail_url'  => JPF_CLOSE_COMMON_THUMBNAIL_URL,
        ) );
        return false;
    }

    return set_post_thumbnail( (int) $post_id, (int) $attachment_id );
}

function jpf_add_close_log( $level, $message, $context = array() ) {
    $logs = get_option( JPF_CLOSE_LOG_OPTION, array() );
    if ( ! is_array( $logs ) ) {
        $logs = array();
    }

    $logs[] = array(
        'time'    => current_time( 'mysql' ),
        'level'   => sanitize_key( (string) $level ),
        'message' => sanitize_text_field( (string) $message ),
        'context' => is_array( $context ) ? $context : array(),
    );

    if ( count( $logs ) > JPF_CLOSE_LOG_LIMIT ) {
        $logs = array_slice( $logs, -JPF_CLOSE_LOG_LIMIT );
    }

    update_option( JPF_CLOSE_LOG_OPTION, $logs, false );
}

function jpf_get_close_logs() {
    $logs = get_option( JPF_CLOSE_LOG_OPTION, array() );
    if ( ! is_array( $logs ) ) {
        return array();
    }

    return array_reverse( $logs );
}

function jpf_get_close_settings() {
    $defaults = array(
        'target_category_slugs'   => 'track_group_open',
        'replacement_category'    => 'track_group_open',
        'replacement_title'       => '{date} 走路利用 受付終了',
        'replacement_content'     => '本日の走路利用受付は終了しました。\n\n{month}のお知らせ・リンクをこちらに記載してください。',
        'replacement_list_text'   => '受付終了',
        'replacement_rules'       => '',
        'delete_permanently'      => 0,
    );

    $saved = get_option( JPF_CLOSE_SETTINGS_OPTION, array() );
    if ( ! is_array( $saved ) ) {
        $saved = array();
    }


    if ( empty( $saved['target_category_slugs'] ) && ! empty( $saved['target_category_slug'] ) ) {
        $saved['target_category_slugs'] = sanitize_text_field( $saved['target_category_slug'] );
    }

    return wp_parse_args( $saved, $defaults );
}

function jpf_render_template_tokens( $text, $today ) {
    $replacements = array(
        '{date}'  => $today,
        '{month}' => wp_date( 'Y年n月', strtotime( $today ) ),
    );

    return strtr( (string) $text, $replacements );
}

function jpf_find_category_by_slug( $slug ) {
    $raw_value = trim( (string) $slug );
    if ( $raw_value === '' ) {
        return false;
    }

    if ( ctype_digit( $raw_value ) ) {
        $numeric_value = (int) $raw_value;

        $term = get_term_by( 'id', $numeric_value, 'category' );
        if ( $term && ! is_wp_error( $term ) ) {
            return $term;
        }

        $term = get_term_by( 'term_taxonomy_id', $numeric_value, 'category' );
        if ( $term && ! is_wp_error( $term ) ) {
            return $term;
        }
    }

    $candidates = array_unique( array_filter( array(
        $raw_value,
        sanitize_title( $raw_value ),
        urldecode( $raw_value ),
    ) ) );

    foreach ( $candidates as $candidate ) {
        $term = get_term_by( 'slug', $candidate, 'category' );
        if ( $term && ! is_wp_error( $term ) ) {
            return $term;
        }
    }

    $term = get_term_by( 'name', $raw_value, 'category' );
    if ( $term && ! is_wp_error( $term ) ) {
        return $term;
    }

    return false;
}


function jpf_parse_category_inputs( $raw_values ) {
    $tokens = preg_split( '/[\s,|]+/', (string) $raw_values );
    $tokens = array_values( array_filter( array_map( 'trim', $tokens ), 'strlen' ) );

    $term_ids = array();
    $resolved_terms = array();
    $unresolved_tokens = array();

    foreach ( $tokens as $token ) {
        $term = jpf_find_category_by_slug( $token );
        if ( $term && ! is_wp_error( $term ) ) {
            $term_id = (int) $term->term_id;
            $term_ids[] = $term_id;
            $resolved_terms[] = array(
                'input'    => $token,
                'term_id'  => $term_id,
                'slug'     => (string) $term->slug,
                'name'     => (string) $term->name,
            );
            continue;
        }

        $unresolved_tokens[] = $token;
    }

    return array(
        'tokens'            => $tokens,
        'term_ids'          => array_values( array_unique( $term_ids ) ),
        'resolved_terms'    => $resolved_terms,
        'unresolved_tokens' => array_values( array_unique( $unresolved_tokens ) ),
    );
}

function jpf_parse_category_term_ids( $raw_slugs ) {
    $parsed = jpf_parse_category_inputs( $raw_slugs );

    return $parsed['term_ids'];
}

function jpf_parse_replacement_rules( $raw_rules ) {
    $rules = array();
    $lines = preg_split( '/\r\n|\r|\n/', (string) $raw_rules );

    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( $line === '' || strpos( $line, '#' ) === 0 ) {
            continue;
        }

        $parts = array_map( 'trim', explode( '|', $line ) );
        if ( empty( $parts[0] ) ) {
            continue;
        }

        $target_term = jpf_find_category_by_slug( $parts[0] );
        if ( ! $target_term ) {
            continue;
        }

        $rules[ (int) $target_term->term_id ] = array(
            'replacement_category'  => isset( $parts[1] ) ? $parts[1] : '',
            'replacement_title'     => isset( $parts[2] ) ? $parts[2] : '',
            'replacement_content'   => isset( $parts[3] ) ? $parts[3] : '',
            'replacement_list_text' => isset( $parts[4] ) ? $parts[4] : '',
        );
    }

    return $rules;
}

function jpf_generate_event_key( $raw_date, $meta_start, $cat_slug, $title ) {
    $base = implode( '|', array(
        (string) $raw_date,
        (string) $meta_start,
        (string) $cat_slug,
        (string) $title,
    ) );

    return 'jpf_' . md5( $base );
}

function jpf_collect_track_usage_candidates( $post_ids, $today ) {
    $candidates = array();

    foreach ( $post_ids as $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            continue;
        }

        $event_date = (string) get_post_meta( $post_id, 'event_date', true );
        $post_date = mysql2date( 'Y-m-d', $post->post_date, false );

        $candidates[] = array(
            'post_id'              => (int) $post_id,
            'post_title'           => get_the_title( $post_id ),
            'post_date'            => $post_date,
            'post_date_time'       => mysql2date( 'Y-m-d H:i:s', $post->post_date, false ),
            'event_date'           => $event_date,
            'is_track_usage'       => (int) get_post_meta( $post_id, 'is_track_usage', true ),
            'matches_today'        => ( $event_date === $today || ( $event_date === '' && $post_date === $today ) ),
            'uses_fallback_delete' => ( $event_date === '' ),
        );
    }

    return $candidates;
}

function jpf_run_track_usage_close_job( $scheduled_time = '' ) {
    $settings = jpf_get_close_settings();

    $timezone = wp_timezone();
    $now = new DateTimeImmutable( 'now', $timezone );
    $today = $now->format( 'Y-m-d' );

    if ( wp_doing_cron() && ! jpf_is_valid_close_scheduled_time( $scheduled_time, $timezone ) ) {
        jpf_add_close_log( 'info', '実行時間帯外のCron実行をスキップしました。', array(
            'date'         => $today,
            'current_time' => $now->format( 'H:i:s' ),
            'scheduled_at' => (string) $scheduled_time,
            'run_times'    => JPF_CLOSE_RUN_TIMES,
        ) );
        return;
    }

    if ( wp_doing_cron() ) {
        $dispatch_state = get_option( JPF_CLOSE_DISPATCH_STATE_OPTION, array() );
        $processed_slots = ( is_array( $dispatch_state ) && isset( $dispatch_state['processed'] ) && is_array( $dispatch_state['processed'] ) )
            ? $dispatch_state['processed']
            : array();

        if ( in_array( (string) $scheduled_time, $processed_slots, true ) ) {
            jpf_add_close_log( 'info', '同一スロットの重複Cron実行をスキップしました。', array(
                'date'         => $today,
                'current_time' => $now->format( 'H:i:s' ),
                'scheduled_at' => (string) $scheduled_time,
            ) );
            return;
        }
    }

    jpf_add_close_log( 'info', '締切自動化ジョブを開始しました。', array(
        'date'         => $today,
        'current_time' => $now->format( 'H:i:s' ),
        'scheduled_at' => (string) $scheduled_time,
        'run_times'    => JPF_CLOSE_RUN_TIMES,
    ) );

    $target_category_slugs = isset( $settings['target_category_slugs'] ) ? $settings['target_category_slugs'] : '';
    $parsed_target_categories = jpf_parse_category_inputs( $target_category_slugs );
    $target_term_ids = $parsed_target_categories['term_ids'];
    if ( empty( $target_term_ids ) ) {
        jpf_add_close_log( 'error', '自動削除をスキップ: 削除対象カテゴリが見つかりません。', array(
            'target_category_slugs' => $target_category_slugs,
            'configured_targets'    => $parsed_target_categories['tokens'],
            'unresolved_targets'    => $parsed_target_categories['unresolved_tokens'],
        ) );
        return;
    }


    $target_posts_query_args = array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'fields'         => 'ids',
        'posts_per_page' => -1,
        'tax_query'      => array(
            array(
                'taxonomy' => 'category',
                'field'    => 'term_id',
                'terms'    => $target_term_ids,
            ),
        ),
    );

    $candidate_target_post_ids = get_posts( $target_posts_query_args );
    $candidate_target_posts = jpf_collect_track_usage_candidates( $candidate_target_post_ids, $today );
    $target_post_ids = array();

    foreach ( $candidate_target_posts as $candidate_target_post ) {
        if ( ! empty( $candidate_target_post['matches_today'] ) ) {
            $target_post_ids[] = (int) $candidate_target_post['post_id'];
        }
    }

    jpf_add_close_log( 'info', '削除対象の抽出結果を確認しました。', array(
        'date'               => $today,
        'configured_targets' => $parsed_target_categories['tokens'],
        'target_term_ids'    => $target_term_ids,
        'resolved_targets'   => $parsed_target_categories['resolved_terms'],
        'unresolved_targets' => $parsed_target_categories['unresolved_tokens'],
        'matched_posts'      => count( $target_post_ids ),
        'lookup_basis'       => 'event_date_or_post_date',
        'candidate_posts'    => count( $candidate_target_post_ids ),
    ) );

    if ( empty( $target_post_ids ) ) {
        $diagnostic_posts = array_slice( $candidate_target_posts, 0, 5 );

        jpf_add_close_log( 'info', '削除対象0件のためカテゴリ内投稿を診断しました。', array(
            'date'                  => $today,
            'lookup_basis'          => 'event_date_or_post_date',
            'candidate_posts'       => $diagnostic_posts,
            'configured_targets'    => $parsed_target_categories['tokens'],
            'target_term_ids'       => $target_term_ids,
            'unresolved_targets'    => $parsed_target_categories['unresolved_tokens'],
        ) );

        $legacy_posts_without_event_date = array_values( array_filter(
            $candidate_target_posts,
            function ( $candidate_target_post ) {
                return ! empty( $candidate_target_post['uses_fallback_delete'] )
                    && empty( $candidate_target_post['matches_today'] );
            }
        ) );

        if ( ! empty( $legacy_posts_without_event_date ) ) {
            jpf_add_close_log( 'info', 'event_date未設定の過去投稿は削除対象から除外しました。', array(
                'date'             => $today,
                'lookup_basis'     => 'event_date_or_post_date',
                'skipped_posts'    => array_slice( $legacy_posts_without_event_date, 0, 5 ),
                'skipped_count'    => count( $legacy_posts_without_event_date ),
                'configured_terms' => $target_term_ids,
            ) );
        }
    }

    $matched_target_term_ids = array();
    $force_delete = ! empty( $settings['delete_permanently'] );
    foreach ( $target_post_ids as $post_id ) {
        $post_term_ids = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'ids' ) );
        if ( ! is_wp_error( $post_term_ids ) ) {
            $post_target_terms = array_intersect( $target_term_ids, array_map( 'intval', $post_term_ids ) );
            $matched_target_term_ids = array_merge( $matched_target_term_ids, $post_target_terms );
        }
        wp_delete_post( (int) $post_id, $force_delete );
    }

    jpf_add_close_log( 'info', '対象投稿の削除処理を実行しました。', array(
        'date'          => $today,
        'deleted_count' => count( $target_post_ids ),
        'force_delete'  => (int) $force_delete,
    ) );

    $matched_target_term_ids = array_values( array_unique( array_map( 'intval', $matched_target_term_ids ) ) );
    if ( empty( $matched_target_term_ids ) ) {
        jpf_add_close_log( 'info', '削除対象はありましたが、差し替え作成対象カテゴリはありませんでした。', array( 'date' => $today ) );
        return;
    }

    $replacement_rules = jpf_parse_replacement_rules( isset( $settings['replacement_rules'] ) ? $settings['replacement_rules'] : '' );
    $closed_notice_key = 'jpf_closed_notice_' . $today;
    $existing_closed = get_posts( array(
        'post_type'      => 'post',
        'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private', 'trash' ),
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'   => 'closed_notice_key',
                'value' => $closed_notice_key,
            ),
        ),
    ) );

    if ( ! empty( $existing_closed ) ) {
        jpf_add_close_log( 'info', '同日分の差し替え投稿が既に存在するためスキップしました。', array(
            'date'             => $today,
            'closed_notice_key'=> $closed_notice_key,
        ) );
        return;
    }

    $primary_term_id = (int) reset( $matched_target_term_ids );
    $rule = isset( $replacement_rules[ $primary_term_id ] ) ? $replacement_rules[ $primary_term_id ] : array();
    $replacement_title = jpf_render_template_tokens( ! empty( $rule['replacement_title'] ) ? $rule['replacement_title'] : $settings['replacement_title'], $today );
    $replacement_content = jpf_render_template_tokens( ! empty( $rule['replacement_content'] ) ? $rule['replacement_content'] : $settings['replacement_content'], $today );
    $replacement_list_text = jpf_render_template_tokens( ! empty( $rule['replacement_list_text'] ) ? $rule['replacement_list_text'] : $settings['replacement_list_text'], $today );

    $replacement_post_time = $today . ' ' . current_time( 'H:i:s' );
    $new_post_id = wp_insert_post( array(
        'post_title'     => $replacement_title,
        'post_content'   => $replacement_content,
        'post_status'    => 'publish',
        'post_type'      => 'post',
        'post_date'      => $replacement_post_time,
        'post_date_gmt'  => get_gmt_from_date( $replacement_post_time ),
    ) );

    if ( is_wp_error( $new_post_id ) ) {
        jpf_add_close_log( 'error', '差し替え投稿の作成に失敗しました。', array(
            'date'    => $today,
            'error'   => $new_post_id->get_error_message(),
        ) );
        return;
    }

    $replacement_slug = ! empty( $rule['replacement_category'] ) ? $rule['replacement_category'] : $settings['replacement_category'];
    $replacement_term = jpf_find_category_by_slug( $replacement_slug );
    if ( $replacement_term && ! is_wp_error( $replacement_term ) ) {
        wp_set_object_terms( $new_post_id, (int) $replacement_term->term_id, 'category', false );
    } else {
        wp_set_object_terms( $new_post_id, $primary_term_id, 'category', false );
    }

    update_post_meta( $new_post_id, '一覧表示用テキスト', $replacement_list_text );
    update_post_meta( $new_post_id, 'event_date', $today );
    update_post_meta( $new_post_id, 'is_track_usage', 1 );
    update_post_meta( $new_post_id, 'closed_notice_key', $closed_notice_key );
    jpf_set_common_close_thumbnail( $new_post_id );

    jpf_add_close_log( 'info', '差し替え投稿を作成しました。', array(
        'date'              => $today,
        'new_post_id'       => (int) $new_post_id,
        'closed_notice_key' => $closed_notice_key,
        'thumbnail_url'     => JPF_CLOSE_COMMON_THUMBNAIL_URL,
    ) );
}

function jpf_update_meta_if_changed( $post_id, $meta_key, $new_value ) {
    $current_value = get_post_meta( $post_id, $meta_key, true );
    if ( (string) $current_value === (string) $new_value ) {
        return false;
    }

    update_post_meta( $post_id, $meta_key, $new_value );
    return true;
}

function jpf_find_existing_post_id( $event_key, $event_date, $meta_start ) {
    if ( ! empty( $event_key ) ) {
        $by_key = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'   => 'event_key',
                    'value' => $event_key,
                ),
            ),
        ) );

        if ( ! empty( $by_key ) ) {
            return (int) $by_key[0];
        }
    }

    if ( ! empty( $event_date ) && ! empty( $meta_start ) ) {
        $by_slot = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                'relation' => 'AND',
                array(
                    'key'   => 'event_date',
                    'value' => $event_date,
                ),
                array(
                    'key'   => 'start',
                    'value' => $meta_start,
                ),
            ),
        ) );

        if ( ! empty( $by_slot ) ) {
            return (int) $by_slot[0];
        }
    }

    return 0;
}

/**
 * 2. 管理画面の描画とCSV処理ロジック
 */
function jpf_event_csv_importer_page() {
    $message = '';

    if ( isset( $_POST['save_close_settings'] ) && check_admin_referer( 'jpf_close_settings_nonce' ) ) {
        $settings = array(
            'target_category_slugs' => isset( $_POST['target_category_slugs'] ) ? sanitize_text_field( wp_unslash( $_POST['target_category_slugs'] ) ) : '',
            'replacement_category'  => isset( $_POST['replacement_category'] ) ? sanitize_text_field( wp_unslash( $_POST['replacement_category'] ) ) : '',
            'replacement_title'     => isset( $_POST['replacement_title'] ) ? sanitize_text_field( wp_unslash( $_POST['replacement_title'] ) ) : '',
            'replacement_content'   => isset( $_POST['replacement_content'] ) ? wp_kses_post( wp_unslash( $_POST['replacement_content'] ) ) : '',
            'replacement_list_text' => isset( $_POST['replacement_list_text'] ) ? sanitize_text_field( wp_unslash( $_POST['replacement_list_text'] ) ) : '',
            'replacement_rules'     => isset( $_POST['replacement_rules'] ) ? sanitize_textarea_field( wp_unslash( $_POST['replacement_rules'] ) ) : '',
            'delete_permanently'    => ! empty( $_POST['delete_permanently'] ) ? 1 : 0,
        );

        update_option( JPF_CLOSE_SETTINGS_OPTION, $settings );
        $message .= "<div class='updated'><p>締切自動化設定を保存しました。</p></div>";
    }

    if ( isset( $_POST['clear_close_logs'] ) && check_admin_referer( 'jpf_close_logs_nonce' ) ) {
        update_option( JPF_CLOSE_LOG_OPTION, array(), false );
        $message .= "<div class='updated'><p>締切自動化ログをクリアしました。</p></div>";
    }

    if ( isset( $_POST['run_close_job_now'] ) && check_admin_referer( 'jpf_close_job_run_nonce' ) ) {
        jpf_run_track_usage_close_job();
        $message .= "<div class='updated'><p>締切自動化ジョブを手動実行しました。最新ログを確認してください。</p></div>";
    }

    if ( isset( $_POST['submit_csv'] ) && check_admin_referer( 'jpf_csv_import_nonce' ) ) {
        if ( ! empty( $_FILES['csv_file']['tmp_name'] ) ) {
            $file = $_FILES['csv_file']['tmp_name'];
            $created_count = 0;
            $updated_count = 0;
            $error_count = 0;
            $inserted_links = array();

            $csv = new SplFileObject( $file );
            $csv->setFlags( SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE );

            foreach ( $csv as $index => $row ) {
                if ( $index === 0 ) continue;
                if ( empty( $row ) || ! is_array( $row ) ) continue;

                // 既存11列 + 拡張列（12:event_key, 13:is_track_usage）
                $title         = isset( $row[0] ) ? preg_replace( '/^\xEF\xBB\xBF/', '', trim( $row[0] ) ) : '';
                $raw_date      = isset( $row[1] ) ? trim( $row[1] ) : '';
                $cat_slug      = isset( $row[2] ) ? trim( $row[2] ) : '';
                $content       = isset( $row[3] ) ? trim( $row[3] ) : '';
                $meta_start    = isset( $row[4] ) ? trim( $row[4] ) : '';
                $meta_open     = isset( $row[5] ) ? trim( $row[5] ) : '';
                $meta_price    = isset( $row[6] ) ? trim( $row[6] ) : '';
                $meta_list_txt = isset( $row[7] ) ? trim( $row[7] ) : '';
                $meta_summary  = isset( $row[8] ) ? trim( $row[8] ) : '';
                $meta_contact  = isset( $row[9] ) ? trim( $row[9] ) : '';
                $thumbnail_url = isset( $row[10] ) ? trim( $row[10] ) : '';
                $event_key     = isset( $row[11] ) ? trim( $row[11] ) : '';
                if ( empty( $event_key ) ) {
                    $event_key = jpf_generate_event_key( $raw_date, $meta_start, $cat_slug, $title );
                }
                $is_track_usage = isset( $row[12] ) ? (int) trim( $row[12] ) : 0;

                if ( empty( $title ) || empty( $raw_date ) ) {
                    $error_count++;
                    continue;
                }

                $timestamp = strtotime( str_replace( '/', '-', $raw_date ) );
                $formatted_date = $timestamp ? date( 'Y-m-d H:i:s', $timestamp ) : current_time( 'mysql' );
                $event_date = $timestamp ? date( 'Y-m-d', $timestamp ) : wp_date( 'Y-m-d', current_time( 'timestamp' ) );

                $existing_post_id = jpf_find_existing_post_id( $event_key, $event_date, $meta_start );
                $is_update = ! empty( $existing_post_id );

                $post_data = array(
                    'post_title'   => $title,
                    'post_content' => $content,
                    'post_status'  => 'publish',
                    'post_date'    => $formatted_date,
                    'post_type'    => 'post',
                );

                if ( $is_update ) {
                    $post_data['ID'] = $existing_post_id;
                    $post_id = wp_update_post( $post_data, true );
                } else {
                    $post_id = wp_insert_post( $post_data, true );
                }

                if ( is_wp_error( $post_id ) ) {
                    $error_count++;
                    continue;
                }

                $changed = ! $is_update;

                // カテゴリーの差し替え
                if ( ! empty( $cat_slug ) ) {
                    $term = jpf_find_category_by_slug( $cat_slug );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $current_terms = wp_get_object_terms( $post_id, 'category', array( 'fields' => 'ids' ) );
                        $new_terms = array( (int) $term->term_id );
                        sort( $current_terms );
                        sort( $new_terms );

                        if ( $current_terms !== $new_terms ) {
                            wp_set_object_terms( $post_id, (int) $term->term_id, 'category', false );
                            $changed = true;
                        }
                    }
                }

                // ACF/metaの差分更新
                $changed = jpf_update_meta_if_changed( $post_id, 'start', $meta_start ) || $changed;
                $changed = jpf_update_meta_if_changed( $post_id, 'open', $meta_open ) || $changed;
                $changed = jpf_update_meta_if_changed( $post_id, 'price', $meta_price ) || $changed;
                $changed = jpf_update_meta_if_changed( $post_id, '一覧表示用テキスト', $meta_list_txt ) || $changed;
                $changed = jpf_update_meta_if_changed( $post_id, '概要', $meta_summary ) || $changed;
                $changed = jpf_update_meta_if_changed( $post_id, 'お問い合わせ', $meta_contact ) || $changed;
                $changed = jpf_update_meta_if_changed( $post_id, 'event_date', $event_date ) || $changed;

                $changed = jpf_update_meta_if_changed( $post_id, 'event_key', $event_key ) || $changed;

                if ( empty( $is_track_usage ) && strpos( $cat_slug, 'track' ) !== false ) {
                    $is_track_usage = 1;
                }
                $changed = jpf_update_meta_if_changed( $post_id, 'is_track_usage', $is_track_usage ) || $changed;

                // アイキャッチ画像差分更新
                if ( ! empty( $thumbnail_url ) ) {
                    $attachment_id = attachment_url_to_postid( $thumbnail_url );
                    if ( $attachment_id ) {
                        $current_thumbnail_id = (int) get_post_thumbnail_id( $post_id );
                        if ( $current_thumbnail_id !== (int) $attachment_id ) {
                            set_post_thumbnail( $post_id, $attachment_id );
                            $changed = true;
                        }
                    }
                }

                $edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
                $inserted_links[] = '<a href="' . esc_url( $edit_url ) . '" target="_blank">📄 ' . esc_html( $title ) . ' (ID: ' . $post_id . ')</a>';

                if ( $is_update ) {
                    if ( $changed ) {
                        $updated_count++;
                    }
                } else {
                    $created_count++;
                }
            }

            $message .= "<div class='updated'><p><strong>インポート完了: 新規 {$created_count}件 / 更新 {$updated_count}件 / 失敗 {$error_count}件</strong></p>";
            if ( ! empty( $inserted_links ) ) {
                $message .= "<p>▼ 処理対象データ（クリックで編集画面を確認できます）<br>" . implode( '<br>', $inserted_links ) . "</p>";
            }
            $message .= "</div>";
        } else {
            $message .= "<div class='error'><p>CSVファイルを選択してください。</p></div>";
        }
    }

    $close_settings = jpf_get_close_settings();
    $close_logs = jpf_get_close_logs();

    // 画面のHTML出力
    ?>
    <div class="wrap">
        <h1>イベントCSV一括登録</h1>
        <?php echo $message; ?>
        <p>以下の順序で作成したCSVファイル（UTF-8）をアップロードしてください。<br>
        1行目はヘッダー行としてスキップされます。既存11列に加え、12列目:event_key / 13列目:is_track_usage(0/1) も利用できます。event_keyが空欄の場合は自動生成します。</p>
        <ol>
            <li>投稿タイトル (必須)</li>
            <li>イベント対象日 (必須 / 例: 2026-04-02 10:00:00)</li>
            <li>カテゴリースラッグ (例: track_group_open)</li>
            <li>本文 (HTML可)</li>
            <li>start (空欄可)</li>
            <li>open (空欄可)</li>
            <li>price (空欄可)</li>
            <li>一覧表示用テキスト (空欄可 / 例: 18:30~21:30&lt;br&gt;残り3枠)</li>
            <li>概要 (空欄可)</li>
            <li>お問い合わせ (空欄可)</li>
            <li>アイキャッチ画像URL (空欄可 / 例: https://.../slide1.jpg)</li>
            <li>event_key (任意 / 空欄なら自動生成)</li>
            <li>is_track_usage (任意 / 1で走路利用扱い)</li>
        </ol>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( 'jpf_csv_import_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="csv_file">CSVファイル</label></th>
                    <td><input type="file" name="csv_file" id="csv_file" accept=".csv" required></td>
                </tr>
            </table>
            <?php submit_button( 'CSVをインポート', 'primary', 'submit_csv' ); ?>
        </form>

        <hr>

        <h2>走路利用の締切自動化設定（時間トリガー）</h2>
        <p>毎日 <code>03:00 / 03:05 / 03:10</code> の計3回だけ自動実行し、対象日の走路利用カテゴリ投稿（<code>event_date=当日</code>）を削除します。削除対象が複数カテゴリにまたがる場合でも、同日分の差し替え投稿は1件のみ作成し、共通アイキャッチ画像（<code><?php echo esc_html( JPF_CLOSE_COMMON_THUMBNAIL_URL ); ?></code>）を設定します。本文には <code>{date}</code> / <code>{month}</code> が使えます。</p>

        <form method="post">
            <?php wp_nonce_field( 'jpf_close_settings_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="target_category_slugs">削除対象カテゴリ（スラッグ/名称/ID、複数可）</label></th>
                    <td><input type="text" name="target_category_slugs" id="target_category_slugs" class="regular-text" value="<?php echo esc_attr( $close_settings['target_category_slugs'] ); ?>">
                    <p class="description">カンマ区切りで複数指定できます（例: track_group_open,track_group_event）</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="replacement_category">差し替え投稿カテゴリ（スラッグ/名称/ID）</label></th>
                    <td><input type="text" name="replacement_category" id="replacement_category" class="regular-text" value="<?php echo esc_attr( $close_settings['replacement_category'] ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="replacement_title">差し替え投稿タイトル</label></th>
                    <td><input type="text" name="replacement_title" id="replacement_title" class="regular-text" value="<?php echo esc_attr( $close_settings['replacement_title'] ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="replacement_content">差し替え投稿本文（月ごとのリンク差し替え先）</label></th>
                    <td><textarea name="replacement_content" id="replacement_content" class="large-text" rows="6"><?php echo esc_textarea( $close_settings['replacement_content'] ); ?></textarea></td>
                </tr>
                <tr>
                    <th scope="row"><label for="replacement_list_text">差し替え一覧表示用テキスト</label></th>
                    <td><input type="text" name="replacement_list_text" id="replacement_list_text" class="regular-text" value="<?php echo esc_attr( $close_settings['replacement_list_text'] ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="replacement_rules">カテゴリ別差し替えルール（任意）</label></th>
                    <td>
                        <textarea name="replacement_rules" id="replacement_rules" class="large-text code" rows="6"><?php echo esc_textarea( $close_settings['replacement_rules'] ); ?></textarea>
                        <p class="description">1行に1ルール、<code>削除対象カテゴリ|差し替えカテゴリ|タイトル|本文|一覧表示用テキスト（カテゴリはスラッグ/名称/ID対応）</code> の順で指定します。未指定項目は共通設定を利用します。</p>
                        <p class="description">例: <code>track_group_open|track_group_open|{date} 受付終了|本日の受付は終了しました。|受付終了</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">削除方式</th>
                    <td>
                        <label>
                            <input type="checkbox" name="delete_permanently" value="1" <?php checked( 1, (int) $close_settings['delete_permanently'] ); ?>>
                            完全削除する（未チェックの場合はゴミ箱へ）
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button( '締切自動化設定を保存', 'secondary', 'save_close_settings' ); ?>
        </form>

        <h2>締切自動化ログ（最新<?php echo esc_html( JPF_CLOSE_LOG_LIMIT ); ?>件）</h2>
        <p>サーバーログを確認できない場合でも、この画面で自動削除処理の結果を確認できます。</p>

        <form method="post" style="margin-bottom: 12px;">
            <?php wp_nonce_field( 'jpf_close_logs_nonce' ); ?>
            <?php submit_button( 'ログをクリア', 'delete', 'clear_close_logs', false ); ?>
        </form>

        <form method="post" style="margin-bottom: 12px;">
            <?php wp_nonce_field( 'jpf_close_job_run_nonce' ); ?>
            <?php submit_button( '締切自動化ジョブをいま実行', 'secondary', 'run_close_job_now', false ); ?>
        </form>

        <?php if ( empty( $close_logs ) ) : ?>
            <p>ログはまだありません。</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width: 180px;">時刻</th>
                        <th style="width: 100px;">レベル</th>
                        <th>内容</th>
                        <th>詳細</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $close_logs as $log ) : ?>
                        <tr>
                            <td><?php echo esc_html( isset( $log['time'] ) ? $log['time'] : '' ); ?></td>
                            <td><code><?php echo esc_html( isset( $log['level'] ) ? strtoupper( $log['level'] ) : '' ); ?></code></td>
                            <td><?php echo esc_html( isset( $log['message'] ) ? $log['message'] : '' ); ?></td>
                            <td><code><?php echo esc_html( wp_json_encode( isset( $log['context'] ) ? $log['context'] : array(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
