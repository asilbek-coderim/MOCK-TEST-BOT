<?php
/**
 * O'RNATUVCHI — brauzerda oching, formani to'ldiring, tamom.
 * Ishlagach BU FAYLNI O'CHIRIB TASHLANG.
 */

date_default_timezone_set('Asia/Tashkent');
$done = false;
$errors = [];
$info = [];

// Formadan kelgan ma'lumotlar
$f = [
    'token'   => trim($_POST['token']   ?? ''),
    'admin'   => trim($_POST['admin']   ?? ''),
    'db_host' => trim($_POST['db_host'] ?? 'localhost'),
    'db_name' => trim($_POST['db_name'] ?? ''),
    'db_user' => trim($_POST['db_user'] ?? ''),
    'db_pass' => (string) ($_POST['db_pass'] ?? ''),
];

function api(string $token, string $method, array $params = []) {
    $ch = curl_init("https://api.telegram.org/bot$token/$method");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_TIMEOUT => 20,
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return json_decode($r, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1) Tekshiruvlar
    if (!function_exists('curl_init')) $errors[] = 'Hostingda PHP cURL yoqilmagan. Supportga yozing.';
    if ($f['token'] === '')  $errors[] = 'Tokenni kiriting.';
    if (!ctype_digit($f['admin'])) $errors[] = 'Admin ID faqat raqamlardan iborat bo\'lishi kerak.';
    if ($f['db_name'] === '' || $f['db_user'] === '') $errors[] = 'Baza nomi va foydalanuvchini kiriting.';

    // 2) Bazaga ulanish
    $pdo = null;
    if (!$errors) {
        try {
            $pdo = new PDO(
                "mysql:host={$f['db_host']};dbname={$f['db_name']};charset=utf8mb4",
                $f['db_user'], $f['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $info[] = 'Bazaga ulanish muvaffaqiyatli';
        } catch (Throwable $e) {
            $errors[] = 'Bazaga ulanib bo\'lmadi: ' . $e->getMessage();
        }
    }

    // 3) Token tekshiruvi
    $username = '';
    if (!$errors) {
        $me = api($f['token'], 'getMe');
        if (empty($me['ok'])) {
            $errors[] = 'Token noto\'g\'ri. @BotFather dan qayta nusxalang.';
        } else {
            $username = $me['result']['username'];
            $info[] = 'Bot topildi: @' . $username;
        }
    }

    // 4) Jadvallar
    if (!$errors) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS users (
                user_id   BIGINT PRIMARY KEY,
                username  VARCHAR(64) NULL,
                full_name VARCHAR(255) NULL,
                referrer  BIGINT NULL,
                day       DATE NOT NULL,
                joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (referrer), INDEX (day)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS given (
                user_id BIGINT NOT NULL,
                day     DATE NOT NULL,
                code    VARCHAR(10) NOT NULL,
                PRIMARY KEY (user_id, day)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS channels (
                username VARCHAR(64) PRIMARY KEY,
                title    VARCHAR(255) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
                k VARCHAR(64) PRIMARY KEY,
                v TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("INSERT IGNORE INTO settings (k,v) VALUES ('code','00000')");
            $info[] = 'Baza jadvallari yaratildi';
        } catch (Throwable $e) {
            $errors[] = 'Jadval yaratishda xato: ' . $e->getMessage();
        }
    }

    // 5) config.php yozish
    if (!$errors) {
        $cfg = "<?php\n"
             . "const BOT_TOKEN = " . var_export($f['token'], true) . ";\n"
             . "const ADMINS    = [" . (int) $f['admin'] . "];\n"
             . "const DB_HOST   = " . var_export($f['db_host'], true) . ";\n"
             . "const DB_NAME   = " . var_export($f['db_name'], true) . ";\n"
             . "const DB_USER   = " . var_export($f['db_user'], true) . ";\n"
             . "const DB_PASS   = " . var_export($f['db_pass'], true) . ";\n";
        if (@file_put_contents(__DIR__ . '/config.php', $cfg) === false) {
            $errors[] = 'config.php yozib bo\'lmadi. Papkaga yozish huquqi yo\'q.';
        } else {
            @chmod(__DIR__ . '/config.php', 0600);
            $info[] = 'config.php yaratildi';
        }
    }

    // 6) Webhook
    if (!$errors) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $url = $scheme . '://' . $_SERVER['HTTP_HOST'] . $dir . '/bot.php';

        if ($scheme !== 'https') {
            $errors[] = 'Saytingiz HTTPS emas. Panelda SSL (Let\'s Encrypt) yoqing va qayta urinib ko\'ring.';
        } else {
            $r = api($f['token'], 'setWebhook', ['url' => $url, 'drop_pending_updates' => 'true']);
            if (!empty($r['ok'])) {
                $info[] = 'Webhook o\'rnatildi: ' . $url;
                $done = true;
            } else {
                $errors[] = 'Webhook xatosi: ' . ($r['description'] ?? 'noma\'lum');
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="uz">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Referal bot — o'rnatish</title>
<style>
  body{font-family:system-ui,sans-serif;margin:0;padding:18px;background:#f4f6f8;color:#111}
  .card{background:#fff;border-radius:12px;padding:18px;max-width:520px;margin:0 auto;
        box-shadow:0 2px 10px rgba(0,0,0,.08)}
  h2{margin:0 0 4px}
  p.sub{margin:0 0 18px;color:#666;font-size:14px}
  label{display:block;margin:14px 0 4px;font-weight:600;font-size:14px}
  input{width:100%;box-sizing:border-box;padding:12px;border:1px solid #ccd;border-radius:8px;
        font-size:16px}
  small{color:#777;font-size:12px;display:block;margin-top:3px}
  button{width:100%;margin-top:22px;padding:14px;border:0;border-radius:8px;background:#2f6fed;
         color:#fff;font-size:17px;font-weight:600}
  .ok{background:#e8f7ec;border-left:4px solid #2e9e4f;padding:10px;border-radius:6px;margin:8px 0}
  .err{background:#fdecec;border-left:4px solid #d63b3b;padding:10px;border-radius:6px;margin:8px 0}
  .final{background:#eef4ff;padding:14px;border-radius:8px;margin-top:16px;line-height:1.6}
</style>
</head>
<body>
<div class="card">
<h2>Referal bot — o'rnatish</h2>
<p class="sub">Katakchalarni to'ldiring, qolganini o'zi qiladi.</p>

<?php foreach ($info as $i): ?><div class="ok">✅ <?= htmlspecialchars($i) ?></div><?php endforeach; ?>
<?php foreach ($errors as $e): ?><div class="err">❌ <?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<?php if ($done): ?>
  <div class="final">
    <b>🎉 Tayyor!</b><br>
    Telegramda botingizga <code>/start</code>, keyin <code>/admin</code> yozing.<br><br>
    <b style="color:#c00">Endi bu faylni (install.php) o'chirib tashlang!</b>
  </div>
<?php else: ?>
<form method="post">
  <label>Bot tokeni</label>
  <input name="token" value="<?= htmlspecialchars($f['token']) ?>" placeholder="123456:AA...">
  <small>@BotFather bergan token</small>

  <label>Admin Telegram ID</label>
  <input name="admin" value="<?= htmlspecialchars($f['admin']) ?>" placeholder="123456789" inputmode="numeric">
  <small>@userinfobot dan oling (faqat raqam)</small>

  <label>Baza serveri</label>
  <input name="db_host" value="<?= htmlspecialchars($f['db_host'] ?: 'localhost') ?>">

  <label>Baza nomi</label>
  <input name="db_name" value="<?= htmlspecialchars($f['db_name']) ?>">

  <label>Baza foydalanuvchisi</label>
  <input name="db_user" value="<?= htmlspecialchars($f['db_user']) ?>">

  <label>Baza paroli</label>
  <input name="db_pass" type="password" value="">

  <button type="submit">O'rnatish</button>
</form>
<?php endif; ?>
</div>
</body>
</html>
