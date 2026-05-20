<?php
session_start();

// Iga kasutaja saab unikaalse sessiooni identifikaatori
if (!isset($_SESSION['uid'])) {
    $_SESSION['uid'] = bin2hex(random_bytes(16));
}
$uid = $_SESSION['uid'];

// ── Loe .env fail ──────────────────────────────────────────────────────────────
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    die('<h1>Viga: .env fail puudub!</h1>');
}
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $rida) {
    if (str_starts_with(trim($rida), '#') || !str_contains($rida, '=')) continue;
    [$key, $val] = explode('=', $rida, 2);
    $_ENV[trim($key)] = trim($val);
}
// ───────────────────────────────────────────────────────────────────────────────

try {
    $db = new PDO(
        'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'] . ';charset=utf8mb4',
        $_ENV['DB_USER'],
        $_ENV['DB_PASS']
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    $db->exec("CREATE TABLE IF NOT EXISTS SUVA (
        id     INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
        TEKST  TEXT         NOT NULL,
        loodud DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Reaktsioonid: üks rida kasutaja+kirje kohta; dislaigil on kohustuslik põhjus
    $db->exec("CREATE TABLE IF NOT EXISTS reactions (
        id      INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
        suva_id INT          NOT NULL,
        uid     VARCHAR(64)  NOT NULL,
        tyyp    ENUM('like','dislike') NOT NULL,
        pohjus  TEXT,
        UNIQUE KEY uk_kasutaja_kirje (suva_id, uid),
        FOREIGN KEY (suva_id) REFERENCES SUVA(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

} catch (PDOException $e) {
    if (isset($_POST['a'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'msg' => 'DB viga: ' . $e->getMessage()]);
        exit;
    }
    die('<h1>Andmebaasi viga: ' . htmlspecialchars($e->getMessage()) . '</h1>');
}

// ── AJAX päringute töötlus ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['a'])) {
    header('Content-Type: application/json');
    $a = $_POST['a'];

    try {
        switch ($a) {

            // Lisa uus tekst baasi
            case 'lisa': {
                $t = trim($_POST['tekst'] ?? '');
                if ($t === '') {
                    echo json_encode(['ok' => false, 'msg' => 'Tekst ei saa olla tühi!']);
                    exit;
                }
                $s = $db->prepare("INSERT INTO SUVA(TEKST) VALUES(?)");
                $s->execute([$t]);
                echo json_encode(['ok' => true, 'id' => (int)$db->lastInsertId()]);
                exit;
            }

            // Laigi / dislaigi reaktsioon
            case 'reageeri': {
                $sid = (int)($_POST['sid'] ?? 0);
                $typ = $_POST['typ'] ?? '';
                $poh = trim($_POST['poh'] ?? '');

                if (!in_array($typ, ['like', 'dislike'])) {
                    echo json_encode(['ok' => false, 'msg' => 'Vigane reaktsioon']);
                    exit;
                }

                // Loe praegune reaktsioon (kui olemas)
                $s = $db->prepare("SELECT tyyp FROM reactions WHERE suva_id=? AND uid=?");
                $s->execute([$sid, $uid]);
                $praegune = $s->fetchColumn(); // 'like' | 'dislike' | false

                if ($praegune === $typ) {
                    // Sama nuppu vajutades → eemalda reaktsioon (toggle off)
                    // NB: dislaigi eemaldamine EI nõua põhjust
                    $db->prepare("DELETE FROM reactions WHERE suva_id=? AND uid=?")
                       ->execute([$sid, $uid]);
                    echo json_encode(['ok' => true, 'olek' => 'none']);

                } else {
                    // Uus reaktsioon või vaheta olemasolevat
                    // Dislaik vajab ALATI põhjust (v.a toggle-off, mis on juba käsitletud)
                    if ($typ === 'dislike' && $poh === '') {
                        echo json_encode(['ok' => false, 'msg' => 'Dislaigi põhjus on kohustuslik!']);
                        exit;
                    }
                    $pohjusDb = ($typ === 'dislike') ? $poh : null; // Laigil ei ole põhjust

                    if ($praegune !== false) {
                        // Vaheta olemasolevat reaktsiooni
                        $db->prepare("UPDATE reactions SET tyyp=?, pohjus=? WHERE suva_id=? AND uid=?")
                           ->execute([$typ, $pohjusDb, $sid, $uid]);
                    } else {
                        // Lisa täiesti uus reaktsioon
                        $db->prepare("INSERT INTO reactions(suva_id, uid, tyyp, pohjus) VALUES(?,?,?,?)")
                           ->execute([$sid, $uid, $typ, $pohjusDb]);
                    }
                    echo json_encode(['ok' => true, 'olek' => $typ]);
                }
                exit;
            }

            // Kustuta kirje (CASCADE kustutab ka kõik reaktsioonid)
            case 'kustuta': {
                $sid = (int)($_POST['sid'] ?? 0);
                $db->prepare("DELETE FROM SUVA WHERE id=?")->execute([$sid]);
                echo json_encode(['ok' => true]);
                exit;
            }

            // Lae kõik kirjed koos reaktsioonide arvudega ja selle kasutaja olekuga
            case 'lae': {
                $qu = $db->quote($uid); // PDO::quote lisab jutumärgid ja escape'ib
                $st = $db->query("
                    SELECT
                        s.id,
                        s.TEKST,
                        s.loodud,
                        COUNT(CASE WHEN r.tyyp='like'    THEN 1 END) AS laigid,
                        COUNT(CASE WHEN r.tyyp='dislike' THEN 1 END) AS dislaigid,
                        ur.tyyp   AS minu_olek,
                        ur.pohjus AS minu_pohjus
                    FROM SUVA s
                    LEFT JOIN reactions r  ON s.id = r.suva_id
                    LEFT JOIN reactions ur ON s.id = ur.suva_id AND ur.uid = $qu
                    GROUP BY s.id
                    ORDER BY s.loodud DESC
                ");
                echo json_encode(['ok' => true, 'read' => $st->fetchAll(PDO::FETCH_ASSOC)]);
                exit;
            }

            default:
                echo json_encode(['ok' => false, 'msg' => 'Tundmatu tegevus']);
                exit;
        }
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'msg' => 'DB viga: ' . $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="et">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SUVA</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', Tahoma, Geneva, sans-serif;
    background: #eef1f7;
    min-height: 100vh;
    padding-bottom: 48px;
}

.wrap { max-width: 680px; margin: 0 auto; padding: 36px 16px; }

h1 {
    text-align: center;
    font-size: 2rem;
    color: #1e2a4a;
    margin-bottom: 28px;
    letter-spacing: 2px;
}

/* ── Sisestus ── */
.sisestus {
    display: flex;
    gap: 10px;
    background: #fff;
    padding: 16px;
    border-radius: 14px;
    box-shadow: 0 2px 12px rgba(0,0,0,.09);
    margin-bottom: 28px;
}
.sisestus input {
    flex: 1;
    padding: 11px 15px;
    border: 2px solid #dde3ef;
    border-radius: 9px;
    font-size: 1rem;
    font-family: inherit;
    color: #222;
    transition: border-color .2s;
}
.sisestus input:focus { outline: none; border-color: #4c7ef3; }
.sisestus input.viga { border-color: #e53935; animation: shake .3s; }
@keyframes shake {
    0%,100% { transform: translateX(0); }
    25% { transform: translateX(-6px); }
    75% { transform: translateX(6px); }
}

/* ── Nupud ── */
.nupp {
    padding: 11px 22px;
    border: none;
    border-radius: 9px;
    cursor: pointer;
    font-size: .95rem;
    font-weight: 700;
    font-family: inherit;
    transition: background .18s, transform .1s;
    white-space: nowrap;
}
.nupp:active { transform: scale(.96); }
.n-sinine  { background: #4c7ef3; color: #fff; }
.n-sinine:hover  { background: #3a6be0; }
.n-hall    { background: #eef1f7; color: #555; }
.n-hall:hover    { background: #dde3ef; }
.n-punane  { background: #e53935; color: #fff; }
.n-punane:hover  { background: #c62828; }

/* ── Kaardid ── */
.kaart {
    background: #fff;
    border-radius: 14px;
    padding: 18px 20px;
    margin-bottom: 14px;
    box-shadow: 0 2px 10px rgba(0,0,0,.07);
    animation: ilmu .22s ease;
}
@keyframes ilmu {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: none; }
}
.kaart-tekst {
    font-size: 1.05rem;
    color: #222;
    line-height: 1.55;
    margin-bottom: 8px;
    word-break: break-word;
}
.kaart-meta { font-size: .78rem; color: #aaa; margin-bottom: 12px; }

.rida { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

/* ── Reaktsiooninupud ── */
.r-nupp {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 16px;
    border-radius: 999px;
    border: 2px solid #e0e6f0;
    background: #f7f9fc;
    color: #666;
    font-size: .9rem;
    font-weight: 700;
    cursor: pointer;
    font-family: inherit;
    user-select: none;
    transition: all .18s;
}
.r-nupp:hover { transform: scale(1.07); box-shadow: 0 2px 8px rgba(0,0,0,.1); }
.r-nupp:active { transform: scale(.95); }
.r-nupp.laik    { background: #e8f0fe; border-color: #4c7ef3; color: #3a6be0; }
.r-nupp.dislaik { background: #fdecea; border-color: #e53935; color: #c62828; }

.kustuta {
    margin-left: auto;
    background: none;
    border: none;
    color: #ccc;
    cursor: pointer;
    font-size: 1.4rem;
    line-height: 1;
    padding: 4px 8px;
    border-radius: 8px;
    font-family: inherit;
    transition: color .18s, background .18s;
}
.kustuta:hover { color: #e53935; background: #fdecea; }

/* ── Dislaigi põhjus ── */
.pohjus-riba {
    margin-top: 11px;
    padding: 8px 12px;
    background: #fdecea;
    border-left: 3px solid #e53935;
    border-radius: 0 8px 8px 0;
    font-size: .83rem;
    color: #c62828;
    word-break: break-word;
}
.pohjus-riba strong { display: block; margin-bottom: 2px; }

/* ── Info ── */
.info { text-align: center; color: #bbb; padding: 52px 16px; font-size: 1rem; }

/* ── Modal ── */
.modal-mask {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(20,30,60,.5);
    backdrop-filter: blur(3px);
    z-index: 999;
    align-items: center;
    justify-content: center;
}
.modal-mask.sees { display: flex; }

.modal {
    background: #fff;
    border-radius: 16px;
    padding: 30px;
    width: 92%;
    max-width: 430px;
    box-shadow: 0 12px 40px rgba(0,0,0,.25);
    animation: ilmu .2s ease;
}
.modal h2 { font-size: 1.2rem; color: #1e2a4a; margin-bottom: 6px; }
.modal > p { font-size: .9rem; color: #666; margin-bottom: 16px; line-height: 1.5; }
.modal textarea {
    width: 100%;
    padding: 11px 13px;
    border: 2px solid #dde3ef;
    border-radius: 9px;
    font-size: .95rem;
    font-family: inherit;
    resize: vertical;
    min-height: 90px;
    color: #222;
    transition: border-color .2s;
}
.modal textarea:focus { outline: none; border-color: #e53935; }
.viga-tekst { min-height: 20px; font-size: .82rem; color: #e53935; margin-top: 6px; }
.modal-nupud { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; }
</style>
</head>
<body>

<div class="wrap">
    <h1>SUVA</h1>

    <div class="sisestus">
        <input type="text" id="tekst" placeholder="Sisesta tekst..." maxlength="500" autocomplete="off">
        <button class="nupp n-sinine" onclick="saada()">Saada baasi</button>
    </div>

    <div id="loend">
        <div class="info">Laen...</div>
    </div>
</div>

<!-- Modal: dislaigi põhjus -->
<div class="modal-mask" id="mask" onclick="maskKlops(event)">
    <div class="modal" id="modalBoks">
        <h2>👎 Miks ei meeldi?</h2>
        <p>Palun selgita lühidalt, miks see tekst sulle ei meeldi.<br>
           Põhjuse sisestamine on kohustuslik.</p>
        <textarea id="pohjus" placeholder="Sisesta põhjus..." maxlength="300"></textarea>
        <div class="viga-tekst" id="modal-viga"></div>
        <div class="modal-nupud">
            <button class="nupp n-hall"   onclick="sulge()">Tühista</button>
            <button class="nupp n-punane" onclick="kinnita()">Dislaigi</button>
        </div>
    </div>
</div>

<script>
'use strict';

let ootelSid = null; // millisele SUVA kirjele oodatav dislaik

// ── Utiliidid ─────────────────────────────────────────────────────────────────

async function post(data) {
    const fd = new FormData();
    for (const [k, v] of Object.entries(data)) fd.append(k, v ?? '');
    const r = await fetch(location.href, { method: 'POST', body: fd });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
}

function esc(s) {
    return String(s ?? '')
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function formatAeg(s) {
    if (!s) return '';
    const d = new Date(s.replace(' ', 'T') + '+00:00');
    if (isNaN(d)) return s;
    return d.toLocaleString('et-EE', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
    });
}

// ── Lisa tekst ────────────────────────────────────────────────────────────────

async function saada() {
    const el = document.getElementById('tekst');
    const t = el.value.trim();
    if (!t) {
        el.classList.add('viga');
        el.focus();
        setTimeout(() => el.classList.remove('viga'), 800);
        return;
    }
    try {
        const r = await post({ a: 'lisa', tekst: t });
        if (r.ok) { el.value = ''; await laeLoend(); }
        else alert(r.msg);
    } catch (e) { alert('Viga: ' + e.message); }
}

document.getElementById('tekst').addEventListener('keydown', e => {
    if (e.key === 'Enter') saada();
});

// ── Lae loend ────────────────────────────────────────────────────────────────

async function laeLoend() {
    const el = document.getElementById('loend');
    try {
        const r = await post({ a: 'lae' });
        if (!r.ok) { el.innerHTML = '<div class="info">Viga: ' + esc(r.msg) + '</div>'; return; }
        if (!r.read || r.read.length === 0) {
            el.innerHTML = '<div class="info">Kirjeid pole. Sisesta esimene tekst!</div>';
            return;
        }
        el.innerHTML = r.read.map(k => {
            const olek = k.minu_olek || 'none';
            const pohjusHtml = (olek === 'dislike' && k.minu_pohjus)
                ? `<div class="pohjus-riba"><strong>Sinu dislaigi põhjus:</strong>${esc(k.minu_pohjus)}</div>`
                : '';
            return `
            <div class="kaart" id="k${k.id}" data-olek="${esc(olek)}">
                <div class="kaart-tekst">${esc(k.TEKST)}</div>
                <div class="kaart-meta">${formatAeg(k.loodud)}</div>
                <div class="rida">
                    <button class="r-nupp ${olek === 'like' ? 'laik' : ''}"
                            onclick="reageeri(${k.id}, 'like')" title="Laigi">
                        👍 <span>${parseInt(k.laigid) || 0}</span>
                    </button>
                    <button class="r-nupp ${olek === 'dislike' ? 'dislaik' : ''}"
                            onclick="reageeri(${k.id}, 'dislike')" title="Dislaigi">
                        👎 <span>${parseInt(k.dislaigid) || 0}</span>
                    </button>
                    <button class="kustuta" onclick="kustuta(${k.id})" title="Kustuta kirje">×</button>
                </div>
                ${pohjusHtml}
            </div>`;
        }).join('');
    } catch (e) {
        el.innerHTML = '<div class="info">Laadimisviga: ' + esc(e.message) + '</div>';
    }
}

// ── Reaktsioon ────────────────────────────────────────────────────────────────
//
// Loogika:
//   like   → otse baasi (põhjust ei nõuta)
//   dislike, kui praegu MITTE dislaigitud → näita modal (põhjus kohustuslik)
//   dislike, kui praegu JO dislaigitud    → toggle off (eemalda, põhjust ei nõuta)
//   like/dislike vahetamine               → server uuendab, dislaigile on põhjus juba modal'ist
//
async function reageeri(sid, typ) {
    const kaart = document.getElementById('k' + sid);
    const praeguneOlek = kaart ? kaart.dataset.olek : 'none';

    if (typ === 'dislike' && praeguneOlek !== 'dislike') {
        // Vajame põhjust – ava modal
        ootelSid = sid;
        document.getElementById('pohjus').value = '';
        document.getElementById('modal-viga').textContent = '';
        document.getElementById('mask').classList.add('sees');
        setTimeout(() => document.getElementById('pohjus').focus(), 60);
        return;
    }

    // Laik (igal juhul) või dislaigi eemaldamine (toggle off) – otse serverisse
    try {
        const r = await post({ a: 'reageeri', sid, typ, poh: '' });
        if (r.ok) await laeLoend();
        else alert(r.msg);
    } catch (e) { alert('Viga: ' + e.message); }
}

// ── Modal ─────────────────────────────────────────────────────────────────────

async function kinnita() {
    const poh = document.getElementById('pohjus').value.trim();
    if (!poh) {
        document.getElementById('modal-viga').textContent = 'Põhjus on kohustuslik – kirjuta midagi!';
        document.getElementById('pohjus').focus();
        return;
    }
    try {
        const r = await post({ a: 'reageeri', sid: ootelSid, typ: 'dislike', poh });
        if (r.ok) { sulge(); await laeLoend(); }
        else document.getElementById('modal-viga').textContent = r.msg;
    } catch (e) {
        document.getElementById('modal-viga').textContent = 'Viga: ' + e.message;
    }
}

function sulge() {
    document.getElementById('mask').classList.remove('sees');
    ootelSid = null;
}

function maskKlops(e) {
    // Sulge ainult siis, kui klikiti taustal (mitte modali sisul)
    if (e.target === document.getElementById('mask')) sulge();
}

// Klaviatuuriototsed modalil
document.addEventListener('keydown', e => {
    const avatud = document.getElementById('mask').classList.contains('sees');
    if (!avatud) return;
    if (e.key === 'Escape') sulge();
    // Enter kinnitab ainult siis, kui fookus ei ole textarea's
    if (e.key === 'Enter' && e.target.id !== 'pohjus') {
        e.preventDefault();
        kinnita();
    }
});

// ── Kustuta kirje ─────────────────────────────────────────────────────────────
// ON DELETE CASCADE tagab, et ka kõik reaktsioonid (sh dislaigi põhjused) kustutatakse

async function kustuta(sid) {
    if (!confirm('Kustutad selle kirje?\n\nKa kõik laigid, dislaigid ja põhjused kustutatakse.')) return;
    try {
        const r = await post({ a: 'kustuta', sid });
        if (r.ok) await laeLoend();
        else alert(r.msg);
    } catch (e) { alert('Viga: ' + e.message); }
}

// ── Käivitus ──────────────────────────────────────────────────────────────────
laeLoend();
</script>
</body>
</html>
