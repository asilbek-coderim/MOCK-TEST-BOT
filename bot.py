"""
REFERAL BOT — KUNLIK HISOB
--------------------------
Har kuni soat 00:00 (Toshkent) da takliflar hisobi nolga tushadi.
Kuniga 3 ta do'st taklif qilgan foydalanuvchiga o'sha kungi 5 xonali kod yuboriladi.
Kodni admin panel orqali har kuni o'zgartiradi.

O'RNATISH:          pip install aiogram
ISHGA TUSHIRISH:    python bot.py
"""

import asyncio
import logging
import os
import sqlite3
from contextlib import closing
from datetime import datetime, timedelta, timezone

from aiogram import Bot, Dispatcher, F
from aiogram.client.default import DefaultBotProperties
from aiogram.enums import ParseMode
from aiogram.filters import CommandStart, Command
from aiogram.fsm.context import FSMContext
from aiogram.fsm.state import State, StatesGroup
from aiogram.types import (
    Message, CallbackQuery,
    InlineKeyboardMarkup, InlineKeyboardButton,
    ReplyKeyboardMarkup, KeyboardButton,
)

# ======================================================================
#  SOZLAMALAR — FAQAT SHU 2 QATOR
# ======================================================================
BOT_TOKEN = os.getenv("BOT_TOKEN", "BU_YERGA_TOKENNI_YOZING")
ADMINS = [int(x) for x in os.getenv("ADMINS", "123456789").replace(",", " ").split()]

REQUIRED_REFS = 3                        # kuniga kerakli takliflar soni
TZ = timezone(timedelta(hours=5))        # Toshkent vaqti (UTC+5)
DB_PATH = "bot.db"
# ======================================================================

BOT_USERNAME = None
logging.basicConfig(level=logging.INFO)


def today() -> str:
    """Bugungi sana, Toshkent vaqti bo'yicha."""
    return datetime.now(TZ).strftime("%Y-%m-%d")


# ============================ BAZA ============================
def db(query, params=(), fetch=None):
    with closing(sqlite3.connect(DB_PATH)) as con:
        cur = con.execute(query, params)
        if fetch == "one":
            return cur.fetchone()
        if fetch == "all":
            return cur.fetchall()
        con.commit()


def db_init():
    db("""CREATE TABLE IF NOT EXISTS users (
            user_id   INTEGER PRIMARY KEY,
            username  TEXT,
            full_name TEXT,
            referrer  INTEGER,
            day       TEXT,
            joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)""")
    db("""CREATE TABLE IF NOT EXISTS given (
            user_id INTEGER,
            day     TEXT,
            code    TEXT,
            PRIMARY KEY (user_id, day))""")
    db("""CREATE TABLE IF NOT EXISTS channels (
            username TEXT PRIMARY KEY, title TEXT)""")
    db("""CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY, value TEXT)""")
    if not db("SELECT 1 FROM settings WHERE key='code'", (), "one"):
        db("INSERT INTO settings VALUES ('code','00000')")


def get_code():
    return db("SELECT value FROM settings WHERE key='code'", (), "one")[0]


def set_code(c):
    db("UPDATE settings SET value=? WHERE key='code'", (c,))


def get_channels():
    return db("SELECT username, title FROM channels", (), "all")


def user_exists(uid):
    return db("SELECT 1 FROM users WHERE user_id=?", (uid,), "one") is not None


def refs_today(uid) -> int:
    """Bugun shu odam taklif qilganlar soni."""
    return db("SELECT COUNT(*) FROM users WHERE referrer=? AND day=?",
              (uid, today()), "one")[0]


def refs_total(uid) -> int:
    return db("SELECT COUNT(*) FROM users WHERE referrer=?", (uid,), "one")[0]


def mark_given(uid):
    db("INSERT OR REPLACE INTO given VALUES (?,?,?)", (uid, today(), get_code()))


def eligible_today():
    """Bugun 3+ taklif qilganlar."""
    return db("""SELECT referrer FROM users
                 WHERE day=? AND referrer IS NOT NULL
                 GROUP BY referrer HAVING COUNT(*) >= ?""",
              (today(), REQUIRED_REFS), "all")


def top_day(limit=10):
    return db("""SELECT u.user_id, u.full_name, u.username, COUNT(*) c
                 FROM users r JOIN users u ON u.user_id = r.referrer
                 WHERE r.day=? GROUP BY u.user_id ORDER BY c DESC LIMIT ?""",
              (today(), limit), "all")


def top_all(limit=10):
    return db("""SELECT u.user_id, u.full_name, u.username, COUNT(*) c
                 FROM users r JOIN users u ON u.user_id = r.referrer
                 GROUP BY u.user_id ORDER BY c DESC LIMIT ?""", (limit,), "all")


def stats():
    total = db("SELECT COUNT(*) FROM users", (), "one")[0]
    new = db("SELECT COUNT(*) FROM users WHERE day=?", (today(),), "one")[0]
    codes = db("SELECT COUNT(*) FROM given WHERE day=?", (today(),), "one")[0]
    return total, new, codes


# ============================ BOT ============================
bot = Bot(BOT_TOKEN, default=DefaultBotProperties(parse_mode=ParseMode.HTML))
dp = Dispatcher()


class Admin(StatesGroup):
    code = State()
    channel = State()
    ad = State()


def menu():
    return ReplyKeyboardMarkup(keyboard=[
        [KeyboardButton(text="🔗 Havolam"), KeyboardButton(text="📊 Hisobim")],
        [KeyboardButton(text="🎁 Kodni olish"), KeyboardButton(text="🏆 Reyting")],
    ], resize_keyboard=True)


async def not_subscribed(uid):
    missing = []
    for username, title in get_channels():
        try:
            m = await bot.get_chat_member(username, uid)
            if m.status in ("left", "kicked"):
                missing.append((username, title))
        except Exception as e:
            logging.warning("Kanal xatosi %s: %s", username, e)
    return missing


def sub_kb(missing, payload=""):
    rows = [[InlineKeyboardButton(text=f"📢 {t}", url=f"https://t.me/{u.lstrip('@')}")]
            for u, t in missing]
    rows.append([InlineKeyboardButton(text="✅ Tekshirish", callback_data=f"chk:{payload}")])
    return InlineKeyboardMarkup(inline_keyboard=rows)


async def send_code(uid, new_day=True):
    code = get_code()
    mark_given(uid)
    if new_day:
        text = (f"🎉 Bugun {REQUIRED_REFS} ta do'stingizni taklif qildingiz!\n\n"
                f"🎁 Bugungi kod:\n\n<code>{code}</code>\n\n"
                f"⚠️ Kod faqat bugun amal qiladi.")
    else:
        text = f"🔄 Kod yangilandi:\n\n<code>{code}</code>"
    await bot.send_message(uid, text)


async def register(user, payload):
    if user_exists(user.id):
        return
    referrer = None
    if payload.isdigit() and int(payload) != user.id and user_exists(int(payload)):
        referrer = int(payload)
    db("INSERT OR IGNORE INTO users (user_id,username,full_name,referrer,day) VALUES (?,?,?,?,?)",
       (user.id, user.username, user.full_name, referrer, today()))

    if referrer:
        c = refs_today(referrer)
        try:
            await bot.send_message(
                referrer, f"➕ Yangi a'zo qo'shildi!\nBugun: <b>{c}</b> / {REQUIRED_REFS}")
            if c == REQUIRED_REFS:
                await send_code(referrer)
        except Exception:
            pass


@dp.message(CommandStart())
async def start(message: Message, state: FSMContext):
    await state.clear()
    parts = message.text.split(maxsplit=1)
    payload = parts[1].strip() if len(parts) > 1 else ""

    missing = await not_subscribed(message.from_user.id)
    if missing:
        await message.answer("👋 Botdan foydalanish uchun kanallarga obuna bo'ling:",
                             reply_markup=sub_kb(missing, payload))
        return

    await register(message.from_user, payload)
    await message.answer(
        f"Assalomu alaykum, <b>{message.from_user.full_name}</b>!\n\n"
        f"🎯 Har kuni <b>{REQUIRED_REFS} ta</b> do'stingizni taklif qiling va "
        f"o'sha kunning maxfiy kodini oling.\n\n"
        f"🕛 Hisob har kuni yarim tunda nolga tushadi.",
        reply_markup=menu())


@dp.callback_query(F.data.startswith("chk:"))
async def check_sub(call: CallbackQuery):
    payload = call.data.split(":", 1)[1]
    if await not_subscribed(call.from_user.id):
        await call.answer("❌ Hali obuna bo'lmadingiz!", show_alert=True)
        return
    await call.message.delete()
    await register(call.from_user, payload)
    await call.message.answer("✅ Obuna tasdiqlandi!", reply_markup=menu())


@dp.message(F.text == "🔗 Havolam")
async def my_link(message: Message):
    link = f"https://t.me/{BOT_USERNAME}?start={message.from_user.id}"
    await message.answer(
        f"🔗 Shaxsiy havolangiz:\n\n<code>{link}</code>\n\n"
        f"Do'stlaringiz aynan shu havola orqali botga kirishi kerak.",
        reply_markup=InlineKeyboardMarkup(inline_keyboard=[[
            InlineKeyboardButton(text="📤 Ulashish",
                                 url=f"https://t.me/share/url?url={link}")]]))


@dp.message(F.text == "📊 Hisobim")
async def my_stats(message: Message):
    c = refs_today(message.from_user.id)
    left = max(0, REQUIRED_REFS - c)
    bar = "🟩" * min(c, REQUIRED_REFS) + "⬜️" * left
    await message.answer(
        f"📊 <b>Bugungi hisobingiz</b>\n\n{bar}\n\n"
        f"Bugun: <b>{c}</b> / {REQUIRED_REFS}\n"
        f"Umumiy: <b>{refs_total(message.from_user.id)}</b> ta\n\n"
        + ("✅ Bugungi kodni olishingiz mumkin!" if left == 0
           else f"Yana <b>{left}</b> ta kerak."))


@dp.message(F.text == "🎁 Kodni olish")
async def get_my_code(message: Message):
    c = refs_today(message.from_user.id)
    if c >= REQUIRED_REFS:
        await send_code(message.from_user.id)
    else:
        await message.answer(
            f"🔒 Kod yopiq.\nBugun yana <b>{REQUIRED_REFS - c}</b> ta do'stingizni taklif qiling.")


@dp.message(F.text == "🏆 Reyting")
async def rating(message: Message):
    rows = top_day()
    if not rows:
        await message.answer("Bugun hali hech kim taklif qilmadi. Birinchi bo'ling! 🚀")
        return
    medals = ["🥇", "🥈", "🥉"]
    text = "🏆 <b>BUGUNGI TOP 10</b>\n\n"
    for i, (uid, name, uname, c) in enumerate(rows):
        text += f"{medals[i] if i < 3 else str(i+1)+'.'} {name} — <b>{c}</b> ta\n"
    await message.answer(text)


# ========================= ADMIN PANEL =========================
def admin_kb():
    return InlineKeyboardMarkup(inline_keyboard=[
        [InlineKeyboardButton(text="📊 Statistika", callback_data="a_stat")],
        [InlineKeyboardButton(text="🏆 Bugungi top", callback_data="a_topd"),
         InlineKeyboardButton(text="👑 Umumiy top", callback_data="a_topa")],
        [InlineKeyboardButton(text="🔑 Kodni o'zgartirish", callback_data="a_code")],
        [InlineKeyboardButton(text="📢 Kanallar", callback_data="a_ch")],
        [InlineKeyboardButton(text="📣 Reklama yuborish", callback_data="a_ad")],
    ])


def back_kb():
    return InlineKeyboardMarkup(inline_keyboard=[[
        InlineKeyboardButton(text="⬅️ Orqaga", callback_data="a_back")]])


def panel_text():
    return (f"⚙️ <b>ADMIN PANEL</b>\n\n"
            f"📅 Sana: {today()}\n"
            f"🔑 Bugungi kod: <code>{get_code()}</code>")


@dp.message(Command("admin"))
async def admin_panel(message: Message, state: FSMContext):
    if message.from_user.id not in ADMINS:
        return
    await state.clear()
    await message.answer(panel_text(), reply_markup=admin_kb())


@dp.callback_query(F.data == "a_back")
async def a_back(call: CallbackQuery, state: FSMContext):
    await state.clear()
    await call.message.edit_text(panel_text(), reply_markup=admin_kb())


@dp.callback_query(F.data == "a_stat")
async def a_stat(call: CallbackQuery):
    total, new, codes = stats()
    await call.message.edit_text(
        f"📊 <b>Statistika</b>\n\n"
        f"👥 Jami foydalanuvchilar: <b>{total}</b>\n"
        f"🆕 Bugun qo'shilganlar: <b>{new}</b>\n"
        f"🎁 Bugun kod olganlar: <b>{codes}</b>\n"
        f"📢 Majburiy kanallar: <b>{len(get_channels())}</b>\n"
        f"🔑 Joriy kod: <code>{get_code()}</code>",
        reply_markup=back_kb())


async def show_top(call, rows, title):
    if not rows:
        text = f"{title}\n\nBo'sh."
    else:
        text = f"{title}\n\n"
        for i, (uid, name, uname, c) in enumerate(rows, 1):
            tag = f"@{uname}" if uname else f"<code>{uid}</code>"
            text += f"{i}. {name} ({tag}) — <b>{c}</b> ta\n"
    await call.message.edit_text(text, reply_markup=back_kb())


@dp.callback_query(F.data == "a_topd")
async def a_topd(call: CallbackQuery):
    await show_top(call, top_day(20), "🏆 <b>BUGUNGI TOP 20</b>")


@dp.callback_query(F.data == "a_topa")
async def a_topa(call: CallbackQuery):
    await show_top(call, top_all(20), "👑 <b>UMUMIY TOP 20</b>")


# ---- kod ----
@dp.callback_query(F.data == "a_code")
async def a_code(call: CallbackQuery, state: FSMContext):
    await state.set_state(Admin.code)
    await call.message.edit_text(
        f"🔑 Joriy kod: <code>{get_code()}</code>\n\n"
        f"Yangi <b>5 xonali</b> kodni yuboring:", reply_markup=back_kb())


@dp.message(Admin.code)
async def a_code_set(message: Message, state: FSMContext):
    code = message.text.strip()
    if not (code.isdigit() and len(code) == 5):
        await message.answer("❌ Aynan 5 ta raqam bo'lishi kerak. Qayta yuboring:")
        return
    set_code(code)
    await state.clear()
    await message.answer(
        f"✅ Yangi kod saqlandi: <code>{code}</code>\n\n"
        f"Bugun huquq qozonganlarga darhol yuborilsinmi?",
        reply_markup=InlineKeyboardMarkup(inline_keyboard=[[
            InlineKeyboardButton(text="✅ Ha", callback_data="a_send"),
            InlineKeyboardButton(text="❌ Yo'q", callback_data="a_back")]]))


@dp.callback_query(F.data == "a_send")
async def a_send(call: CallbackQuery):
    await call.message.edit_text("⏳ Yuborilmoqda...")
    ok = 0
    for (uid,) in eligible_today():
        try:
            await send_code(uid, new_day=False)
            ok += 1
        except Exception:
            pass
        await asyncio.sleep(0.05)
    await call.message.edit_text(f"✅ {ok} ta foydalanuvchiga yuborildi.",
                                 reply_markup=back_kb())


# ---- kanallar ----
def channels_kb():
    rows = [[InlineKeyboardButton(text=f"🗑 {u}", callback_data=f"a_del:{u}")]
            for u, t in get_channels()]
    rows.append([InlineKeyboardButton(text="➕ Kanal qo'shish", callback_data="a_add")])
    rows.append([InlineKeyboardButton(text="⬅️ Orqaga", callback_data="a_back")])
    return InlineKeyboardMarkup(inline_keyboard=rows)


@dp.callback_query(F.data == "a_ch")
async def a_ch(call: CallbackQuery, state: FSMContext):
    await state.clear()
    chs = get_channels()
    text = "📢 <b>Majburiy kanallar</b>\n\n" + (
        "\n".join(f"• {t} ({u})" for u, t in chs) if chs else "Hozircha kanal yo'q.")
    await call.message.edit_text(text, reply_markup=channels_kb())


@dp.callback_query(F.data == "a_add")
async def a_add(call: CallbackQuery, state: FSMContext):
    await state.set_state(Admin.channel)
    await call.message.edit_text(
        "➕ Kanal usernameni yuboring, masalan: <code>@mening_kanalim</code>\n\n"
        "⚠️ Bot o'sha kanalda <b>admin</b> bo'lishi shart!", reply_markup=back_kb())


@dp.message(Admin.channel)
async def a_add_set(message: Message, state: FSMContext):
    username = message.text.strip()
    if not username.startswith("@"):
        await message.answer("❌ @ bilan boshlanishi kerak. Qayta yuboring:")
        return
    try:
        chat = await bot.get_chat(username)
        me = await bot.get_chat_member(username, (await bot.get_me()).id)
        if me.status not in ("administrator", "creator"):
            raise Exception
    except Exception:
        await message.answer("❌ Kanal topilmadi yoki bot u yerda admin emas. Qayta yuboring:")
        return
    db("INSERT OR REPLACE INTO channels VALUES (?,?)", (username, chat.title))
    await state.clear()
    await message.answer(f"✅ «{chat.title}» qo'shildi.", reply_markup=channels_kb())


@dp.callback_query(F.data.startswith("a_del:"))
async def a_del(call: CallbackQuery):
    db("DELETE FROM channels WHERE username=?", (call.data.split(":", 1)[1],))
    await call.answer("O'chirildi")
    await call.message.edit_text("📢 <b>Majburiy kanallar</b>", reply_markup=channels_kb())


# ---- reklama ----
@dp.callback_query(F.data == "a_ad")
async def a_ad(call: CallbackQuery, state: FSMContext):
    await state.set_state(Admin.ad)
    await call.message.edit_text("📣 Hammaga yubormoqchi bo'lgan xabarni yuboring:",
                                 reply_markup=back_kb())


@dp.message(Admin.ad)
async def a_ad_send(message: Message, state: FSMContext):
    await state.clear()
    status = await message.answer("⏳ Yuborilmoqda...")
    ok = fail = 0
    for (uid,) in db("SELECT user_id FROM users", (), "all"):
        try:
            await message.copy_to(uid)
            ok += 1
        except Exception:
            fail += 1
        await asyncio.sleep(0.05)
    await status.edit_text(f"✅ Yuborildi: {ok}\n❌ Yuborilmadi: {fail}",
                           reply_markup=admin_kb())


async def keep_alive():
    """Bepul hostinglar ochiq port talab qiladi — kichik veb-sahifa."""
    from aiohttp import web
    app = web.Application()
    app.router.add_get("/", lambda r: web.Response(text="Bot ishlayapti ✅"))
    runner = web.AppRunner(app)
    await runner.setup()
    port = int(os.getenv("PORT", 8000))
    await web.TCPSite(runner, "0.0.0.0", port).start()
    logging.info("Veb-server %s portda ishga tushdi", port)


async def main():
    global BOT_USERNAME
    db_init()
    me = await bot.get_me()
    BOT_USERNAME = me.username
    logging.info("Bot ishga tushdi: @%s", BOT_USERNAME)
    await keep_alive()
    await dp.start_polling(bot)


if __name__ == "__main__":
    asyncio.run(main())
