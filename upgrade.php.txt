<?php
/**
 * Test moduli uchun jadvallarni yaratadi.
 * Brauzerda bir marta oching, keyin faylni o'chiring.
 */
require_once __DIR__ . '/config.php';

header('Content-Type: text/html; charset=utf-8');
echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
echo '<body style="font-family:system-ui,sans-serif;padding:20px;line-height:1.7">';
echo '<h2>Test moduli — o\'rnatish</h2>';

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                   DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS tests (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        title      VARCHAR(160) NOT NULL,
        active     TINYINT(1) DEFAULT 1,
        created_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS items (
        test_id    INT NOT NULL,
        num        INT NOT NULL,
        answer_key VARCHAR(100) NOT NULL,
        PRIMARY KEY (test_id, num)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS responses (
        test_id    INT NOT NULL,
        user_id    BIGINT NOT NULL,
        num        INT NOT NULL,
        answer     VARCHAR(100) NULL,
        correct    TINYINT(1) DEFAULT 0,
        created_at DATETIME NULL,
        PRIMARY KEY (test_id, user_id, num),
        INDEX (test_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    echo '<p>✅ Jadvallar yaratildi: tests, items, responses</p>';
    echo '<hr><p><b>Keyingi qadam:</b> Telegramda <code>/admin</code> → 🧪 Testlar → ➕ Yangi test</p>';
    echo '<p style="color:#c00"><b>Bu faylni (upgrade.php) o\'chirib tashlang!</b></p>';
} catch (Throwable $e) {
    echo '<p>❌ Xato: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
echo '</body>';
