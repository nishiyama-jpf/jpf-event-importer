<?php
/*
Plugin Name: JPF イベント一括登録プラグイン
Description: CSVファイルからイベント（投稿）、ACF、およびアイキャッチ画像を一括で新規登録します。
Version: 1.5
Author: プロのWordPressエンジニア
*/

// 直接アクセスを禁止
if ( ! defined( 'ABSPATH' ) ) exit;

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

/**
 * 2. 管理画面の描画とCSV処理ロジック
 */
function jpf_event_csv_importer_page() {
    $message = '';

    if ( isset( $_POST['submit_csv'] ) && check_admin_referer( 'jpf_csv_import_nonce' ) ) {
        if ( ! empty( $_FILES['csv_file']['tmp_name'] ) ) {
            $file = $_FILES['csv_file']['tmp_name'];
            $success_count = 0;
            $error_count = 0;
            $inserted_links = array();

            $csv = new SplFileObject( $file );
            $csv->setFlags( SplFileObject::READ_CSV | SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE );
            
            foreach ( $csv as $index => $row ) {
                if ( $index === 0 ) continue;

                // 11列のデータ取得（BOM除去含む）
                $title        = isset($row[0]) ? preg_replace('/^\xEF\xBB\xBF/', '', trim($row[0])) : '';
                $raw_date     = isset($row[1]) ? trim($row[1]) : '';
                $cat_slug     = isset($row[2]) ? trim($row[2]) : '';
                $content      = isset($row[3]) ? trim($row[3]) : '';
                $meta_start   = isset($row[4]) ? trim($row[4]) : '';
                $meta_open    = isset($row[5]) ? trim($row[5]) : '';
                $meta_price   = isset($row[6]) ? trim($row[6]) : '';
                $meta_list_txt= isset($row[7]) ? trim($row[7]) : '';
                $meta_summary = isset($row[8]) ? trim($row[8]) : '';
                $meta_contact = isset($row[9]) ? trim($row[9]) : '';
                $thumbnail_url= isset($row[10])? trim($row[10]) : ''; // ★アイキャッチURL

                if ( empty( $title ) || empty( $raw_date ) ) {
                    $error_count++;
                    continue;
                }

                // 日付をWordPressが確実に理解できる形式(Y-m-d H:i:s)に強制変換
                $formatted_date = date( 'Y-m-d H:i:s', strtotime( str_replace('/', '-', $raw_date) ) );
                if ( ! $formatted_date || $formatted_date === '1970-01-01 00:00:00' ) {
                    $formatted_date = current_time( 'mysql' );
                }

                $post_data = array(
                    'post_title'   => $title,
                    'post_content' => $content,
                    'post_status'  => 'publish',
                    'post_date'    => $formatted_date,
                    'post_type'    => 'post',
                );

                $post_id = wp_insert_post( $post_data );

                if ( ! is_wp_error( $post_id ) ) {
                    
                    // 強制公開処理
                    global $wpdb;
                    $wpdb->update(
                        $wpdb->posts,
                        array( 'post_status' => 'publish' ),
                        array( 'ID' => $post_id ),
                        array( '%s' ),
                        array( '%d' )
                    );
                    clean_post_cache( $post_id );

                    // カテゴリーの紐付け
                    if ( ! empty( $cat_slug ) ) {
                        $term = get_term_by( 'slug', $cat_slug, 'category' );
                        if ( $term ) {
                            wp_set_object_terms( $post_id, intval( $term->term_id ), 'category' );
                        }
                    }

                    // ACFの保存
                    update_post_meta( $post_id, 'start', $meta_start );
                    update_post_meta( $post_id, 'open', $meta_open );
                    update_post_meta( $post_id, 'price', $meta_price );
                    update_post_meta( $post_id, '一覧表示用テキスト', $meta_list_txt );
                    update_post_meta( $post_id, '概要', $meta_summary );
                    update_post_meta( $post_id, 'お問い合わせ', $meta_contact );

                    // ★アイキャッチ画像の設定
                    if ( ! empty( $thumbnail_url ) ) {
                        // URLからメディアのIDを取得するWordPress標準機能
                        $attachment_id = attachment_url_to_postid( $thumbnail_url );
                        
                        if ( $attachment_id ) {
                            // IDが見つかったらアイキャッチに設定
                            set_post_thumbnail( $post_id, $attachment_id );
                        }
                    }

                    // 成功した記事の編集画面URLを生成
                    $edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
                    $inserted_links[] = '<a href="' . esc_url( $edit_url ) . '" target="_blank">📄 ' . esc_html( $title ) . ' (ID: ' . $post_id . ')</a>';

                    $success_count++;
                } else {
                    $error_count++;
                }
            }
            
            // 結果メッセージ
            $message = "<div class='updated'><p><strong>インポート完了: 成功 {$success_count}件 / 失敗 {$error_count}件</strong></p>";
            if ( ! empty( $inserted_links ) ) {
                $message .= "<p>▼ 登録されたデータ（クリックで編集画面を確認できます）<br>" . implode( '<br>', $inserted_links ) . "</p>";
            }
            $message .= "</div>";
            
        } else {
            $message = "<div class='error'><p>CSVファイルを選択してください。</p></div>";
        }
    }

    // 画面のHTML出力
    ?>
    <div class="wrap">
        <h1>イベントCSV一括登録</h1>
        <?php echo $message; ?>
        <p>以下の順序で作成した全11列のCSVファイル（UTF-8）をアップロードしてください。<br>
        1行目はヘッダー行としてスキップされます。使用しない項目は列を消さずに「空欄」のままにしてください。</p>
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
            <li><strong>アイキャッチ画像URL (空欄可 / 例: https://.../slide1.jpg)</strong></li>
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
    </div>
    <?php
}