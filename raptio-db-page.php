<?php
// plugins/raptio-db/raptio-db-page.php

// 1. 管理者認証チェック
require_once __DIR__ . '/../../admin/auth.php';
if (!check_raptio_auth()) { 
    header('Location: ../../admin/login.php'); 
    exit; 
}

// 2. 共通設定とデータベースコアの読み込み
require_once __DIR__ . '/../../admin/config.php';
require_once __DIR__ . '/raptio-db.php';

// 3. ページタイトルの定義
$page_title   = 'データベース共通基盤';
$current_page = 'raptio-db';

// 4. 管理画面の共通パーツ（ヘッダー・サイドバー）をロード
require_once __DIR__ . '/../../admin/includes/header.php';
require_once __DIR__ . '/../../admin/includes/sidebar.php';

// 5. メインコンテンツの描画
global $raptio_db_instance;
if (isset($raptio_db_instance)) {
    $raptio_db_instance->render_html();
} else {
    echo '<div class="importer-card"><p style="color:red;">Raptio DB プラグインが正常に初期化されていません。</p></div>';
}

// 6. 管理画面共通フッターをロード
require_once __DIR__ . '/../../admin/includes/footer.php';