<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'No autenticado']);
    exit;
}
$uid = (int)$user['id'];
$nick = (string)$user['nick'];

function clean_nick(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/^wv:/i', '', $value, 1);
    $parts = preg_split('~/~', (string)$value);
    return trim((string)($parts[0] ?? ''));
}

function norm_mood(?string $mood): ?string
{
    $m = strtoupper(trim((string)$mood));
    if ($m === '') return null;
    static $valid = ['HAPPY','SAD','ANGRY','JEALOUS','ASHAMED','INVINCIBLE','IN_LOVE','SLEEPY','BORED','EXCITED','ANXIOUS'];
    return in_array($m, $valid, true) ? $m : null;
}

function norm_avail(?string $code): string
{
    $c = strtoupper(trim((string)$code));
    if ($c === 'NOT_AVAILABLE') return 'NOT_AVAILABLE';
    if ($c === 'DISCREET') return 'DISCREET';
    if ($c === 'INVISIBLE') return 'INVISIBLE';
    return 'AVAILABLE';
}

function touch_presence(int $uid): void
{
    // UPSERT: solo actualiza updated_at/online. Con INSERT OR REPLACE se borraba
    // el resto de la fila, incluida la imagen de estado (status_content), en cada
    // peticion a la API.
    db()->prepare('INSERT INTO presence (user_id, status_code, status_mood, status_text, updated_at, online) VALUES (?, "AVAILABLE", NULL, "", datetime("now"), 1) ON CONFLICT(user_id) DO UPDATE SET updated_at = datetime("now"), online = 1')
        ->execute([$uid]);
}

touch_presence($uid);

$raw = file_get_contents('php://input');
$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
$json = [];
if ($raw !== '' && str_contains($contentType, 'application/json')) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $json = $decoded;
    }
}
$param = function (string $key, $default = null) use ($json) {
    return $json[$key] ?? $_POST[$key] ?? $_GET[$key] ?? $default;
};
$action = (string)$param('action', '');

function message_payload(array $m): array
{
    return [
        'id' => (int)$m['id'],
        'from' => (string)$m['from_user'],
        'to' => (string)$m['to_user'],
        'body' => (string)$m['body'],
        'created_at' => (string)$m['created_at'],
        'delivered' => !empty($m['delivered_at']),
    ];
}

function is_online(int $flag, ?string $updatedAt, ?string $lastSeen): bool
{
    if ($flag !== 1) {
        return false;
    }
    $now = time();
    foreach ([$updatedAt, $lastSeen] as $ts) {
        if ($ts === null || $ts === '') {
            continue;
        }
        $t = strtotime($ts . ' UTC');
        if ($t !== false && ($now - $t) < 180) {
            return true;
        }
    }
    return false;
}

// --- Conversaciones abiertas en la web ("zona de chats" persistente) --------
// El movil vacia su zona de chats al desconectar (CSP 1.2 §6.5); la web las
// mantiene: son filas de web_conversations y solo se cierran a mano (la X).
function conv_touch(int $uid, string $kind, string $target): void
{
    if ($uid <= 0 || $target === '' || !in_array($kind, ['user', 'group'], true)) {
        return;
    }
    try {
        // INSERT OR IGNORE: reabrir una conversacion no cambia opened_at (el
        // orden de la lista va por el ultimo mensaje, no por cuando la abriste).
        db()->prepare('INSERT OR IGNORE INTO web_conversations (user_id, kind, target) VALUES (?, ?, ?)')
            ->execute([$uid, $kind, $target]);
    } catch (Throwable $e) {
    }
}

function conv_drop(int $uid, string $kind, string $target): void
{
    try {
        db()->prepare('DELETE FROM web_conversations WHERE user_id = ? AND kind = ? AND target = ?')
            ->execute([$uid, $kind, $target]);
    } catch (Throwable $e) {
    }
}

// Lista de la pestana "Conversaciones": una entrada por conversacion abierta,
// con el ultimo mensaje, lo no leido y la presencia/estado del grupo. Se ordena
// por el ultimo mensaje (las que aun no tienen, por cuando se abrieron).
function conv_list(int $uid, string $nick): array
{
    $out = [];
    try {
        $st = db()->prepare('SELECT kind, target, opened_at FROM web_conversations WHERE user_id = ?');
        $st->execute([$uid]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $blockedSet = [];
    try {
        $bs = db()->prepare('SELECT contact_nick FROM blocked WHERE owner_id = ?');
        $bs->execute([$uid]);
        $blockedSet = array_map('clean_nick', $bs->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
    }
    foreach ($rows as $r) {
        $kind = (string)$r['kind'];
        $target = (string)$r['target'];
        if ($kind === 'group') {
            $g = db()->prepare('SELECT name FROM groups WHERE group_id = ?');
            $g->execute([$target]);
            $name = $g->fetchColumn();
            if ($name === false) {
                conv_drop($uid, $kind, $target);
                continue;
            }
            $mrow = null;
            try {
                $gm = db()->prepare('SELECT joined, last_read_id FROM group_members WHERE group_id = ? AND user_id = ? AND member = 1 AND rejected = 0');
                $gm->execute([$target, $uid]);
                $mrow = $gm->fetch() ?: null;
            } catch (Throwable $e) {
            }
            $joined = $mrow !== null && (int)$mrow['joined'] === 1;
            $lastRead = $mrow ? (int)$mrow['last_read_id'] : 0;
            $cnt = db()->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ? AND member = 1 AND rejected = 0');
            $cnt->execute([$target]);
            $ins = db()->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ? AND member = 1 AND rejected = 0 AND joined = 1');
            $ins->execute([$target]);
            $unread = 0;
            $last = null;
            if ($joined) {
                $un = db()->prepare('SELECT COUNT(*) FROM group_messages WHERE group_id = ? AND id > ? AND from_user <> ? AND kind = "msg"');
                $un->execute([$target, $lastRead, $nick]);
                $unread = (int)$un->fetchColumn();
                $lm = db()->prepare('SELECT from_user, body, kind, created_at FROM group_messages WHERE group_id = ? ORDER BY id DESC LIMIT 1');
                $lm->execute([$target]);
                $last = $lm->fetch() ?: null;
            }
            $out[] = [
                'kind' => 'group',
                'target' => $target,
                'name' => (string)$name,
                'unread' => $unread,
                'joined' => $joined,
                'inside' => (int)$ins->fetchColumn(),
                'online' => false,
                'status_code' => '',
                'blocked' => false,
                'avatar' => false,
                'avatar_ver' => '',
                'last' => $last ? (string)$last['body'] : '',
                'last_from' => $last ? (string)$last['from_user'] : '',
                'last_kind' => $last ? (string)$last['kind'] : '',
                'last_at' => $last ? (string)$last['created_at'] : '',
                'opened_at' => (string)$r['opened_at'],
            ];
            continue;
        }
        // Conversacion privada: misma presencia que en la agenda (bloqueo y
        // autorizacion de atributos incluidos, CSP 1.2 §8.2).
        $contact = clean_nick($target);
        $s = db()->prepare('SELECT u.id, p.status_code, p.status_content, p.updated_at, p.online FROM users u LEFT JOIN presence p ON p.user_id = u.id WHERE u.nick = ?');
        $s->execute([$contact]);
        $row = $s->fetch();
        if (!$row) {
            conv_drop($uid, $kind, $target);
            continue;
        }
        $l = db()->prepare('SELECT MAX(last_seen) FROM wv_sessions WHERE user_id = (SELECT id FROM users WHERE nick = ?)');
        $l->execute([$contact]);
        $lastSeen = $l->fetchColumn();
        $code = norm_avail($row['status_code'] ?? 'AVAILABLE');
        $online = is_online((int)($row['online'] ?? 0), $row['updated_at'] ?? null, is_string($lastSeen) ? $lastSeen : null);
        $hidden = presence_hidden($uid, $contact);
        $allowed = authorized_attrs((int)$row['id'], $nick);
        $can = static fn(string $a): bool => $allowed === null || in_array($a, $allowed, true);
        if ($code === 'INVISIBLE' || $hidden || !$can('OnlineStatus')) {
            $online = false;
        }
        $hasPhoto = !$hidden && $can('StatusContent') && (string)($row['status_content'] ?? '') !== '';
        $un = db()->prepare('SELECT COUNT(*) FROM messages WHERE to_user = ? AND from_user = ? AND read_at IS NULL');
        $un->execute([$nick, $contact]);
        $lm = db()->prepare('SELECT from_user, body, created_at FROM messages WHERE (from_user = ? AND to_user = ?) OR (from_user = ? AND to_user = ?) ORDER BY id DESC LIMIT 1');
        $lm->execute([$nick, $contact, $contact, $nick]);
        $last = $lm->fetch() ?: null;
        $out[] = [
            'kind' => 'user',
            'target' => $contact,
            'name' => $contact,
            'unread' => (int)$un->fetchColumn(),
            'joined' => true,
            'members' => 0,
            'inside' => 0,
            'online' => $online,
            'status_code' => $online ? $code : 'NOT_AVAILABLE',
            'blocked' => in_array($contact, $blockedSet, true),
            'avatar' => $hasPhoto,
            'avatar_ver' => $hasPhoto ? substr(md5((string)$row['status_content']), 0, 10) : '',
            'last' => $last ? (string)$last['body'] : '',
            'last_from' => $last ? (string)$last['from_user'] : '',
            'last_kind' => $last ? 'msg' : '',
            'last_at' => $last ? (string)$last['created_at'] : '',
            'opened_at' => (string)$r['opened_at'],
        ];
    }
    usort($out, static function (array $a, array $b): int {
        $ka = $a['last_at'] !== '' ? $a['last_at'] : $a['opened_at'];
        $kb = $b['last_at'] !== '' ? $b['last_at'] : $b['opened_at'];
        return strcmp($kb, $ka);
    });
    return $out;
}

switch ($action) {
    case 'send':
        $to = clean_nick((string)$param('to', ''));
        $body = trim((string)$param('body', ''));
        if ($to === '' || $body === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Faltan destinatario o mensaje']);
            exit;
        }
        // Lista de bloqueados: si el destinatario ha bloqueado al remitente, no
        // se entrega el mensaje (mismo criterio que el SendMessage del movil).
        $blocked = false;
        try {
            $blk = db()->prepare('SELECT 1 FROM blocked b JOIN users u ON u.id = b.owner_id WHERE u.nick = ? AND b.contact_nick = ? LIMIT 1');
            $blk->execute([$to, $nick]);
            $blocked = (bool)$blk->fetchColumn();
        } catch (Throwable $e) {}
        if ($blocked) {
            // CSP 1.1 §11.5.12: 532 = el destinatario ha bloqueado al remitente.
            http_response_code(403);
            echo json_encode(['error' => 'Este usuario no acepta mensajes', 'code' => 532]);
            exit;
        }
        // Si es el propio remitente quien ha bloqueado al destinatario, tampoco se
        // envia: en la web hay que desbloquear para poder volver a escribir.
        $iBlocked = false;
        try {
            $blk = db()->prepare('SELECT 1 FROM blocked b JOIN users u ON u.id = b.owner_id WHERE u.nick = ? AND b.contact_nick = ? LIMIT 1');
            $blk->execute([$nick, $to]);
            $iBlocked = (bool)$blk->fetchColumn();
        } catch (Throwable $e) {}
        if ($iBlocked) {
            http_response_code(403);
            echo json_encode(['error' => 'You have blocked this user. Unblock them to send messages.', 'code' => 532]);
            exit;
        }
        db()->prepare('INSERT INTO messages (from_user, to_user, body, content_type) VALUES (?, ?, ?, ?)')
            ->execute([$nick, $to, $body, 'text/plain']);
        $id = (int)db()->lastInsertId();
        // Historial acotado: los mensajes mas viejos de esta conversacion se van.
        prune_private_history($nick, $to);
        // Escribir a alguien abre su conversacion en la pestana Conversaciones.
        conv_touch($uid, 'user', $to);
        echo json_encode(['ok' => true, 'message' => [
            'id' => $id,
            'from' => $nick,
            'to' => $to,
            'body' => $body,
            // UTC, igual que datetime('now') de SQLite (con date() el eco
            // podia llevar otra hora si el host no esta en UTC).
            'created_at' => gmdate('Y-m-d H:i:s'),
            'delivered' => false,
        ]]);
        exit;

    case 'conv_list':
        echo json_encode(['conversations' => conv_list($uid, $nick)]);
        exit;

    case 'conv_open':
        $kind = (string)$param('kind', 'user');
        $target = $kind === 'group' ? (string)$param('target', '') : clean_nick((string)$param('target', ''));
        if ($target === '' || !in_array($kind, ['user', 'group'], true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing conversation or type']);
            exit;
        }
        conv_touch($uid, $kind, $target);
        echo json_encode(['ok' => true]);
        exit;

    case 'conv_close':
        // Cerrar la conversacion (la X de la pestana Conversaciones): se quita de
        // la lista pero no borra el historial ni sale del grupo.
        $kind = (string)$param('kind', 'user');
        $target = $kind === 'group' ? (string)$param('target', '') : clean_nick((string)$param('target', ''));
        if ($kind === 'group') {
            conv_drop($uid, 'group', $target);
        } else {
            conv_drop($uid, 'user', $target);
        }
        echo json_encode(['ok' => true]);
        exit;

    case 'messages':
        $since = (int)$param('since', 0);
        $stmt = db()->prepare('SELECT * FROM messages WHERE (from_user = ? OR to_user = ?) AND id > ? ORDER BY id ASC LIMIT 200');
        $stmt->execute([$nick, $nick, $since]);
        $rows = $stmt->fetchAll();
        // Si te escribe alguien que no esta en tu lista, lo anadimos para que el
        // mensaje sea visible (entrada en el sidebar + aviso) y puedas responder.
        // Se marca auto=1: no cuenta como contacto para la autorizacion de presencia.
        try {
            foreach ($rows as $r) {
                $from = clean_nick((string)($r['from_user'] ?? ''));
                if ($from !== '' && $from !== $nick) {
                    db()->prepare('INSERT OR IGNORE INTO contacts (owner_id, contact_nick, auto) VALUES (?, ?, 1)')->execute([$uid, $from]);
                    // Quien te escribe aparece en Conversaciones (como en el movil,
                    // que abre el chat al recibir un mensaje).
                    conv_touch($uid, 'user', $from);
                }
            }
        } catch (Throwable $e) {}
        echo json_encode(['messages' => array_map('message_payload', $rows)]);
        exit;

    case 'conversation':
        $with = clean_nick((string)$param('with', ''));
        $since = (int)$param('since', 0);
        if ($with === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Falta el contacto']);
            exit;
        }
        $stmt = db()->prepare('SELECT * FROM messages WHERE ((from_user = ? AND to_user = ?) OR (from_user = ? AND to_user = ?)) AND id > ? ORDER BY id ASC LIMIT 300');
        $stmt->execute([$nick, $with, $with, $nick, $since]);
        $rows = $stmt->fetchAll();
        db()->prepare('UPDATE messages SET read_at = datetime("now") WHERE to_user = ? AND from_user = ? AND read_at IS NULL')->execute([$nick, $with]);
        conv_touch($uid, 'user', $with);
        echo json_encode(['with' => $with, 'messages' => array_map('message_payload', $rows)]);
        exit;

    case 'contacts':
        $stmt = db()->prepare('SELECT contact_nick FROM contacts WHERE owner_id = ? ORDER BY contact_nick');
        $stmt->execute([$uid]);
        $contacts = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $bs = db()->prepare('SELECT contact_nick FROM blocked WHERE owner_id = ?');
        $bs->execute([$uid]);
        $blockedSet = array_map('clean_nick', $bs->fetchAll(PDO::FETCH_COLUMN));
        $out = [];
        foreach ($contacts as $contact) {
            $contact = clean_nick((string)$contact);
            $s = db()->prepare('SELECT u.id, u.nick, p.status_code, p.status_mood, p.status_text, p.status_content, p.updated_at, p.online FROM users u LEFT JOIN presence p ON p.user_id = u.id WHERE u.nick = ?');
            $s->execute([$contact]);
            $row = $s->fetch() ?: [];
            $l = db()->prepare('SELECT MAX(last_seen) FROM wv_sessions WHERE user_id = (SELECT id FROM users WHERE nick = ?)');
            $l->execute([$contact]);
            $lastSeen = $l->fetchColumn();
            $u = db()->prepare('SELECT COUNT(*) FROM messages WHERE to_user = ? AND from_user = ? AND read_at IS NULL');
            $u->execute([$nick, $contact]);
            $code = norm_avail($row['status_code'] ?? 'AVAILABLE');
            $online = is_online((int)($row['online'] ?? 0), $row['updated_at'] ?? null, is_string($lastSeen) ? $lastSeen : null);
            // El bloqueo oculta la presencia en ambos sentidos (se ven desconectados).
            $hidden = presence_hidden($uid, $contact);
            // Autorizacion de presencia (CSP 1.2 §8.2): el contacto decide que
            // atributos ve cada observador (su ajuste "Mostrar mi estado").
            $allowed = authorized_attrs((int)($row['id'] ?? 0), $nick);
            $can = static fn(string $a): bool => $allowed === null || in_array($a, $allowed, true);
            if ($code === 'INVISIBLE' || $hidden || !$can('OnlineStatus')) {
                $online = false;
            }
            $showMood = !$hidden && $can('StatusMood');
            $showText = !$hidden && $can('StatusText');
            $showPhoto = !$hidden && $can('StatusContent');
            $hasPhoto = $showPhoto && (string)($row['status_content'] ?? '') !== '';
            $out[] = [
                'nick' => $contact,
                'online' => $online,
                'status_code' => $online ? $code : 'NOT_AVAILABLE',
                'status_mood' => $showMood ? (norm_mood($row['status_mood'] ?? '') ?? '') : '',
                'status_text' => $showText ? (string)($row['status_text'] ?? '') : '',
                'unread' => (int)$u->fetchColumn(),
                'blocked' => in_array($contact, $blockedSet, true),
                // Imagen de estado (WV-029 StatusContent). avatar_ver es un hash
                // corto del contenido, para cachear/cache-bustear avatar.php.
                'avatar' => $hasPhoto,
                'avatar_ver' => $hasPhoto ? substr(md5((string)$row['status_content']), 0, 10) : '',
            ];
        }
        echo json_encode(['contacts' => $out]);
        exit;

    case 'add_contact':
        $contact = clean_nick((string)$param('nick', ''));
        if ($contact === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Empty nick']);
            exit;
        }
        db()->prepare('INSERT INTO contacts (owner_id, contact_nick, auto) VALUES (?, ?, 0) ON CONFLICT(owner_id, contact_nick) DO UPDATE SET auto = 0')->execute([$uid, $contact]);
        echo json_encode(['ok' => true, 'nick' => $contact]);
        exit;

    case 'remove_contact':
        $contact = clean_nick((string)$param('nick', ''));
        if ($contact !== '') {
            db()->prepare('DELETE FROM contacts WHERE owner_id = ? AND contact_nick = ?')->execute([$uid, $contact]);
            // Si lo quitas de la agenda, tampoco tiene sentido en Conversaciones.
            conv_drop($uid, 'user', $contact);
        }
        echo json_encode(['ok' => true]);
        exit;

    case 'clear_chat':
        // Vaciar la conversacion: borra los mensajes entre los dos, en las dos
        // direcciones, como un "clear" del historial.
        $with = clean_nick((string)$param('nick', ''));
        if ($with === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Falta el contacto']);
            exit;
        }
        $del = db()->prepare('DELETE FROM messages WHERE (from_user = ? AND to_user = ?) OR (from_user = ? AND to_user = ?)');
        $del->execute([$nick, $with, $with, $nick]);
        echo json_encode(['ok' => true, 'nick' => $with, 'deleted' => $del->rowCount()]);
        exit;

    case 'groups':
        // Grupos de los que soy miembro (CSP 1.2 §10). "member" (agenda) se
        // mantiene siempre; "joined" (dentro) cambia al abrir/cerrar la
        // conversacion, igual que el JoinGroup/LeaveGroup del movil.
        // Antes de listar, sacamos de sus grupos a quien cerro la pestana sin
        // desconectar (asi la lista del movil no miente).
        group_leave_stale();
        $rows = [];
        try {
            $st = db()->prepare('SELECT g.group_id, g.name, gm.privilege, gm.joined, gm.last_read_id FROM group_members gm JOIN groups g ON g.group_id = gm.group_id WHERE gm.user_id = ? AND gm.member = 1 AND gm.rejected = 0 ORDER BY g.name');
            $st->execute([$uid]);
            $rows = $st->fetchAll();
        } catch (Throwable $e) {}
        $out = [];
        foreach ($rows as $r) {
            $gid = (string)$r['group_id'];
            $cnt = db()->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ? AND member = 1 AND rejected = 0');
            $cnt->execute([$gid]);
            $ins = db()->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ? AND member = 1 AND rejected = 0 AND joined = 1');
            $ins->execute([$gid]);
            $joined = (int)$r['joined'] === 1;
            // Si no estoy "dentro" (joined=0), no recibo notificaciones ni
            // previews de mensajes: el grupo sigue en la lista para reentrar
            // con un clic, pero no me molesta con mensajes nuevos.
            $unread = 0;
            $last = null;
            if ($joined) {
                $un = db()->prepare('SELECT COUNT(*) FROM group_messages WHERE group_id = ? AND id > ? AND from_user <> ? AND kind = "msg"');
                $un->execute([$gid, (int)$r['last_read_id'], $nick]);
                $unread = (int)$un->fetchColumn();
                $lm = db()->prepare('SELECT from_user, body, kind FROM group_messages WHERE group_id = ? ORDER BY id DESC LIMIT 1');
                $lm->execute([$gid]);
                $last = $lm->fetch() ?: null;
            }
            $out[] = [
                'id' => $gid,
                'name' => (string)$r['name'] !== '' ? (string)$r['name'] : $gid,
                'members' => (int)$cnt->fetchColumn(),
                'inside' => (int)$ins->fetchColumn(),
                'unread' => $unread,
                'privilege' => (string)$r['privilege'],
                'joined' => $joined,
                'last' => $last ? ['from' => (string)$last['from_user'], 'body' => (string)$last['body'], 'kind' => (string)$last['kind']] : null,
            ];
        }
        $inv = [];
        try {
            $st = db()->prepare('SELECT i.invite_id, i.group_id, i.note, u.nick AS inviter, g.name AS gname FROM group_invites i JOIN users u ON u.id = i.inviter_id LEFT JOIN groups g ON g.group_id = i.group_id WHERE i.invitee_id = ? AND i.state = "pending"');
            $st->execute([$uid]);
            foreach ($st->fetchAll() as $r) {
                $inv[] = ['invite_id' => (string)$r['invite_id'], 'id' => (string)$r['group_id'], 'inviter' => (string)$r['inviter'], 'name' => (string)($r['gname'] ?: $r['group_id']), 'note' => (string)$r['note']];
            }
        } catch (Throwable $e) {}
        echo json_encode(['groups' => $out, 'invites' => $inv]);
        exit;

    case 'group_messages':
        $gid = (string)$param('id', '');
        $since = (int)$param('since', 0);
        // Solo se "entra" en el grupo cuando el cliente abre la conversacion
        // (open=1). El sondeo periodico no entra NUNCA: si no, una peticion de
        // sondeo en vuelo volvia a poner joined=1 despues de salir y parecia
        // que no habias salido (el icono no se quedaba en gris).
        $open = (string)$param('open', '') === '1';
        if ($gid === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Falta el grupo']);
            exit;
        }
        $st = db()->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ? AND member = 1 AND rejected = 0');
        $st->execute([$gid, $uid]);
        if (!$st->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['error' => 'No perteneces a este grupo']);
            exit;
        }
        $st = db()->prepare('SELECT id, from_user, body, kind, created_at FROM group_messages WHERE group_id = ? AND id > ? ORDER BY id ASC LIMIT 300');
        $st->execute([$gid, $since]);
        $rows = $st->fetchAll();
        $max = db()->prepare('SELECT COALESCE(MAX(id), 0) FROM group_messages WHERE group_id = ?');
        $max->execute([$gid]);
        // Abrir la conversacion es "entrar" en el grupo (igual que el JoinGroup
        // del movil). Si acaba de entrar, se avisa a los demas con la lista de
        // participantes; al cerrar/salir se avisa con Left.
        $prev = db()->prepare('SELECT joined FROM group_members WHERE group_id = ? AND user_id = ?');
        $prev->execute([$gid, $uid]);
        $wasJoined = (int)$prev->fetchColumn();
        $maxId = (int)$max->fetchColumn();
        if ($open) {
            db()->prepare('UPDATE group_members SET last_read_id = ?, joined = 1 WHERE group_id = ? AND user_id = ?')
                ->execute([$maxId, $gid, $uid]);
            // Abrir la conversacion la anade a la pestana Conversaciones.
            conv_touch($uid, 'group', $gid);
            if ($wasJoined !== 1) {
                group_add_event($gid, 'join', $nick);
                group_invites_accept_pending($gid, $uid);
                group_notify_join($gid, $uid);
            }
        } else {
            // Sondeo: solo marca leido si ya estabamos dentro; nunca entra.
            db()->prepare('UPDATE group_members SET last_read_id = ? WHERE group_id = ? AND user_id = ? AND joined = 1')
                ->execute([$maxId, $gid, $uid]);
        }
        // "members" = los que estan dentro ahora mismo (lo que ve el movil);
        // "members_total" = todos los de la agenda del grupo.
        $ms = db()->prepare('SELECT u.nick FROM group_members gm JOIN users u ON u.id = gm.user_id WHERE gm.group_id = ? AND gm.member = 1 AND gm.rejected = 0 AND gm.joined = 1 ORDER BY u.nick');
        $ms->execute([$gid]);
        $tot = db()->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ? AND member = 1 AND rejected = 0');
        $tot->execute([$gid]);
        $g = db()->prepare('SELECT name FROM groups WHERE group_id = ?');
        $g->execute([$gid]);
        echo json_encode([
            'id' => $gid,
            'name' => (string)($g->fetchColumn() ?: $gid),
            'members' => array_map('strval', $ms->fetchAll(PDO::FETCH_COLUMN)),
            'members_total' => (int)$tot->fetchColumn(),
            'messages' => array_map(static fn($m) => [
                'id' => (int)$m['id'],
                'from' => (string)$m['from_user'],
                'to' => '',
                'body' => (string)$m['body'],
                'kind' => (string)$m['kind'],
                'created_at' => (string)$m['created_at'],
                'delivered' => true,
            ], $rows),
        ]);
        exit;

    case 'group_members':
        // Miembros del grupo con su estado de conexion: la lista de "Miembros"
        // de la web. Van primero los conectados (esfera verde) y despues el
        // resto (esfera roja). Se aplican las mismas reglas de presencia que en
        // la agenda: bloqueo (el que me bloquea se me ve desconectado) y
        // autorizacion por listas de atributos ("Mostrar mi estado").
        $gid = (string)$param('id', '');
        if ($gid === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Falta el grupo']);
            exit;
        }
        $st = db()->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ? AND member = 1 AND rejected = 0');
        $st->execute([$gid, $uid]);
        if (!$st->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['error' => 'No perteneces a este grupo']);
            exit;
        }
        $gn = db()->prepare('SELECT name FROM groups WHERE group_id = ?');
        $gn->execute([$gid]);
        $gname = (string)($gn->fetchColumn() ?: $gid);
        $out = [];
        foreach (group_members($gid) as $m) {
            $muid = (int)$m['user_id'];
            $s = db()->prepare('SELECT u.id, p.status_code, p.updated_at, p.online FROM users u LEFT JOIN presence p ON p.user_id = u.id WHERE u.id = ?');
            $s->execute([$muid]);
            $row = $s->fetch() ?: [];
            $l = db()->prepare('SELECT MAX(last_seen) FROM wv_sessions WHERE user_id = ?');
            $l->execute([$muid]);
            $lastSeen = $l->fetchColumn();
            $code = norm_avail($row['status_code'] ?? 'AVAILABLE');
            $online = is_online((int)($row['online'] ?? 0), $row['updated_at'] ?? null, is_string($lastSeen) ? $lastSeen : null);
            $hidden = presence_hidden($uid, (string)$m['nick']);
            $allowed = authorized_attrs((int)($row['id'] ?? 0), $nick);
            $can = static fn(string $a): bool => $allowed === null || in_array($a, $allowed, true);
            if ($code === 'INVISIBLE' || $hidden || !$can('OnlineStatus')) {
                $online = false;
            }
            $out[] = [
                'nick' => (string)$m['nick'],
                'sname' => (string)$m['sname'],
                'privilege' => (string)$m['privilege'],
                'joined' => (int)$m['joined'] === 1,
                'online' => $online,
                // Disponibilidad: la web pinta el punto (verde/rojo/gris).
                'status_code' => $online ? $code : 'NOT_AVAILABLE',
                'is_me' => (string)$m['nick'] === $nick,
            ];
        }
        // Conectados primero; dentro de cada mitad, por privilegio y nick.
        $rank = ['Admin' => 0, 'Mod' => 1, 'User' => 2];
        usort($out, static function (array $a, array $b) use ($rank): int {
            if ($a['online'] !== $b['online']) {
                return $a['online'] ? -1 : 1;
            }
            $ra = $rank[$a['privilege']] ?? 3;
            $rb = $rank[$b['privilege']] ?? 3;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return strcasecmp($a['nick'], $b['nick']);
        });
        echo json_encode([
            'id' => $gid,
            'name' => $gname,
            'online' => count(array_filter($out, static fn(array $m): bool => $m['online'])),
            'members' => $out,
        ]);
        exit;

    case 'clear_group':
        // Vaciar el chat del grupo: borra el historial (mensajes y avisos de
        // entrar/salir) para todos, como el "Vaciar chat" del privado. Los
        // cursores de entrega (`last_msg_id` del movil, `last_read_id` de la
        // web) siguen siendo validos: `group_messages` es AUTOINCREMENT, asi que
        // los mensajes nuevos llevan ids mayores y nadie recibe nada viejo.
        $gid = (string)$param('id', '');
        if ($gid === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Falta el grupo']);
            exit;
        }
        $st = db()->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ? AND member = 1 AND rejected = 0');
        $st->execute([$gid, $uid]);
        if (!$st->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['error' => 'No perteneces a este grupo']);
            exit;
        }
        $del = db()->prepare('DELETE FROM group_messages WHERE group_id = ?');
        $del->execute([$gid]);
        echo json_encode(['ok' => true, 'id' => $gid, 'deleted' => $del->rowCount()]);
        exit;

    case 'send_group':
        $gid = (string)$param('id', '');
        $body = trim((string)$param('body', ''));
        if ($gid === '' || $body === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Faltan grupo o mensaje']);
            exit;
        }
        $st = db()->prepare('SELECT joined FROM group_members WHERE group_id = ? AND user_id = ? AND member = 1 AND rejected = 0');
        $st->execute([$gid, $uid]);
        $wasJoined = $st->fetchColumn();
        if ($wasJoined === false) {
            http_response_code(403);
            echo json_encode(['error' => 'No perteneces a este grupo']);
            exit;
        }
        db()->prepare('INSERT INTO group_messages (group_id, from_user, body, content_type) VALUES (?, ?, ?, "text/plain")')
            ->execute([$gid, $nick, $body]);
        $id = (int)db()->lastInsertId();
        // Historial acotado tambien en los grupos.
        prune_group_history($gid);
        // Ni recontar como no leido ni el eco a mi mismo (la entrega al movil
        // filtra from_user). Ojo: no se toca last_msg_id, que es el cursor de
        // entrega al movil; ponerlo a este id se saltaria lo que tuviera en cola.
        db()->prepare('UPDATE group_members SET last_read_id = ?, joined = 1 WHERE group_id = ? AND user_id = ?')
            ->execute([$id, $gid, $uid]);
        conv_touch($uid, 'group', $gid);
        // Si acabo de entrar, el movil tiene que enterarse (lista de participantes).
        if ((int)$wasJoined !== 1) {
            group_add_event($gid, 'join', $nick);
            group_invites_accept_pending($gid, $uid);
            group_notify_join($gid, $uid);
        }
        echo json_encode(['ok' => true, 'message' => [
            'id' => $id, 'from' => $nick, 'to' => '', 'body' => $body,
            'created_at' => gmdate('Y-m-d H:i:s'), 'delivered' => true,
        ]]);
        exit;

    case 'create_group':
        $name = trim((string)$param('name', ''));
        if ($name === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Falta el nombre del grupo']);
            exit;
        }
        // El movil titula el grupo con su GroupID (sin el "wv:"), y necesita la
        // forma wv:<algo>/<algo> (dos partes no vacias) para mostrar la
        // invitacion. El nombre del grupo se ve limpio en la web y en el movil
        // se vera "creador/nombre" (sin fecha); la unicidad se resuelve
        // numerando (viktoria2, viktoria3...).
        $slug = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
        $slug = trim($slug, '_');
        if ($slug === '') {
            $slug = 'grupo';
        }
        // El movil corta el titulo sobre los 19 caracteres.
        if (strlen($slug) > 18) {
            $slug = substr($slug, 0, 18);
        }
        $gid = 'wv:' . $nick . '/' . $slug;
        for ($i = 2; !group_id_free($gid); $i++) {
            $gid = 'wv:' . $nick . '/' . $slug . $i;
        }
        // Igual que el movil: el creador es Admin y entra dentro.
        db()->prepare('INSERT INTO groups (group_id, owner_id, name, accesstype, props) VALUES (?, ?, ?, "Open", ?) ON CONFLICT(group_id) DO UPDATE SET name = excluded.name')
            ->execute([$gid, $uid, $name, json_encode(['Name' => $name, 'Accesstype' => 'Open'])]);
        db()->prepare('INSERT INTO group_members (group_id, user_id, privilege, member, joined, screen_name, subscribed) VALUES (?, ?, "Admin", 1, 1, ?, 1)
            ON CONFLICT(group_id, user_id) DO UPDATE SET privilege = "Admin", member = 1, joined = 1, rejected = 0')
            ->execute([$gid, $uid, $nick]);
        echo json_encode(['ok' => true, 'id' => $gid, 'name' => $name]);
        exit;

    case 'group_add_member':
        // Invitar a un amigo al grupo. Igual que el movil: NO se le mete dentro
        // de golpe, se le manda una invitacion (InviteUser-Request) y entra
        // cuando la acepta. Asi da igual quien invite, el movil o la web: al
        // invitado le sale el aviso y decide. (Antes la web lo metia directo y
        // quedaba "dentro" en el servidor sin haberse enterado.)
        $gid = (string)$param('id', '');
        $member = clean_nick((string)$param('nick', ''));
        if ($gid === '' || $member === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Faltan grupo o nick']);
            exit;
        }
        $st = db()->prepare('SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ? AND member = 1');
        $st->execute([$gid, $uid]);
        if (!$st->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['error' => 'No perteneces a este grupo']);
            exit;
        }
        $mid = db()->prepare('SELECT id FROM users WHERE nick = ?');
        $mid->execute([$member]);
        $memberId = (int)$mid->fetchColumn();
        if ($memberId <= 0) {
            db()->prepare('INSERT OR IGNORE INTO users (nick, domain, password_hash) VALUES (?, "village", "")')->execute([$member]);
            $mid->execute([$member]);
            $memberId = (int)$mid->fetchColumn();
        }
        $prev = db()->prepare('SELECT joined FROM group_members WHERE group_id = ? AND user_id = ?');
        $prev->execute([$gid, $memberId]);
        $wasIn = (int)$prev->fetchColumn();
        if ($wasIn === 1) {
            echo json_encode(['ok' => true, 'nick' => $member, 'already' => true]);
            exit;
        }
        $inviteId = group_invite_send($gid, $uid, $nick, $memberId);
        echo json_encode(['ok' => true, 'nick' => $member, 'invited' => true, 'invite_id' => $inviteId]);
        exit;

    case 'group_leave':
        $gid = (string)$param('id', '');
        if ($gid === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Falta el grupo']);
            exit;
        }
        $prev = db()->prepare('SELECT joined FROM group_members WHERE group_id = ? AND user_id = ?');
        $prev->execute([$gid, $uid]);
        $wasJoined = (int)$prev->fetchColumn();
        db()->prepare('UPDATE group_members SET joined = 0 WHERE group_id = ? AND user_id = ?')->execute([$gid, $uid]);
        if ($wasJoined === 1) {
            group_add_event($gid, 'leave', $nick);
            group_notify_leave($gid, $uid, $uid);
        }
        echo json_encode(['ok' => true]);
        exit;

    case 'group_remove':
        // Quitar el grupo de MI lista (la X del sidebar). No es lo mismo que
        // "salir": salir solo pone joined=0 y el grupo sigue en la agenda para
        // volver a entrar con un clic. Quitarlo pone member=0: desaparece de mi
        // lista, dejo de recibir sus mensajes y, para volver, hace falta otra
        // invitacion.
        //
        // Si quien quita es el CREADOR (owner) del grupo, el grupo se disuelve
        // por completo para todos: se borran mensajes, miembros, invitaciones y
        // el propio grupo. No se transfiere admin a nadie porque solo el creador
        // puede ser admin.
        $gid = (string)$param('id', '');
        if ($gid === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Falta el grupo']);
            exit;
        }
        $st = db()->prepare('SELECT joined, privilege FROM group_members WHERE group_id = ? AND user_id = ? AND member = 1 AND rejected = 0');
        $st->execute([$gid, $uid]);
        $fila = $st->fetch();
        if ($fila === false) {
            http_response_code(404);
            echo json_encode(['error' => 'You are not in this group']);
            exit;
        }
        // Comprobar si el usuario es el creador (owner) del grupo.
        $ownerSt = db()->prepare('SELECT owner_id FROM groups WHERE group_id = ?');
        $ownerSt->execute([$gid]);
        $ownerId = (int)$ownerSt->fetchColumn();
        $esOwner = ($ownerId === $uid);

        if ($esOwner) {
            // El creador disuelve el grupo para todo el mundo.
            $wasJoined = (int)$fila['joined'];
            if ($wasJoined === 1) {
                // Notificar a todos los miembros que el grupo se ha eliminado.
                foreach (group_members($gid) as $m) {
                    if ((int)$m['user_id'] === $uid) continue;
                    try {
                        db()->prepare('INSERT INTO wv_notices (user_id, xml) VALUES (?, ?)')->execute([
                            (int)$m['user_id'],
                            '<LeaveGroup-Response><GroupID>' . htmlspecialchars($gid, ENT_XML1, 'UTF-8') .
                            '</GroupID><Result><Code>200</Code><Description>Group deleted.</Description></Result></LeaveGroup-Response>',
                        ]);
                    } catch (Throwable $e) {
                    }
                }
            }
            foreach (['group_messages', 'group_members', 'group_invites'] as $t) {
                try {
                    db()->prepare('DELETE FROM ' . $t . ' WHERE group_id = ?')->execute([$gid]);
                } catch (Throwable $e) {
                }
            }
            try {
                db()->prepare('DELETE FROM groups WHERE group_id = ?')->execute([$gid]);
            } catch (Throwable $e) {
            }
            // Quitarlo de Conversaciones del creador.
            conv_drop($uid, 'group', $gid);
            echo json_encode(['ok' => true, 'deleted' => true]);
            exit;
        }

        $wasJoined = (int)$fila['joined'];
        if ($wasJoined === 1) {
            group_add_event($gid, 'leave', $nick);
            // Antes de member=0: asi el aviso lleva el ScreenName de mi fila.
            group_notify_leave($gid, $uid, $uid);
        }
        db()->prepare('UPDATE group_members SET member = 0, joined = 0 WHERE group_id = ? AND user_id = ?')
            ->execute([$gid, $uid]);
        // Quitarlo de la agenda lo quita tambien de Conversaciones.
        conv_drop($uid, 'group', $gid);
        // Solo el creador puede ser admin: no se transfiere admin a nadie.
        // El grupo sigue existiendo sin admin hasta que el creador lo disuelva.
        $left = db()->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ? AND member = 1 AND rejected = 0');
        $left->execute([$gid]);
        $borrado = false;
        if ((int)$left->fetchColumn() === 0) {
            $borrado = true;
            foreach (['group_messages', 'group_members', 'group_invites'] as $t) {
                try {
                    db()->prepare('DELETE FROM ' . $t . ' WHERE group_id = ?')->execute([$gid]);
                } catch (Throwable $e) {
                }
            }
            try {
                db()->prepare('DELETE FROM groups WHERE group_id = ?')->execute([$gid]);
            } catch (Throwable $e) {
            }
        }
        echo json_encode(['ok' => true, 'deleted' => $borrado]);
        exit;

    case 'group_invite_response':
        // Aceptar (accept=true) o rechazar una invitacion a un grupo.
        $inviteId = (string)$param('invite_id', '');
        $gid = (string)$param('id', '');
        $accept = (bool)$param('accept', false);
        try {
            if ($inviteId === '' && $gid !== '') {
                $s = db()->prepare('SELECT invite_id FROM group_invites WHERE group_id = ? AND invitee_id = ? AND state = "pending" LIMIT 1');
                $s->execute([$gid, $uid]);
                $inviteId = (string)$s->fetchColumn();
            }
            db()->prepare('UPDATE group_invites SET state = ? WHERE invite_id = ? AND invitee_id = ?')
                ->execute([$accept ? 'accepted' : 'rejected', $inviteId, $uid]);
            if ($accept && $gid !== '') {
                $prev = db()->prepare('SELECT joined FROM group_members WHERE group_id = ? AND user_id = ?');
                $prev->execute([$gid, $uid]);
                $wasIn = (int)$prev->fetchColumn();
                db()->prepare('INSERT INTO group_members (group_id, user_id, privilege, member, joined, screen_name, subscribed) VALUES (?, ?, "User", 1, 1, ?, 1)
                    ON CONFLICT(group_id, user_id) DO UPDATE SET member = 1, joined = 1, rejected = 0')
                    ->execute([$gid, $uid, $nick]);
                // El movil se entera por aqui de que hay alguien nuevo (es lo que
                // usa para pintar la lista de participantes y los nombres).
                if ($wasIn !== 1) {
                    group_add_event($gid, 'join', $nick);
                    group_notify_join($gid, $uid);
                }
            }
        } catch (Throwable $e) {}
        echo json_encode(['ok' => true, 'accepted' => $accept]);
        exit;

    case 'block':
    case 'unblock':
        $contact = clean_nick((string)$param('nick', ''));
        if ($contact === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Empty nick']);
            exit;
        }
        if ($action === 'block') {
            db()->prepare('INSERT OR IGNORE INTO blocked (owner_id, contact_nick) VALUES (?, ?)')->execute([$uid, $contact]);
        } else {
            db()->prepare('DELETE FROM blocked WHERE owner_id = ? AND contact_nick = ?')->execute([$uid, $contact]);
        }
        echo json_encode(['ok' => true, 'nick' => $contact, 'blocked' => $action === 'block']);
        exit;

    case 'set_avatar':
        // Foto de perfil desde la web. El navegador ya la reduce a 80x60 (igual
        // que el movil) y la manda como data URL; se guarda como StatusContent
        // (WV-029) para que la vean la web y tambien el movil (GetPresence).
        $clear = (bool)$param('clear', false);
        $image = trim((string)$param('image', ''));
        if ($clear) {
            db()->prepare('UPDATE presence SET status_content = NULL, content_type = NULL, content_kind = NULL, updated_at = datetime("now") WHERE user_id = ?')->execute([$uid]);
            echo json_encode(['ok' => true, 'avatar' => false, 'avatar_ver' => '']);
            exit;
        }
        if ($image === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Falta la imagen']);
            exit;
        }
        $type = 'image/jpeg';
        $b64 = $image;
        if (preg_match('~^data:(image/[a-z0-9.+-]+);base64,(.*)$~is', $image, $m)) {
            $type = strtolower($m[1]);
            $b64 = $m[2];
        }
        $b64 = (string)preg_replace('~\s+~', '', $b64);
        $data = base64_decode($b64, true);
        if ($data === false || $data === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid image']);
            exit;
        }
        if (strlen($data) > 40000) {
            http_response_code(413);
            echo json_encode(['error' => 'La imagen es demasiado grande']);
            exit;
        }
        if (!preg_match('~^image/[a-z0-9.+-]+$~i', $type)) {
            $type = 'image/jpeg';
        }
        db()->prepare('INSERT INTO presence (user_id, status_code, status_mood, status_text, status_content, content_type, content_kind, updated_at, online) VALUES (?, "AVAILABLE", NULL, "", ?, ?, "direct", datetime("now"), 1) ON CONFLICT(user_id) DO UPDATE SET status_content = excluded.status_content, content_type = excluded.content_type, content_kind = "direct", updated_at = datetime("now")')
            ->execute([$uid, $b64, $type]);
        echo json_encode(['ok' => true, 'avatar' => true, 'avatar_ver' => substr(md5($b64), 0, 10), 'bytes' => strlen($data), 'base64' => strlen($b64), 'type' => $type]);
        exit;

    case 'get_status':
        $s = db()->prepare('SELECT status_code, status_mood, status_text, updated_at, status_content FROM presence WHERE user_id = ?');
        $s->execute([$uid]);
        $row = $s->fetch() ?: [];
        $content = (string)($row['status_content'] ?? '');
        echo json_encode([
            'status' => [
                'status_code' => norm_avail($row['status_code'] ?? 'AVAILABLE'),
                'status_mood' => norm_mood($row['status_mood'] ?? '') ?? '',
                'status_text' => (string)($row['status_text'] ?? ''),
            ],
            'avatar' => $content !== '',
            'avatar_ver' => $content !== '' ? substr(md5($content), 0, 10) : '',
        ]);
        exit;

    case 'set_status':
        $code = norm_avail($param('code', 'AVAILABLE'));
        $text = trim((string)$param('text', ''));
        $mood = norm_mood($param('mood', null));
        // UPSERT: conserva la imagen de estado (status_content) al cambiar el estado.
        db()->prepare('INSERT INTO presence (user_id, status_code, status_text, status_mood, updated_at, online) VALUES (?, ?, ?, ?, datetime("now"), 1) ON CONFLICT(user_id) DO UPDATE SET status_code = excluded.status_code, status_text = excluded.status_text, status_mood = excluded.status_mood, updated_at = datetime("now"), online = 1')
            ->execute([$uid, $code, $text, $mood]);
        echo json_encode(['ok' => true]);
        exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action']);
