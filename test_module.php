<?php
/**
 * TEST MODULI — Rasch modeli bo'yicha baholash
 * --------------------------------------------
 * bot.php ichidan chaqiriladi. Alohida ishlatilmaydi.
 *
 * Jadvallar: tests, items, responses  (upgrade.php yaratadi)
 */

// ===================== YORDAMCHI =====================

function active_test() {
    return one("SELECT * FROM tests WHERE active=1 ORDER BY id DESC LIMIT 1");
}

function test_items($test_id): array {
    return all("SELECT * FROM items WHERE test_id=? ORDER BY num", [$test_id]);
}

function has_taken($test_id, $uid): bool {
    return (bool) one("SELECT 1 FROM responses WHERE test_id=? AND user_id=? LIMIT 1",
                      [$test_id, $uid]);
}

/** Javobni solishtirish uchun normallashtirish */
function norm_answer(string $a): string {
    $a = mb_strtolower(trim($a));
    $a = str_replace([' ', '·', '×', '*'], ['', '', '', ''], $a);
    $a = str_replace(',', '.', $a);
    $a = str_replace(['−', '–'], '-', $a);
    return $a;
}

/** Ikki javob teng deb hisoblanadimi */
function same_answer(string $user, string $key): bool {
    $u = norm_answer($user);
    $k = norm_answer($key);
    if ($u === '' ) return false;
    if ($u === $k) return true;
    // sonli tenglik: 0.5 == 1/2 == .5
    $un = to_number($u);
    $kn = to_number($k);
    if ($un !== null && $kn !== null) return abs($un - $kn) < 1e-9;
    return false;
}

/** Oddiy son yoki a/b kasrni songa aylantiradi, aks holda null */
function to_number(string $s) {
    if (preg_match('~^-?\d+(\.\d+)?$~', $s)) return (float) $s;
    if (preg_match('~^(-?\d+(?:\.\d+)?)/(\d+(?:\.\d+)?)$~', $s, $m) && (float)$m[2] != 0.0) {
        return (float) $m[1] / (float) $m[2];
    }
    return null;
}

// ===================== RASCH (PROX) =====================
/**
 * PROX usuli: savollar qiyinligi va o'quvchi darajasi javoblardan hisoblanadi.
 * Kamida 5 ta ishtirokchi va 5 ta savol kerak, aks holda faqat xom ball ko'rsatiladi.
 * Natija logit birligida (-5 … +5 oralig'ida bo'ladi odatda).
 */
function rasch_calc($test_id): ?array {
    $items = test_items($test_id);
    $L = count($items);
    if ($L < 5) return null;

    $rows = all("SELECT user_id, num, correct FROM responses WHERE test_id=?", [$test_id]);
    if (!$rows) return null;

    $byUser = []; $byItem = [];
    foreach ($rows as $r) {
        $byUser[$r['user_id']][$r['num']] = (int) $r['correct'];
        $byItem[$r['num']][] = (int) $r['correct'];
    }
    $N = count($byUser);
    if ($N < 5) return null;

    // 1) Savollar qiyinligi (xom logit)
    $d = [];
    foreach ($items as $it) {
        $arr = $byItem[$it['num']] ?? [];
        $n = count($arr);
        if ($n === 0) continue;
        $s = array_sum($arr);
        $s = min(max($s, 0.3), $n - 0.3);          // chekka holatlarni yumshatish
        $d[$it['num']] = log(($n - $s) / $s);
    }
    if (count($d) < 5) return null;

    // 2) O'quvchilar darajasi (xom logit)
    $b = [];
    foreach ($byUser as $uid => $ans) {
        $l = count($ans);
        $r = array_sum($ans);
        $r = min(max($r, 0.3), $l - 0.3);
        $b[$uid] = log($r / ($l - $r));
    }

    // 3) Kengaytirish koeffitsiyentlari
    $U = variance($d);   // savollar tarqoqligi
    $V = variance($b);   // o'quvchilar tarqoqligi
    $denom = 1 - ($U * $V) / 8.35;
    if ($denom <= 0.05) $denom = 0.05;
    $X = sqrt((1 + $V / 2.89) / $denom);
    $Y = sqrt((1 + $U / 2.89) / $denom);

    $dMean = array_sum($d) / count($d);

    $diff = [];
    foreach ($d as $num => $v) $diff[$num] = $X * ($v - $dMean);

    $abil = [];
    foreach ($b as $uid => $v) $abil[$uid] = $Y * $v + $dMean - $dMean;

    return ['difficulty' => $diff, 'ability' => $abil, 'persons' => $N, 'items' => $L];
}

function variance(array $a): float {
    $n = count($a);
    if ($n < 2) return 0.0;
    $m = array_sum($a) / $n;
    $s = 0.0;
    foreach ($a as $v) $s += ($v - $m) ** 2;
    return $s / ($n - 1);
}

/** Logitni 0–100 shkalaga o'tkazish (taxminiy, ko'rsatish uchun) */
function logit_to_scale(float $logit): int {
    return (int) round(max(0, min(100, 50 + $logit * 12)));
}

function level_name(float $logit): string {
    if ($logit >= 1.5)  return 'A+ (juda yuqori)';
    if ($logit >= 0.7)  return 'A (yuqori)';
    if ($logit >= 0.0)  return 'B+ (o\'rtadan yuqori)';
    if ($logit >= -0.7) return 'B (o\'rta)';
    if ($logit >= -1.5) return 'C (o\'rtadan past)';
    return 'D (past)';
}

// ===================== NATIJA MATNI =====================

function result_text($test_id, $uid): string {
    $test = one("SELECT * FROM tests WHERE id=?", [$test_id]);
    $rows = all("SELECT num, answer, correct FROM responses WHERE test_id=? AND user_id=? ORDER BY num",
                [$test_id, $uid]);
    if (!$rows) return "Siz bu testni hali ishlamadingiz.";

    $total = count($rows);
    $right = 0;
    foreach ($rows as $r) $right += (int) $r['correct'];
    $pct = $total ? round($right / $total * 100) : 0;

    $t = "📊 <b>" . htmlspecialchars($test['title']) . "</b>\n\n";
    $t .= "✅ To'g'ri: <b>$right</b> / $total  ($pct%)\n";

    $rasch = rasch_calc($test_id);
    if ($rasch && isset($rasch['ability'][$uid])) {
        $logit = $rasch['ability'][$uid];
        $t .= "📈 Daraja: <b>" . number_format($logit, 2) . "</b> logit\n";
        $t .= "🏅 Baho: <b>" . level_name($logit) . "</b>\n";
        $t .= "📐 Shkala: <b>" . logit_to_scale($logit) . "</b> / 100\n";
        $t .= "\n<i>Hisob " . $rasch['persons'] . " ta ishtirokchi natijasi asosida "
            . "Rasch modeli (PROX) bo'yicha chiqarildi.</i>\n";
    } else {
        $t .= "\n<i>Rasch hisobi uchun kamida 5 ta ishtirokchi va 5 ta savol kerak. "
            . "Hozircha faqat xom ball ko'rsatildi.</i>\n";
    }

    // xato savollar
    $wrong = [];
    foreach ($rows as $r) if (!$r['correct']) $wrong[] = $r['num'];
    if ($wrong) {
        $t .= "\n❌ Xato savollar: " . implode(', ', $wrong);
    }
    return $t;
}

// ===================== MINI APP MA'LUMOTI =====================

function handle_webapp($msg, $uid, $chat) {
    $data = json_decode($msg['web_app_data']['data'] ?? '', true);
    if (!$data || !isset($data['test_id'], $data['answers'])) {
        send($chat, '❌ Ma\'lumot tushunarsiz. Qayta urinib ko\'ring.');
        return;
    }
    $test_id = (int) $data['test_id'];
    $test = one("SELECT * FROM tests WHERE id=? AND active=1", [$test_id]);
    if (!$test) { send($chat, '❌ Test topilmadi yoki yopilgan.'); return; }

    if (has_taken($test_id, $uid)) {
        send($chat, "⚠️ Siz bu testni allaqachon ishlagansiz.\n\n" . result_text($test_id, $uid));
        return;
    }

    $items = test_items($test_id);
    $right = 0;
    foreach ($items as $it) {
        $ans = (string) ($data['answers'][$it['num']] ?? '');
        $ok = same_answer($ans, $it['answer_key']) ? 1 : 0;
        $right += $ok;
        q("INSERT INTO responses (test_id,user_id,num,answer,correct,created_at)
           VALUES (?,?,?,?,?,NOW())
           ON DUPLICATE KEY UPDATE answer=?, correct=?",
          [$test_id, $uid, $it['num'], mb_substr($ans, 0, 100), $ok,
           mb_substr($ans, 0, 100), $ok]);
    }

    send($chat, "✅ Javoblaringiz qabul qilindi!\n\n" . result_text($test_id, $uid));

    // adminlarga xabar
    foreach (ADMINS as $a) {
        $name = htmlspecialchars($msg['from']['first_name'] ?? '');
        @send($a, "🧪 <b>$name</b> testni ishladi: <b>$right</b> / " . count($items));
    }
}

// ===================== FOYDALANUVCHI TUGMALARI =====================

function test_user_button($text, $uid, $chat): bool {
    if ($text === '🧪 Test ishlash') {
        $test = active_test();
        if (!$test) { send($chat, "Hozircha faol test yo'q."); return true; }
        if (has_taken($test['id'], $uid)) {
            send($chat, "Siz bu testni ishlagansiz.\n\n" . result_text($test['id'], $uid));
            return true;
        }
        $url = base_url() . '/test.php?id=' . $test['id'];
        send($chat, "🧪 <b>" . htmlspecialchars($test['title']) . "</b>\n"
            . "Savollar soni: <b>" . count(test_items($test['id'])) . "</b>\n\n"
            . "Boshlash uchun tugmani bosing:",
            ['inline_keyboard' => [[['text' => '📝 Testni boshlash',
                                     'web_app' => ['url' => $url]]]]]);
        return true;
    }

    if ($text === '📊 Natijam') {
        $test = active_test();
        if (!$test) { send($chat, "Faol test yo'q."); return true; }
        send($chat, result_text($test['id'], $uid));
        return true;
    }
    return false;
}

function base_url(): string {
    return 'https://' . ($_SERVER['HTTP_HOST'] ?? '');
}

// ===================== ADMIN =====================

function test_admin_kb(): array {
    $t = active_test();
    $rows = [];
    if ($t) {
        $rows[] = [['text' => '📈 Test statistikasi', 'callback_data' => 't_stat']];
        $rows[] = [['text' => '🔒 Testni yopish', 'callback_data' => 't_close']];
    }
    $rows[] = [['text' => '➕ Yangi test', 'callback_data' => 't_new']];
    $rows[] = [['text' => '📋 Testlar ro\'yxati', 'callback_data' => 't_list']];
    $rows[] = [['text' => '⬅️ Orqaga', 'callback_data' => 'a_back']];
    return ['inline_keyboard' => $rows];
}

function test_admin_text(): string {
    $t = active_test();
    if (!$t) return "🧪 <b>Testlar</b>\n\nHozircha faol test yo'q.";
    $cnt = one("SELECT COUNT(DISTINCT user_id) c FROM responses WHERE test_id=?", [$t['id']])['c'];
    return "🧪 <b>Testlar</b>\n\nFaol test: <b>" . htmlspecialchars($t['title']) . "</b>\n"
         . "Savollar: <b>" . count(test_items($t['id'])) . "</b>\n"
         . "Ishlaganlar: <b>$cnt</b> ta";
}

function test_stat_text(): string {
    $t = active_test();
    if (!$t) return "Faol test yo'q.";
    $rasch = rasch_calc($t['id']);
    $txt = "📈 <b>" . htmlspecialchars($t['title']) . "</b>\n\n";

    $top = all("SELECT r.user_id, u.full_name, SUM(r.correct) s, COUNT(*) c
                FROM responses r LEFT JOIN users u ON u.user_id=r.user_id
                WHERE r.test_id=? GROUP BY r.user_id ORDER BY s DESC LIMIT 10", [$t['id']]);
    if (!$top) return $txt . "Hali hech kim ishlamadi.";

    $txt .= "<b>TOP 10</b>\n";
    $i = 1;
    foreach ($top as $r) {
        $name = htmlspecialchars($r['full_name'] ?: $r['user_id']);
        $extra = '';
        if ($rasch && isset($rasch['ability'][$r['user_id']])) {
            $extra = ' · ' . number_format($rasch['ability'][$r['user_id']], 2) . ' logit';
        }
        $txt .= "$i. $name — {$r['s']}/{$r['c']}$extra\n";
        $i++;
    }

    if ($rasch) {
        arsort($rasch['difficulty']);
        $hard = array_slice($rasch['difficulty'], 0, 5, true);
        $txt .= "\n<b>Eng qiyin savollar</b>\n";
        foreach ($hard as $num => $v) {
            $txt .= "№$num — " . number_format($v, 2) . " logit\n";
        }
        $txt .= "\n<i>Ishtirokchilar: {$rasch['persons']} · Savollar: {$rasch['items']}</i>";
    } else {
        $txt .= "\n<i>Rasch hisobi uchun kamida 5 ta ishtirokchi va 5 ta savol kerak.</i>";
    }
    return $txt;
}

/** Admin tugmalari. true qaytsa — hodisa shu yerda hal qilindi. */
function test_admin_cb(string $data, $uid, $chat, $mid): bool {
    switch (true) {
        case $data === 'a_tests':
            clear_state($uid);
            edit($chat, $mid, test_admin_text(), test_admin_kb());
            return true;

        case $data === 't_stat':
            edit($chat, $mid, test_stat_text(), back_kb());
            return true;

        case $data === 't_new':
            set_state($uid, 'test_title');
            edit($chat, $mid, "➕ <b>Yangi test</b>\n\nTest nomini yuboring:", back_kb());
            return true;

        case $data === 't_close':
            q("UPDATE tests SET active=0 WHERE active=1");
            edit($chat, $mid, "🔒 Test yopildi. Endi hech kim ishlay olmaydi.", back_kb());
            return true;

        case $data === 't_list':
            $rows = all("SELECT t.id,t.title,t.active,
                         (SELECT COUNT(DISTINCT user_id) FROM responses WHERE test_id=t.id) c
                         FROM tests t ORDER BY t.id DESC LIMIT 15");
            $txt = "📋 <b>Testlar</b>\n\n";
            if (!$rows) $txt .= "Bo'sh.";
            foreach ($rows as $r) {
                $txt .= ($r['active'] ? '🟢' : '⚪️') . " #{$r['id']} "
                      . htmlspecialchars($r['title']) . " — {$r['c']} ta ishtirokchi\n";
            }
            edit($chat, $mid, $txt, back_kb());
            return true;
    }
    return false;
}

/** Admin holatlari (matn kutilayotgan paytlar). true qaytsa — hal qilindi. */
function test_admin_state(string $state, string $text, $uid, $chat): bool {
    if ($state === 'test_title') {
        $title = mb_substr(trim($text), 0, 120);
        if ($title === '') { send($chat, 'Nom bo\'sh bo\'lmasin:'); return true; }
        set_setting('new_test_title', $title);
        set_state($uid, 'test_key');
        send($chat, "📝 Endi <b>javoblar kalitini</b> yuboring.\n\n"
            . "Har bir javob yangi qatordan yoki probel bilan:\n\n"
            . "<code>A\nB\nC\n1/2\nsqrt(2)\nx^2</code>\n\n"
            . "Yoki bir qatorda: <code>A B C 1/2 sqrt(2)</code>\n\n"
            . "Raqamlash avtomatik: 1, 2, 3 ...");
        return true;
    }

    if ($state === 'test_key') {
        $parts = preg_split('~[\s,;]+~u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) < 1) { send($chat, 'Kalit bo\'sh. Qayta yuboring:'); return true; }
        if (count($parts) > 200) { send($chat, 'Juda ko\'p (200 tadan oshmasin):'); return true; }

        q("UPDATE tests SET active=0 WHERE active=1");
        q("INSERT INTO tests (title, active, created_at) VALUES (?,1,NOW())",
          [setting('new_test_title', 'Test')]);
        $test_id = (int) pdo()->lastInsertId();

        $num = 1;
        foreach ($parts as $k) {
            q("INSERT INTO items (test_id,num,answer_key) VALUES (?,?,?)",
              [$test_id, $num, mb_substr($k, 0, 100)]);
            $num++;
        }
        clear_state($uid);
        q("DELETE FROM settings WHERE k='new_test_title'");
        send($chat, "✅ Test yaratildi!\n\nSavollar soni: <b>" . count($parts) . "</b>\n"
            . "Test faol holatda — foydalanuvchilar «🧪 Test ishlash» tugmasidan kirishadi.",
            test_admin_kb());
        return true;
    }
    return false;
}
