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
const JPF_CLOSE_CRON_RECURRENCE = 'jpf_every_five_minutes';

register_activation_hook( __FILE__, 'jpf_event_csv_importer_activate' );
register_deactivation_hook( __FILE__, 'jpf_event_csv_importer_deactivate' );
add_action( JPF_CLOSE_CRON_HOOK, 'jpf_run_track_usage_close_job' );
add_filter( 'cron_schedules', 'jpf_register_close_cron_schedule' );

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

function jpf_register_close_cron_schedule( $schedules ) {
    if ( ! isset( $schedules[ JPF_CLOSE_CRON_RECURRENCE ] ) ) {
        $schedules[ JPF_CLOSE_CRON_RECURRENCE ] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'Every 5 Minutes (JPF)',
        );
    }

    return $schedules;
}

function jpf_event_csv_importer_activate() {
    $timestamp = wp_next_scheduled( JPF_CLOSE_CRON_HOOK );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, JPF_CLOSE_CRON_HOOK );
    }

    wp_schedule_event( time() + MINUTE_IN_SECONDS, JPF_CLOSE_CRON_RECURRENCE, JPF_CLOSE_CRON_HOOK );
}

function jpf_event_csv_importer_deactivate() {
    $timestamp = wp_next_scheduled( JPF_CLOSE_CRON_HOOK );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, JPF_CLOSE_CRON_HOOK );
    }
}

function jpf_get_close_settings() {
    $defaults = array(
        'trigger_time'            => '23:55',
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
    $slug = trim( (string) $slug );
    if ( $slug === '' ) {
        return false;
    }

    $candidates = array_unique( array_filter( array(
        $slug,
        sanitize_title( $slug ),
        urldecode( $slug ),
    ) ) );

    foreach ( $candidates as $candidate ) {
        $term = get_term_by( 'slug', $candidate, 'category' );
        if ( $term && ! is_wp_error( $term ) ) {
            return $term;
        }
    }

    return false;
}


function jpf_parse_category_term_ids( $raw_slugs ) {
    $slugs = array_filter( array_map( 'trim', explode( ',', (string) $raw_slugs ) ) );
    $term_ids = array();

    foreach ( $slugs as $slug ) {
        $term = jpf_find_category_by_slug( $slug );
        if ( $term && ! is_wp_error( $term ) ) {
            $term_ids[] = (int) $term->term_id;
        }
    }

    return array_values( array_unique( $term_ids ) );
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

function jpf_run_track_usage_close_job() {
    $settings = jpf_get_close_settings();

    $trigger_time = isset( $settings['trigger_time'] ) ? (string) $settings['trigger_time'] : '23:55';
    if ( ! preg_match( '/^\d{2}:\d{2}$/', $trigger_time ) ) {
        return;
    }

    $current_timestamp = current_time( 'timestamp' );
    $today = wp_date( 'Y-m-d', $current_timestamp );
    $trigger_timestamp = strtotime( $today . ' ' . $trigger_time . ':00' );

    // 指定時刻を過ぎるまでは何もしない（過ぎたら次回Cron以降で実行）
    if ( ! $trigger_timestamp || $current_timestamp < $trigger_timestamp ) {
        return;
    }

    $target_category_slugs = isset( $settings['target_category_slugs'] ) ? $settings['target_category_slugs'] : '';
    $target_term_ids = jpf_parse_category_term_ids( $target_category_slugs );
    if ( empty( $target_term_ids ) ) {
        return;
    }


    $target_post_ids = get_posts( array(
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
        'meta_query'     => array(
            array(
                'key'   => 'event_date',
                'value' => $today,
            ),
        ),
    ) );

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

    $matched_target_term_ids = array_values( array_unique( array_map( 'intval', $matched_target_term_ids ) ) );
    if ( empty( $matched_target_term_ids ) ) {
        return;
    }

    $replacement_rules = jpf_parse_replacement_rules( isset( $settings['replacement_rules'] ) ? $settings['replacement_rules'] : '' );

    foreach ( $matched_target_term_ids as $term_id ) {
        $closed_notice_key = 'jpf_closed_notice_' . $today . '_' . $term_id;
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
            continue;
        }

        $rule = isset( $replacement_rules[ $term_id ] ) ? $replacement_rules[ $term_id ] : array();
        $replacement_title = jpf_render_template_tokens( ! empty( $rule['replacement_title'] ) ? $rule['replacement_title'] : $settings['replacement_title'], $today );
        $replacement_content = jpf_render_template_tokens( ! empty( $rule['replacement_content'] ) ? $rule['replacement_content'] : $settings['replacement_content'], $today );
        $replacement_list_text = jpf_render_template_tokens( ! empty( $rule['replacement_list_text'] ) ? $rule['replacement_list_text'] : $settings['replacement_list_text'], $today );

        $new_post_id = wp_insert_post( array(
            'post_title'   => $replacement_title,
            'post_content' => $replacement_content,
            'post_status'  => 'publish',
            'post_type'    => 'post',
            'post_date'    => current_time( 'mysql' ),
        ) );

        if ( is_wp_error( $new_post_id ) ) {
            continue;
        }

        $replacement_slug = ! empty( $rule['replacement_category'] ) ? $rule['replacement_category'] : $settings['replacement_category'];
        $replacement_term = jpf_find_category_by_slug( $replacement_slug );
        if ( $replacement_term && ! is_wp_error( $replacement_term ) ) {
            wp_set_object_terms( $new_post_id, (int) $replacement_term->term_id, 'category', false );
        } else {
            wp_set_object_terms( $new_post_id, $term_id, 'category', false );
        }

        update_post_meta( $new_post_id, '一覧表示用テキスト', $replacement_list_text );
        update_post_meta( $new_post_id, 'event_date', $today );
        update_post_meta( $new_post_id, 'is_track_usage', 1 );
        update_post_meta( $new_post_id, 'closed_notice_key', $closed_notice_key );
    }
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
            'trigger_time'          => isset( $_POST['trigger_time'] ) ? sanitize_text_field( wp_unslash( $_POST['trigger_time'] ) ) : '23:55',
            'target_category_slugs' => isset( $_POST['target_category_slugs'] ) ? sanitize_text_field( wp_unslash( $_POST['target_category_slugs'] ) ) : '',
            'replacement_category'  => isset( $_POST['replacement_category'] ) ? sanitize_title( wp_unslash( $_POST['replacement_category'] ) ) : '',
            'replacement_title'     => isset( $_POST['replacement_title'] ) ? sanitize_text_field( wp_unslash( $_POST['replacement_title'] ) ) : '',
            'replacement_content'   => isset( $_POST['replacement_content'] ) ? wp_kses_post( wp_unslash( $_POST['replacement_content'] ) ) : '',
            'replacement_list_text' => isset( $_POST['replacement_list_text'] ) ? sanitize_text_field( wp_unslash( $_POST['replacement_list_text'] ) ) : '',
            'replacement_rules'     => isset( $_POST['replacement_rules'] ) ? sanitize_textarea_field( wp_unslash( $_POST['replacement_rules'] ) ) : '',
            'delete_permanently'    => ! empty( $_POST['delete_permanently'] ) ? 1 : 0,
        );

        update_option( JPF_CLOSE_SETTINGS_OPTION, $settings );
        $message .= "<div class='updated'><p>締切自動化設定を保存しました。</p></div>";
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
        <p>指定時刻になると、対象日の走路利用カテゴリ投稿（event_date=当日）を削除し、受付終了投稿を自動で1件作成します。本文には <code>{date}</code> / <code>{month}</code> が使えます。</p>

        <form method="post">
            <?php wp_nonce_field( 'jpf_close_settings_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="trigger_time">実行時刻 (HH:MM)</label></th>
                    <td><input type="time" name="trigger_time" id="trigger_time" value="<?php echo esc_attr( $close_settings['trigger_time'] ); ?>"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="target_category_slugs">削除対象カテゴリースラッグ（複数可）</label></th>
                    <td><input type="text" name="target_category_slugs" id="target_category_slugs" class="regular-text" value="<?php echo esc_attr( $close_settings['target_category_slugs'] ); ?>">
                    <p class="description">カンマ区切りで複数指定できます（例: track_group_open,track_group_event）</p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="replacement_category">差し替え投稿カテゴリースラッグ</label></th>
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
                        <p class="description">1行に1ルール、<code>削除対象カテゴリ|差し替えカテゴリ|タイトル|本文|一覧表示用テキスト</code> の順で指定します。未指定項目は共通設定を利用します。</p>
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
    </div>
    <?php
}
