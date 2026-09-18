<?php
/**
 * REFERAL BOT — PHP webhook versiyasi
 * -----------------------------------
 * PHP + MySQL hostinglar uchun (MyXvest, Beget, cPanel va h.k.)
 * Har kuni 00:00 (Toshkent) da takliflar hisobi nolga tushadi.
 * Kuniga 3 ta do'st taklif qilgan foydalanuvchiga 5 xonali kod yuboriladi.
 *
 * O'RNATISH:
 *   1) bot.php va install.php fayllarini saytingizga yuklang
 *   2) Brauzerda install.php ni oching va formani to'ldiring
 *   3) install.php ni o'chirib tashlang
 */

// ================== SOZLAMALAR ==================
// Hech narsani qo'lda tahrirlash SHART EMAS.
// Barcha ma'lumotlar install.php formasi orqali config.php ga yoziladi.
if (!file_exists(__DIR__ . '/config.php')) {
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
        http_response_code(200);
        exit('Bot sozlanmagan. install.php ni oching.');
    }
} else {
    require_once __DIR__ . '/config.php';
}

const REQUIRED_REFS = 3;   // kuniga kerakli takliflar soni
// ================================================

function cfg(string $k, $default = '') {
    return defined($k) ? constant($k) : $default;
}

function is_admin($uid): bool {
    $list = defined('ADMINS') ? constant('ADMINS') : [];
    return in_array((int) $uid, array_map('intval', (array) $list), true);
}

date_default_timezone_set('Asia/Tashkent');
header('Content-Type: application/json');

// ------------------------- BAZA -------------------------
function pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . cfg('DB_HOST') . ';dbname=' . cfg('DB_NAME') . ';charset=utf8mb4',
            cfg('DB_USER'), cfg('DB_PASS'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement {
    $st = pdo()->prepare($sql);
    $st->execute($p);
    return $st;
}

function one(string $sql, array $p = []) { return q($sql, $p)->fetch(); }
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }

function today(): string { return date('Y-m-d'); }

// --------------------- TELEGRAM API ---------------------
function tg(string $method, array $params = []) {
    $url = 'https://api.telegram.org/bot' . cfg('BOT_TOKEN') . '/' . $method;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_TIMEOUT        => 20,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function send($chat, string $text, $kb = null) {
    $p = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML',
          'disable_web_page_preview' => true];
    if ($kb) $p['reply_markup'] = json_encode($kb);
    return tg('sendMessage', $p);
}

function edit($chat, $msg_id, string $text, $kb = null) {
    $p = ['chat_id' => $chat, 'message_id' => $msg_id, 'text' => $text,
          'parse_mode' => 'HTML'];
    if ($kb) $p['reply_markup'] = json_encode($kb);
    return tg('editMessageText', $p);
}

// --------------------- SOZLAMA/HOLAT ---------------------
function setting(string $k, $default = null) {
    $r = one('SELECT v FROM settings WHERE k=?', [$k]);
    return $r ? $r['v'] : $default;
}

function set_setting(string $k, string $v) {
    q('INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=?', [$k, $v, $v]);
}

function get_code(): string { return setting('code', '00000'); }

function get_state($uid) { return setting('state:' . $uid, ''); }
function set_state($uid, string $s) { set_setting('state:' . $uid, $s); }
function clear_state($uid) { q('DELETE FROM settings WHERE k=?', ['state:' . $uid]); }

// ------------------------ HISOB ------------------------
function user_exists($uid): bool {
    return (bool) one('SELECT 1 FROM users WHERE user_id=?', [$uid]);
}

function refs_today($uid): int {
    $r = one('SELECT COUNT(*) c FROM users WHERE referrer=? AND day=?', [$uid, today()]);
    return (int) $r['c'];
}

function refs_total($uid): int {
    $r = one('SELECT COUNT(*) c FROM users WHERE referrer=?', [$uid]);
    return (int) $r['c'];
}

function channels(): array { return all('SELECT username,title FROM channels'); }

function top_day(int $lim = 10): array {
    return all(
        'SELECT u.user_id,u.full_name,u.username,COUNT(*) c
         FROM users r JOIN users u ON u.user_id=r.referrer
         WHERE r.day=? GROUP BY u.user_id ORDER BY c DESC LIMIT ' . (int)$lim, [today()]);
}

function top_all(int $lim = 10): array {
    return all(
        'SELECT u.user_id,u.full_name,u.username,COUNT(*) c
         FROM users r JOIN users u ON u.user_id=r.referrer
         GROUP BY u.user_id ORDER BY c DESC LIMIT ' . (int)$lim);
}

function eligible_today(): array {
    return all('SELECT referrer uid FROM users WHERE day=? AND referrer IS NOT NULL
                GROUP BY referrer HAVING COUNT(*)>=?', [today(), REQUIRED_REFS]);
}

// --------------------- OBUNA TEKSHIRUV ---------------------
function not_subscribed($uid): array {
    $missing = [];
    foreach (channels() as $ch) {
        $r = tg('getChatMember', ['chat_id' => $ch['username'], 'user_id' => $uid]);
        $status = $r['result']['status'] ?? 'left';
        if (!$r['ok'] || in_array($status, ['left', 'kicked'])) $missing[] = $ch;
    }
    return $missing;
}

function sub_kb(array $missing, string $payload = ''): array {
    $rows = [];
    foreach ($missing as $ch) {
        $rows[] = [['text' => '📢 ' . $ch['title'],
                    'url'  => 'https://t.me/' . ltrim($ch['username'], '@')]];
    }
    $rows[] = [['text' => '✅ Tekshirish', 'callback_data' => 'chk:' . $payload]];
    return ['inline_keyboard' => $rows];
}

function menu_kb(): array {
    return ['keyboard' => [
        [['text' => '🔗 Havolam'], ['text' => '📊 Hisobim']],
        [['text' => '🎁 Kodni olish'], ['text' => '🏆 Reyting']],
    ], 'resize_keyboard' => true];
}

// ------------------------ KOD ------------------------
function give_code($uid, bool $new_day = true) {
    $code = get_code();
    q('INSERT INTO given (user_id,day,code) VALUES (?,?,?) ON DUPLICATE KEY UPDATE code=?',
      [$uid, today(), $code, $code]);
    $text = $new_day
        ? "🎉 Bugun " . REQUIRED_REFS . " ta do'stingizni taklif qildingiz!\n\n"
          . "🎁 Bugungi kod:\n\n<code>$code</code>\n\n⚠️ Kod faqat bugun amal qiladi."
        : "🔄 Kod yangilandi:\n\n<code>$code</code>";
    send($uid, $text);
}

// --------------------- RO'YXATDAN O'TISH ---------------------
function register(array $u, string $payload) {
    if (user_exists($u['id'])) return;
    $ref = null;
    if (ctype_digit($payload) && (int)$payload !== (int)$u['id'] && user_exists((int)$payload)) {
        $ref = (int) $payload;
    }
    $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
    q('INSERT IGNORE INTO users (user_id,username,full_name,referrer,day) VALUES (?,?,?,?,?)',
      [$u['id'], $u['username'] ?? null, $name, $ref, today()]);

    if ($ref) {
        $c = refs_today($ref);
        send($ref, "➕ Yangi a'zo qo'shildi!\nBugun: <b>$c</b> / " . REQUIRED_REFS);
        if ($c == REQUIRED_REFS) give_code($ref);
    }
}

// --------------------- ADMIN KLAVIATURA ---------------------
function admin_kb(): array {
    return ['inline_keyboard' => [
        [['text' => '📊 Statistika', 'callback_data' => 'a_stat']],
        [['text' => '🏆 Bugungi top', 'callback_data' => 'a_topd'],
         ['text' => '👑 Umumiy top', 'callback_data' => 'a_topa']],
        [['text' => "🔑 Kodni o'zgartirish", 'callback_data' => 'a_code']],
        [['text' => '📢 Kanallar', 'callback_data' => 'a_ch']],
        [['text' => '📣 Reklama yuborish', 'callback_data' => 'a_ad']],
    ]];
}

function back_kb(): array {
    return ['inline_keyboard' => [[['text' => '⬅️ Orqaga', 'callback_data' => 'a_back']]]];
}

function channels_kb(): array {
    $rows = [];
    foreach (channels() as $ch) {
        $rows[] = [['text' => '🗑 ' . $ch['username'], 'callback_data' => 'a_del:' . $ch['username']]];
    }
    $rows[] = [['text' => "➕ Kanal qo'shish", 'callback_data' => 'a_add']];
    $rows[] = [['text' => '⬅️ Orqaga', 'callback_data' => 'a_back']];
    return ['inline_keyboard' => $rows];
}

function panel_text(): string {
    return "⚙️ <b>ADMIN PANEL</b>\n\n📅 Sana: " . today() . "\n🔑 Bugungi kod: <code>" . get_code() . "</code>";
}

function top_text(array $rows, string $title): string {
    if (!$rows) return "$title\n\nBo'sh.";
    $t = "$title\n\n"; $i = 1;
    foreach ($rows as $r) {
        $tag = $r['username'] ? '@' . $r['username'] : $r['user_id'];
        $t .= "$i. " . htmlspecialchars($r['full_name']) . " ($tag) — <b>{$r['c']}</b> ta\n";
        $i++;
    }
    return $t;
}

// ======================= ASOSIY QISM =======================
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) { echo '{}'; exit; }

// ---------- MATNLI XABAR ----------
if (isset($update['message'])) {
    $msg  = $update['message'];
    $chat = $msg['chat']['id'];
    $from = $msg['from'];
    $uid  = $from['id'];
    $text = trim($msg['text'] ?? '');
    $is_admin = is_admin($uid);
    $state = get_state($uid);

    // --- admin holatlari ---
    if ($is_admin && $state === 'code') {
        if (preg_match('/^\d{5}$/', $text)) {
            set_setting('code', $text);
            clear_state($uid);
            send($chat, "✅ Yangi kod saqlandi: <code>$text</code>\n\nBugun huquq qozonganlarga yuborilsinmi?",
                ['inline_keyboard' => [[
                    ['text' => '✅ Ha', 'callback_data' => 'a_send'],
                    ['text' => "❌ Yo'q", 'callback_data' => 'a_back']]]]);
        } else {
            send($chat, '❌ Aynan 5 ta raqam bo\'lishi kerak. Qayta yuboring:');
        }
        exit;
    }

    if ($is_admin && $state === 'channel') {
        if ($text[0] !== '@') { send($chat, '❌ @ bilan boshlanishi kerak:'); exit; }
        $info = tg('getChat', ['chat_id' => $text]);
        $me   = tg('getChatMember', ['chat_id' => $text, 'user_id' => (tg('getMe')['result']['id'])]);
        $st   = $me['result']['status'] ?? '';
        if (!$info['ok'] || !in_array($st, ['administrator', 'creator'])) {
            send($chat, '❌ Kanal topilmadi yoki bot u yerda admin emas. Qayta yuboring:');
            exit;
        }
        $title = $info['result']['title'];
        q('INSERT INTO channels (username,title) VALUES (?,?) ON DUPLICATE KEY UPDATE title=?',
          [$text, $title, $title]);
        clear_state($uid);
        send($chat, "✅ «$title» qo'shildi.", channels_kb());
        exit;
    }

    if ($is_admin && $state === 'ad') {
        clear_state($uid);
        $ok = 0; $fail = 0;
        foreach (all('SELECT user_id FROM users') as $u) {
            $r = tg('copyMessage', ['chat_id' => $u['user_id'],
                                    'from_chat_id' => $chat,
                                    'message_id' => $msg['message_id']]);
            ($r['ok'] ?? false) ? $ok++ : $fail++;
            usleep(50000);
        }
        send($chat, "✅ Yuborildi: $ok\n❌ Yuborilmadi: $fail", admin_kb());
        exit;
    }

    // --- /start ---
    if (strpos($text, '/start') === 0) {
        $parts = explode(' ', $text, 2);
        $payload = isset($parts[1]) ? trim($parts[1]) : '';
        $missing = not_subscribed($uid);
        if ($missing) {
            send($chat, "👋 Botdan foydalanish uchun kanallarga obuna bo'ling:",
                 sub_kb($missing, $payload));
            exit;
        }
        register($from, $payload);
        $name = htmlspecialchars($from['first_name'] ?? '');
        send($chat, "Assalomu alaykum, <b>$name</b>!\n\n🎯 Har kuni <b>" . REQUIRED_REFS
            . " ta</b> do'stingizni taklif qiling va o'sha kunning maxfiy kodini oling.\n\n"
            . '🕛 Hisob har kuni yarim tunda nolga tushadi.', menu_kb());
        exit;
    }

    // --- /admin ---
    if ($text === '/admin' && $is_admin) {
        clear_state($uid);
        send($chat, panel_text(), admin_kb());
        exit;
    }

    // --- tugmalar ---
    if ($text === '🔗 Havolam') {
        $me = tg('getMe')['result']['username'];
        $link = "https://t.me/$me?start=$uid";
        send($chat, "🔗 Shaxsiy havolangiz:\n\n<code>$link</code>\n\n"
            . "Do'stlaringiz aynan shu havola orqali botga kirishi kerak.",
            ['inline_keyboard' => [[['text' => '📤 Ulashish',
              'url' => 'https://t.me/share/url?url=' . urlencode($link)]]]]);
        exit;
    }

    if ($text === '📊 Hisobim') {
        $c = refs_today($uid);
        $left = max(0, REQUIRED_REFS - $c);
        $bar = str_repeat('🟩', min($c, REQUIRED_REFS)) . str_repeat('⬜️', $left);
        send($chat, "📊 <b>Bugungi hisobingiz</b>\n\n$bar\n\nBugun: <b>$c</b> / "
            . REQUIRED_REFS . "\nUmumiy: <b>" . refs_total($uid) . "</b> ta\n\n"
            . ($left === 0 ? '✅ Bugungi kodni olishingiz mumkin!' : "Yana <b>$left</b> ta kerak."));
        exit;
    }

    if ($text === '🎁 Kodni olish') {
        $c = refs_today($uid);
        if ($c >= REQUIRED_REFS) give_code($uid);
        else send($chat, '🔒 Kod yopiq.\nBugun yana <b>' . (REQUIRED_REFS - $c)
                       . "</b> ta do'stingizni taklif qiling.");
        exit;
    }

    if ($text === '🏆 Reyting') {
        $rows = top_day();
        if (!$rows) { send($chat, "Bugun hali hech kim taklif qilmadi. Birinchi bo'ling! 🚀"); exit; }
        $medals = ['🥇', '🥈', '🥉'];
        $t = "🏆 <b>BUGUNGI TOP 10</b>\n\n"; $i = 0;
        foreach ($rows as $r) {
            $p = $i < 3 ? $medals[$i] : ($i + 1) . '.';
            $t .= "$p " . htmlspecialchars($r['full_name']) . " — <b>{$r['c']}</b> ta\n";
            $i++;
        }
        send($chat, $t);
        exit;
    }
    exit;
}

// ---------- TUGMA BOSILISHI ----------
if (isset($update['callback_query'])) {
    $cb   = $update['callback_query'];
    $uid  = $cb['from']['id'];
    $chat = $cb['message']['chat']['id'];
    $mid  = $cb['message']['message_id'];
    $data = $cb['data'];
    $is_admin = is_admin($uid);

    // obuna tekshiruvi
    if (strpos($data, 'chk:') === 0) {
        $payload = substr($data, 4);
        if (not_subscribed($uid)) {
            tg('answerCallbackQuery', ['callback_query_id' => $cb['id'],
                'text' => '❌ Hali obuna bo\'lmadingiz!', 'show_alert' => true]);
            exit;
        }
        tg('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
        tg('deleteMessage', ['chat_id' => $chat, 'message_id' => $mid]);
        register($cb['from'], $payload);
        send($chat, '✅ Obuna tasdiqlandi!', menu_kb());
        exit;
    }

    if (!$is_admin) exit;
    tg('answerCallbackQuery', ['callback_query_id' => $cb['id']]);

    switch (true) {
        case $data === 'a_back':
            clear_state($uid);
            edit($chat, $mid, panel_text(), admin_kb());
            break;

        case $data === 'a_stat':
            $total = one('SELECT COUNT(*) c FROM users')['c'];
            $new   = one('SELECT COUNT(*) c FROM users WHERE day=?', [today()])['c'];
            $codes = one('SELECT COUNT(*) c FROM given WHERE day=?', [today()])['c'];
            edit($chat, $mid, "📊 <b>Statistika</b>\n\n👥 Jami foydalanuvchilar: <b>$total</b>\n"
                . "🆕 Bugun qo'shilganlar: <b>$new</b>\n🎁 Bugun kod olganlar: <b>$codes</b>\n"
                . '📢 Majburiy kanallar: <b>' . count(channels()) . "</b>\n"
                . '🔑 Joriy kod: <code>' . get_code() . '</code>', back_kb());
            break;

        case $data === 'a_topd':
            edit($chat, $mid, top_text(top_day(20), '🏆 <b>BUGUNGI TOP 20</b>'), back_kb());
            break;

        case $data === 'a_topa':
            edit($chat, $mid, top_text(top_all(20), '👑 <b>UMUMIY TOP 20</b>'), back_kb());
            break;

        case $data === 'a_code':
            set_state($uid, 'code');
            edit($chat, $mid, '🔑 Joriy kod: <code>' . get_code()
                . "</code>\n\nYangi <b>5 xonali</b> kodni yuboring:", back_kb());
            break;

        case $data === 'a_send':
            $ok = 0;
            foreach (eligible_today() as $u) { give_code($u['uid'], false); $ok++; usleep(50000); }
            edit($chat, $mid, "✅ $ok ta foydalanuvchiga yuborildi.", back_kb());
            break;

        case $data === 'a_ch':
            clear_state($uid);
            $chs = channels();
            $t = "📢 <b>Majburiy kanallar</b>\n\n";
            if ($chs) foreach ($chs as $c) $t .= "• {$c['title']} ({$c['username']})\n";
            else $t .= "Hozircha kanal yo'q.";
            edit($chat, $mid, $t, channels_kb());
            break;

        case $data === 'a_add':
            set_state($uid, 'channel');
            edit($chat, $mid, "➕ Kanal usernameni yuboring, masalan: <code>@mening_kanalim</code>\n\n"
                . '⚠️ Bot o\'sha kanalda <b>admin</b> bo\'lishi shart!', back_kb());
            break;

        case strpos($data, 'a_del:') === 0:
            q('DELETE FROM channels WHERE username=?', [substr($data, 6)]);
            edit($chat, $mid, '📢 <b>Majburiy kanallar</b>', channels_kb());
            break;

        case $data === 'a_ad':
            set_state($uid, 'ad');
            edit($chat, $mid, '📣 Hammaga yubormoqchi bo\'lgan xabarni yuboring:', back_kb());
            break;
    }
    exit;
}

echo '{}';
