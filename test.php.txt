<?php
/**
 * MINI APP — test ishlash sahifasi
 * Telegram botdagi «📝 Testni boshlash» tugmasi shu sahifani ochadi.
 */
require_once __DIR__ . '/config.php';

$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
               DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$id = (int) ($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT * FROM tests WHERE id=? AND active=1");
$st->execute([$id]);
$test = $st->fetch();

if (!$test) {
    exit('<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<p style="font-family:sans-serif;padding:24px">Test topilmadi yoki yopilgan.</p>');
}

$st = $pdo->prepare("SELECT num FROM items WHERE test_id=? ORDER BY num");
$st->execute([$id]);
$nums = array_column($st->fetchAll(), 'num');
$count = count($nums);
?><!DOCTYPE html>
<html lang="uz">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title><?= htmlspecialchars($test['title']) ?></title>
<script src="https://telegram.org/js/telegram-web-app.js"></script>
<style>
:root{
  --bg: var(--tg-theme-bg-color, #fff);
  --fg: var(--tg-theme-text-color, #111);
  --hint: var(--tg-theme-hint-color, #888);
  --card: var(--tg-theme-secondary-bg-color, #f2f3f5);
  --accent: var(--tg-theme-button-color, #2f6fed);
  --accent-fg: var(--tg-theme-button-text-color, #fff);
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
body{margin:0;padding:0 0 180px;background:var(--bg);color:var(--fg);
     font-family:system-ui,-apple-system,sans-serif}
header{position:sticky;top:0;background:var(--bg);padding:12px 16px 8px;
       border-bottom:1px solid rgba(128,128,128,.2);z-index:5}
h1{font-size:17px;margin:0 0 6px}
.progress{height:4px;background:var(--card);border-radius:2px;overflow:hidden}
.bar{height:100%;background:var(--accent);width:0;transition:width .2s}
.sub{font-size:13px;color:var(--hint);margin-top:6px}

.list{padding:8px 12px}
.row{display:flex;align-items:center;gap:10px;padding:8px 10px;margin:6px 0;
     background:var(--card);border-radius:12px}
.row.filled{outline:2px solid var(--accent)}
.n{min-width:30px;font-weight:700;color:var(--hint);font-size:15px}
.val{flex:1;font-size:17px;min-height:24px;word-break:break-all}
.val:empty::before{content:'—';color:var(--hint)}
.clr{border:0;background:transparent;color:var(--hint);font-size:20px;padding:4px 8px}

.pad{position:fixed;left:0;right:0;bottom:0;background:var(--bg);
     border-top:1px solid rgba(128,128,128,.2);padding:8px;z-index:10}
.tabs{display:flex;gap:6px;margin-bottom:6px}
.tab{flex:1;padding:8px;border:0;border-radius:8px;background:var(--card);
     color:var(--fg);font-size:14px;font-weight:600}
.tab.on{background:var(--accent);color:var(--accent-fg)}
.keys{display:grid;grid-template-columns:repeat(5,1fr);gap:6px}
.keys.abcd{grid-template-columns:repeat(4,1fr)}
.k{padding:14px 4px;border:0;border-radius:10px;background:var(--card);
   color:var(--fg);font-size:18px;font-weight:600}
.k:active{background:var(--accent);color:var(--accent-fg)}
.k.wide{grid-column:span 2}
.send{width:100%;margin-top:8px;padding:14px;border:0;border-radius:12px;
      background:var(--accent);color:var(--accent-fg);font-size:16px;font-weight:700}
.send:disabled{opacity:.45}
.hidden{display:none}
</style>
</head>
<body>

<header>
  <h1><?= htmlspecialchars($test['title']) ?></h1>
  <div class="progress"><div class="bar" id="bar"></div></div>
  <div class="sub" id="sub">0 / <?= $count ?> javob berildi</div>
</header>

<div class="list" id="list"></div>

<div class="pad">
  <div class="tabs">
    <button class="tab on" data-pad="abcd">ABCD</button>
    <button class="tab" data-pad="num">123</button>
    <button class="tab" data-pad="math">√ x²</button>
  </div>

  <div class="keys abcd" id="pad-abcd">
    <button class="k" data-ins="A">A</button>
    <button class="k" data-ins="B">B</button>
    <button class="k" data-ins="C">C</button>
    <button class="k" data-ins="D">D</button>
  </div>

  <div class="keys hidden" id="pad-num">
    <button class="k" data-ins="7">7</button>
    <button class="k" data-ins="8">8</button>
    <button class="k" data-ins="9">9</button>
    <button class="k" data-ins="/">/</button>
    <button class="k" data-act="back">⌫</button>
    <button class="k" data-ins="4">4</button>
    <button class="k" data-ins="5">5</button>
    <button class="k" data-ins="6">6</button>
    <button class="k" data-ins="-">−</button>
    <button class="k" data-act="clear">C</button>
    <button class="k" data-ins="1">1</button>
    <button class="k" data-ins="2">2</button>
    <button class="k" data-ins="3">3</button>
    <button class="k" data-ins=".">.</button>
    <button class="k" data-act="next">▼</button>
    <button class="k wide" data-ins="0">0</button>
    <button class="k" data-ins="(">(</button>
    <button class="k" data-ins=")">)</button>
    <button class="k" data-ins="=">=</button>
  </div>

  <div class="keys hidden" id="pad-math">
    <button class="k" data-ins="sqrt(">√(</button>
    <button class="k" data-ins="^2">x²</button>
    <button class="k" data-ins="^3">x³</button>
    <button class="k" data-ins="^">x^</button>
    <button class="k" data-act="back">⌫</button>
    <button class="k" data-ins="/">a/b</button>
    <button class="k" data-ins="pi">π</button>
    <button class="k" data-ins="x">x</button>
    <button class="k" data-ins="y">y</button>
    <button class="k" data-act="clear">C</button>
    <button class="k" data-ins="+">+</button>
    <button class="k" data-ins="-">−</button>
    <button class="k" data-ins="*">×</button>
    <button class="k" data-ins="%">%</button>
    <button class="k" data-act="next">▼</button>
  </div>

  <button class="send" id="send" disabled>Javoblarni yuborish</button>
</div>

<script>
const tg = window.Telegram?.WebApp;
tg?.ready(); tg?.expand();

const NUMS = <?= json_encode($nums) ?>;
const TEST_ID = <?= (int) $id ?>;
const answers = {};
let active = NUMS[0];

const list = document.getElementById('list');
NUMS.forEach(n => {
  const row = document.createElement('div');
  row.className = 'row';
  row.id = 'row' + n;
  row.innerHTML = `<div class="n">${n}.</div><div class="val" id="val${n}"></div>
                   <button class="clr" data-clear="${n}">×</button>`;
  row.addEventListener('click', e => {
    if (e.target.dataset.clear) { delete answers[n]; render(); return; }
    active = n; render();
  });
  list.appendChild(row);
});

function render() {
  NUMS.forEach(n => {
    document.getElementById('val' + n).textContent = answers[n] || '';
    const row = document.getElementById('row' + n);
    row.classList.toggle('filled', n === active);
  });
  const done = Object.keys(answers).filter(k => answers[k]).length;
  document.getElementById('bar').style.width = (done / NUMS.length * 100) + '%';
  document.getElementById('sub').textContent = done + ' / ' + NUMS.length + ' javob berildi';
  document.getElementById('send').disabled = done === 0;
}

function insert(txt) {
  const isLetter = /^[A-D]$/.test(txt);
  answers[active] = isLetter ? txt : (answers[active] || '') + txt;
  if (isLetter) next();
  render();
}

function next() {
  const i = NUMS.indexOf(active);
  if (i < NUMS.length - 1) {
    active = NUMS[i + 1];
    document.getElementById('row' + active).scrollIntoView({block:'center', behavior:'smooth'});
  }
}

document.querySelectorAll('.k').forEach(b => b.addEventListener('click', () => {
  tg?.HapticFeedback?.impactOccurred('light');
  if (b.dataset.ins) return insert(b.dataset.ins);
  const a = b.dataset.act;
  if (a === 'back')  { answers[active] = (answers[active] || '').slice(0, -1); render(); }
  if (a === 'clear') { delete answers[active]; render(); }
  if (a === 'next')  { next(); render(); }
}));

document.querySelectorAll('.tab').forEach(t => t.addEventListener('click', () => {
  document.querySelectorAll('.tab').forEach(x => x.classList.remove('on'));
  t.classList.add('on');
  ['abcd','num','math'].forEach(p =>
    document.getElementById('pad-' + p).classList.toggle('hidden', p !== t.dataset.pad));
}));

document.getElementById('send').addEventListener('click', () => {
  const done = Object.keys(answers).filter(k => answers[k]).length;
  const go = () => tg.sendData(JSON.stringify({test_id: TEST_ID, answers}));
  if (done < NUMS.length) {
    tg.showConfirm(`${NUMS.length - done} ta savol bo'sh qoldi. Baribir yuborilsinmi?`,
                   ok => { if (ok) go(); });
  } else go();
});

render();
</script>
</body>
</html>
