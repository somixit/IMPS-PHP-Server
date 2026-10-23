<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/lang.php';
require_login();
$user = current_user();
$uid = (int)$user['id'];
$nick = (string)$user['nick'];

$recipient = isset($_GET['to']) ? trim((string)$_GET['to']) : '';
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'send';
    if ($action === 'send') {
        $to = trim((string)($_POST['to'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        if ($to !== '' && $body !== '') {
            db()->prepare('INSERT INTO messages (from_user, to_user, body, content_type) VALUES (?,?,?,?)')
                ->execute([$nick, $to, $body, 'text/plain']);
            header('Location: talk.php?to=' . urlencode($to));
            exit;
        }
        $notice = t('select_friend_and_type');
    } elseif ($action === 'add_contact') {
        $contact = trim((string)($_POST['contact'] ?? ''));
        if ($contact !== '') {
            db()->prepare('INSERT OR IGNORE INTO contacts (owner_id, contact_nick) VALUES (?, ?)')->execute([$uid, $contact]);
            header('Location: talk.php?to=' . urlencode($contact));
            exit;
        }
    } elseif ($action === 'logout') {
        session_destroy();
        header('Location: login.php');
        exit;
    }
}

$stmt = db()->prepare('SELECT contact_nick FROM contacts WHERE owner_id = ? ORDER BY contact_nick');
$stmt->execute([$uid]);
$contacts = $stmt->fetchAll(PDO::FETCH_COLUMN);

$stmt = db()->prepare('SELECT COALESCE(MAX(id), 0) FROM messages WHERE from_user = ? OR to_user = ?');
$stmt->execute([$nick, $nick]);
$lastId = (int)$stmt->fetchColumn();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WV Chat</title>
<style>
*{box-sizing:border-box}
body{font-family:system-ui,sans-serif;background:#eef2f7;margin:0;padding:18px;color:#20242e}
.wrap{max-width:1100px;margin:0 auto}
.top{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;background:#fff;border-radius:14px;padding:12px 16px;box-shadow:0 8px 22px rgba(0,0,0,.08);margin-bottom:14px}
.top-user{display:flex;align-items:center;gap:10px;min-width:0}
.top-user .who{font-weight:600;font-size:.95rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.top-status{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-left:auto}
.top-status select,.top-status input{padding:8px 9px;max-width:150px}
.top-status #status-text{width:150px}
.my-photo-wrap{position:relative;display:inline-flex}
.my-photo{padding:0;background:transparent;border-radius:50%;line-height:0;cursor:pointer}
.my-photo:hover .avatar{filter:brightness(.85)}
.photo-btn{position:absolute;top:-5px;width:19px;height:19px;padding:0;border-radius:50%;color:#fff;border:2px solid #fff;line-height:1;display:none;align-items:center;justify-content:center;cursor:pointer}
.photo-x{right:-5px;background:#e0364a;font-size:12px}
.photo-edit{left:-5px;background:#2f6fed}
.my-photo-wrap:hover .photo-edit{display:flex}
.my-photo-wrap.has-photo:hover .photo-x{display:flex}
@media(hover:none){.my-photo-wrap .photo-edit{display:flex}.my-photo-wrap.has-photo .photo-x{display:flex}}
.app{display:grid;grid-template-columns:280px 1fr;gap:14px;height:calc(100vh - 120px)}
.card{background:#fff;border-radius:14px;box-shadow:0 8px 22px rgba(0,0,0,.08);overflow:hidden;display:flex;flex-direction:column;min-height:0}
.sidebar-head{padding:12px;border-bottom:1px solid #eef1f6;flex:0 0 auto}
.tabs{display:flex;gap:6px;padding:9px 9px 8px;border-bottom:1px solid #eef1f6;flex:0 0 auto}
.tab{flex:1;min-width:0;padding:7px 8px;border:0;border-radius:9px;background:#f2f6fe;color:#5a6478;font:inherit;font-size:.84rem;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;white-space:nowrap}
.tab.active{background:#2f6fed;color:#fff}
.tab{transition:background .1s,transform .1s}
.tab:active:not(.active){transform:scale(.97)}
.tab-badge{background:rgba(255,255,255,.28);color:#fff;border-radius:999px;font-size:.68rem;padding:1px 6px;min-width:18px;text-align:center}
.tab:not(.active) .tab-badge{background:#2f6fed;color:#fff}
.pane{flex:1 1 auto;min-height:0;display:flex;flex-direction:column}
.pane[hidden]{display:none}
.conv-time{font-size:.68rem;color:#98a0b3;flex:0 0 auto}
.contact-item.out{opacity:.62}
.contact-item.out .group-icon{background:#b7c0d2}
.contact-list{flex:1 1 auto;overflow:auto;padding:6px;min-height:0}
.contact-item{display:flex;align-items:center;gap:8px;padding:9px 10px;border-radius:9px;cursor:pointer;margin:2px 0}
.contact-item:hover{background:#f2f6fe}
.contact-item.active{background:#e3ecff}
.contact-item{transition:background .1s,transform .08s}
.contact-item:active{transform:scale(.985)}
.contact-item .remove{display:none;width:18px;height:18px;padding:0;border:0;border-radius:50%;background:#e0364a;color:#fff;font-size:12px;line-height:1;align-items:center;justify-content:center;flex:0 0 auto}
.contact-item:hover .remove{display:flex}
@media(hover:none){.contact-item .remove{display:flex}}
.contact-main{flex:1;min-width:0}
.contact-name{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.contact-status{font-size:.75rem;color:#7a8296;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
/* Punto de estado: gris = invisible o desconectado, verde = disponible,
   rojo = "Comunica" (DISCREET / NOT_AVAILABLE, sigue conectado). */
.dot{width:9px;height:9px;border-radius:50%;background:#b9bfcc;flex:0 0 auto}
.dot.online{background:#2ecc71}
.dot.busy{background:#e0364a}
.avatar-wrap{position:relative;flex:0 0 auto;display:inline-flex}
.avatar-wrap .dot{position:absolute;right:-1px;bottom:-1px;border:2px solid #fff;box-sizing:content-box}
.avatar{width:34px;height:34px;border-radius:50%;overflow:hidden;flex:0 0 auto;background:#dfe6f5;display:block}
.avatar.big{width:42px;height:42px}
.avatar img{width:100%;height:100%;object-fit:cover;display:block}
.avatar.fallback{display:flex;align-items:center;justify-content:center;background:#8ea3d6;color:#fff;font-weight:600;font-size:.9rem;text-transform:uppercase}
.avatar.big.fallback{font-size:1.1rem}
.chat-head .avatar{cursor:zoom-in}
.avatar-modal{position:fixed;inset:0;background:rgba(15,20,35,.72);display:flex;align-items:center;justify-content:center;z-index:50;cursor:zoom-out;padding:24px}
.avatar-modal[hidden]{display:none}
.avatar-modal img{border-radius:0;max-width:92vw;max-height:92vh;background:#fff;box-shadow:0 14px 44px rgba(0,0,0,.55)}
.badge{background:#2f6fed;color:#fff;border-radius:999px;font-size:.72rem;padding:1px 7px;min-width:20px;text-align:center}
.group-head{display:flex;align-items:center;justify-content:space-between;padding:8px 12px 4px;border-top:1px solid #eef1f6;font-size:.72rem;color:#7a8296;text-transform:uppercase;letter-spacing:.05em;flex:0 0 auto}
.group-list{flex:0 0 auto;max-height:34%;overflow:auto;padding:2px 6px 6px}
.group-item{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:9px;cursor:pointer;margin:2px 0}
.group-item:hover{background:#f2f6fe}
.group-item.active{background:#e3ecff}
.group-item{transition:background .1s,transform .08s}
.group-item:active{transform:scale(.985)}
.group-item.out{opacity:.62}
.group-item.out .group-icon{background:#b7c0d2}
.group-item .remove{display:none;width:18px;height:18px;padding:0;border:0;border-radius:50%;background:#e0364a;color:#fff;font-size:12px;line-height:1;align-items:center;justify-content:center;flex:0 0 auto}
.group-item:hover .remove{display:flex}
@media(hover:none){.group-item .remove{display:flex}}
.group-icon{width:34px;height:34px;border-radius:9px;background:#8ea3d6;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;flex:0 0 auto}
.group-item.invite .group-icon{background:#f5a623}
/* Icono del grupo en el que estoy dentro: verde, igual en el sidebar y en la
   cabecera de la conversacion (gris cuando no estoy dentro). */
.icon-in{background:#57a86e}
.chat-head .avatar.icon-in{background:#57a86e;color:#fff}
.mini{width:22px;height:22px;padding:0;border-radius:50%;font-size:12px;line-height:1;background:#2ecc71;color:#fff}
.mini.no{background:#e0364a}
.msg .who{font-size:.7rem;font-weight:600;color:#5a6b93;margin-bottom:3px}
.chat-head{padding:12px 16px;border-bottom:1px solid #eef1f6;display:flex;align-items:center;gap:10px;min-height:56px;flex-wrap:wrap}
.chat-head h2{margin:0;font-size:1rem}
.chat-head .sub{font-size:.78rem;color:#7a8296}
/* Acciones de la conversacion, agrupadas a la derecha: si no caben bajan
   juntas a otra linea en vez de apretar el nombre. */
.chat-actions{margin-left:auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.messages{flex:1;overflow:auto;padding:14px;background:#f7f9fd}
.msg{padding:8px 11px;border-radius:11px;margin:7px 0;max-width:76%;word-wrap:break-word}
.msg .bubble{white-space:pre-wrap}
.me{background:#d9e6ff;margin-left:auto}
.them{background:#fff;border:1px solid #dbe0ea}
.meta{font-size:.68rem;color:#7a8296;margin-top:4px}
/* Entradas y salidas del grupo: linea de sistema, centrada y discreta. */
.sysmsg{align-self:center;max-width:88%;margin:9px auto;padding:4px 10px;border-radius:999px;background:#e9edf5;color:#5f6b82;font-size:.72rem;text-align:center}
.composer{border-top:1px solid #eef1f6;padding:10px;display:flex;gap:8px;align-items:flex-end}
.composer textarea{flex:1;resize:none;min-height:46px;max-height:130px;padding:10px;border:1px solid #cad2e0;border-radius:9px;font:inherit}
button{background:#2f6fed;color:#fff;border:0;border-radius:9px;padding:10px 14px;cursor:pointer;font:inherit;transition:transform .1s ease,filter .1s ease,opacity .15s}
button:active:not(:disabled){transform:scale(.97)}
button.secondary{background:#e9eefb;color:#2f489f}
button.secondary:active:not(:disabled){transform:scale(.97)}
button:disabled{opacity:.5;cursor:not-allowed}
input,select,textarea{padding:9px;border:1px solid #cad2e0;border-radius:8px;font:inherit}
form{margin:0}
.empty{flex:1;display:flex;align-items:center;justify-content:center;color:#7a8296;padding:30px;text-align:center}
#send-error{color:#b00020;font-size:.8rem;padding:0 10px 6px}
.sidebar-foot{padding:10px 12px;border-top:1px solid #eef1f6;text-align:center;flex:0 0 auto}
.link-btn{background:none;border:0;color:#7a8296;font-size:.76rem;padding:4px 8px;cursor:pointer;text-decoration:underline;transition:color .1s,transform .1s}
.link-btn:hover{color:#2f489f}
.link-btn:active{transform:scale(.95);color:#1a3a8f}
.modal{position:fixed;inset:0;background:rgba(15,20,35,.72);display:flex;align-items:center;justify-content:center;z-index:60;padding:24px}
.modal[hidden]{display:none}
.modal-box{position:relative;background:#fff;border-radius:14px;max-width:520px;width:100%;max-height:86vh;overflow:auto;padding:24px 26px;box-shadow:0 18px 50px rgba(0,0,0,.4);text-align:center;color:#4a5266;font-size:.86rem;line-height:1.6}
.modal-box h2{margin:0 0 12px;font-size:1.05rem;color:#20242e}
.modal-box p{margin:9px 0}
.modal-box strong{color:#20242e}
.password-box{max-width:560px;width:100%;padding:20px;text-align:left}
.password-frame{width:100%;height:560px;border:0;border-radius:8px;background:#fff}
.device-panel{color:#333;text-align:left}
.device-panel h1{margin:0 0 4px;font-size:1.15rem;color:#20242e}
.device-panel .sub{margin:0 0 12px;font-size:.82rem;color:#7a8296}
.device-panel p{margin:8px 0;line-height:1.5;font-size:.92rem}
.device-panel label{display:block;margin:12px 0 5px;font-size:.9rem;font-weight:600;color:#20242e}
.device-panel input{width:100%;padding:10px 11px;border:1px solid #d3d9e4;border-radius:8px;font-size:1rem;box-sizing:border-box}
.device-panel .actions{display:flex;gap:10px;margin-top:12px}
.device-panel .actions button{flex:1;margin-top:0}
.device-panel .state{font-size:.85rem;margin:10px 0}
.device-panel .msg,.device-panel .ok{padding:10px;border-radius:8px;margin:12px 0}
.device-panel .msg{background:#fdecea;color:#b00020}
.device-panel .ok{background:#e8f6ec;color:#1d6b32}
.device-panel .password{display:block;font-family:ui-monospace,monospace;font-size:1.25rem;letter-spacing:2px;background:#f7f9fd;border:1px dashed #c7d0e0;border-radius:8px;padding:12px;text-align:center;margin:8px 0;user-select:all}
.device-panel a{color:#2f6fed}
.modal-close{position:absolute;top:8px;right:10px;width:26px;height:26px;padding:0;border:0;border-radius:50%;background:#e9eefb;color:#2f489f;font-size:16px;line-height:1;cursor:pointer}
/* Miembros del grupo: conectados primero (esfera verde), el resto en rojo. */
.members-box{text-align:left;max-width:420px}
.members-sub{font-size:.78rem;color:#7a8296;margin:-6px 0 10px}
.members-id{font-size:.7rem;color:#a2a9bb;margin:-8px 0 10px;word-break:break-all;font-family:ui-monospace,Menlo,Consolas,monospace}
.members-list{display:flex;flex-direction:column}
.member-row{display:flex;align-items:center;gap:9px;padding:7px 2px;border-bottom:1px solid #f1f4fa}
.member-row:last-child{border-bottom:0}
.members-list .dot{width:10px;height:10px}
.member-row .avatar{width:28px;height:28px;font-size:.75rem}
.member-name{flex:1;min-width:0;font-weight:600;font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.member-role{font-size:.7rem;color:#7a8296;flex:0 0 auto}
.member-role.admin{color:#2f6fed}
@media(max-width:760px){.app{grid-template-columns:1fr;height:auto}.sidebar{max-height:52vh}.messages{height:50vh}}
</style></head><body><div class="wrap">
<div class="top">
  <div class="top-user">
    <span class="my-photo-wrap" id="my-photo-wrap">
      <button type="button" class="my-photo" id="my-photo" title="Ver mi foto" aria-label="Ver mi foto">
        <span class="avatar-wrap" id="my-avatar-wrap"><span class="avatar big fallback">?</span><span class="dot" id="my-dot" title="Tu estado"></span></span>
      </button>
      <button type="button" class="photo-btn photo-edit" id="edit-photo" title="Cambiar mi foto" aria-label="Cambiar mi foto">
        <svg viewBox="0 0 24 24" width="11" height="11" fill="currentColor" aria-hidden="true"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
      </button>
      <button type="button" class="photo-btn photo-x" id="clear-photo" title="Quitar mi foto" aria-label="Quitar mi foto">×</button>
    </span>
    <span class="who">Logged in as: <b><?= h($nick) ?></b></span>
    <button type="button" id="lang-toggle" onclick="window.location.href='?lang=<?= $lang === 'en' ? 'es' : 'en' ?>'" style="margin-left:8px;background:none;border:0;color:#7a8296;font-size:.76rem;padding:4px 8px;cursor:pointer"><span style="font-weight:<?= $lang === 'en' ? 'bold' : 'normal' ?>">EN</span> - <span style="font-weight:<?= $lang === 'es' ? 'bold' : 'normal' ?>">ES</span></button>
  </div>
  <div class="top-status">
    <select id="status-mood" title="Mood (StatusMood)">
      <option value=""><?= t('mood') ?></option>
      <option value="HAPPY"><?= t('happy') ?></option>
      <option value="SAD"><?= t('sad') ?></option>
      <option value="ANGRY"><?= t('angry') ?></option>
      <option value="JEALOUS"><?= t('jealous') ?></option>
      <option value="ASHAMED"><?= t('ashamed') ?></option>
      <option value="INVINCIBLE"><?= t('invincible') ?></option>
      <option value="IN_LOVE"><?= t('in_love') ?></option>
      <option value="SLEEPY"><?= t('sleepy') ?></option>
      <option value="BORED"><?= t('bored') ?></option>
      <option value="EXCITED"><?= t('excited') ?></option>
      <option value="ANXIOUS"><?= t('anxious') ?></option>
    </select>
    <select id="status-code" title="<?= t('availability') ?>">
      <option value="AVAILABLE"><?= t('available') ?></option>
      <option value="DISCREET"><?= t('discreet') ?></option>
      <option value="INVISIBLE"><?= t('invisible') ?></option>
    </select>
    <input id="status-text" placeholder="<?= t('about_me') ?>" title="<?= t('about_me') ?> (StatusText)">
    <button class="secondary" id="save-status" type="button"><?= t('save_status') ?></button>
    <form method="post"><input type="hidden" name="action" value="logout"><button class="secondary" type="submit"><?= t('logout') ?></button></form>
  </div>
  <input type="file" id="photo-input" accept="image/*" hidden>
</div>

<div class="app">
  <div class="card sidebar">
    <div class="tabs" role="tablist" aria-label="Secciones">
      <button type="button" class="tab active" data-tab="agenda" role="tab" aria-selected="true"><?= t('contacts') ?> <span class="tab-badge" id="agenda-badge" hidden></span></button>
      <button type="button" class="tab" data-tab="conv" role="tab" aria-selected="false"><?= t('conversations') ?> <span class="tab-badge" id="conv-badge" hidden></span></button>
    </div>
    <div class="pane" id="pane-conv">
      <div class="contact-list" id="conversations"></div>
    </div>
    <div class="pane" id="pane-agenda" hidden>
      <div class="sidebar-head">
        <form id="add-contact-form" style="display:flex;gap:6px">
          <input id="new-contact" placeholder="<?= t('add_contact_by_nick') ?>" style="flex:1;min-width:0">
          <button type="submit">+</button>
        </form>
      </div>
      <div class="contact-list" id="contacts"></div>
      <div class="group-head">
        <span><?= t('groups') ?></span>
        <button type="button" class="link-btn" id="new-group-btn"><?= t('create_group') ?></button>
      </div>
      <div class="group-list" id="groups"></div>
    </div>
    <div class="sidebar-foot">
      <button type="button" class="link-btn" id="password-btn"><?= t('phone') ?></button>
      <button type="button" class="link-btn" id="about-btn">About IMPS</button>
    </div>
  </div>

  <div class="card chat">
    <div class="chat-head">
      <span class="avatar-wrap" id="active-avatar-wrap">
        <span class="avatar big fallback" id="active-avatar">?</span>
        <span class="dot" id="active-dot"></span>
      </span>
      <div>
        <h2 id="active-name"><?= t('select_friend') ?></h2>
        <div class="sub" id="active-status"></div>
      </div>
      <div class="chat-actions">
        <button class="secondary" id="block-button" type="button" disabled><?= t('block') ?></button>
        <button class="secondary" id="clear-chat" type="button" disabled title="Delete messages in this conversation">Clear chat</button>
        <button class="secondary" id="group-add" type="button" hidden>Add member</button>
        <button class="secondary" id="group-members" type="button" title="View group members and who is online" hidden>Members</button>
        <button class="secondary" id="group-clear" type="button" title="<?= t('clear_chat') ?>" hidden><?= t('clear_chat') ?></button>
        <button class="secondary" id="group-leave" type="button" title="<?= t('leave_group_stays') ?>" hidden><?= t('leave_group') ?></button>
      </div>
    </div>
    <div class="messages" id="messages">
      <div class="empty" id="empty"><?= t('select_contact_chat') ?></div>
    </div>
    <div id="send-error"></div>
    <form class="composer" id="send-form">
      <textarea id="composer" rows="1" placeholder="<?= t('type_message') ?>" disabled></textarea>
      <button type="submit" id="send-button" disabled><?= t('send') ?></button>
    </form>
  </div>
</div>
</div>

<div id="about-modal" class="modal" hidden>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="about-title">
    <button type="button" class="modal-close" id="about-close" aria-label="<?= t('close') ?>">×</button>
    <h2 id="about-title"><?= t('about_title') ?></h2>
    <p><?= t('about_p1') ?></p>
    <p><?= t('about_p2') ?></p>
    <p><?= t('about_p3') ?></p>
    <p><?= t('about_p4') ?></p>
  </div>
</div>

<div id="password-modal" class="modal" hidden>
  <div class="modal-box password-box" role="dialog" aria-modal="true" aria-labelledby="password-title">
    <button type="button" class="modal-close" id="password-close" aria-label="<?= t('close') ?>">×</button>
    <h2 id="password-title"><?= t('phone') ?></h2>
    <div id="password-panel"></div>
  </div>
</div>

<div id="members-modal" class="modal" hidden>
  <div class="modal-box members-box" role="dialog" aria-modal="true" aria-labelledby="members-title">
    <button type="button" class="modal-close" id="members-close" aria-label="<?= t('close') ?>">×</button>
    <h2 id="members-title"><?= t('members') ?></h2>
    <div class="members-sub" id="members-sub"></div>
    <div class="members-id" id="members-id"></div>
    <div class="members-list" id="members-list"></div>
  </div>
</div>

<div id="avatar-modal" class="avatar-modal" hidden>
  <img id="avatar-modal-img" alt="Imagen de perfil">
</div>

<script>
const L = <?= json_encode($T[$lang]) ?>;
const ME = <?= json_encode($nick) ?>;
let contacts = [];
let active = <?= json_encode($recipient !== '' ? $recipient : null) ?>;
let activeLastId = 0;
let globalLastId = <?= (int)$lastId ?>;
const localUnread = {};
const contactsBox = document.getElementById('contacts');
// Sidebar en dos pestanas: Conversaciones (abiertas por mi, persistentes) y
// Agenda (todos los contactos y grupos, como el movil).
let conversations = [];
const convBox = document.getElementById('conversations');
const convBadge = document.getElementById('conv-badge');
const agendaBadge = document.getElementById('agenda-badge');
const paneConv = document.getElementById('pane-conv');
const paneAgenda = document.getElementById('pane-agenda');
const messagesBox = document.getElementById('messages');
const emptyBox = document.getElementById('empty');
const activeName = document.getElementById('active-name');
const activeStatus = document.getElementById('active-status');
const activeDot = document.getElementById('active-dot');
const activeAvatarWrap = document.getElementById('active-avatar-wrap');
const avatarModal = document.getElementById('avatar-modal');
const avatarModalImg = document.getElementById('avatar-modal-img');
const aboutModal = document.getElementById('about-modal');
const photoInput = document.getElementById('photo-input');
const myAvatarWrap = document.getElementById('my-avatar-wrap');
// El punto de mi propio estado (verde/rojo/gris), sobre mi foto de la barra.
const myDot = document.getElementById('my-dot');
let myAvatar = false;
let myAvatarVer = '';
// Chat grupal (CSP 1.2 §10). active y activeGroup son excluyentes: o hablas con
// un amigo o con un grupo.
let groups = [];
let groupInvites = [];
let activeGroup = null;
let activeGroupLastId = 0;
const groupsBox = document.getElementById('groups');
const groupAddBtn = document.getElementById('group-add');
const groupLeaveBtn = document.getElementById('group-leave');
// Grupo: limpiar el chat y ver la lista de miembros (conectados en verde).
const groupMembersBtn = document.getElementById('group-members');
const groupClearBtn = document.getElementById('group-clear');
const membersModal = document.getElementById('members-modal');
const membersList = document.getElementById('members-list');
const membersSub = document.getElementById('members-sub');
const membersId = document.getElementById('members-id');
let membersTimer = null;
const composer = document.getElementById('composer');
const sendButton = document.getElementById('send-button');
const sendError = document.getElementById('send-error');
const blockButton = document.getElementById('block-button');
const clearChatButton = document.getElementById('clear-chat');

function esc(s){ return s === null || s === undefined ? '' : String(s); }

const MOOD_LABELS = {HAPPY:L.happy,SAD:L.sad,ANGRY:L.angry,JEALOUS:L.jealous,ASHAMED:L.ashamed,INVINCIBLE:L.invincible,IN_LOVE:L.in_love,SLEEPY:L.sleepy,BORED:L.bored,EXCITED:L.excited,ANXIOUS:L.anxious};
function statusLine(c){
  const parts = [];
  if(c.status_mood && MOOD_LABELS[c.status_mood]) parts.push(MOOD_LABELS[c.status_mood]);
  if(c.status_text) parts.push(c.status_text);
  if(!parts.length){
    // Sin animo ni "sobre mi": al menos la disponibilidad, para que el texto
    // cuadre con el color del punto.
    if(!c.online) parts.push('Desconectado');
    else if(!c.status_code || c.status_code === 'AVAILABLE') parts.push('Online');
    else if(c.status_code === 'INVISIBLE') parts.push('Invisible');
    else parts.push('Comunica');
  }
  return parts.join(' · ');
}

function contactData(nick){
  return contacts.find(c => c.nick === nick) || {nick, online:false, status_code:'AVAILABLE', status_mood:'', status_text:'', unread:0, blocked:false, avatar:false, avatar_ver:''};
}

// Punto de estado, el mismo criterio en toda la web:
//   gris  = desconectado (o invisible: te ven desconectado)
//   verde = conectado y disponible
//   rojo  = conectado pero "Comunica" (DISCREET) o no disponible (NOT_AVAILABLE)
function dotClass(online, code){
  if(!online) return 'dot';
  if(!code || code === 'AVAILABLE') return 'dot online';
  if(code === 'INVISIBLE') return 'dot';
  return 'dot busy';
}

// El punto de mi propio estado, sobre mi foto de la barra de arriba.
function updateMyDot(code){
  if(!myDot) return;
  myDot.className = dotClass(true, code);
  myDot.title = 'You: ' + dotTitle(true, code);
}

// Texto para el title del punto (sobre todo el mio, que no lleva etiqueta).
function dotTitle(online, code){
  if(!online) return 'Desconectado';
  if(!code || code === 'AVAILABLE') return 'Conectado (disponible)';
  if(code === 'INVISIBLE') return 'Invisible: te ven desconectado';
  return 'Conectado: comunica / no disponible';
}

// Crea el avatar de un contacto: su imagen de estado (WV StatusContent) si la
// tiene, o un circulo con la inicial como reserva.
function avatarEl(c, big){
  const span = document.createElement('span');
  span.className = 'avatar' + (big ? ' big' : '');
  const nick = (c && c.nick) || '';
  if(c && c.avatar){
    const img = document.createElement('img');
    img.alt = '';
    img.src = 'avatar.php?nick=' + encodeURIComponent(nick) + '&v=' + encodeURIComponent(c.avatar_ver || '');
    img.addEventListener('error', () => {
      span.classList.add('fallback');
      span.textContent = (nick || '?').charAt(0).toUpperCase();
    });
    span.appendChild(img);
  } else {
    span.classList.add('fallback');
    span.textContent = (nick || '?').charAt(0).toUpperCase();
  }
  return span;
}

// Mi propia foto (80x60, igual que la del movil). Se sube ya reducida desde el
// navegador con un canvas y se guarda como StatusContent, asi que tambien la ve
// el movil por GetPresence-Response.
function renderMyAvatar(){
  const span = document.createElement('span');
  span.className = 'avatar big';
  if(myAvatar){
    const img = document.createElement('img');
    img.alt = 'Mi foto';
    img.src = 'avatar.php?nick=' + encodeURIComponent(ME) + '&v=' + encodeURIComponent(myAvatarVer);
    img.addEventListener('error', () => { span.classList.add('fallback'); span.textContent = ME.charAt(0).toUpperCase(); });
    span.appendChild(img);
  } else {
    span.classList.add('fallback');
    span.textContent = ME.charAt(0).toUpperCase();
  }
  // El punto va despues de la foto: replaceChildren se lo llevaria por delante.
  myAvatarWrap.replaceChildren(span, myDot);
  // La "x" para quitar la foto solo se muestra si hay foto (y al pasar el raton).
  const box = document.getElementById('my-photo-wrap');
  if(box) box.classList.toggle('has-photo', !!myAvatar);
  const btn = document.getElementById('my-photo');
  if(btn){
    const label = myAvatar ? 'Ver mi foto' : 'Poner una foto';
    btn.title = label;
    btn.setAttribute('aria-label', label);
  }
}

// Reduce cualquier imagen a 80x60 (recorte centrado) y la devuelve como JPEG en
// data URL. Baja la calidad si hace falta para que el base64 no pase de 4500
// caracteres: por encima de 4700 el movil ya no recibe la imagen
// (status_content_xml la descarta para no pasarse de su ParserSize de 6000).
function shrinkTo80x60(file){
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      try {
        const W = 80, H = 60;
        const c = document.createElement('canvas');
        c.width = W; c.height = H;
        const ctx = c.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, W, H);
        const sw = img.naturalWidth, sh = img.naturalHeight;
        if(!sw || !sh) throw new Error('sin dimensiones');
        const scale = Math.max(W / sw, H / sh);
        const dw = sw * scale, dh = sh * scale;
        ctx.drawImage(img, (W - dw) / 2, (H - dh) / 2, dw, dh);
        const prefix = 'data:image/jpeg;base64,';
        let q = 0.85;
        let out = c.toDataURL('image/jpeg', q);
        while(out.length - prefix.length > 4500 && q > 0.3){
          q = Math.max(0.3, q - 0.15);
          out = c.toDataURL('image/jpeg', q);
        }
        resolve(out);
      } catch(err){ reject(err); }
      finally { URL.revokeObjectURL(url); }
    };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('invalid image')); };
    img.src = url;
  });
}

function updateActiveAvatar(c){
  const av = avatarEl(c, true);
  if(c && c.avatar){
    av.style.cursor = 'zoom-in';
    av.title = 'View full size image';
    av.addEventListener('click', () => openAvatarZoom(c.nick, c.avatar_ver));
  }
  activeAvatarWrap.replaceChildren(av, activeDot);
}

// Muestra una imagen de perfil en grande (4x el tamano original) y sin el
// recorte circular. Se cierra al hacer clic o con Esc.
function openAvatarZoom(nick, ver){
  if(!nick) return;
  avatarModalImg.onload = () => {
    const w = (avatarModalImg.naturalWidth || 80) * 4;
    const h = (avatarModalImg.naturalHeight || 60) * 4;
    avatarModalImg.style.width = w + 'px';
    avatarModalImg.style.height = h + 'px';
  };
  avatarModalImg.onerror = () => { avatarModal.hidden = true; };
  avatarModalImg.src = 'avatar.php?nick=' + encodeURIComponent(nick) + '&v=' + encodeURIComponent(ver || '');
  avatarModal.hidden = false;
}
function closeAvatarZoom(){ avatarModal.hidden = true; }

// --- Sidebar tabs ---------------------------------------------------
function switchTab(name){
  document.querySelectorAll('.tab').forEach(b => {
    const on = b.dataset.tab === name;
    b.classList.toggle('active', on);
    b.setAttribute('aria-selected', on ? 'true' : 'false');
  });
  paneConv.hidden = name !== 'conv';
  paneAgenda.hidden = name !== 'agenda';
}
document.querySelectorAll('.tab').forEach(b => b.addEventListener('click', () => switchTab(b.dataset.tab)));
// Sincronizar panes con la pestaña activa al cargar (si no, el tab activo
// no coincide con el pane visible).
switchTab(document.querySelector('.tab.active')?.dataset.tab || 'agenda');

// --- Conversaciones abiertas -------------------------------------------------
// La web mantiene las conversaciones (el movil las vacia al desconectar): se
// abren al pulsar un contacto/grupo y solo se cierran con la X. El servidor
// ordena por el ultimo mensaje.
function timeShort(s){
  if(!s) return '';
  const d = new Date(String(s).replace(' ', 'T'));
  if(isNaN(d.getTime())) return '';
  if(d.toDateString() === new Date().toDateString()){
    return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
  }
  return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0');
}

function convPreview(cv){
  if(!cv.last) return L.no_messages_yet;
  if(cv.last_kind && cv.last_kind !== 'msg') return cv.last;
  const mio = cv.last_from === ME;
  if(cv.kind === 'group') return (mio ? 'you' : cv.last_from) + ': ' + cv.last;
  return mio ? L.you + ': ' + cv.last : cv.last;
}

function updateTabBadges(){
  const total = conversations.reduce((a, c) => a + (c.unread || 0), 0);
  convBadge.textContent = total > 99 ? '99+' : String(total);
  convBadge.hidden = total === 0;
  const inv = (groupInvites || []).length;
  agendaBadge.textContent = String(inv);
  agendaBadge.hidden = inv === 0;
  agendaBadge.title = inv ? 'Invitaciones de grupo pendientes' : '';
}

function renderConversations(){
  convBox.innerHTML = '';
  if(!conversations.length){
    const d = document.createElement('div');
    d.className = 'contact-status';
    d.style.padding = '12px 10px';
    d.textContent = 'No tienes conversaciones abiertas.';
    convBox.appendChild(d);
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'link-btn';
    b.style.margin = '0 10px';
    b.textContent = 'Abrir una desde la Agenda';
    b.addEventListener('click', () => switchTab('agenda'));
    convBox.appendChild(b);
    updateTabBadges();
    return;
  }
  conversations.forEach(cv => {
    const esGrupo = cv.kind === 'group';
    const abierta = esGrupo ? (activeGroup === cv.target) : (!activeGroup && active === cv.target);
    const item = document.createElement('div');
    item.className = 'contact-item' + (abierta ? ' active' : '') + (esGrupo && !cv.joined ? ' out' : '');
    const wrap = document.createElement('span');
    wrap.className = 'avatar-wrap';
    if(esGrupo){
      const icon = document.createElement('span');
      icon.className = 'group-icon' + (cv.joined ? ' icon-in' : '');
      icon.textContent = '#';
      wrap.appendChild(icon);
    } else {
      wrap.appendChild(avatarEl({nick: cv.target, avatar: cv.avatar, avatar_ver: cv.avatar_ver}, false));
      const dot = document.createElement('span');
      dot.className = dotClass(cv.online, cv.status_code);
      dot.title = dotTitle(cv.online, cv.status_code);
      wrap.appendChild(dot);
    }
    const main = document.createElement('div');
    main.className = 'contact-main';
    const name = document.createElement('div');
    name.className = 'contact-name';
    name.textContent = cv.name + (cv.blocked ? ' 🚫' : '');
    const st = document.createElement('div');
    st.className = 'contact-status';
    st.textContent = (esGrupo && !cv.joined) ? 'Has salido · pulsa para entrar' : convPreview(cv);
    main.appendChild(name); main.appendChild(st);
    item.appendChild(wrap); item.appendChild(main);
    const t = document.createElement('span');
    t.className = 'conv-time';
    t.textContent = timeShort(cv.last_at);
    item.appendChild(t);
    if(cv.unread > 0){
      const b = document.createElement('span');
      b.className = 'badge';
      b.textContent = cv.unread > 99 ? '99+' : cv.unread;
      item.appendChild(b);
    }
    // Cerrar la conversacion: no borra historial ni sale del grupo, solo la
    // quita de esta lista (se vuelve a abrir desde la Agenda).
    const rm = document.createElement('button');
    rm.type = 'button';
    rm.className = 'remove';
    rm.textContent = '×';
    rm.title = L.close_conversation;
    rm.setAttribute('aria-label', L.close_conversation + ' with ' + cv.name);
    rm.addEventListener('click', (e) => { e.stopPropagation(); closeConversation(cv.kind, cv.target); });
    item.appendChild(rm);
    item.addEventListener('click', () => { if(esGrupo) openGroup(cv.target); else openConversation(cv.target); });
    convBox.appendChild(item);
  });
  updateTabBadges();
}

async function loadConversations(){
  try {
    const res = await fetch('api.php?action=conv_list', {cache:'no-store', credentials:'same-origin'});
    if(!res.ok) return;
    const data = await res.json();
    conversations = data.conversations || [];
    renderConversations();
  } catch(e){}
}

function closeActiveWindow(){
  active = null;
  activeGroup = null;
  activeLastId = 0;
  activeGroupLastId = 0;
  messagesBox.innerHTML = '';
  const e = document.createElement('div');
  e.className = 'empty';
  e.textContent = L.select_contact_or_group;
  messagesBox.appendChild(e);
  updateActiveHeader();
  updateComposerState();
  renderContacts();
  renderGroups();
}

async function closeConversation(kind, target){
  try {
    await fetch('api.php', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({action:'conv_close', kind, target})});
  } catch(e){}
  const abierta = kind === 'group' ? (activeGroup === target) : (!activeGroup && active === target);
  if(abierta) closeActiveWindow();
  await loadConversations();
}

function renderContacts(){
  contactsBox.innerHTML = '';
  if(!contacts.length){
    const d = document.createElement('div');
    d.className = 'contact-status';
    d.style.padding = '10px';
    d.textContent = L.no_contacts_yet;
    contactsBox.appendChild(d);
  }
  contacts.forEach(c => {
    const item = document.createElement('div');
    item.className = 'contact-item' + (c.nick === active ? ' active' : '');
    const dot = document.createElement('span');
    dot.className = dotClass(c.online, c.status_code);
    dot.title = dotTitle(c.online, c.status_code);
    const wrap = document.createElement('span');
    wrap.className = 'avatar-wrap';
    wrap.appendChild(avatarEl(c, false));
    wrap.appendChild(dot);
    const main = document.createElement('div');
    main.className = 'contact-main';
    const name = document.createElement('div');
    name.className = 'contact-name';
    name.textContent = c.nick;
    const st = document.createElement('div');
    st.className = 'contact-status';
    st.textContent = statusLine(c);
    main.appendChild(name); main.appendChild(st);
    item.appendChild(wrap); item.appendChild(main);
    if(c.blocked){
      const bk = document.createElement('span');
      bk.textContent = '🚫';
      bk.title = 'Bloqueado';
      item.appendChild(bk);
    }
    const unread = localUnread[c.nick] !== undefined ? localUnread[c.nick] : (c.unread || 0);
    if(unread > 0){
      const b = document.createElement('span');
      b.className = 'badge';
      b.textContent = unread > 99 ? '99+' : unread;
      item.appendChild(b);
    }
    // "x" roja (al pasar el raton) para quitar el contacto de la lista.
    const rm = document.createElement('button');
    rm.type = 'button';
    rm.className = 'remove';
    rm.textContent = '×';
    rm.title = 'Quitar de la lista';
    rm.setAttribute('aria-label', 'Quitar a ' + c.nick + ' de la lista');
    rm.addEventListener('click', (e) => { e.stopPropagation(); removeContact(c.nick); });
    item.appendChild(rm);
    item.addEventListener('click', () => openConversation(c.nick));
    contactsBox.appendChild(item);
  });
  updateActiveHeader();
}

function updateActiveHeader(){
  if(activeGroup){
    const g = groupData(activeGroup);
    activeName.textContent = g.name;
    // El rol en el grupo: el dato lo manda ya la lista de grupos (privilege).
    const rol = g.privilege === 'Admin' ? ' · ' + L.you_are_admin : (g.privilege === 'Mod' ? ' · ' + L.you_are_mod : '');
    activeStatus.textContent = 'Grupo · ' + (g.joined
      ? (g.inside || 0) + ' ' + L.inside_of + ' ' + g.members
      : g.members + (g.members === 1 ? ' miembro' : ' miembros') + ' · not inside') + rol;
    activeDot.className = 'dot';
    const av = document.createElement('span');
    // Verde si estoy dentro del grupo, como en el sidebar.
    av.className = 'avatar big fallback' + (g.joined ? ' icon-in' : '');
    av.textContent = '#';
    activeAvatarWrap.replaceChildren(av, activeDot);
    updateGroupButtons();
    updateComposerState();
    return;
  }
  if(!active){ activeName.textContent = L.select_friend; activeStatus.textContent = ''; activeDot.className='dot'; blockButton.disabled = true; blockButton.textContent = L.block; clearChatButton.disabled = true; updateActiveAvatar(null); updateGroupButtons(); updateComposerState(); return; }
  const c = contactData(active);
  activeName.textContent = c.nick;
  activeStatus.textContent = statusLine(c) + (c.blocked ? ' · 🚫 bloqueado' : '');
  activeDot.className = dotClass(c.online, c.status_code);
  activeDot.title = dotTitle(c.online, c.status_code);
  blockButton.disabled = false;
  blockButton.textContent = c.blocked ? L.unblock : L.block;
  clearChatButton.disabled = false;
  updateActiveAvatar(c);
  updateGroupButtons();
  updateComposerState();
}

// Si has bloqueado a este amigo, se desactiva la escritura hasta desbloquearlo.
function updateComposerState(){
  if(activeGroup){
    composer.disabled = false;
    sendButton.disabled = false;
    composer.placeholder = 'Escribe al grupo...';
    return;
  }
  const c = active ? contactData(active) : null;
  const blocked = !!(c && c.blocked);
  composer.disabled = !active || blocked;
  sendButton.disabled = !active || blocked;
  composer.placeholder = blocked
    ? 'Has bloqueado a ' + active + '. Unblock to send messages.'
    : L.type_message;
}

function messageEl(m, isGroup){
  const div = document.createElement('div');
  div.className = 'msg ' + (m.from === ME ? 'me' : 'them');
  div.dataset.id = (isGroup ? 'g' : '') + m.id;
  if(isGroup && m.from !== ME){
    const who = document.createElement('div');
    who.className = 'who';
    who.textContent = m.from;
    div.appendChild(who);
  }
  const bubble = document.createElement('div');
  bubble.className = 'bubble';
  bubble.textContent = m.body;
  const meta = document.createElement('div');
  meta.className = 'meta';
  meta.textContent = m.created_at + (m.from === ME ? ' · you' : '');
  div.appendChild(bubble); div.appendChild(meta);
  return div;
}

// Quita el cartel de "no hay mensajes" (el fijo y los que se crean al abrir
// una conversacion vacia): si llega o se envia un mensaje ya no pinta.
function clearEmptyPlaceholder(){
  messagesBox.querySelectorAll('.empty').forEach(e => e.remove());
}

function appendActive(m){
  if(!active) return;
  if(document.querySelector('#messages .msg[data-id="'+m.id+'"]')) return;
  clearEmptyPlaceholder();
  messagesBox.appendChild(messageEl(m));
  if(m.id > activeLastId) activeLastId = m.id;
}

function scrollBottom(){ messagesBox.scrollTop = messagesBox.scrollHeight; }

async function openConversation(nick){
  activeGroup = null;
  active = nick;
  activeLastId = 0;
  localUnread[nick] = 0;
  messagesBox.innerHTML = '';
  renderContacts();
  renderGroups();
  updateComposerState();
  composer.focus();
  try {
    const res = await fetch('api.php?action=conversation&with=' + encodeURIComponent(nick) + '&since=0', {cache:'no-store', credentials:'same-origin'});
    const data = await res.json();
    (data.messages || []).forEach(appendActive);
    if(!(data.messages || []).length){
      const e = document.createElement('div');
      e.className = 'empty';
      e.textContent = L.no_messages_with + ' ' + nick + '.';
      messagesBox.appendChild(e);
    }
    scrollBottom();
  } catch(e){}
  loadContacts();
  loadConversations();
}

// Quita un contacto de la lista (con confirmacion).
async function removeContact(nick){
  if(!confirm(L.remove_from_contacts.replace('%s', nick))) return;
  try {
    await fetch('api.php', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({action:'remove_contact', nick})});
  } catch(e){}
  delete localUnread[nick];
  if(active === nick){
    active = null;
    activeLastId = 0;
    messagesBox.innerHTML = '';
    const e = document.createElement('div');
    e.className = 'empty';
    e.textContent = L.select_contact_chat;
    messagesBox.appendChild(e);
  }
  await loadContacts();
  loadConversations();
}

async function pollActive(){
  if(activeGroup){
    try {
      const res = await fetch('api.php?action=group_messages&id=' + encodeURIComponent(activeGroup) + '&since=' + activeGroupLastId, {cache:'no-store', credentials:'same-origin'});
      if(!res.ok) return;
      const data = await res.json();
      const list = data.messages || [];
      list.forEach(appendGroupMsg);
      if(list.length) scrollBottom();
    } catch(e){}
    return;
  }
  if(!active) return;
  try {
    const res = await fetch('api.php?action=conversation&with=' + encodeURIComponent(active) + '&since=' + activeLastId, {cache:'no-store', credentials:'same-origin'});
    if(!res.ok) return;
    const data = await res.json();
    const list = data.messages || [];
    list.forEach(appendActive);
    if(list.length) scrollBottom();
  } catch(e){}
}

async function pollGlobal(){
  try {
    const res = await fetch('api.php?action=messages&since=' + globalLastId, {cache:'no-store', credentials:'same-origin'});
    if(!res.ok) return;
    const data = await res.json();
    (data.messages || []).forEach(m => {
      if(m.id > globalLastId) globalLastId = m.id;
      if(m.from === active || m.to === active) return;
      if(m.from !== ME){
        localUnread[m.from] = (localUnread[m.from] || 0) + 1;
        renderContacts();
        // Si quien escribe no estaba en la lista, el servidor lo ha anadido:
        // recargamos para que aparezca con su aviso.
        if(!contacts.some(c => c.nick === m.from)) loadContacts();
      }
    });
  } catch(e){}
}

async function loadContacts(){
  try {
    const res = await fetch('api.php?action=contacts', {cache:'no-store', credentials:'same-origin'});
    if(!res.ok) return;
    const data = await res.json();
    contacts = data.contacts || [];
    renderContacts();
  } catch(e){}
}

// --- Chat grupal (CSP 1.2 §10) ---------------------------------------------
// Los grupos viven en el servidor (group_messages) y los comparten el movil y
// la web: el movil los recibe por Polling como NewMessage con el GroupID.

function groupData(id){
  return groups.find(g => g.id === id) || {id, name:id, members:0, inside:0, unread:0, joined:false, last:null};
}

function renderGroups(){
  groupsBox.innerHTML = '';
  groupInvites.forEach(inv => {
    const item = document.createElement('div');
    item.className = 'group-item invite';
    const icon = document.createElement('span'); icon.className = 'group-icon'; icon.textContent = '!';
    const main = document.createElement('div'); main.className = 'contact-main';
    const name = document.createElement('div'); name.className = 'contact-name'; name.textContent = inv.name || inv.id;
    const st = document.createElement('div'); st.className = 'contact-status';
    st.textContent = inv.inviter + ' te invita' + (inv.note ? ' · ' + inv.note : '');
    main.appendChild(name); main.appendChild(st);
    const ok = document.createElement('button'); ok.type = 'button'; ok.className = 'mini'; ok.textContent = '✓';
    ok.title = 'Accept invitation';
    ok.addEventListener('click', e => { e.stopPropagation(); respondInvite(inv, true); });
    const no = document.createElement('button'); no.type = 'button'; no.className = 'mini no'; no.textContent = '×';
    no.title = 'Reject invitation';
    no.addEventListener('click', e => { e.stopPropagation(); respondInvite(inv, false); });
    item.appendChild(icon); item.appendChild(main); item.appendChild(ok); item.appendChild(no);
    groupsBox.appendChild(item);
  });
  if(!groups.length && !groupInvites.length){
    const d = document.createElement('div');
    d.className = 'contact-status';
    d.style.padding = '6px 10px';
    d.textContent = 'No groups yet.';
    groupsBox.appendChild(d);
  }
  groups.forEach(g => {
    const item = document.createElement('div');
    item.className = 'group-item' + (g.joined ? '' : ' out') + (g.id === activeGroup ? ' active' : '');
    const icon = document.createElement('span');
    icon.className = 'group-icon' + (g.joined ? ' icon-in' : '');
    icon.textContent = '#';
    const main = document.createElement('div'); main.className = 'contact-main';
    const name = document.createElement('div'); name.className = 'contact-name'; name.textContent = g.name;
    const st = document.createElement('div'); st.className = 'contact-status';
    const cuantos = g.joined
      ? L.inside + ' · ' + (g.inside || 0) + ((g.inside || 0) === 1 ? ' ' + L.person : ' ' + L.people)
      : g.members + (g.members === 1 ? ' ' + L.member : ' ' + L.members) + ' · ' + L.click_to_join;
    const ultimo = g.last
      ? ' · ' + ((g.last.kind && g.last.kind !== 'msg') ? g.last.body : g.last.from + ': ' + g.last.body)
      : '';
    st.textContent = cuantos + ultimo;
    main.appendChild(name); main.appendChild(st);
    item.appendChild(icon); item.appendChild(main);
    if(g.unread > 0){
      const b = document.createElement('span'); b.className = 'badge';
      b.textContent = g.unread > 99 ? '99+' : g.unread;
      item.appendChild(b);
    }
    // La X quita el grupo de MI lista; no es lo mismo que "Salir del grupo"
    // (eso solo cierra la conversacion y el grupo sigue en la agenda).
    const rm = document.createElement('button'); rm.type = 'button'; rm.className = 'remove'; rm.textContent = '×';
    rm.title = 'Quitar de mi lista';
    rm.setAttribute('aria-label', 'Quitar de mi lista el grupo ' + g.name);
    rm.addEventListener('click', e => { e.stopPropagation(); removeGroup(g.id, e.currentTarget); });
    item.appendChild(rm);
    item.addEventListener('click', () => openGroup(g.id));
    groupsBox.appendChild(item);
  });
  updateGroupButtons();
  updateTabBadges();
}

// Con un grupo abierto el "Bloquear"/"Vaciar chat" no aplican: se cambian por
// "Add member"/"Salir del grupo".
function updateGroupButtons(){
  const isGroup = !!activeGroup;
  groupAddBtn.hidden = !isGroup;
  groupLeaveBtn.hidden = !isGroup;
  groupMembersBtn.hidden = !isGroup;
  groupClearBtn.hidden = !isGroup;
  blockButton.hidden = isGroup;
  clearChatButton.hidden = isGroup;
  if(isGroup){
    const g = groupData(activeGroup);
    const dentro = !!(g && g.joined);
    groupLeaveBtn.textContent = dentro ? L.leave_group : L.join_group;
    groupLeaveBtn.title = dentro ? L.leave_group_stays : L.rejoin_group;
  } else {
    closeMembers();
  }
}

// --- Miembros del grupo -----------------------------------------------------
// Los pide al servidor (group_members), que los devuelve ya ordenados: primero
// los conectados ahora (esfera verde) y despues el resto (esfera roja). Se
// refresca solo mientras el panel esta abierto.
async function loadMembers(){
  if(!activeGroup || membersModal.hidden) return;
  const gid = activeGroup;
  try {
    const res = await fetch('api.php?action=group_members&id=' + encodeURIComponent(gid), {cache:'no-store', credentials:'same-origin'});
    const data = await res.json().catch(() => ({}));
    if(activeGroup !== gid || membersModal.hidden) return;
    if(!res.ok || data.error){ membersSub.textContent = data.error || 'No se pudo cargar la lista.'; membersId.textContent = ''; membersList.innerHTML = ''; return; }
    const ms = data.members || [];
    const on = (data.online || 0);
    membersSub.textContent = on + ' conectado' + (on === 1 ? '' : 's') + ' de ' + ms.length +
      (data.name ? ' · ' + data.name : '');
    // El ID del grupo: en el movil es el titulo (no hay otra forma de que lo
    // sepa), asi que desde la web conviene poder verlo.
    membersId.textContent = 'ID: ' + gid;
    membersList.innerHTML = '';
    ms.forEach(m => {
      const row = document.createElement('div'); row.className = 'member-row';
      const dot = document.createElement('span');
      dot.className = dotClass(m.online, m.status_code);
      dot.title = dotTitle(m.online, m.status_code);
      const av = document.createElement('span');
      av.className = 'avatar fallback';
      av.textContent = (m.sname || m.nick || '?').charAt(0);
      const name = document.createElement('span');
      name.className = 'member-name';
      name.textContent = (m.sname && m.sname !== m.nick ? m.sname + ' | ' + m.nick : m.nick) + (m.is_me ? ' (you)' : '');
      row.appendChild(dot); row.appendChild(av); row.appendChild(name);
      if(m.privilege && m.privilege !== 'User'){
        const role = document.createElement('span');
        role.className = 'member-role' + (m.privilege === 'Admin' ? ' admin' : '');
        role.textContent = m.privilege === 'Admin' ? 'Admin' : 'Mod';
        row.appendChild(role);
      }
      membersList.appendChild(row);
    });
    if(!ms.length) membersSub.textContent = 'No hay miembros en este grupo.';
  } catch(e){}
}

async function showMembers(){
  if(!activeGroup) return;
  membersModal.hidden = false;
  membersSub.textContent = 'Cargando…';
  membersId.textContent = '';
  membersList.innerHTML = '';
  await loadMembers();
  if(membersTimer) clearInterval(membersTimer);
  membersTimer = setInterval(loadMembers, 10000);
}

function closeMembers(){
  membersModal.hidden = true;
  if(membersTimer){ clearInterval(membersTimer); membersTimer = null; }
}

async function loadGroups(){
  try {
    const res = await fetch('api.php?action=groups', {cache:'no-store', credentials:'same-origin'});
    if(!res.ok) return;
    const data = await res.json();
    groups = data.groups || [];
    groupInvites = data.invites || [];
    renderGroups();
    // El recuento de "dentro" cambia al entrar/salir: refresca la cabecera.
    if(activeGroup) updateActiveHeader();
  } catch(e){}
}

function appendGroupMsg(m){
  if(!activeGroup) return;
  if(document.querySelector('#messages [data-id="g' + m.id + '"]')) return;
  clearEmptyPlaceholder();
  const esAviso = m.kind && m.kind !== 'msg';
  const div = esAviso ? document.createElement('div') : messageEl(m, true);
  if(esAviso){
    div.className = 'sysmsg';
    div.dataset.id = 'g' + m.id;
    div.textContent = m.body;
  }
  messagesBox.appendChild(div);
  if(m.id > activeGroupLastId) activeGroupLastId = m.id;
}

async function openGroup(id){
  active = null;
  activeGroup = id;
  activeGroupLastId = 0;
  // Optimista: abrir la conversacion es entrar (el servidor lo confirma en la
  // misma peticion). Asi la cabecera no parpadea con "not inside".
  const g0 = groups.find(x => x.id === id);
  if(g0 && !g0.joined){ g0.joined = true; g0.inside = (g0.inside || 0) + 1; }
  messagesBox.innerHTML = '';
  renderContacts();
  renderGroups();
  updateActiveHeader();
  updateComposerState();
  composer.focus();
  try {
    const res = await fetch('api.php?action=group_messages&id=' + encodeURIComponent(id) + '&since=0&open=1', {cache:'no-store', credentials:'same-origin'});
    const data = await res.json();
    if(!res.ok || data.error){ sendError.textContent = data.error || ''; return; }
    (data.messages || []).forEach(appendGroupMsg);
    if(!(data.messages || []).length){
      const e = document.createElement('div');
      e.className = 'empty';
      e.textContent = L.no_messages_group;
      messagesBox.appendChild(e);
    }
    scrollBottom();
  } catch(e){}
  loadGroups();
  loadConversations();
}

async function leaveGroup(id){
  const g = groupData(id);
  if(!confirm(L.leave_group.replace('%s', g.name) + '\n\n' + L.leave_group_stays)) return;
  // Paramos el sondeo ANTES de salir: si una peticion de group_messages
  // quedaba en vuelo, volvia a entrar al grupo y parecia que no habias salido
  // (el icono no se ponia en gris).
  const eraActiva = activeGroup === id;
  if(eraActiva){
    activeGroup = null;
    activeGroupLastId = 0;
  }
  try {
    await fetch('api.php', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({action:'group_leave', id})});
  } catch(e){}
  if(eraActiva){
    messagesBox.innerHTML = '';
    const e = document.createElement('div');
    e.className = 'empty';
    e.textContent = L.select_contact_or_group;
    messagesBox.appendChild(e);
    updateActiveHeader();
    updateComposerState();
  }
  await loadGroups();
  loadConversations();
}

// Quita el grupo de mi lista (la X del sidebar). No es lo mismo que "salir":
// salir deja el grupo en la agenda para volver a entrar; quitarlo lo saca de
// la lista y deja de llegarme nada (para volver, que me inviten otra vez).
async function removeGroup(id, btn){
  const g = groupData(id);
  const isAdmin = g.privilege === 'Admin';
  const solo = (g.members || 0) <= 1;
  let aviso;
  if(isAdmin){
    aviso = L.delete_group.replace('%s', g.name) + '\n\n' + L.admin_delete_warning;
  } else {
    aviso = L.remove_group_from_list.replace('%s', g.name) + '\n\n' +
      L.stop_receiving +
      (solo ? L.group_deleted_no_members : L.need_invite_to_rejoin);
  }
  if(!confirm(aviso)) return;
  if(btn) btn.disabled = true;
  try {
    const res = await fetch('api.php', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({action:'group_remove', id})});
    const data = await res.json().catch(() => ({}));
    if(!res.ok || data.error){ sendError.textContent = data.error || 'No se pudo quitar el grupo'; return; }
  } catch(e){ sendError.textContent = 'Error de red'; return; }
  finally { if(btn) btn.disabled = false; }
  if(activeGroup === id){
    activeGroup = null;
    activeGroupLastId = 0;
    messagesBox.innerHTML = '';
    const e = document.createElement('div');
    e.className = 'empty';
    e.textContent = L.select_contact_or_group;
    messagesBox.appendChild(e);
    updateActiveHeader();
    updateComposerState();
  }
  await loadGroups();
  loadConversations();
}

async function respondInvite(inv, accept){
  try {
    await fetch('api.php', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({action:'group_invite_response', invite_id: inv.invite_id, id: inv.id, accept})});
  } catch(e){}
  await loadGroups();
  if(accept) openGroup(inv.id);
}

document.getElementById('new-group-btn').addEventListener('click', async () => {
  const name = prompt('Nombre del grupo:');
  if(!name || !name.trim()) return;
  try {
    const res = await fetch('api.php', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({action:'create_group', name: name.trim()})});
    const data = await res.json();
    if(!res.ok || data.error){ alert(data.error || 'No se pudo crear el grupo'); return; }
    await loadGroups();
    openGroup(data.id);
  } catch(e){ alert('Error de red'); }
});

groupAddBtn.addEventListener('click', async () => {
  if(!activeGroup) return;
  const nick = prompt('Nick del amigo al que quieres invitar al grupo:');
  if(!nick || !nick.trim()) return;
  try {
    const res = await fetch('api.php', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({action:'group_add_member', id: activeGroup, nick: nick.trim()})});
    const data = await res.json();
    if(!res.ok || data.error){ alert(data.error || 'No se pudo invitar'); return; }
    // No entra de golpe: le llega la invitacion y tiene que aceptarla, igual
    // que cuando invitas desde el movil.
    if(data.already) alert(L.already_in_group.replace('%s', data.nick));
    else alert(L.invitation_sent.replace('%s', data.nick));
    await loadGroups();
    openGroup(activeGroup);
  } catch(e){}
});

groupLeaveBtn.addEventListener('click', () => {
  if(!activeGroup) return;
  const g = groupData(activeGroup);
  // Si ya estamos fuera, el mismo boton vuelve a entrar (antes no hacia nada y
  // parecia que "salir" no funcionaba).
  if(g && g.joined) leaveGroup(activeGroup);
  else openGroup(activeGroup);
});

// Miembros del grupo (conectados en verde, el resto en rojo).
groupMembersBtn.addEventListener('click', showMembers);
document.getElementById('members-close').addEventListener('click', closeMembers);
membersModal.addEventListener('click', (e) => { if(e.target === membersModal) closeMembers(); });

// Vaciar el chat del grupo (borra el historial para todos, como el privado).
groupClearBtn.addEventListener('click', async () => {
  if(!activeGroup) return;
  const gid = activeGroup;
  const g = groupData(gid);
  if(!confirm(L.delete_all_messages_group.replace('%s', g.name))) return;
  groupClearBtn.disabled = true;
  try {
    await fetch('api.php', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({action:'clear_group', id:gid})});
  } catch(e){}
  if(activeGroup === gid){
    messagesBox.innerHTML = '';
    const e = document.createElement('div');
    e.className = 'empty';
    e.textContent = L.no_messages_group;
    messagesBox.appendChild(e);
  }
  groupClearBtn.disabled = false;
  loadGroups();
  loadConversations();
});

async function loadStatus(){
  try {
    const res = await fetch('api.php?action=get_status', {cache:'no-store', credentials:'same-origin'});
    if(!res.ok) return;
    const data = await res.json();
    const s = data.status || {};
    document.getElementById('status-mood').value = s.status_mood || '';
    document.getElementById('status-code').value = s.status_code || 'AVAILABLE';
    document.getElementById('status-text').value = s.status_text || '';
    // Mi propio punto: yo estoy conectado (esta peticion acaba de latir), asi
    // que el color lo decide mi disponibilidad.
    updateMyDot(s.status_code);
    myAvatar = !!data.avatar;
    myAvatarVer = data.avatar_ver || '';
    renderMyAvatar();
  } catch(e){}
}

document.getElementById('send-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  sendError.textContent = '';
  if(activeGroup){
    const body = composer.value.trim();
    if(!body) return;
    sendButton.disabled = true;
    try {
      const res = await fetch('api.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        credentials:'same-origin',
        body: JSON.stringify({action:'send_group', id:activeGroup, body})
      });
      const data = await res.json();
      if(!res.ok || data.error){ sendError.textContent = data.error || 'No se pudo enviar'; return; }
      composer.value = '';
      composer.focus();
      // No pintamos el eco nosotros: pedimos la cola pendiente. Asi el servidor
      // devuelve en orden de id lo que se hubiera quedado sin traer (un mensaje
      // del companero que llego entre sondeo y sondeo) y el mio despues, sin
      // saltarse ninguno ni desordenar la conversacion. (Antes se pintaba el eco
      // y eso adelantaba el cursor: el mensaje de en medio no se pedia nunca.)
      await pollActive();
      scrollBottom();
      loadConversations();
    } catch(err){ sendError.textContent = 'Error de red'; }
    finally { updateComposerState(); }
    return;
  }
  if(!active){ sendError.textContent = L.select_friend_first; return; }
  if(contactData(active).blocked){ sendError.textContent = L.blocked_user.replace('%s', active); return; }
  const body = composer.value.trim();
  if(!body) return;
  sendButton.disabled = true;
  try {
    const res = await fetch('api.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      credentials:'same-origin',
      body: JSON.stringify({action:'send', to:active, body})
    });
    const data = await res.json();
    if(!res.ok || data.error){ sendError.textContent = data.error || 'No se pudo enviar'; return; }
    composer.value = '';
    composer.focus();
    // Igual que en los grupos: en vez de pintar el eco (que saltaria el cursor y
    // dejaria fuera lo que estuviera en cola), pedimos la cola pendiente y el
    // servidor nos lo da todo en orden.
    await pollActive();
    scrollBottom();
    loadConversations();
  } catch(err){ sendError.textContent = 'Error de red'; }
  finally { updateComposerState(); }
});

composer.addEventListener('keydown', (e) => {
  if(e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); document.getElementById('send-form').requestSubmit(); }
});

document.getElementById('add-contact-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const input = document.getElementById('new-contact');
  const nick = input.value.trim();
  if(!nick) return;
  await fetch('api.php', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({action:'add_contact', nick})});
  input.value = '';
  await loadContacts();
  openConversation(nick);
});

document.getElementById('save-status').addEventListener('click', async () => {
  const code = document.getElementById('status-code').value;
  const mood = document.getElementById('status-mood').value;
  const text = document.getElementById('status-text').value.trim();
  await fetch('api.php', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({action:'set_status', code, mood, text})});
  updateMyDot(code);
  loadContacts();
});

// Mi foto: al pasar el raton salen el lapiz (cambiar) y la "x" (quitar).
// Pulsar la foto la amplia a 4x; si aun no hay foto, abre el selector.
document.getElementById('my-photo').addEventListener('click', () => {
  if(myAvatar) openAvatarZoom(ME, myAvatarVer);
  else photoInput.click();
});
document.getElementById('edit-photo').addEventListener('click', (e) => { e.stopPropagation(); photoInput.click(); });

photoInput.addEventListener('change', async () => {
  const file = photoInput.files && photoInput.files[0];
  if(!file) return;
  try {
    const image = await shrinkTo80x60(file);
    const res = await fetch('api.php', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({action:'set_avatar', image})});
    const data = await res.json();
    if(!res.ok || data.error){ alert(data.error || 'No se pudo guardar la foto'); return; }
    myAvatar = !!data.avatar;
    myAvatarVer = data.avatar_ver || '';
    renderMyAvatar();
    loadContacts();
  } catch(e){ alert('No se pudo procesar la imagen'); }
  finally { photoInput.value = ''; }
});

document.getElementById('clear-photo').addEventListener('click', async (e) => {
  e.stopPropagation();
  try {
    await fetch('api.php', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({action:'set_avatar', clear:true})});
  } catch(e){}
  myAvatar = false;
  myAvatarVer = '';
  renderMyAvatar();
  loadContacts();
});

blockButton.addEventListener('click', async () => {
  if(!active) return;
  const c = contactData(active);
  const action = c.blocked ? 'unblock' : 'block';
  blockButton.disabled = true;
  try {
    await fetch('api.php', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({action, nick:active})});
  } catch(e){}
  await loadContacts();
});

// Vaciar la conversacion activa (borra los mensajes, de las dos partes).
clearChatButton.addEventListener('click', async () => {
  if(!active) return;
  const nick = active;
  if(!confirm('Delete all messages with ' + nick + '?')) return;
  clearChatButton.disabled = true;
  try {
    await fetch('api.php', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({action:'clear_chat', nick})});
  } catch(e){}
  messagesBox.innerHTML = '';
  const e = document.createElement('div');
  e.className = 'empty';
  e.textContent = L.no_messages_with + ' ' + nick + '.';
  messagesBox.appendChild(e);
  clearChatButton.disabled = false;
});

avatarModal.addEventListener('click', closeAvatarZoom);

// About IMPS: modal informativo (se cierra con la x, con Esc o pulsando fuera).
document.getElementById('about-btn').addEventListener('click', () => { aboutModal.hidden = false; });
document.getElementById('about-close').addEventListener('click', () => { aboutModal.hidden = true; });
aboutModal.addEventListener('click', (e) => { if(e.target === aboutModal) aboutModal.hidden = true; });

const passwordModal = document.getElementById('password-modal');
const passwordPanel = document.getElementById('password-panel');

function generatePassword() {
  const input = document.getElementById('password');
  if (!input) return;
  const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  const out = new Uint32Array(12);
  (window.crypto || window.msCrypto).getRandomValues(out);
  let password = '';
  for (let i = 0; i < out.length; i++) {
    password += alphabet[out[i] % alphabet.length];
  }
  input.value = password;
}

function bindPasswordForm() {
  const form = passwordPanel.querySelector('#password-form');
  if (!form) return;
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const data = new FormData(form);
    fetch('device.php?partial=1', { method: 'POST', body: data, credentials: 'same-origin' })
      .then((r) => r.text())
      .then((html) => { passwordPanel.innerHTML = html; bindPasswordForm(); })
      .catch(() => { passwordPanel.innerHTML = '<p>Error loading password form.</p>'; });
  });
}

function loadPasswordPanel() {
  passwordPanel.innerHTML = '<p>Loading...</p>';
  fetch('device.php?partial=1', { credentials: 'same-origin' })
    .then((r) => r.text())
    .then((html) => { passwordPanel.innerHTML = html; bindPasswordForm(); })
    .catch(() => { passwordPanel.innerHTML = '<p>Error loading password form.</p>'; });
}

document.getElementById('password-btn').addEventListener('click', () => {
  loadPasswordPanel();
  passwordModal.hidden = false;
});
document.getElementById('password-close').addEventListener('click', () => { passwordModal.hidden = true; });
passwordModal.addEventListener('click', (e) => { if (e.target === passwordModal) passwordModal.hidden = true; });

document.addEventListener('keydown', (e) => { if(e.key === 'Escape'){ closeAvatarZoom(); aboutModal.hidden = true; passwordModal.hidden = true; closeMembers(); } });

setInterval(pollActive, 2000);
setInterval(pollGlobal, 4000);
setInterval(loadContacts, 10000);
setInterval(loadGroups, 10000);
setInterval(loadConversations, 5000);
setInterval(loadStatus, 30000);
loadContacts().then(() => { if(active) openConversation(active); });
loadGroups();
loadConversations();
renderMyAvatar();
loadStatus();
</script>
</body></html>
