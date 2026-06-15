<?php
/*
Plugin Name: Raptio DB
Plugin URI:  https://raptio-cms.example.com/plugins/raptio-db
Description: 拡張プラグイン向けの共通SQLiteデータベース基盤を提供します。
Version:     1.0.0
Requires at least: 1.0.0
Requires PHP: 8.0
Author:      Raptio Developer
Author URI:  https://developer.example.com
License:     GPLv2
Text Domain: raptio-db
*/

if (!defined('ABSPATH') && !class_exists('RaptioHook')) {
    // Raptioのコアが読み込まれていない場合はスキップ
}

class RaptioDB {
    /**
     * PDO インスタンスの保持
     * @var PDO|null
     */
    private static ?PDO $pdo = null;

    /**
     * データベースファイルの保存先ディレクトリ
     * @var string
     */
    private static string $db_dir;

    /**
     * データベースファイルのフルパス
     * @var string
     */
    private static string $db_path;

    /**
     * コンストラクタ
     */
    public function __construct() {
        // ディレクトリとパスの設定（プラグイン配下の db-store ディレクトリ内）
        self::$db_dir  = __DIR__ . '/db-store';
        self::$db_path = self::$db_dir . '/.ht_raptio_shared.sqlite';

        // プラグイン読み込み時にデータベース接続と環境の初期化を実行
        self::init_database();

        // 管理画面サイドバーへのメニュー追加フック
        if (class_exists('RaptioHook')) {
            RaptioHook::add('admin_sidebar_menu', [$this, 'add_sidebar_menu'], 20);
        }
    }

    /**
     * データベースの初期化と安全なディレクトリ・ファイルの自動生成
     */
    private static function init_database(): void {
        if (self::$pdo !== null) {
            return;
        }

        // 1. 保存先ディレクトリの自動作成
        if (!is_dir(self::$db_dir)) {
            if (!mkdir(self::$db_dir, 0755, true) && !is_dir(self::$db_dir)) {
                error_log('Raptio DB: ディレクトリの作成に失敗しました: ' . self::$db_dir);
                return;
            }
        }

        // 2. .htaccess による外部アクセス制限（Apache/レンタルサーバー向けセキュリティ強化）
        $htaccess_path = self::$db_dir . '/.htaccess';
        if (!file_exists($htaccess_path)) {
            $htaccess_content = "Deny from all\n<Files ~ \"^\.ht\">\n    Order allow,deny\n    Deny from all\n</Files>\n";
            file_put_contents($htaccess_path, $htaccess_content);
        }

        // 3. PDO接続の確立
        try {
            self::$pdo = new PDO('sqlite:' . self::$db_path);
            
            // エラーモードを例外（Exception）に設定
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // デフォルトのフェッチモードを連想配列に設定
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // 4. パフォーマンスおよび安全性のチューニング（PRAGMA設定）
            // WALモード（Write-Ahead Logging）を有効化：書き込み中も読み込みをブロックしない
            self::$pdo->exec('PRAGMA journal_mode = WAL;');
            // 外部キー（Foreign Key）制約を有効化
            self::$pdo->exec('PRAGMA foreign_keys = ON;');
            // 同時書き込み時のロック待機タイムアウト設定（5000ミリ秒 = 5秒間エラーにせず待つ）
            self::$pdo->exec('PRAGMA busy_timeout = 5000;');
            // 同期設定をNORMALに（WALモード時のI/Oパフォーマンスを最大化）
            self::$pdo->exec('PRAGMA synchronous = NORMAL;');

        } catch (PDOException $e) {
            error_log('Raptio DB 接続エラー: ' . $e->getMessage());
            self::$pdo = null;
        }
    }

    /**
     * 他のプラグインから共通SQLite接続（PDOインスタンス）を取得するための静的メソッド
     *
     * @return PDO|null 接続成功時はPDOインスタンス、失敗時はnull
     */
    public static function get_db(): ?PDO {
        if (self::$pdo === null) {
            self::init_database();
        }
        return self::$pdo;
    }

    /**
     * データベースファイルが正常に作成され、利用可能状態にあるかチェック
     *
     * @return bool
     */
    public static function is_ready(): bool {
        return self::get_db() !== null;
    }

    /**
     * データベースファイルのステータス情報を取得（管理画面での確認用など）
     *
     * @return array{path: string, size: int, mode: string}
     */
    public static function get_status(): array {
        $status = [
            'path' => self::$db_path,
            'size' => 0,
            'mode' => 'UNKNOWN'
        ];

        if (file_exists(self::$db_path)) {
            $status['size'] = filesize(self::$db_path);
        }

        $db = self::get_db();
        if ($db) {
            try {
                $stmt = $db->query('PRAGMA journal_mode;');
                $res = $stmt->fetch();
                if ($res && isset($res['journal_mode'])) {
                    $status['mode'] = strtoupper($res['journal_mode']);
                }
            } catch (PDOException $e) {
                // 計測エラー時は初期値のまま
            }
        }

        return $status;
    }

    /**
     * データベース内に存在するカスタムテーブルの一覧を取得（システムテーブルは除外）
     *
     * @return string[]
     */
    public static function get_tables(): array {
        $db = self::get_db();
        if (!$db) {
            return [];
        }
        try {
            $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%';");
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (PDOException $e) {
            error_log('Raptio DB テーブル一覧取得失敗: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 指定されたテーブルのレコードを最大100件取得（安全対策のためホワイトリスト照合）
     *
     * @param string $table
     * @return array[]
     */
    public static function get_table_data(string $table): array {
        $tables = self::get_tables();
        // 存在するテーブル名以外は処理を拒否（SQLインジェクション対策）
        if (!in_array($table, $tables, true)) {
            return [];
        }

        $db = self::get_db();
        if (!$db) {
            return [];
        }

        try {
            // テーブル名はプレースホルダーにバインドできないため、安全を確認した変数を埋め込み
            $stmt = $db->query("SELECT * FROM `{$table}` LIMIT 100;");
            return $stmt->fetchAll() ?: [];
        } catch (PDOException $e) {
            error_log("Raptio DB データ取得失敗 ({$table}): " . $e->getMessage());
            return [];
        }
    }

    /**
     * 管理画面サイドバーにメニュー項目を追加
     */
    public function add_sidebar_menu(): void {
        $url       = 'plugin-page.php?plugin=raptio-db';
        $is_active = str_contains($_SERVER['SCRIPT_FILENAME'] ?? '', 'plugin-page.php')
                  && ($_GET['plugin'] ?? '') === 'raptio-db';
                  
        echo '<li><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"'
           . ($is_active ? ' class="active"' : '') . '>データベース</a></li>';
    }

    /**
     * 管理画面ページを描画する（raptio-db-page.php から呼ばれる）
     */
    public function render_html(): void {
        $status         = self::get_status();
        $is_ready       = self::is_ready();
        $tables         = self::get_tables();
        
        // クエリパラメータから選択中のテーブルを取得
        $selected_table = $_GET['table'] ?? '';
        if ($selected_table && !in_array($selected_table, $tables, true)) {
            $selected_table = ''; // 存在しないテーブル名が渡されたらリセット
        }

        // 選択されたテーブルのデータをロード
        $table_data = $selected_table ? self::get_table_data($selected_table) : [];
        ?>
        <div class="importer-wrap" style="max-width: 900px;">
            <div class="importer-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 6px; padding: 24px 28px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <h2 style="margin: 0 0 12px; font-size: 1.3em; color: #1d2327;">共通 SQLite データベース基盤</h2>
                <p style="margin: 0 0 18px; color: #646970; font-size: 13px; line-height: 1.6;">
                    このプラグインは、会員機能やフォーラムなどの他の拡張プラグインが共通で高速・安全に利用できるデータベース（SQLite）を提供します。
                </p>

                <table style="width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 13px;">
                    <tr style="border-bottom: 1px solid #f0f0f1;">
                        <th style="text-align: left; padding: 10px 0; color: #1d2327; width: 30%;">現在のステータス</th>
                        <td style="padding: 10px 0;">
                            <?php if ($is_ready): ?>
                                <span style="background: #e7f6ed; color: #135e2d; padding: 3px 8px; border-radius: 4px; font-weight: bold;">稼働中 (接続成功)</span>
                            <?php else: ?>
                                <span style="background: #fcf0f1; color: #b32d2e; padding: 3px 8px; border-radius: 4px; font-weight: bold;">エラー (接続失敗)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f0f0f1;">
                        <th style="text-align: left; padding: 10px 0; color: #1d2327;">ジャーナルモード</th>
                        <td style="padding: 10px 0;"><code style="background: #f6f7f7; padding: 2px 5px; border-radius: 3px; font-family: monospace;"><?php echo htmlspecialchars($status['mode'], ENT_QUOTES, 'UTF-8'); ?> (推奨: WAL)</code></td>
                    </tr>
                    <tr style="border-bottom: 1px solid #f0f0f1;">
                        <th style="text-align: left; padding: 10px 0; color: #1d2327;">データベース容量</th>
                        <td style="padding: 10px 0; color: #2c3338;"><?php echo number_format($status['size']); ?> バイト (Bytes)</td>
                    </tr>
                    <tr>
                        <th style="text-align: left; padding: 10px 0; color: #1d2327; vertical-align: top;">ファイルパス</th>
                        <td style="padding: 10px 0; color: #646970; word-break: break-all; font-family: monospace; font-size: 12px;">
                            <?php echo htmlspecialchars($status['path'], ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="importer-card" style="background: #fff; border: 1px solid #ccd0d4; border-radius: 6px; padding: 24px 28px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                <h3 style="margin: 0 0 16px; font-size: 1.15em; color: #1d2327; border-bottom: 2px solid #f0f0f1; padding-bottom: 8px;">データブラウザ</h3>
                
                <?php if (empty($tables)): ?>
                    <p style="margin: 0; color: #646970; font-style: italic; font-size: 13px;">
                        現在、データベース内にカスタムテーブルは存在しません。（他のプラグインが稼働してテーブルを作成するとここに自動的に表示されます）
                    </p>
                <?php else: ?>
                    <div style="margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 8px;">
                        <?php foreach ($tables as $t): ?>
                            <?php 
                            $tab_url = 'plugin-page.php?plugin=raptio-db&table=' . urlencode($t); 
                            $is_sel  = ($t === $selected_table);
                            ?>
                            <a href="<?php echo htmlspecialchars($tab_url, ENT_QUOTES, 'UTF-8'); ?>" 
                               style="text-decoration: none; padding: 6px 12px; border-radius: 4px; font-size: 13px; font-weight: bold; border: 1px solid #ccd0d4; transition: all 0.15s ease-in-out; <?php echo $is_sel ? 'background: #2271b1; color: #fff; border-color: #2271b1;' : 'background: #f6f7f7; color: #2271b1;'; ?>">
                                📊 <?php echo htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>

                    <?php if (empty($selected_table)): ?>
                        <p style="margin: 0; color: #646970; font-size: 13px;">上記のテーブル名をクリックすると、格納されているレコードデータを表示します。</p>
                    <?php else: ?>
                        <h4 style="margin: 0 0 10px; font-size: 13px; color: #1d2327;">
                            テーブル: <code style="background:#f0f0f1; padding:2px 6px; border-radius:3px; font-family:monospace;"><?php echo htmlspecialchars($selected_table, ENT_QUOTES, 'UTF-8'); ?></code> のデータ（最新最大100件）
                        </h4>
                        
                        <?php if (empty($table_data)): ?>
                            <div style="background: #f6f7f7; border-left: 4px solid #72aee6; padding: 12px; font-size: 13px; color: #50575e;">
                                テーブルは存在しますが、まだレコード（データ）が1件も登録されていません。
                            </div>
                        <?php else: ?>
                            <div style="width: 100%; overflow-x: auto; border: 1px solid #dcdcde; border-radius: 4px; margin-top: 10px;">
                                <table style="width: 100%; border-collapse: collapse; font-size: 12px; text-align: left; background: #fff;">
                                    <thead>
                                        <tr style="background: #f6f7f7; border-bottom: 1px solid #dcdcde;">
                                            <?php 
                                            // 最初のレコードのキーをカラム名としてヘッダーを生成
                                            $columns = array_keys($table_data[0]);
                                            foreach ($columns as $col): 
                                            ?>
                                                <th style="padding: 10px 12px; font-weight: 600; color: #1d2327; border-right: 1px solid #dcdcde; white-space: nowrap;">
                                                    <?php echo htmlspecialchars($col, ENT_QUOTES, 'UTF-8'); ?>
                                                </th>
                                            <?php endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($table_data as $row): ?>
                                            <tr style="border-bottom: 1px solid #f0f0f1; transition: background 0.1s;" onmouseover="this.style.background='#f6f7f7'" onmouseout="this.style.background='transparent'">
                                                <?php foreach ($columns as $col): ?>
                                                    <td style="padding: 10px 12px; color: #2c3338; border-right: 1px solid #f0f0f1; max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars((string)$row[$col], ENT_QUOTES, 'UTF-8'); ?>">
                                                        <?php 
                                                        if ($row[$col] === null) {
                                                            echo '<span style="color:#a7aaad; font-style:italic;">NULL</span>';
                                                        } else {
                                                            echo htmlspecialchars((string)$row[$col], ENT_QUOTES, 'UTF-8');
                                                        }
                                                        ?>
                                                    </td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

// グローバル変数にインスタンスを保持し、プラグインを起動
global $raptio_db_instance;
$raptio_db_instance = new RaptioDB();