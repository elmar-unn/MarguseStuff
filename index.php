<?php
// ── .env laadimine ─────────────────────────────────────────────────────────────
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) die('<h1>Viga: .env fail puudub!</h1>');
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim($value);
}

define('DB_HOST', $env['DB_HOST'] ?? '');
define('DB_NAME', $env['DB_NAME'] ?? '');
define('DB_USER', $env['DB_USER'] ?? '');
define('DB_PASS', $env['DB_PASS'] ?? '');

// ── API päringud ───────────────────────────────────────────────────────────────
if (isset($_GET['action'])) {
    session_start();
    if (empty($_SESSION['uid'])) {
        $_SESSION['uid'] = bin2hex(random_bytes(32));
    }
    $uid = $_SESSION['uid'];

    header('Content-Type: application/json');

    try {
        $db = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
        );

        $db->exec("CREATE TABLE IF NOT EXISTS SUVA (
            id     INT  NOT NULL AUTO_INCREMENT PRIMARY KEY,
            TEKST  TEXT NOT NULL,
            loodud TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS reactions (
            id      INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
            suva_id INT         NOT NULL,
            uid     VARCHAR(64) NOT NULL,
            tyyp    ENUM('like','dislike') NOT NULL,
            pohjus  TEXT,
            UNIQUE KEY uk_sessioon_kirje (suva_id, uid),
            FOREIGN KEY (suva_id) REFERENCES SUVA(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Lae kõik kirjed koos reaktsioonidega
        if ($_GET['action'] === 'get') {
            $s = $db->prepare("
                SELECT s.id, s.TEKST, s.loodud,
                    COUNT(CASE WHEN r.tyyp='like'    THEN 1 END) AS laigid,
                    COUNT(CASE WHEN r.tyyp='dislike' THEN 1 END) AS dislaigid,
                    ur.tyyp   AS minu_olek,
                    ur.pohjus AS minu_pohjus
                FROM SUVA s
                LEFT JOIN reactions r  ON s.id = r.suva_id
                LEFT JOIN reactions ur ON s.id = ur.suva_id AND ur.uid = ?
                GROUP BY s.id, ur.tyyp, ur.pohjus
                ORDER BY s.loodud DESC
            ");
            $s->execute([$uid]);
            echo json_encode(['success' => true, 'items' => $s->fetchAll(PDO::FETCH_ASSOC)]);

        // Lisa uus kirje
        } elseif ($_GET['action'] === 'add') {
            $input = json_decode(file_get_contents('php://input'), true);
            $tekst = trim($input['tekst'] ?? '');
            if ($tekst === '') { echo json_encode(['success' => false, 'msg' => 'Tekst on tühi!']); exit; }
            $s = $db->prepare("INSERT INTO SUVA (TEKST) VALUES (?)");
            $s->execute([$tekst]);
            echo json_encode(['success' => true, 'id' => (int)$db->lastInsertId()]);

        // Reageeri (laik / dislaik)
        } elseif ($_GET['action'] === 'react') {
            $input  = json_decode(file_get_contents('php://input'), true);
            $sid    = (int)($input['id']     ?? 0);
            $typ    = $input['type']   ?? '';
            $pohjus = trim($input['reason'] ?? '');

            if (!in_array($typ, ['like', 'dislike'])) {
                echo json_encode(['success' => false, 'msg' => 'Vigane reaktsioon']); exit;
            }

            $s = $db->prepare("SELECT tyyp FROM reactions WHERE suva_id=? AND uid=?");
            $s->execute([$sid, $uid]);
            $praegune = $s->fetchColumn();

            if ($praegune === $typ) {
                // Toggle off — sama nuppu vajutades eemalda
                $db->prepare("DELETE FROM reactions WHERE suva_id=? AND uid=?")->execute([$sid, $uid]);
                echo json_encode(['success' => true, 'olek' => 'none']);
            } else {
                if ($typ === 'dislike' && $pohjus === '') {
                    echo json_encode(['success' => false, 'msg' => 'Dislaigi põhjus on kohustuslik!']); exit;
                }
                $pohjusDb = $typ === 'dislike' ? $pohjus : null;
                if ($praegune !== false) {
                    $db->prepare("UPDATE reactions SET tyyp=?, pohjus=? WHERE suva_id=? AND uid=?")
                       ->execute([$typ, $pohjusDb, $sid, $uid]);
                } else {
                    $db->prepare("INSERT INTO reactions (suva_id, uid, tyyp, pohjus) VALUES (?,?,?,?)")
                       ->execute([$sid, $uid, $typ, $pohjusDb]);
                }
                echo json_encode(['success' => true, 'olek' => $typ]);
            }

        // Kustuta kirje
        } elseif ($_GET['action'] === 'delete') {
            $input = json_decode(file_get_contents('php://input'), true);
            $sid   = (int)($input['id'] ?? 0);
            $db->prepare("DELETE FROM SUVA WHERE id=?")->execute([$sid]);
            echo json_encode(['success' => true]);
        }

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="et">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>SUVA</title>
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet" />
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --bg: #0f0f0f; --surface: #1a1a1a; --surface2: #222;
    --border: #2a2a2a; --text: #e8e4dc; --muted: #555;
    --up: #22c55e; --down: #ef4444; --accent: #f0e96a;
}
body {
    min-height: 100vh; background: var(--bg);
    font-family: 'DM Sans', sans-serif; color: var(--text);
    padding: 40px 16px 64px;
}
body::before {
    content: ''; position: fixed; inset: 0; pointer-events: none;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
    opacity: .03;
}

.wrap { max-width: 640px; margin: 0 auto; position: relative; z-index: 1; }

/* ── Pealkiri ── */
.header { text-align: center; margin-bottom: 40px; animation: fadeUp .5s ease both; }
.label  { font-size: 11px; font-weight: 300; letter-spacing: .18em; text-transform: uppercase; color: var(--muted); margin-bottom: 14px; }
h1 { font-family: 'Syne', sans-serif; font-size: clamp(1.8rem, 6vw, 2.6rem); font-weight: 800; line-height: 1.1; }
h1 span { color: var(--accent); }

/* ── Sisestus ── */
.sisestus {
    display: flex; gap: 10px;
    background: var(--surface); border: 1px solid var(--border); border-radius: 20px;
    padding: 14px; margin-bottom: 32px;
    box-shadow: 0 0 0 1px #ffffff06, 0 16px 40px #00000040;
    animation: fadeUp .5s .1s ease both;
}
.sisestus input {
    flex: 1; background: var(--surface2); border: 1px solid var(--border);
    border-radius: 12px; color: var(--text); font-family: 'DM Sans', sans-serif;
    font-size: .95rem; padding: 12px 16px; outline: none; transition: border-color .2s;
}
.sisestus input::placeholder { color: var(--muted); }
.sisestus input:focus { border-color: var(--accent); }
.sisestus input.viga { border-color: var(--down); animation: shake .3s; }
@keyframes shake {
    0%,100% { transform:translateX(0); } 25% { transform:translateX(-5px); } 75% { transform:translateX(5px); }
}
.btn-add {
    padding: 12px 22px; border: none; border-radius: 12px; background: var(--accent);
    color: #111; font-family: 'DM Sans', sans-serif; font-size: .9rem; font-weight: 500;
    cursor: pointer; white-space: nowrap; transition: opacity .2s, transform .1s;
}
.btn-add:hover { opacity: .88; }
.btn-add:active { transform: scale(.96); }

/* ── Kaardid ── */
.kaart {
    background: var(--surface); border: 1px solid var(--border); border-radius: 20px;
    padding: 22px 24px; margin-bottom: 14px;
    box-shadow: 0 0 0 1px #ffffff05, 0 8px 24px #00000030;
    animation: fadeUp .3s ease both;
}
@keyframes fadeUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:none; } }
.kaart-tekst { font-size: 1rem; line-height: 1.6; color: var(--text); margin-bottom: 10px; word-break: break-word; }
.kaart-meta  { font-size: .75rem; color: var(--muted); font-weight: 300; margin-bottom: 16px; }

/* ── Reaktsiooninupud ── */
.rida { display: flex; align-items: center; gap: 10px; }
.r-nupp {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 8px 18px; border-radius: 999px; border: 1px solid var(--border);
    background: var(--surface2); color: var(--muted);
    font-family: 'DM Sans', sans-serif; font-size: .85rem; font-weight: 500;
    cursor: pointer; user-select: none; transition: all .2s; position: relative; overflow: hidden;
}
.r-nupp:hover { transform: translateY(-2px); border-color: #444; color: var(--text); }
.r-nupp:active { transform: scale(.95); }
.r-nupp.laik    { border-color: var(--up);   background: #22c55e12; color: var(--up); }
.r-nupp.dislaik { border-color: var(--down); background: #ef444412; color: var(--down); }
.r-nupp.inactive { opacity: .35; }

.btn-kustuta {
    margin-left: auto; background: none; border: none; color: var(--muted);
    cursor: pointer; font-size: 1.2rem; line-height: 1; padding: 6px 10px;
    border-radius: 8px; transition: color .2s, background .2s;
}
.btn-kustuta:hover { color: var(--down); background: #ef444415; }

/* ── Dislaigi põhjus ── */
.pohjus-riba {
    margin-top: 14px; padding: 10px 14px;
    background: #ef444410; border-left: 2px solid var(--down);
    border-radius: 0 10px 10px 0; font-size: .82rem; color: #f87171;
    font-weight: 300; word-break: break-word;
}
.pohjus-riba strong { display: block; font-weight: 500; margin-bottom: 2px; font-size: .78rem; letter-spacing: .05em; text-transform: uppercase; color: var(--down); }

/* ── Info ── */
.info { text-align: center; color: var(--muted); padding: 56px 16px; font-size: .95rem; font-weight: 300; }

/* ── Overlay / Modal ── */
.overlay {
    position: fixed; inset: 0; background: #00000090; backdrop-filter: blur(6px);
    display: flex; align-items: center; justify-content: center; z-index: 100;
    opacity: 0; pointer-events: none; transition: opacity .3s;
}
.overlay.open { opacity: 1; pointer-events: all; }
.modal {
    background: var(--surface); border: 1px solid var(--border); border-radius: 22px;
    padding: 36px 32px 30px; max-width: 420px; width: 88vw;
    box-shadow: 0 24px 80px #00000080;
    transform: scale(.92); transition: transform .35s cubic-bezier(.34,1.56,.64,1);
}
.overlay.open .modal { transform: scale(1); }
.modal h2 { font-family: 'Syne', sans-serif; font-size: 1.2rem; font-weight: 800; margin-bottom: 6px; }
.modal p  { font-size: .88rem; color: var(--muted); font-weight: 300; margin-bottom: 20px; line-height: 1.6; }
textarea {
    width: 100%; min-height: 100px; background: var(--bg); border: 1px solid var(--border);
    border-radius: 12px; color: var(--text); font-family: 'DM Sans', sans-serif;
    font-size: .9rem; padding: 13px 15px; resize: vertical; outline: none;
    transition: border-color .2s; margin-bottom: 6px;
}
textarea:focus { border-color: var(--down); }
textarea::placeholder { color: var(--muted); }
.viga-tekst { min-height: 18px; font-size: .8rem; color: var(--down); margin-bottom: 14px; }
.modal-btns { display: flex; gap: 10px; }
.btn-cancel {
    flex: 1; padding: 12px; border: 1px solid var(--border); border-radius: 12px;
    background: none; color: var(--muted); font-family: 'DM Sans', sans-serif;
    font-size: .88rem; cursor: pointer; transition: background .2s;
}
.btn-cancel:hover { background: var(--surface2); }
.btn-confirm {
    flex: 2; padding: 12px; border: none; border-radius: 12px; background: var(--down);
    color: #fff; font-family: 'DM Sans', sans-serif; font-size: .88rem; font-weight: 500;
    cursor: pointer; transition: opacity .2s;
}
.btn-confirm:hover { opacity: .85; }

@keyframes ripple { from { transform:scale(0); opacity:.3; } to { transform:scale(5); opacity:0; } }
.ripple {
    position: absolute; border-radius: 50%; width: 50px; height: 50px;
    pointer-events: none; animation: ripple .5s ease-out forwards;
    margin-top: -25px; margin-left: -25px;
}
</style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <p class="label">Kommentaarid</p>
        <h1>SUVA <span>baas</span></h1>
    </div>

    <div class="sisestus">
        <input type="text" id="tekst" placeholder="Sisesta tekst..." maxlength="500" autocomplete="off" />
        <button class="btn-add" onclick="lisaKirje()">Saada baasi</button>
    </div>

    <div id="loend"><div class="info">Laen…</div></div>
</div>

<!-- Dislaigi põhjuse modal -->
<div class="overlay" id="overlay" onclick="overlayKlõps(event)">
    <div class="modal">
        <h2>Miks ei meeldi? 🤔</h2>
        <p>Sinu tagasiside on kasulik. Põhjus on kohustuslik.</p>
        <textarea id="pohjus-tekst" placeholder="Kirjuta siia…" maxlength="500"></textarea>
        <div class="viga-tekst" id="modal-viga"></div>
        <div class="modal-btns">
            <button class="btn-cancel"  onclick="sulgeModal()">Tühista</button>
            <button class="btn-confirm" onclick="kinnitaDislaik()">Dislaigi</button>
        </div>
    </div>
</div>

<script>
const API = 'index.php';
let ootelId = null;

// ── Utiliidid ─────────────────────────────────────────────────────────────────

function api(action, body) {
    return fetch(API + '?action=' + action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    }).then(function(r) { return r.json(); });
}

function get(action) {
    return fetch(API + '?action=' + action).then(function(r) { return r.json(); });
}

function esc(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function formatAeg(s) {
    if (!s) return '';
    try {
        var d = new Date(s.replace(' ','T'));
        return d.toLocaleString('et-EE', { day:'2-digit', month:'2-digit', year:'numeric', hour:'2-digit', minute:'2-digit' });
    } catch(e) { return s; }
}

function ripple(btn, color) {
    var r = document.createElement('span');
    r.className = 'ripple'; r.style.background = color;
    r.style.top = '50%'; r.style.left = '50%';
    btn.appendChild(r);
    r.addEventListener('animationend', function() { r.remove(); });
}

// ── Lisa kirje ────────────────────────────────────────────────────────────────

function lisaKirje() {
    var el = document.getElementById('tekst');
    var t  = el.value.trim();
    if (!t) {
        el.classList.add('viga');
        el.focus();
        setTimeout(function() { el.classList.remove('viga'); }, 800);
        return;
    }
    api('add', { tekst: t }).then(function(r) {
        if (r.success) { el.value = ''; laeLoend(); }
        else alert(r.msg);
    });
}

document.getElementById('tekst').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') lisaKirje();
});

// ── Lae loend ────────────────────────────────────────────────────────────────

function laeLoend() {
    var el = document.getElementById('loend');
    get('get').then(function(r) {
        if (!r.success) { el.innerHTML = '<div class="info">Viga: ' + esc(r.error) + '</div>'; return; }
        if (!r.items || r.items.length === 0) {
            el.innerHTML = '<div class="info">Kirjeid pole veel. Sisesta esimene!</div>';
            return;
        }
        el.innerHTML = r.items.map(function(k, i) {
            var olek = k.minu_olek || 'none';
            var laigid    = parseInt(k.laigid)    || 0;
            var dislaigid = parseInt(k.dislaigid) || 0;
            var pohjusHtml = (olek === 'dislike' && k.minu_pohjus)
                ? '<div class="pohjus-riba"><strong>Sinu põhjus</strong>' + esc(k.minu_pohjus) + '</div>'
                : '';
            return '<div class="kaart" id="k' + k.id + '" data-olek="' + olek + '" style="animation-delay:' + (i * 0.04) + 's">' +
                '<div class="kaart-tekst">' + esc(k.TEKST) + '</div>' +
                '<div class="kaart-meta">' + formatAeg(k.loodud) + '</div>' +
                '<div class="rida">' +
                    '<button class="r-nupp ' + (olek==='like' ? 'laik' : olek==='dislike' ? 'inactive' : '') + '" ' +
                        'onclick="reageeri(' + k.id + ',\'like\',this)">👍 ' + laigid + '</button>' +
                    '<button class="r-nupp ' + (olek==='dislike' ? 'dislaik' : olek==='like' ? 'inactive' : '') + '" ' +
                        'onclick="reageeri(' + k.id + ',\'dislike\',this)">👎 ' + dislaigid + '</button>' +
                    '<button class="btn-kustuta" onclick="kustuta(' + k.id + ')" title="Kustuta">×</button>' +
                '</div>' +
                pohjusHtml +
            '</div>';
        }).join('');
    }).catch(function(e) {
        el.innerHTML = '<div class="info">Laadimisviga.</div>';
    });
}

// ── Reaktsioon ────────────────────────────────────────────────────────────────

function reageeri(id, typ, btn) {
    var kaart = document.getElementById('k' + id);
    var praeguneOlek = kaart ? kaart.dataset.olek : 'none';

    if (typ === 'dislike' && praeguneOlek !== 'dislike') {
        ripple(btn, '#ef4444');
        ootelId = id;
        document.getElementById('pohjus-tekst').value = '';
        document.getElementById('modal-viga').textContent = '';
        document.getElementById('overlay').classList.add('open');
        setTimeout(function() { document.getElementById('pohjus-tekst').focus(); }, 350);
        return;
    }

    ripple(btn, typ === 'like' ? '#22c55e' : '#ef4444');
    api('react', { id: id, type: typ, reason: '' }).then(function(r) {
        if (r.success) laeLoend();
        else alert(r.msg);
    });
}

// ── Modal ─────────────────────────────────────────────────────────────────────

function kinnitaDislaik() {
    var poh = document.getElementById('pohjus-tekst').value.trim();
    if (!poh) {
        document.getElementById('modal-viga').textContent = 'Põhjus on kohustuslik!';
        document.getElementById('pohjus-tekst').focus();
        return;
    }
    api('react', { id: ootelId, type: 'dislike', reason: poh }).then(function(r) {
        if (r.success) { sulgeModal(); laeLoend(); }
        else document.getElementById('modal-viga').textContent = r.msg;
    });
}

function sulgeModal() {
    document.getElementById('overlay').classList.remove('open');
    ootelId = null;
}

function overlayKlõps(e) {
    if (e.target === document.getElementById('overlay')) sulgeModal();
}

// ── Kustuta ───────────────────────────────────────────────────────────────────

function kustuta(id) {
    if (!confirm('Kustutad selle kirje?\nKa kõik reaktsioonid kustutatakse.')) return;
    api('delete', { id: id }).then(function(r) {
        if (r.success) laeLoend();
        else alert(r.error);
    });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') sulgeModal();
});

// ── Käivitus ──────────────────────────────────────────────────────────────────
laeLoend();
</script>
</body>
</html>
