<?php
require __DIR__ . '/db.php';
require __DIR__ . '/raw_input.php';
require __DIR__ . '/wbxml.php';
require __DIR__ . '/security.php';
require __DIR__ . '/imps_crypto.php';

$config = require __DIR__ . '/config.php';

function user_id_by_session(string $sessionId): int
{
    static $touched = [];
    if ($sessionId === '') {
        return 0;
    }
    $stmt = db()->prepare('SELECT user_id FROM wv_sessions WHERE session_id = ? AND expires_at > datetime("now") ORDER BY id DESC LIMIT 1');
    $stmt->execute([$sessionId]);
    $id = $stmt->fetchColumn();
    if (!is_numeric($id)) {
        return 0;
    }
    $uid = (int)$id;
    // Sesion deslizante: cada peticion valida la renueva. Se hace una sola vez
    // por peticion aunque se llame varias veces a esta funcion.
    if (!isset($touched[$sessionId])) {
        $touched[$sessionId] = true;
        try {
            db()->prepare('UPDATE wv_sessions SET last_seen = datetime("now"), expires_at = ? WHERE session_id = ?')
                ->execute([gmdate('Y-m-d H:i:s', time() + 1800), $sessionId]);
        } catch (Throwable $e) {
        }
    }
    return $uid;
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

function set_presence(int $userId, string $code, string $text = '', ?string $mood = null, ?string $content = null, ?string $contentType = null, ?string $contentKind = null): void
{
    if ($userId <= 0) return;
    // COALESCE preserva el valor previo cuando el parametro es null (no se ha
    // enviado en esta peticion); pasar '' limpia el campo.
    db()->prepare('INSERT OR REPLACE INTO presence (user_id, status_code, status_text, status_mood, status_content, content_type, content_kind, updated_at, online)
        VALUES (?, ?, ?, COALESCE(?, (SELECT status_mood FROM presence WHERE user_id = ?)),
            COALESCE(?, (SELECT status_content FROM presence WHERE user_id = ?)),
            COALESCE(?, (SELECT content_type FROM presence WHERE user_id = ?)),
            COALESCE(?, (SELECT content_kind FROM presence WHERE user_id = ?)),
            datetime("now"), 1)')
        ->execute([$userId, norm_avail($code), $text, norm_mood($mood), $userId, $content, $userId, $contentType, $userId, $contentKind, $userId]);
}

// Actualiza solo la marca de "visto" sin pisar el estado elegido por el usuario.
function touch_presence(int $userId): void
{
    if ($userId <= 0) return;
    $stmt = db()->prepare('UPDATE presence SET updated_at = datetime("now"), online = 1 WHERE user_id = ?');
    $stmt->execute([$userId]);
    if ($stmt->rowCount() === 0) {
        db()->prepare('INSERT INTO presence (user_id, status_code, status_text, updated_at, online) VALUES (?, "AVAILABLE", "", datetime("now"), 1)')
            ->execute([$userId]);
    }
}

// Marca al usuario como desconectado (logout explicito). Conserva su estado
// (Disponible/Comunica/Invisible) para cuando vuelva a entrar.
function set_offline(int $userId): void
{
    if ($userId <= 0) return;
    $stmt = db()->prepare('UPDATE presence SET online = 0, updated_at = datetime("now") WHERE user_id = ?');
    $stmt->execute([$userId]);
    if ($stmt->rowCount() === 0) {
        db()->prepare('INSERT INTO presence (user_id, status_code, status_text, updated_at, online) VALUES (?, "AVAILABLE", "", datetime("now"), 0)')
            ->execute([$userId]);
    }
}

function clean_nick(string $nick): string
{
    $nick = trim(preg_replace('/^wv:/i', '', $nick, 1));
    $parts = explode('/', $nick);
    return trim((string)($parts[0] ?? ''));
}

// Devuelve el estado de presencia calculado de un nick (web o movil).
function presence_snapshot(string $nick): array
{
    $nick = clean_nick($nick);
    $stmt = db()->prepare('SELECT u.id, u.nick, p.status_code, p.status_mood, p.status_text, p.status_content, p.content_type, p.content_kind, p.updated_at, p.online,
        (SELECT MAX(last_seen) FROM wv_sessions s WHERE s.user_id = u.id) AS last_seen
        FROM users u LEFT JOIN presence p ON p.user_id = u.id WHERE u.nick = ?');
    $stmt->execute([$nick]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['nick' => $nick, 'id' => 0, 'exists' => false, 'online' => false, 'status_text' => '', 'status_code' => 'AVAILABLE', 'status_mood' => '', 'status_content' => '', 'content_type' => '', 'content_kind' => ''];
    }
    $now = time();
    $updated = !empty($row['updated_at']) ? strtotime($row['updated_at'] . ' UTC') : false;
    $seen = !empty($row['last_seen']) ? strtotime($row['last_seen'] . ' UTC') : false;
    $code = norm_avail((string)($row['status_code'] ?? 'AVAILABLE'));
    // online = flag de conexion Y latido reciente (por si el cliente desaparece
    // sin hacer logout). Invisible siempre cuenta como offline.
    $flag = ((int)($row['online'] ?? 0)) === 1;
    $fresh = ($updated !== false && $now - $updated < 180) || ($seen !== false && $now - $seen < 180);
    $online = $flag && $fresh;
    if ($code === 'INVISIBLE') {
        $online = false;
    }
    return [
        'nick' => $nick,
        'id' => (int)$row['id'],
        'exists' => true,
        'online' => $online,
        'status_text' => (string)($row['status_text'] ?? ''),
        'status_code' => $code,
        'status_mood' => norm_mood($row['status_mood'] ?? '') ?? '',
        'status_content' => (string)($row['status_content'] ?? ''),
        'content_type' => (string)($row['content_type'] ?? ''),
        'content_kind' => (string)($row['content_kind'] ?? ''),
    ];
}

// Construye el <StatusContent> (WV-029) de una presencia, o '' si no hay.
// La imagen viaja como DirectContent (base64) o ReferredContent (URL) + ContentType.
function status_content_xml(array $p): string
{
    $content = (string)($p['status_content'] ?? '');
    // Tope pensado para que la respuesta del movil siga por debajo de su
    // ParserSize de 6000 bytes (la imagen del W200i ocupa ~4.6 KB en base64).
    if ($content === '' || strlen($content) > 4700) {
        return '';
    }
    $kind = (string)($p['content_kind'] ?? '');
    $tag = ($kind === 'referred') ? 'ReferredContent' : 'DirectContent';
    $type = (string)($p['content_type'] ?? '');
    return '<StatusContent><Qualifier>T</Qualifier>' .
        '<' . $tag . '>' . htmlspecialchars($content, ENT_XML1, 'UTF-8') . '</' . $tag . '>' .
        ($type !== '' ? '<ContentType>' . htmlspecialchars($type, ENT_XML1, 'UTF-8') . '</ContentType>' : '') .
        '</StatusContent>';
}

// Notificaciones de presencia pendientes para el usuario movil: contactos cuyo
// estado ha cambiado respecto al ultimo notificado (presence_track). Devuelve
// las primitivas PresenceNotification-Request ya construidas y actualiza el
// track. Se usa tanto en el poll como adjunta a la respuesta de
// SubscribePresence (la spec de transporte permite insertar peticiones del
// servidor en la respuesta a una peticion del cliente).
function presence_notifications(int $mobileUserId, int $limit = 0): array
{
    $out = [];
    if ($mobileUserId <= 0) {
        return $out;
    }
    try {
        $viewerNick = user_nick_by_id($mobileUserId);
        $cs = db()->prepare('SELECT u.id, u.nick FROM contacts c JOIN users u ON u.nick = c.contact_nick WHERE c.owner_id = ? ORDER BY u.nick');
        $cs->execute([$mobileUserId]);
        foreach ($cs->fetchAll() as $contact) {
            $snap = presence_snapshot((string)$contact['nick']);
            if (presence_hidden($mobileUserId, (string)$contact['nick'])) {
                // Bloqueo: se oculta la presencia (aparece desconectado).
                $snap['online'] = false;
                $snap['status_code'] = 'NOT_AVAILABLE';
                $snap['status_mood'] = '';
                $snap['status_text'] = '';
                $snap['status_content'] = '';
            }
            // Autorizacion de presencia (CSP 1.2 §8.2): solo se notifican los
            // atributos que el contacto ha autorizado a este observador.
            $snap = filter_presence($snap, authorized_attrs((int)$contact['id'], $viewerNick));
            $online = !empty($snap['online']) ? 1 : 0;
            $statusText = trim((string)$snap['status_text']);
            $avail = $online ? (string)$snap['status_code'] : 'NOT_AVAILABLE';
            $mood = (string)$snap['status_mood'];
            // Firma del estado completo (no solo el flag online): asi se detectan
            // tambien los cambios de disponibilidad, animo o texto estando ya
            // conectado, no solo las transiciones online/offline.
            // Nota: la imagen de estado (StatusContent) NO se incluye en las
            // notificaciones porque el W200i limita las respuestas a 6000 bytes
            // (ParserSize/AcceptedContentLength) y una sola imagen ya ocupa ~4.6 KB.
            // Si el movil la pide, se entrega en GetPresence-Response.
            $state = implode("\x1f", [$online, $avail, $mood, $statusText]);
            $track = db()->prepare('SELECT state FROM presence_track WHERE mobile_user_id = ? AND contact_user_id = ?');
            $track->execute([$mobileUserId, (int)$contact['id']]);
            $prev = $track->fetchColumn();
            if ($prev === $state) {
                continue;
            }
            $out[] = '<PresenceNotification-Request>' .
                '<Presence>' .
                '<UserID>wv:' . htmlspecialchars((string)$contact['nick'], ENT_XML1, 'UTF-8') . '</UserID>' .
                '<PresenceSubList xmlns="http://www.wireless-village.org/PA1.1">' .
                '<OnlineStatus><Qualifier>T</Qualifier><PresenceValue>' . ($online ? 'T' : 'F') . '</PresenceValue></OnlineStatus>' .
                '<UserAvailability><Qualifier>T</Qualifier><PresenceValue>' . $avail . '</PresenceValue></UserAvailability>' .
                ($mood !== '' ? '<StatusMood><Qualifier>T</Qualifier><PresenceValue>' . $mood . '</PresenceValue></StatusMood>' : '') .
                ($statusText !== '' ? '<StatusText><Qualifier>T</Qualifier><PresenceValue>' . htmlspecialchars($statusText, ENT_XML1, 'UTF-8') . '</PresenceValue></StatusText>' : '') .
                '</PresenceSubList>' .
                '</Presence>' .
                '</PresenceNotification-Request>';
            db()->prepare('INSERT OR REPLACE INTO presence_track (mobile_user_id, contact_user_id, online, state, updated_at) VALUES (?, ?, ?, ?, datetime("now"))')
                ->execute([$mobileUserId, (int)$contact['id'], $online, $state]);
            if ($limit > 0 && count($out) >= $limit) {
                break;
            }
        }
    } catch (Throwable $e) {
    }
    return $out;
}

function user_nick_by_id(int $id): string
{
    $stmt = db()->prepare('SELECT nick FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $nick = $stmt->fetchColumn();
    return is_string($nick) ? $nick : '';
}

function user_id_for(string $nick): int
{
    $nick = preg_replace('/^wv:/i', '', $nick);
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE nick = ?');
    $stmt->execute([$nick]);
    $id = $stmt->fetchColumn();
    if ($id !== false) {
        return (int)$id;
    }
    $pdo->prepare('INSERT INTO users (nick, domain, password_hash) VALUES (?, ?, ?)')->execute([$nick, 'village', password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT)]);
    $pdo->prepare('INSERT OR IGNORE INTO presence (user_id) VALUES (?)')->execute([(int)$pdo->lastInsertId()]);
    return (int)$pdo->lastInsertId();
}

// --- Listas de contactos (IMPS GetList/CreateList/DeleteList) --------------

function owner_lists(int $ownerId): array
{
    if ($ownerId <= 0) return [];
    $stmt = db()->prepare('SELECT list_name, display_name, is_default FROM lists WHERE owner_id = ? ORDER BY is_default DESC, list_name');
    $stmt->execute([$ownerId]);
    return $stmt->fetchAll();
}

// Crea o actualiza una lista. La primera lista del usuario pasa a ser la default
// (CSP 1.1, seccion 8.1.1).
function save_list(int $ownerId, string $listName, string $displayName = '', bool $makeDefault = false): void
{
    if ($ownerId <= 0 || $listName === '') return;
    $cnt = db()->prepare('SELECT COUNT(*) FROM lists WHERE owner_id = ?');
    $cnt->execute([$ownerId]);
    if ((int)$cnt->fetchColumn() === 0) {
        $makeDefault = true;
    }
    db()->prepare('INSERT OR IGNORE INTO lists (owner_id, list_name, display_name, is_default) VALUES (?, ?, ?, 0)')
        ->execute([$ownerId, $listName, $displayName]);
    if ($displayName !== '') {
        db()->prepare('UPDATE lists SET display_name = ? WHERE owner_id = ? AND list_name = ?')
            ->execute([$displayName, $ownerId, $listName]);
    }
    if ($makeDefault) {
        db()->prepare('UPDATE lists SET is_default = 0 WHERE owner_id = ?')->execute([$ownerId]);
        db()->prepare('UPDATE lists SET is_default = 1 WHERE owner_id = ? AND list_name = ?')->execute([$ownerId, $listName]);
    }
}

function delete_list(int $ownerId, string $listName): void
{
    if ($ownerId <= 0 || $listName === '') return;
    db()->prepare('DELETE FROM lists WHERE owner_id = ? AND list_name = ?')->execute([$ownerId, $listName]);
    // Si se borro la lista por defecto, elegir otra como default.
    $d = db()->prepare('SELECT COUNT(*) FROM lists WHERE owner_id = ? AND is_default = 1');
    $d->execute([$ownerId]);
    if ((int)$d->fetchColumn() === 0) {
        db()->prepare('UPDATE lists SET is_default = 1 WHERE owner_id = ? AND list_name = (SELECT list_name FROM lists WHERE owner_id = ? ORDER BY list_name LIMIT 1)')
            ->execute([$ownerId, $ownerId]);
    }
}

// --- Listas de atributos de presencia (IMPS CreateAttributeList/DeleteAttributeList) ---
// CSP 1.2 §8.2: la lista de atributos define la "proactive authorization" (que
// atributos de presencia puede ver cada observador). Es el mecanismo con el que
// el W200i implementa "Mostrar mi estado: a todos / solo contactos".
function attr_names_in(string $presenceSubList): string
{
    static $valid = ['OnlineStatus', 'CommCap', 'UserAvailability', 'StatusText', 'StatusMood', 'StatusContent',
        'Alias', 'Contact', 'UserID', 'ClientType', 'Network', 'Location', 'DateTime', 'Searchable', 'Timezone', 'Unknown'];
    preg_match_all('/<([A-Za-z]+)\s*\/?>/', $presenceSubList, $m);
    $out = [];
    foreach ($m[1] ?? [] as $name) {
        if (in_array($name, $valid, true) && !in_array($name, $out, true)) {
            $out[] = $name;
        }
    }
    return implode(',', $out);
}

// Referencia a lista de contactos ("wv:jat/~pep1.0_privatelist") -> nombre.
function clean_list_ref(string $ref): string
{
    $ref = trim(preg_replace('/^wv:/i', '', $ref, 1));
    $pos = strrpos($ref, '/');
    return $pos === false ? $ref : trim(substr($ref, $pos + 1));
}

function store_attribute_list(int $ownerId, string $xml): void
{
    if ($ownerId <= 0) return;
    preg_match('/<PresenceSubList[^>]*>(.*?)<\/PresenceSubList>/s', $xml, $mPS);
    $attrs = attr_names_in($mPS[1] ?? '');
    preg_match('/<DefaultList>(.*?)<\/DefaultList>/s', $xml, $mDef);
    $isDefault = strtoupper(trim($mDef[1] ?? 'F')) === 'T';
    preg_match_all('/<UserID>(.*?)<\/UserID>/s', $xml, $mUsers);
    preg_match_all('/<ContactList>(.*?)<\/ContactList>/s', $xml, $mLists);
    $upsert = static function (string $kind, string $target) use ($ownerId, $attrs): void {
        db()->prepare('INSERT INTO attr_lists (owner_id, kind, target, attrs, updated_at) VALUES (?, ?, ?, ?, datetime("now"))
            ON CONFLICT(owner_id, kind, target) DO UPDATE SET attrs = excluded.attrs, updated_at = datetime("now")')
            ->execute([$ownerId, $kind, $target, $attrs]);
    };
    foreach ($mUsers[1] ?? [] as $u) {
        $nick = clean_nick($u);
        if ($nick !== '') $upsert('user', $nick);
    }
    foreach ($mLists[1] ?? [] as $l) {
        $name = clean_list_ref($l);
        if ($name !== '') $upsert('list', $name);
    }
    // Default-List=T: ademas de los usuarios/listas indicados, aplica a todos los demas.
    if ($isDefault) {
        $upsert('default', '');
    }
}

function delete_attribute_list(int $ownerId, string $xml): void
{
    if ($ownerId <= 0) return;
    preg_match('/<DefaultList>(.*?)<\/DefaultList>/s', $xml, $mDef);
    $isDefault = strtoupper(trim($mDef[1] ?? 'F')) === 'T';
    preg_match_all('/<UserID>(.*?)<\/UserID>/s', $xml, $mUsers);
    preg_match_all('/<ContactList>(.*?)<\/ContactList>/s', $xml, $mLists);
    foreach ($mUsers[1] ?? [] as $u) {
        $nick = clean_nick($u);
        if ($nick !== '') db()->prepare('DELETE FROM attr_lists WHERE owner_id = ? AND kind = "user" AND target = ?')->execute([$ownerId, $nick]);
    }
    foreach ($mLists[1] ?? [] as $l) {
        $name = clean_list_ref($l);
        if ($name !== '') db()->prepare('DELETE FROM attr_lists WHERE owner_id = ? AND kind = "list" AND target = ?')->execute([$ownerId, $name]);
    }
    if ($isDefault) {
        db()->prepare('DELETE FROM attr_lists WHERE owner_id = ? AND kind = "default" AND target = ""')->execute([$ownerId]);
    }
}


// --- Chat grupal (CSP 1.2 seccion 10, "Group Feature") ---------------------
// El movil crea grupos con un GroupID propio (p.ej.
// "wv:jat/los_fiesteros_20261016t115452z"). Los mensajes se guardan en
// group_messages; cada miembro los lee de ahi (la web) o los recibe por
// Polling (el movil, segun group_members.last_msg_id). Los nombres de los
// elementos siguen el DTD oficial (WV-043 v1.2).

// Sobre estandar de una transaccion (mismo que usan el resto de respuestas).
function wv_response(string $tid, string $sessId, string $content): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' . $content . '</TransactionContent></Transaction></Session></WV-CSP-Message>';
}

function wv_status(string $tid, string $sessId, int $code, string $desc): string
{
    return wv_response($tid, $sessId, '<Status><Result><Code>' . $code . '</Code><Description>' .
        htmlspecialchars($desc, ENT_XML1, 'UTF-8') . '</Description></Result></Status>');
}

// Error de sesion cuando aun no hay SessionID (peticion sin login).
function wv_status_outband(string $tid, int $code, string $desc): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Outband</SessionType></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<Status><Result><Code>' . $code . '</Code><Description>' .
        htmlspecialchars($desc, ENT_XML1, 'UTF-8') . '</Description></Result></Status>' .
        '</TransactionContent></Transaction></Session></WV-CSP-Message>';
}

// Fallo de login: Login-Response con Result != 200 y sin SessionID.
function wv_login_error(string $tid, string $clientUrl, int $code, string $desc): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Outband</SessionType></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<Login-Response><ClientID><URL>' . htmlspecialchars($clientUrl, ENT_XML1, 'UTF-8') . '</URL></ClientID>' .
        '<Result><Code>' . $code . '</Code><Description>' .
        htmlspecialchars($desc, ENT_XML1, 'UTF-8') . '</Description></Result>' .
        '</Login-Response></TransactionContent></Transaction></Session></WV-CSP-Message>';
}

// Contenido de un elemento de primer nivel.
function wv_inner(string $xml, string $tag): string
{
    $q = preg_quote($tag, '/');
    if (preg_match('/<' . $q . '>(.*?)<\/' . $q . '>/s', $xml, $m)) {
        return $m[1];
    }
    return '';
}

// <GroupProperties>/<OwnProperties> -> [Nombre => Valor].
function wv_parse_props(string $inner): array
{
    $out = [];
    preg_match_all('/<Property>(.*?)<\/Property>/s', $inner, $m);
    foreach ($m[1] ?? [] as $p) {
        preg_match('/<Name>(.*?)<\/Name>/s', $p, $mn);
        preg_match('/<Value>(.*?)<\/Value>/s', $p, $mv);
        $name = trim($mn[1] ?? '');
        if ($name !== '') {
            $out[$name] = trim($mv[1] ?? '');
        }
    }
    return $out;
}

function wv_props_xml(array $props): string
{
    $out = '';
    foreach ($props as $name => $value) {
        $out .= '<Property><Name>' . htmlspecialchars((string)$name, ENT_XML1, 'UTF-8') . '</Name>' .
            '<Value>' . htmlspecialchars((string)$value, ENT_XML1, 'UTF-8') . '</Value></Property>';
    }
    return $out;
}

// (Nota: los <Mapping>/<UserMapList> de CSP 1.2 no se usan: el W200i es 1.1 y
// usa <UserList> de <ScreenName>. Ver wv_user_list_xml y PROTOCOL.md.)

// <UserList> (DTD: User* | ScreenName*).
function wv_user_list_xml(array $members, bool $screenNames = false, string $gid = ''): string
{
    if (!$members) {
        return '';
    }
    $s = '<UserList>';
    foreach ($members as $m) {
        if ($screenNames) {
            $s .= '<ScreenName><SName>' . htmlspecialchars((string)$m['sname'], ENT_XML1, 'UTF-8') . '</SName>' .
                ($gid !== '' ? '<GroupID>' . htmlspecialchars($gid, ENT_XML1, 'UTF-8') . '</GroupID>' : '') . '</ScreenName>';
        } else {
            $s .= '<User><UserID>wv:' . htmlspecialchars((string)$m['nick'], ENT_XML1, 'UTF-8') . '</UserID></User>';
        }
    }
    return $s . '</UserList>';
}

function group_get(string $gid): ?array
{
    try {
        $st = db()->prepare('SELECT * FROM groups WHERE group_id = ?');
        $st->execute([$gid]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function group_exists(string $gid): bool
{
    return group_get($gid) !== null;
}

// "El dueno o un usuario con privilegios suficientes" (Features & Functions
// §7.3.2-§7.3.4). El dueno manda siempre (aunque otro tenga el privilegio
// Admin, p. ej. tras quitarlo de su lista); si no, hace falta privilegio Admin.
function group_is_admin(string $gid, int $userId): bool
{
    $g = group_get($gid);
    if ($g !== null && (int)$g['owner_id'] === $userId) {
        return true;
    }
    return strcasecmp(group_privilege($gid, $userId), 'Admin') === 0;
}

function group_props(string $gid): array
{
    $g = group_get($gid);
    if (!$g) {
        return [];
    }
    $props = json_decode((string)$g['props'], true);
    return is_array($props) ? $props : [];
}

function group_store(string $gid, int $ownerId, array $props, string $welcome = ''): void
{
    $name = (string)($props['Name'] ?? '');
    $access = (string)($props['Accesstype'] ?? 'Open');
    db()->prepare('INSERT INTO groups (group_id, owner_id, name, accesstype, props, welcome) VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(group_id) DO UPDATE SET name = excluded.name, accesstype = excluded.accesstype, props = excluded.props, welcome = excluded.welcome')
        ->execute([$gid, $ownerId, $name, $access, json_encode($props), $welcome]);
}

// Encola una primitiva cruda para que el movil la reciba en su proximo Polling.
function group_queue(int $userId, string $xml): void
{
    if ($userId <= 0) {
        return;
    }
    try {
        db()->prepare('INSERT INTO wv_notices (user_id, xml) VALUES (?, ?)')->execute([$userId, $xml]);
    } catch (Throwable $e) {
    }
}

// Fecha/hora WV (YYYYMMDDTHHMMSSZ) a partir del created_at de SQLite (UTC).
// Se usa la hora REAL del mensaje, no la del envio: si no, todo lo que llega
// con retraso se quedaria con la hora del poll y el movil los ordenaria mal.
function wv_msg_datetime(string $createdAt): string
{
    $ts = $createdAt !== '' ? strtotime($createdAt . ' UTC') : false;
    return gmdate('Ymd\THis\Z', $ts === false ? time() : $ts);
}

// Cuantas primitivas seguidas admite el cliente en un mismo mensaje (MultiTrans).
// Traza del protocolo en csp.log. Solo se escribe con debug = true: en
// produccion no interesa (el fichero crece sin fin en un alojamiento
// compartido y ademas guarda el contenido de los mensajes de la gente). Los
// errores de verdad (lo que no se puede decodificar o codificar) se registran
// siempre, para que un fallo no pase inadvertido.
function wv_log(string $line, bool $always = false): void
{
    global $config;
    if (!$always && empty($config['debug'])) {
        return;
    }
    if (!isset($config['log_path']) || $config['log_path'] === '') {
        return;
    }
    @file_put_contents($config['log_path'], $line, FILE_APPEND);
}

// Envia una respuesta (codificando a WBXML si el cliente lo uso) y termina.
function wv_send(string $xmlResponse, bool $binary, bool $forceText): void
{
    wv_log("RESP:\n" . $xmlResponse . "\n");

    if ($binary && !$forceText) {
        try {
            $out = WbxmlCodec::encode($xmlResponse);
            header('Content-Type: application/vnd.wv.csp.wbxml');
            echo $out;
        } catch (Throwable $e) {
            wv_log('ENCODE ERROR: ' . $e->getMessage() . "\n", true);
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'WBXML encode not implemented';
        }
    } else {
        header('Content-Type: text/xml; charset=utf-8');
        echo $xmlResponse;
    }
    exit;
}

function wv_multitrans(string $sessId): int
{
    try {
        $st = db()->prepare('SELECT multitrans FROM wv_sessions WHERE session_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$sessId]);
        $v = (int)$st->fetchColumn();
        return $v > 0 ? min($v, 10) : 1;
    } catch (Throwable $e) {
        return 1;
    }
}

// Primitivas pendientes para $userId, en el orden en que deben llegar:
// mensajes individuales -> avisos (GroupChangeNotice, invitaciones...) ->
// mensajes de grupo. Cada una se marca como entregada al recogerla.
// Orden 2->3 a proposito: si alguien entra y escribe, primero el "se ha unido"
// (asi el movil pinta al participante) y despues el mensaje.
// Primitivas pendientes para un usuario, EN ORDEN CRONOLOGICO. Las tres fuentes
// (mensajes individuales, avisos del servidor y mensajes de grupo) se juntan y se
// ordenan por su hora de creacion: asi el movil recibe entradas/salidas y
// mensajes mezclados tal y como han pasado, y no primero todos los avisos y luego
// todos los mensajes.
//   - Misma hora (created_at de SQLite tiene precision de segundo): primero los
//     avisos y despues los mensajes, para que si alguien entra y escribe en el
//     mismo segundo el movil ya tenga la lista de participantes actualizada.
//   - Los avisos con sort_first=1 (sincronizacion de la lista al reconectar) van
//     por delante de todo: son estado, no un suceso.
// Solo se marcan como entregadas las primitivas que caben en $max.
function wv_pending_primitives(int $userId, int $max): array
{
    if ($userId <= 0 || $max <= 0) {
        return [];
    }
    $nick = user_nick_by_id($userId);
    $cola = [];
    // 1) Mensajes individuales.
    try {
        // Red de seguridad: no entregar mensajes de remitentes bloqueados (el
        // bloqueo ya se aplica al enviar, tanto en movil como en web).
        $stmt = db()->prepare('SELECT id, from_user, content_type, body, created_at FROM messages WHERE to_user = ? AND (delivered_at IS NULL OR delivered_at = "") AND from_user NOT IN (SELECT contact_nick FROM blocked WHERE owner_id = ?) ORDER BY id ASC LIMIT ' . (int)$max);
        $stmt->execute([$nick, $userId]);
        foreach ($stmt->fetchAll() as $row) {
            $ct = (string)($row['content_type'] ?? '');
            if ($ct === '') {
                $ct = 'text/plain';
            }
            $body = (string)($row['body'] ?? '');
            $cola[] = [
                'first' => 0,
                'key' => (string)($row['created_at'] ?? ''),
                'prio' => 1,
                'src' => 0,
                'id' => (int)$row['id'],
                'xml' => '<NewMessage>' .
                    '<MessageInfo>' .
                    '<MessageID>msg-' . (int)$row['id'] . '</MessageID>' .
                    '<ContentType>' . htmlspecialchars($ct, ENT_XML1, 'UTF-8') . '</ContentType>' .
                    '<ContentSize>' . strlen($body) . '</ContentSize>' .
                    '<Sender><User><UserID>wv:' . htmlspecialchars((string)$row['from_user'], ENT_XML1, 'UTF-8') . '</UserID></User></Sender>' .
                    '<Recipient><User><UserID>wv:' . htmlspecialchars($nick, ENT_XML1, 'UTF-8') . '</UserID></User></Recipient>' .
                    '<DateTime>' . wv_msg_datetime((string)($row['created_at'] ?? '')) . '</DateTime>' .
                    '</MessageInfo>' .
                    '<ContentData>' . htmlspecialchars($body, ENT_XML1, 'UTF-8') . '</ContentData>' .
                    '</NewMessage>',
                'marcar' => static function () use ($row): void {
                    db()->prepare('UPDATE messages SET delivered_at = datetime("now") WHERE id = ?')->execute([(int)$row['id']]);
                },
            ];
        }
    } catch (Throwable $e) {}
    // 2) Avisos pendientes del servidor (GroupChangeNotice, invitaciones...).
    try {
        $st = db()->prepare('SELECT id, xml, created_at, sort_first FROM wv_notices WHERE user_id = ? AND (sent_at IS NULL OR sent_at = "") ORDER BY id ASC LIMIT ' . (int)$max);
        $st->execute([$userId]);
        foreach ($st->fetchAll() as $notice) {
            $cola[] = [
                'first' => (int)($notice['sort_first'] ?? 0) > 0 ? 1 : 0,
                'key' => (string)($notice['created_at'] ?? ''),
                'prio' => 0,
                'src' => 1,
                'id' => (int)$notice['id'],
                'xml' => (string)$notice['xml'],
                'marcar' => static function () use ($notice): void {
                    db()->prepare('UPDATE wv_notices SET sent_at = datetime("now") WHERE id = ?')->execute([(int)$notice['id']]);
                },
            ];
        }
    } catch (Throwable $e) {}
    // 3) Mensajes de grupo: solo de los grupos en los que estoy dentro
    // (member y joined), y solo los posteriores a mi last_msg_id (asi no recibo
    // el eco de lo ya entregado; el filtro por remitente evita el mio propio).
    try {
        $st = db()->prepare('SELECT group_id, last_msg_id FROM group_members WHERE user_id = ? AND member = 1 AND joined = 1');
        $st->execute([$userId]);
        $myGroups = $st->fetchAll();
    } catch (Throwable $e) {
        $myGroups = [];
    }
    foreach ($myGroups as $grp) {
        try {
            $st = db()->prepare('SELECT id, from_user, body, created_at FROM group_messages WHERE group_id = ? AND id > ? AND kind = "msg" AND from_user <> ? AND from_user NOT IN (SELECT contact_nick FROM blocked WHERE owner_id = ?) ORDER BY id ASC LIMIT ' . (int)$max);
            $st->execute([(string)$grp['group_id'], (int)$grp['last_msg_id'], $nick, $userId]);
            $gmsgs = $st->fetchAll();
        } catch (Throwable $e) {
            $gmsgs = [];
        }
        foreach ($gmsgs as $gm) {
            $body = (string)$gm['body'];
            $gid = (string)$grp['group_id'];
            $gmid = (int)$gm['id'];
            $cola[] = [
                'first' => 0,
                'key' => (string)($gm['created_at'] ?? ''),
                'prio' => 1,
                'src' => 2,
                'id' => $gmid,
                'xml' => '<NewMessage>' .
                    '<MessageInfo>' .
                    '<MessageID>msg-' . $gmid . '</MessageID>' .
                    '<ContentType>text/plain</ContentType>' .
                    '<ContentSize>' . strlen($body) . '</ContentSize>' .
                    // El remitente de un mensaje de grupo va como ScreenName
                    // (SName + GroupID), no como UserID: asi lo dicen los
                    // ejemplos del DTD oficial y asi el W200i muestra "loko"
                    // en lugar de "wv:loko".
                    '<Sender><Group><ScreenName><SName>' . htmlspecialchars(group_screen_name($gid, (string)$gm['from_user']), ENT_XML1, 'UTF-8') . '</SName><GroupID>' . htmlspecialchars($gid, ENT_XML1, 'UTF-8') . '</GroupID></ScreenName></Group></Sender>' .
                    '<Recipient><Group><GroupID>' . htmlspecialchars($gid, ENT_XML1, 'UTF-8') . '</GroupID></Group></Recipient>' .
                    '<DateTime>' . wv_msg_datetime((string)($gm['created_at'] ?? '')) . '</DateTime>' .
                    '</MessageInfo>' .
                    '<ContentData>' . htmlspecialchars($body, ENT_XML1, 'UTF-8') . '</ContentData>' .
                    '</NewMessage>',
                'marcar' => static function () use ($gid, $gmid, $userId): void {
                    db()->prepare('UPDATE group_members SET last_msg_id = ? WHERE group_id = ? AND user_id = ?')
                        ->execute([$gmid, $gid, $userId]);
                },
            ];
        }
    }
    // Orden final y recorte a $max (MultiTrans). Las que no caben se quedan
    // pendientes, sin marcar, para la siguiente entrega.
    usort($cola, static function (array $a, array $b): int {
        if ($a['first'] !== $b['first']) {
            return $b['first'] <=> $a['first'];
        }
        if ($a['key'] !== $b['key']) {
            return strcmp($a['key'], $b['key']);
        }
        if ($a['prio'] !== $b['prio']) {
            return $a['prio'] <=> $b['prio'];
        }
        if ($a['src'] !== $b['src']) {
            return $a['src'] <=> $b['src'];
        }
        return $a['id'] <=> $b['id'];
    });
    $out = [];
    foreach (array_slice($cola, 0, $max) as $p) {
        try {
            ($p['marcar'])();
        } catch (Throwable $e) {
        }
        $out[] = (string)$p['xml'];
    }
    return $out;
}

// Cada primitiva pendiente va como PETICION del servidor (Client <- Server) con
// su propio TransactionID, hasta MultiTrans transacciones en el mismo Session.
function wv_requests_xml(array $requests, bool $pollLast = false): string
{
    $out = '';
    $n = count($requests);
    foreach ($requests as $i => $reqXml) {
        $serverTid = 'srv' . strtoupper(bin2hex(random_bytes(4)));
        // Poll=T en la ultima transaccion: pedimos al cliente que vuelva a
        // pollear enseguida para vaciar la cola sin esperar al intervalo.
        $pollFlag = ($pollLast && $i === $n - 1) ? 'T' : 'F';
        $out .= '<Transaction><TransactionDescriptor><TransactionMode>Request</TransactionMode>' .
            '<TransactionID>' . htmlspecialchars($serverTid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>' . $pollFlag . '</Poll></TransactionDescriptor>' .
            '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' . $reqXml . '</TransactionContent></Transaction>';
    }
    return $out;
}

// Cuela primitivas pendientes en la respuesta a una peticion del cliente. La
// respuesta ya ocupa una transaccion, asi que caben MultiTrans-1. Se usa al
// enviar un mensaje: sin esto, el mensaje que te acababan de mandar no aparecia
// hasta el siguiente Polling (2 min) y en pantalla salia antes el tuyo.
// La transaccion que se cuela lleva <Poll>T</Poll>: el movil vuelve a pollear
// de inmediato y se lleva el resto de la hilera (2 por vuelta, en cadena) en
// vez de esperar al poll de dentro de 2 min. Asi no hace falta pasarse del
// MultiTrans que declara el cliente.
function wv_piggyback(string $xmlResponse, int $userId, int $max): string
{
    if ($userId <= 0 || $max <= 0) {
        return $xmlResponse;
    }
    $extra = wv_requests_xml(wv_pending_primitives($userId, $max), true);
    if ($extra === '') {
        return $xmlResponse;
    }
    $pos = strrpos($xmlResponse, '</Session>');
    if ($pos === false) {
        return $xmlResponse;
    }
    return substr($xmlResponse, 0, $pos) . $extra . substr($xmlResponse, $pos);
}

function group_add_message(string $gid, string $fromNick, string $body, string $ct = 'text/plain'): int
{
    db()->prepare('INSERT INTO group_messages (group_id, from_user, body, content_type) VALUES (?, ?, ?, ?)')
        ->execute([$gid, $fromNick, $body, $ct]);
    $id = (int)db()->lastInsertId();
    // Historial acotado (WV_HISTORY_LIMIT por grupo).
    prune_group_history($gid);
    return $id;
}

// GroupID de primer nivel. Los <GroupID> que van dentro de <Recipient> o de
// <ScreenName> (Invite-Request, SendMessage) no cuentan.
function wv_top_group_id(string $xml): string
{
    $stripped = preg_replace('/<Recipient>.*?<\/Recipient>/s', '', $xml, 1);
    $stripped = preg_replace('/<ScreenName>.*?<\/ScreenName>/s', '', $stripped);
    if (preg_match('/<GroupID>(.*?)<\/GroupID>/s', $stripped, $m)) {
        return trim($m[1]);
    }
    return '';
}

// Grupo al que apunta un <Recipient> de SendMessage (<Group><GroupID>..).
function group_recipient_in(string $xml): string
{
    if (preg_match('/<Recipient>(.*?)<\/Recipient>/s', $xml, $mRec) && strpos($mRec[1], '<Group>') !== false) {
        if (preg_match('/<GroupID>(.*?)<\/GroupID>/s', $mRec[1], $mGid)) {
            return trim($mGid[1]);
        }
    }
    return '';
}

$raw = read_raw_http_body();
$ct = request_content_type();
$binary = is_wbxml($raw, $ct);
$forceText = false;

$line = date('c') . " METHOD=" . ($_SERVER['REQUEST_METHOD'] ?? '') .
        " CT=" . $ct . " BINARY=" . ($binary ? '1' : '0') .
        " LEN=" . strlen($raw) . "\n";
wv_log($line . bin2hex(substr($raw, 0, 256)) . "\n");

$xml = null;
try {
    if ($binary) {
        $xml = WbxmlCodec::decode($raw);
    } else {
        $xml = $raw;
    }
} catch (Throwable $e) {
    wv_log('DECODE ERROR: ' . $e->getMessage() . "\n", true);
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Bad request';
    exit;
}

if ($xml === null || trim($xml) === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Empty body';
    exit;
}

wv_log("XML:\n" . $xml . "\n");

preg_match('/<TransactionID>(.*?)<\/TransactionID>/s', $xml, $mTid);
$tid = $mTid[1] ?? 'tmp';
preg_match('/<URL>(.*?)<\/URL>/s', $xml, $mUrl);
$clientUrl = $mUrl[1] ?? 'WV://SEMC/NC/1.0';
preg_match('/<SessionID>(.*?)<\/SessionID>/s', $xml, $mSess);
$sessId = trim($mSess[1] ?? '');

$mobileSessionUserId = user_id_by_session($sessId);
if ($mobileSessionUserId > 0) {
    try {
        touch_presence($mobileSessionUserId);
    } catch (Throwable $e) {}
}

// Sin sesion valida solo se permiten las primitivas de arranque. Antes, si no
// habia sesion, muchas ramas continuaban como el usuario de pruebas 'jat':
// cualquiera podia leer y enviar como el. Ahora se corta aqui.
$isLoginRequest = strpos($xml, '<Login-Request>') !== false;
$isCapabilityRequest = strpos($xml, '<ClientCapability-Request>') !== false;
$isVersionDiscovery = strpos($xml, '<VersionDiscovery-Request') !== false;
if ($mobileSessionUserId <= 0 && !$isLoginRequest && !$isCapabilityRequest && !$isVersionDiscovery) {
    // Si el cliente mando una sesion (aunque este caducada) se le responde
    // Inband con ese mismo SessionID; si no mando ninguna, Outband.
    $unauth = $sessId !== ''
        ? wv_status($tid, $sessId, 401, 'Not logged in')
        : wv_status_outband($tid, 401, 'Not logged in');
    wv_send($unauth, $binary, $forceText);
}

$capList = '';
if (strpos($xml, '<ClientCapability-Request>') !== false) {
    preg_match('/<CapabilityList>(.*?)<\/CapabilityList>/s', $xml, $mCap);
    $capList = trim($mCap[1] ?? '');
    // MultiTrans: cuantas primitivas puede manejar el cliente en un mismo mensaje.
    if (preg_match('/<MultiTrans>(.*?)<\/MultiTrans>/s', $xml, $mMt)) {
        $mt = (int)trim($mMt[1]);
        if ($mt > 0) {
            try { db()->prepare('UPDATE wv_sessions SET multitrans = ? WHERE session_id = ?')->execute([$mt, $sessId]); } catch (Throwable $e) {}
        }
    }
    $serverFields = '';
    if (strpos($capList, '<ServerPollMin>') === false) {
        $serverFields .= '<ServerPollMin>3</ServerPollMin>';
    }
    if (strpos($capList, '<TCPAddress>') === false) {
        $serverFields = '<TCPAddress>198.251.89.144</TCPAddress><TCPPort>80</TCPPort>' . $serverFields;
    }
    if ($serverFields !== '') {
        if (preg_match('/<DefaultLanguage>/', $capList)) {
            $capList = preg_replace('/<DefaultLanguage>/', $serverFields . '<DefaultLanguage>', $capList, 1);
        } else {
            $capList .= $serverFields;
        }
    }
}

$hasDigest = preg_match('/<DigestBytes>(.*?)<\/DigestBytes>/s', $xml, $mDigest) === 1;

if (strpos($xml, '<Login-Request>') !== false) {
    preg_match('/<UserID>(.*?)<\/UserID>/s', $xml, $mUser);
    $mobileUser = trim(preg_replace('/^wv:/i', '', $mUser[1] ?? '', 1));
    $mobileUser = explode('/', $mobileUser)[0];

    // Anti fuerza bruta. Clave separada de la web para no mezclar contadores.
    $throttleKey = 'imps:' . client_ip();
    $blocked = throttle_check($throttleKey);
    if ($blocked !== null) {
        wv_send(wv_login_error($tid, $clientUrl, 409, $blocked), $binary, $forceText);
    }

    if ($hasDigest) {
        $digest = trim($mDigest[1]);

        $stmt = db()->prepare('SELECT id, imps_password FROM users WHERE nick = ?');
        $stmt->execute([$mobileUser]);
        $row = $stmt->fetch();
        $stmt->closeCursor();

        $st = db()->prepare('SELECT nonce FROM imps_challenges WHERE nick = ? AND created_at > datetime("now", "-2 minutes") ORDER BY id DESC LIMIT 1');
        $st->execute([$mobileUser]);
        $nonce = (string)$st->fetchColumn();
        $st->closeCursor();

        $phonePassword = $row ? imps_decrypt((string)$row['imps_password']) : '';
        $ok = false;
        if ($row && $nonce !== '' && $phonePassword !== '') {
            // CSP: DigestBytes = BASE64(MD5(nonce + password)). Se aceptan
            // tambien hex, BASE64(hex) y base64 sin relleno/URL-safe por
            // compatibilidad con clientes viejos.
            $raw = md5($nonce . $phonePassword, true);
            $hex = md5($nonce . $phonePassword);
            // Algunos clientes antiguos concatenan al reves; se acepta tambien.
            $rawAlt = md5($phonePassword . $nonce, true);
            $hexAlt = md5($phonePassword . $nonce);
            $candidates = [];
            foreach ([$raw, $rawAlt] as $bin) {
                $b64 = base64_encode($bin);
                $candidates[] = $b64;
                $candidates[] = rtrim($b64, '=');
                $candidates[] = strtr($b64, '+/', '-_');
                $candidates[] = rtrim(strtr($b64, '+/', '-_'), '=');
            }
            $candidates[] = $hex;
            $candidates[] = $hexAlt;
            $candidates[] = base64_encode($hex);
            $candidates[] = base64_encode($hexAlt);
            $digestLower = strtolower($digest);
            foreach ($candidates as $candidate) {
                if (hash_equals($candidate, $digest) || hash_equals($candidate, $digestLower)) {
                    $ok = true;
                    break;
                }
            }
        }

        // El reto es de un solo uso, acierte o falle.
        db()->prepare('DELETE FROM imps_challenges WHERE nick = ?')->execute([$mobileUser]);

        if (!$ok) {
            throttle_record($throttleKey, false);
            wv_log('LOGIN FAIL nick=' . $mobileUser . ' ip=' . client_ip()
                . ' digest_len=' . strlen($digest) . ' nonce=' . ($nonce !== '' ? 'yes' : 'no') . "\n", true);
            wv_send(wv_login_error($tid, $clientUrl, 409, 'Invalid password'), $binary, $forceText);
        }

        throttle_record($throttleKey, true);
        wv_log('LOGIN OK nick=' . $mobileUser . ' ip=' . client_ip() . "\n", true);

        // El SessionID lo genera el servidor: no se acepta el que mande el
        // cliente en el Login-Request (evita fijacion de sesion).
        $sessId = bin2hex(random_bytes(16));
        $mobileUserId = (int)$row['id'];
        $expiry = gmdate('Y-m-d H:i:s', time() + 1800);
        $sessionCookie = '';
        if (preg_match('/<SessionCookie>(.*?)<\/SessionCookie>/s', $xml, $mCookie)) {
            $sessionCookie = trim($mCookie[1]);
        }
        try { db()->prepare('DELETE FROM wv_sessions WHERE expires_at < datetime("now")')->execute(); } catch (Throwable $e) {}
        // Una sola sesion IMPS activa por usuario: al entrar de nuevo se cierra
        // la anterior (si el movil perdio la respuesta, no queda una zombie).
        db()->prepare('DELETE FROM wv_sessions WHERE user_id = ?')->execute([$mobileUserId]);
        db()->prepare('INSERT INTO wv_sessions (user_id, session_id, session_cookie, expires_at) VALUES (?, ?, ?, ?)')
            ->execute([$mobileUserId, $sessId, $sessionCookie, $expiry]);
        $GLOBALS['mobile_user_id'] = $mobileUserId;
        try { db()->prepare('DELETE FROM presence_track WHERE mobile_user_id = ?')->execute([$mobileUserId]); } catch (Throwable $e) {}
        try { touch_presence($mobileUserId); } catch (Throwable $e) {}
        $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
            '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
            '<Session><SessionDescriptor><SessionType>Outband</SessionType></SessionDescriptor>' .
            '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
            '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
            '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
            '<Login-Response>' .
            '<ClientID><URL>' . htmlspecialchars($clientUrl, ENT_XML1, 'UTF-8') . '</URL></ClientID>' .
            '<Result><Code>200</Code><Description>Successfully logged in.</Description></Result>' .
            '<SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID>' .
            '<KeepAliveTime>120</KeepAliveTime>' .
            '<CapabilityRequest>T</CapabilityRequest>' .
            '</Login-Response>' .
            '</TransactionContent></Transaction></Session></WV-CSP-Message>';
    } else {
        // Fase 1: se emite un reto. Se guarda el nonce y la fase 2 lo consume.
        $nonce = bin2hex(random_bytes(16));
        db()->prepare('DELETE FROM imps_challenges WHERE nick = ?')->execute([$mobileUser]);
        try { db()->prepare('DELETE FROM imps_challenges WHERE created_at < datetime("now", "-10 minutes")')->execute(); } catch (Throwable $e) {}
        db()->prepare('INSERT INTO imps_challenges (nick, client_url, nonce) VALUES (?, ?, ?)')
            ->execute([$mobileUser, $clientUrl, $nonce]);
        $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
            '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
            '<Session><SessionDescriptor><SessionType>Outband</SessionType></SessionDescriptor>' .
            '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
            '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
            '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
            '<Login-Response>' .
            '<ClientID><URL>' . htmlspecialchars($clientUrl, ENT_XML1, 'UTF-8') . '</URL></ClientID>' .
            '<Result><Code>200</Code><Description>Further authorization needed.</Description></Result>' .
            '<Nonce>' . $nonce . '</Nonce>' .
            '<DigestSchema>MD5</DigestSchema>' .
            '<CapabilityRequest>F</CapabilityRequest>' .
            '</Login-Response>' .
            '</TransactionContent></Transaction></Session></WV-CSP-Message>';
    }
} elseif (strpos($xml, '<GetList-Request') !== false) {
    $ownerId = $mobileSessionUserId > 0 ? $mobileSessionUserId : user_id_by_session($sessId);
    $listXml = '';
    $defaultList = '';
    foreach (owner_lists($ownerId) as $l) {
        $listXml .= '<ContactList>' . htmlspecialchars((string)$l['list_name'], ENT_XML1, 'UTF-8') . '</ContactList>';
        if ((int)$l['is_default'] === 1) { $defaultList = (string)$l['list_name']; }
    }
    $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<GetList-Response>' .
        $listXml .
        ($defaultList !== '' ? '<DefaultContactList>' . htmlspecialchars($defaultList, ENT_XML1, 'UTF-8') . '</DefaultContactList>' : '') .
        '</GetList-Response>' .
        '</TransactionContent></Transaction></Session></WV-CSP-Message>';
} elseif (strpos($xml, '<ClientCapability-Request>') !== false) {
    $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<ClientCapability-Response>' .
        '<ClientID><URL>' . htmlspecialchars($clientUrl, ENT_XML1, 'UTF-8') . '</URL></ClientID>' .
        '<CapabilityList>' . $capList . '</CapabilityList>' .
        '</ClientCapability-Response>' .
        '</TransactionContent></Transaction></Session></WV-CSP-Message>';
} elseif (strpos($xml, '<GetPresence-Request') !== false) {
    preg_match_all('/<UserID>(.*?)<\/UserID>/s', $xml, $mUsers);
    $presenceXml = '';
    $viewerNick = $mobileSessionUserId > 0 ? user_nick_by_id($mobileSessionUserId) : '';
    foreach (array_unique($mUsers[1] ?? []) as $target) {
        $nick = clean_nick($target);
        if ($nick === '') continue;
        $p = presence_snapshot($nick);
        if ($mobileSessionUserId > 0 && presence_hidden($mobileSessionUserId, $nick)) {
            $p['online'] = false;
            $p['status_code'] = 'NOT_AVAILABLE';
            $p['status_mood'] = '';
            $p['status_text'] = '';
            $p['status_content'] = '';
        }
        // Autorizacion de presencia (CSP 1.2 §8.2): el servidor solo entrega los
        // atributos que el usuario observado ha autorizado al que pregunta.
        $p = filter_presence($p, authorized_attrs((int)($p['id'] ?? 0), $viewerNick));
        $online = !empty($p['online']);
        $statusText = (string)$p['status_text'];
        $avail = $online ? (string)$p['status_code'] : 'NOT_AVAILABLE';
        $mood = (string)$p['status_mood'];
        $presenceXml .= '<Presence>' .
            '<UserID>wv:' . htmlspecialchars($nick, ENT_XML1, 'UTF-8') . '</UserID>' .
            '<PresenceSubList xmlns="http://www.wireless-village.org/PA1.1">' .
            '<OnlineStatus><Qualifier>T</Qualifier><PresenceValue>' . ($online ? 'T' : 'F') . '</PresenceValue></OnlineStatus>' .
            '<UserAvailability><Qualifier>T</Qualifier><PresenceValue>' . $avail . '</PresenceValue></UserAvailability>' .
            ($mood !== '' ? '<StatusMood><Qualifier>T</Qualifier><PresenceValue>' . $mood . '</PresenceValue></StatusMood>' : '') .
            ($statusText !== '' ? '<StatusText><Qualifier>T</Qualifier><PresenceValue>' . htmlspecialchars($statusText, ENT_XML1, 'UTF-8') . '</PresenceValue></StatusText>' : '') .
            status_content_xml($p) .
            '</PresenceSubList>' .
            '</Presence>';
    }
    $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<GetPresence-Response>' .
        '<Result><Code>200</Code><Description>Successfully completed.</Description></Result>' .
        $presenceXml .
        '</GetPresence-Response>' .
        '</TransactionContent></Transaction></Session></WV-CSP-Message>';
    // El boton "Ver estado" del movil tambien vale de "refrescar": en la misma
    // respuesta se cuelan los mensajes/avisos pendientes (con <Poll>T</Poll>),
    // igual que al enviar un mensaje. Si el GetPresence llegara fuera de la
    // sesion (outband, sin SessionID) no habria usuario de sesion y no se cuela
    // nada: asi no se pierde nada que el movil no fuese a procesar.
    $xmlResponse = wv_piggyback($xmlResponse, $mobileSessionUserId, wv_multitrans($sessId) - 1);
} elseif (strpos($xml, '<UpdatePresence-Request') !== false) {
    if ($mobileSessionUserId > 0) {
        preg_match('/<UserAvailability>.*?<PresenceValue>(.*?)<\/PresenceValue>/s', $xml, $mAvail);
        preg_match('/<StatusText>.*?<PresenceValue>(.*?)<\/PresenceValue>/s', $xml, $mText);
        preg_match('/<StatusMood>.*?<PresenceValue>(.*?)<\/PresenceValue>/s', $xml, $mMood);
        // Imagen/logo de estado (WV-029 StatusContent). El W200i la envia como
        // <StatusContent><Qualifier>T</Qualifier><DirectContent>base64</DirectContent>
        // <ContentType>image/jpg</ContentType></StatusContent> dentro del propio
        // UpdatePresence. null = no venia (se conserva); '' = venia vacia (se borra).
        $content = null;
        $contentType = null;
        $contentKind = null;
        if (preg_match('/<StatusContent>(.*?)<\/StatusContent>/s', $xml, $mSc)) {
            $scBody = $mSc[1];
            $content = '';
            $contentType = '';
            $contentKind = '';
            if (preg_match('/<DirectContent>(.*?)<\/DirectContent>/s', $scBody, $mDc)) {
                $content = trim($mDc[1]);
                $contentKind = 'direct';
            } elseif (preg_match('/<ReferredContent>(.*?)<\/ReferredContent>/s', $scBody, $mRc)) {
                $content = trim($mRc[1]);
                $contentKind = 'referred';
            }
            if ($content === '') {
                $contentKind = '';
            }
            if (preg_match('/<ContentType>(.*?)<\/ContentType>/s', $scBody, $mCt)) {
                $contentType = trim($mCt[1]);
            }
        }
        // "Invisible": OnlineStatus Qualifier=F o CommCap/IM Status=CLOSED (IMPS impl. guidelines).
        preg_match('/<OnlineStatus>.*?<Qualifier>(.*?)<\/Qualifier>/s', $xml, $mOsQ);
        preg_match('/<CommC>.*?<Cap>IM<\/Cap>.*?<Status>(.*?)<\/Status>/s', $xml, $mIm);
        $invisible = (strtoupper(trim($mOsQ[1] ?? 'T')) === 'F') || (strtoupper(trim($mIm[1] ?? 'OPEN')) === 'CLOSED');
        if ($invisible) {
            $code = 'INVISIBLE';
        } else {
            $ua = strtoupper(trim($mAvail[1] ?? 'AVAILABLE'));
            // "Comunica" (ocupado) = DISCREET; el resto = disponible.
            $code = in_array($ua, ['DISCREET', 'NOT_AVAILABLE', 'BUSY'], true) ? 'DISCREET' : 'AVAILABLE';
        }
        set_presence($mobileSessionUserId, $code, trim($mText[1] ?? ''), $mMood[1] ?? null, $content, $contentType, $contentKind);
    }
    $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<Status><Result><Code>200</Code><Description>Status updated</Description></Result></Status>' .
        '</TransactionContent></Transaction></Session></WV-CSP-Message>';
} elseif (strpos($xml, '<CreateAttributeList-Request') !== false) {
    // CSP 1.2 §8.2: la lista de atributos define la autorizacion de presencia
    // (que atributos puede ver cada observador). Se guarda para aplicarla al
    // entregar presencia (GetPresence y notificaciones).
    $attrOwnerId = $mobileSessionUserId > 0 ? $mobileSessionUserId : user_id_by_session($sessId);
    store_attribute_list($attrOwnerId, $xml);
    $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<Status><Result><Code>200</Code><Description>Attribute list created</Description></Result></Status>' .
        '</TransactionContent></Transaction></Session></WV-CSP-Message>';
} elseif (strpos($xml, '<DeleteAttributeList-Request') !== false) {
    $attrOwnerId = $mobileSessionUserId > 0 ? $mobileSessionUserId : user_id_by_session($sessId);
    delete_attribute_list($attrOwnerId, $xml);
    $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<Status><Result><Code>200</Code><Description>Attribute list deleted</Description></Result></Status>' .
        '</TransactionContent></Transaction></Session></WV-CSP-Message>';
} elseif (strpos($xml, '<ListManage-Request') !== false) {
    preg_match('/<ContactList>(.*?)<\/ContactList>/s', $xml, $mList);
    $list = trim($mList[1] ?? 'My_friends');
    // El movil SE usa listas propias: ~pep1.0_subscriptions-1, ~pep1.0_privatelist,
    // ~pep1.0_blocklist. La de bloqueados NO debe mezclarse con los contactos.
    $isBlockList = (stripos($list, 'block') !== false);
    preg_match_all('/<AddNickList>(.*?)<\/AddNickList>/s', $xml, $mAdds);
    preg_match_all('/<RemoveNickList>(.*?)<\/RemoveNickList>/s', $xml, $mRemoves);
    $ownerId = isset($GLOBALS['mobile_user_id']) && $GLOBALS['mobile_user_id'] > 0 ? (int)$GLOBALS['mobile_user_id'] : user_id_by_session($sessId);
    $cleanTarget = function (string $target): string {
        $target = trim(preg_replace('/^wv:/i', '', $target, 1));
        return (string)(explode('/', $target)[0] ?? $target);
    };
    // RemoveNickList lleva solo UserID+.
    $extractNicks = function ($blocks) use ($cleanTarget) {
        $out = [];
        foreach ($blocks ?? [] as $block) {
            preg_match_all('/<UserID>(.*?)<\/UserID>/s', $block, $mUsers);
            foreach ($mUsers[1] as $target) {
                $target = $cleanTarget($target);
                if ($target !== '') { $out[] = $target; }
            }
        }
        return $out;
    };
    // AddNickList lleva (NickName | UserID)*. En un NickName, Name es el alias
    // (el nombre interno que oculta el UserID) y UserID es el contacto real. Al
    // "editar alias" el movil reenvia el mismo UserID con un Name nuevo.
    $extractAdds = function ($blocks) use ($cleanTarget) {
        $out = [];
        foreach ($blocks ?? [] as $block) {
            if (preg_match_all('/<NickName>(.*?)<\/NickName>/s', $block, $nn, PREG_SET_ORDER)) {
                foreach ($nn as $item) {
                    preg_match('/<UserID>(.*?)<\/UserID>/s', $item[1], $mu);
                    preg_match('/<Name>(.*?)<\/Name>/s', $item[1], $mn);
                    $target = $cleanTarget($mu[1] ?? '');
                    if ($target === '') { continue; }
                    $out[$target] = trim((string)($mn[1] ?? ''));
                }
            }
            // UserID sueltos (sin NickName) en el mismo bloque.
            $stripped = preg_replace('/<NickName>.*?<\/NickName>/s', '', $block);
            preg_match_all('/<UserID>(.*?)<\/UserID>/s', $stripped, $mUsers);
            foreach ($mUsers[1] as $target) {
                $target = $cleanTarget($target);
                if ($target !== '' && !array_key_exists($target, $out)) { $out[$target] = ''; }
            }
        }
        return $out;
    };
    $adds = $extractAdds($mAdds[1] ?? []);
    $removeNicks = $extractNicks($mRemoves[1] ?? []);
    if ($isBlockList) {
        foreach (array_keys($adds) as $target) {
            try { db()->prepare('INSERT OR IGNORE INTO blocked (owner_id, contact_nick) VALUES (?, ?)')->execute([$ownerId, $target]); } catch (Throwable $e) {}
        }
        foreach ($removeNicks as $target) {
            try { db()->prepare('DELETE FROM blocked WHERE owner_id = ? AND contact_nick = ?')->execute([$ownerId, $target]); } catch (Throwable $e) {}
        }
        $stmt = db()->prepare("SELECT contact_nick, '' AS alias FROM blocked WHERE owner_id = ? ORDER BY contact_nick");
    } else {
        foreach ($adds as $target => $alias) {
            try {
                db()->prepare('INSERT OR IGNORE INTO contacts (owner_id, contact_nick, list_name) VALUES (?, ?, ?)')->execute([$ownerId, $target, $list]);
                if ($alias !== '') {
                    db()->prepare('UPDATE contacts SET alias = ? WHERE owner_id = ? AND contact_nick = ?')->execute([$alias, $ownerId, $target]);
                }
            } catch (Throwable $e) {}
        }
        foreach ($removeNicks as $target) {
            try { db()->prepare('DELETE FROM contacts WHERE owner_id = ? AND contact_nick = ?')->execute([$ownerId, $target]); } catch (Throwable $e) {}
        }
        $stmt = db()->prepare('SELECT contact_nick, alias FROM contacts WHERE owner_id = ? ORDER BY contact_nick');
    }
    // ContactListProperties: la spec permite fijar la lista por defecto.
    if (!$isBlockList && preg_match('/<Property>\s*<Name>Default<\/Name>\s*<Value>\s*T\s*<\/Value>\s*<\/Property>/s', $xml)) {
        save_list($ownerId, $list, '', true);
    }
    $stmt->execute([$ownerId]);
    $rows = $stmt->fetchAll();
    $nickListXml = '';
    foreach ($rows as $row) {
        $contact = (string)$row['contact_nick'];
        $alias = trim((string)($row['alias'] ?? ''));
        $display = $alias !== '' ? $alias : $contact;
        $nickListXml .= '<NickName><Name>' . htmlspecialchars($display, ENT_XML1, 'UTF-8') . '</Name><UserID>wv:' . htmlspecialchars($contact, ENT_XML1, 'UTF-8') . '</UserID></NickName>';
    }
    $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<ListManage-Response>' .
        '<Result><Code>200</Code><Description>OK</Description></Result>' .
        '<NickList>' . $nickListXml . '</NickList>' .
        '</ListManage-Response>' .
        '</TransactionContent></Transaction></Session></WV-CSP-Message>';
} elseif (strpos($xml, '<SubscribePresence-Request') !== false) {
    // Ademas del Status, adjuntamos la presencia actual de los contactos como
    // peticiones del servidor. La spec de transporte permite insertar peticiones
    // del servidor en la respuesta a una peticion del cliente; asi el movil ve
    // el estado al instante, sin esperar al siguiente poll (~2 min).
    $notifTransactions = '';
    if ($mobileSessionUserId > 0) {
        foreach (presence_notifications($mobileSessionUserId) as $notif) {
            $serverTid = 'srv' . strtoupper(bin2hex(random_bytes(4)));
            $notifTransactions .= '<Transaction><TransactionDescriptor><TransactionMode>Request</TransactionMode>' .
                '<TransactionID>' . htmlspecialchars($serverTid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
                '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' . $notif . '</TransactionContent></Transaction>';
        }
    }
    $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<Status><Result><Code>200</Code><Description>Subscribed</Description></Result></Status>' .
        '</TransactionContent></Transaction>' .
        $notifTransactions .
        '</Session></WV-CSP-Message>';
} elseif (strpos($xml, '<UnsubscribePresence-Request') !== false) {
    // CSP 1.1, tabla 34: la respuesta a UnsubscribePresence es Status.
    $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<Status><Result><Code>200</Code><Description>Unsubscribed</Description></Result></Status>' .
        '</TransactionContent></Transaction></Session></WV-CSP-Message>';
} elseif (strpos($xml, '<CreateList-Request') !== false) {
    // CSP 1.1, tabla 34: CreateListRequest -> Status. Recordamos la lista del
    // movil (~pep1.0_*) para devolverla en GetList y no perderla al reconectar.
    $ownerId = $mobileSessionUserId > 0 ? $mobileSessionUserId : user_id_by_session($sessId);
    preg_match('/<ContactList>(.*?)<\/ContactList>/s', $xml, $mCL);
    $listName = trim($mCL[1] ?? '');
    $displayName = '';
    if (preg_match('/<Property>\s*<Name>DisplayName<\/Name>\s*<Value>(.*?)<\/Value>\s*<\/Property>/s', $xml, $mDN)) {
        $displayName = trim($mDN[1]);
    }
    if ($listName !== '') { save_list($ownerId, $listName, $displayName); }
    $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<Status><Result><Code>200</Code><Description>List created</Description></Result></Status>' .
        '</TransactionContent></Transaction></Session></WV-CSP-Message>';
} elseif (strpos($xml, '<DeleteList-Request') !== false) {
    $ownerId = $mobileSessionUserId > 0 ? $mobileSessionUserId : user_id_by_session($sessId);
    preg_match('/<ContactList>(.*?)<\/ContactList>/s', $xml, $mCL);
    $listName = trim($mCL[1] ?? '');
    if ($listName !== '') { delete_list($ownerId, $listName); }
    $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<Status><Result><Code>200</Code><Description>List deleted</Description></Result></Status>' .
        '</TransactionContent></Transaction></Session></WV-CSP-Message>';
} elseif (strpos($xml, '<GetBlockedList-Request') !== false) {
    $ownerId = $mobileSessionUserId > 0 ? $mobileSessionUserId : 0;
    $stmt = db()->prepare('SELECT contact_nick FROM blocked WHERE owner_id = ? ORDER BY contact_nick');
    $stmt->execute([$ownerId]);
    $blockedNicks = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $blockedXml = '';
    foreach ($blockedNicks as $b) {
        $blockedXml .= '<UserID>wv:' . htmlspecialchars((string)$b, ENT_XML1, 'UTF-8') . '</UserID>';
    }
    $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<GetBlockedList-Response>' .
        '<BlockList><InUse>' . (count($blockedNicks) ? 'T' : 'F') . '</InUse><EntityList>' . $blockedXml . '</EntityList></BlockList>' .
        '<GrantList><InUse>F</InUse><EntityList/></GrantList>' .
        '</GetBlockedList-Response>' .
        '</TransactionContent></Transaction></Session></WV-CSP-Message>';
} elseif (strpos($xml, '<BlockEntity-Request') !== false) {
    $ownerId = $mobileSessionUserId > 0 ? $mobileSessionUserId : 0;
    if (preg_match('/<BlockList>(.*?)<\/BlockList>/s', $xml, $mBl) && trim($mBl[1]) !== '') {
        $bl = $mBl[1];
        preg_match('/<InUse>(.*?)<\/InUse>/s', $bl, $mInUse);
        if (isset($mInUse[1]) && strtoupper(trim($mInUse[1])) === 'F') {
            db()->prepare('DELETE FROM blocked WHERE owner_id = ?')->execute([$ownerId]);
        } else {
            if (preg_match('/<EntityList>(.*?)<\/EntityList>/s', $bl, $mEnt)) {
                db()->prepare('DELETE FROM blocked WHERE owner_id = ?')->execute([$ownerId]);
                preg_match_all('/<UserID>(.*?)<\/UserID>/s', $mEnt[1], $mIds);
                foreach ($mIds[1] as $id) {
                    $n = clean_nick($id);
                    if ($n !== '') { db()->prepare('INSERT OR IGNORE INTO blocked (owner_id, contact_nick) VALUES (?, ?)')->execute([$ownerId, $n]); }
                }
            }
            if (preg_match('/<AddList>(.*?)<\/AddList>/s', $bl, $mAdd)) {
                preg_match_all('/<UserID>(.*?)<\/UserID>/s', $mAdd[1], $mIds);
                foreach ($mIds[1] as $id) {
                    $n = clean_nick($id);
                    if ($n !== '') { db()->prepare('INSERT OR IGNORE INTO blocked (owner_id, contact_nick) VALUES (?, ?)')->execute([$ownerId, $n]); }
                }
            }
            if (preg_match('/<RemoveList>(.*?)<\/RemoveList>/s', $bl, $mRem)) {
                preg_match_all('/<UserID>(.*?)<\/UserID>/s', $mRem[1], $mIds);
                foreach ($mIds[1] as $id) {
                    $n = clean_nick($id);
                    if ($n !== '') { db()->prepare('DELETE FROM blocked WHERE owner_id = ? AND contact_nick = ?')->execute([$ownerId, $n]); }
                }
            }
        }
    }
    $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<Status><Result><Code>200</Code><Description>OK</Description></Result></Status>' .
        '</TransactionContent></Transaction></Session></WV-CSP-Message>';
} elseif (strpos($xml, '<CreateGroup-Request') !== false) {
    // CSP 1.2 §10.2 (DTD: GroupID, GroupProperties, JoinGroup, ScreenName?,
    // SubscribeNotification) -> Status. El creador queda como Admin y, si
    // JoinGroup=T, entra en el grupo.
    $userId = $mobileSessionUserId > 0 ? $mobileSessionUserId : 0;
    $gid = wv_top_group_id($xml);
    $props = wv_parse_props(wv_inner($xml, 'GroupProperties'));
    $welcome = wv_inner(wv_inner($xml, 'GroupProperties'), 'WelcomeNote');
    $join = strtoupper(trim(wv_inner($xml, 'JoinGroup'))) === 'T';
    $subs = strtoupper(trim(wv_inner($xml, 'SubscribeNotification'))) === 'T';
    $sname = trim(wv_inner(wv_inner($xml, 'ScreenName'), 'SName'));
    if ($gid === '') {
        $xmlResponse = wv_status($tid, $sessId, 400, 'Bad Request');
    } elseif (group_exists($gid)) {
        $xmlResponse = wv_status($tid, $sessId, 801, 'Group already exists');
    } else {
        group_store($gid, $userId, $props, $welcome);
        group_member_set($gid, $userId, [
            'privilege' => 'Admin', 'member' => 1, 'joined' => $join ? 1 : 0,
            'screen_name' => $sname !== '' ? $sname : user_nick_by_id($userId),
            'subscribed' => $subs ? 1 : 0,
        ]);
        wv_log('GRP: creado ' . $gid . ' por ' . user_nick_by_id($userId) . "\n");
        $xmlResponse = wv_status($tid, $sessId, 200, 'Successfully completed.');
    }
} elseif (strpos($xml, '<Search-Request') !== false) {
    // Busqueda de grupos (canales) por nombre o ID.
    $searchStr = '';
    $sp = wv_inner($xml, 'SearchPairList');
    if ($sp !== '') {
        $searchStr = trim(wv_inner($sp, 'SearchString'));
    }
    if ($searchStr === '') {
        $searchStr = trim(wv_inner($xml, 'SearchString'));
    }
    $limit = (int)trim(wv_inner($xml, 'SearchLimit'));
    if ($limit <= 0) { $limit = 10; }
    if ($limit > 50) { $limit = 50; }
    $like = '%' . $searchStr . '%';
    $rows = [];
    try {
        $st = db()->prepare('SELECT group_id, name FROM groups WHERE name LIKE ? OR group_id LIKE ? LIMIT ?');
        $st->execute([$like, $like, $limit]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    $findings = count($rows);
    $resultXml = '';
    foreach ($rows as $r) {
        $resultXml .= '<User><UserID>' . htmlspecialchars((string)$r['group_id'], ENT_XML1, 'UTF-8') . '</UserID></User>';
    }
    $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<Search-Response>' .
        '<SearchFindings>' . $findings . '</SearchFindings>' .
        '<CompletionFlag>T</CompletionFlag>' .
        '<SearchIndex>0</SearchIndex>' .
        ($resultXml !== '' ? '<SearchResult><UserList>' . $resultXml . '</UserList></SearchResult>' : '') .
        '</Search-Response>' .
        '</TransactionContent></Transaction></Session></WV-CSP-Message>';
    wv_log('SEARCH: "' . $searchStr . '" -> ' . $findings . " resultados\n");
} elseif (strpos($xml, '<DeleteGroup-Request') !== false) {
    // CSP 1.2 §10.3 -> Status. Avisa a los que estan dentro y borra el grupo.
    $userId = $mobileSessionUserId > 0 ? $mobileSessionUserId : 0;
    $gid = wv_top_group_id($xml);
    $g = group_get($gid);
    if ($g === null) {
        $xmlResponse = wv_status($tid, $sessId, 800, 'Group does not exist');
    } elseif ((int)$g['owner_id'] !== $userId) {
        $xmlResponse = wv_status($tid, $sessId, 816, 'Insufficient group privileges');
    } else {
        foreach (group_members($gid) as $m) {
            if ((int)$m['user_id'] === $userId) continue;
            group_queue((int)$m['user_id'], '<LeaveGroup-Response><GroupID>' . htmlspecialchars($gid, ENT_XML1, 'UTF-8') .
                '</GroupID><Result><Code>200</Code><Description>Group deleted.</Description></Result></LeaveGroup-Response>');
        }
        db()->prepare('DELETE FROM group_messages WHERE group_id = ?')->execute([$gid]);
        db()->prepare('DELETE FROM group_members WHERE group_id = ?')->execute([$gid]);
        db()->prepare('DELETE FROM group_invites WHERE group_id = ?')->execute([$gid]);
        db()->prepare('DELETE FROM groups WHERE group_id = ?')->execute([$gid]);
        wv_log('GRP: borrado ' . $gid . "\n");
        $xmlResponse = wv_status($tid, $sessId, 200, 'Successfully completed.');
    }
} elseif (strpos($xml, '<JoinGroup-Request') !== false) {
    // CSP (DTD: GroupID, ScreenName?, JoinedRequest, SubscribeNotification,
    // OwnProperties?) -> JoinGroup-Response (UserList?, WelcomeNote?; sin
    // Result). El W200i es CSP 1.1, donde la lista es un UserList de
    // ScreenName (SName + GroupID), no un UserMapList de 1.2.
    $userId = $mobileSessionUserId > 0 ? $mobileSessionUserId : 0;
    $nick = user_nick_by_id($userId);
    $gid = wv_top_group_id($xml);
    $g = group_get($gid);
    $sname = trim(wv_inner(wv_inner($xml, 'ScreenName'), 'SName'));
    $subs = strtoupper(trim(wv_inner($xml, 'SubscribeNotification'))) === 'T';
    $wantList = strtoupper(trim(wv_inner($xml, 'JoinedRequest'))) === 'T';
    $row = group_member_row($gid, $userId);
    $wasJoined = $row !== null && (int)$row['joined'] === 1;
    if ($g === null) {
        $xmlResponse = wv_status($tid, $sessId, 800, 'Group does not exist');
    } elseif ($row === null && strcasecmp((string)$g['accesstype'], 'Restricted') === 0) {
        // 810 "Not a group member": quien no esta en la lista de miembros de un
        // grupo Restricted no puede entrar (antes devolviamos 402, que es "Bad
        // Parameter" y no dice nada).
        $xmlResponse = wv_status($tid, $sessId, 810, 'Not a member of the group');
    } else {
        $mine = $sname !== '' ? $sname : ($row !== null && (string)$row['screen_name'] !== '' ? (string)$row['screen_name'] : $nick);
        if ($row === null) {
            group_member_set($gid, $userId, ['privilege' => 'User', 'member' => 1, 'joined' => 1, 'screen_name' => $mine, 'subscribed' => $subs ? 1 : 0]);
        } else {
            group_member_set($gid, $userId, ['joined' => 1, 'subscribed' => $subs ? 1 : 0, 'screen_name' => $mine]);
        }
        // Solo los mensajes que lleguen a partir de ahora.
        try {
            db()->prepare('UPDATE group_members SET last_msg_id = (SELECT COALESCE(MAX(id), 0) FROM group_messages WHERE group_id = ?) WHERE group_id = ? AND user_id = ?')
                ->execute([$gid, $gid, $userId]);
        } catch (Throwable $e) {}
        $content = '<JoinGroup-Response>';
        if ($wantList) {
            $joined = group_members($gid, true);
            $content .= $joined ? wv_user_list_xml($joined, true, $gid) : '<UserList></UserList>';
        }
        if (trim((string)$g['welcome']) !== '') {
            // WelcomeNote (ContentType, ContentEncoding?, ContentData).
            $content .= '<WelcomeNote><ContentType>text/plain</ContentType><ContentData>' .
                htmlspecialchars((string)$g['welcome'], ENT_XML1, 'UTF-8') . '</ContentData></WelcomeNote>';
        }
        $content .= '</JoinGroup-Response>';
        $xmlResponse = wv_response($tid, $sessId, $content);
        // Linea de sistema para el chat de la web (solo si entra de verdad: el
        // W200i manda JoinGroup cada vez que abre el grupo, tambien estando ya
        // dentro).
        if (!$wasJoined) {
            group_add_event($gid, 'join', $nick);
            // El movil "acepta" la invitacion mandando JoinGroup: si tenia una
            // pendiente, queda aceptada (si no, la web la seguiria ofreciendo).
            group_invites_accept_pending($gid, $userId);
        }
        // $tellJoiner: el movil aprende el nombre del grupo (GroupProperties) y
        // deja de titularlo con el GroupID. Se manda tambien si ya estaba
        // dentro, para arreglar grupos abiertos antes de este cambio.
        group_notify_join($gid, $userId, true);
    }
} elseif (strpos($xml, '<LeaveGroup-Request') !== false) {
    // CSP 1.2 §10.5 (DTD: GroupID) -> LeaveGroup-Response (GroupID?, Result).
    $userId = $mobileSessionUserId > 0 ? $mobileSessionUserId : 0;
    $nick = user_nick_by_id($userId);
    $gid = wv_top_group_id($xml);
    $row = group_member_row($gid, $userId);
    if (group_get($gid) === null) {
        $xmlResponse = wv_status($tid, $sessId, 800, 'Group does not exist');
    } elseif ($row === null || (int)$row['joined'] !== 1) {
        $xmlResponse = wv_status($tid, $sessId, 808, 'Group was not joined before transaction');
    } else {
        $mine = (string)$row['screen_name'] !== '' ? (string)$row['screen_name'] : $nick;
        group_member_set($gid, $userId, ['joined' => 0]);
        $xmlResponse = wv_response($tid, $sessId, '<LeaveGroup-Response><GroupID>' . htmlspecialchars($gid, ENT_XML1, 'UTF-8') .
            '</GroupID><Result><Code>200</Code><Description>Own request.</Description></Result></LeaveGroup-Response>');
        group_add_event($gid, 'leave', $nick);
        group_notify_leave($gid, $userId, $userId);
    }
} elseif (strpos($xml, '<GetGroupMembers-Request') !== false) {
    // CSP 1.2 §10.6 -> GetGroupMembers-Response (Admin?, Mod?, Users?). La
    // lista de miembros solo la ven los miembros (§10.6: 810 si no lo eres).
    $userId = $mobileSessionUserId > 0 ? $mobileSessionUserId : 0;
    $gid = wv_top_group_id($xml);
    if (group_get($gid) === null) {
        $xmlResponse = wv_status($tid, $sessId, 800, 'Group does not exist');
    } elseif (group_privilege($gid, $userId) === '') {
        $xmlResponse = wv_status($tid, $sessId, 810, 'Not a member of the group');
    } else {
        $members = group_members($gid);
        $content = '<GetGroupMembers-Response>';
        foreach (['Admin', 'Mod'] as $lvl) {
            $sel = array_values(array_filter($members, static fn($m) => $m['privilege'] === $lvl));
            if ($sel) {
                $content .= '<' . $lvl . '>' . wv_user_list_xml($sel) . '</' . $lvl . '>';
            }
        }
        $users = array_values(array_filter($members, static fn($m) => $m['privilege'] !== 'Admin' && $m['privilege'] !== 'Mod'));
        if ($users) {
            $content .= '<Users>' . wv_user_list_xml($users) . '</Users>';
        }
        $content .= '</GetGroupMembers-Response>';
        $xmlResponse = wv_response($tid, $sessId, $content);
    }
} elseif (strpos($xml, '<GetJoinedUsers-Request') !== false) {
    // CSP §10.7 -> GetJoinedUsers-Response. En 1.2 seria AdminMapList; el
    // W200i (1.1) solo conoce UserList, asi que se responde en forma 1.1.
    $userId = $mobileSessionUserId > 0 ? $mobileSessionUserId : 0;
    $gid = wv_top_group_id($xml);
    $row = group_member_row($gid, $userId);
    if (group_get($gid) === null) {
        $xmlResponse = wv_status($tid, $sessId, 800, 'Group does not exist');
    } elseif ($row === null || (int)$row['joined'] !== 1) {
        $xmlResponse = wv_status($tid, $sessId, 808, 'Group was not joined before transaction');
    } else {
        $joined = group_members($gid, true);
        $content = '<GetJoinedUsers-Response>' .
            ($joined ? wv_user_list_xml($joined, true, $gid) : '<UserList></UserList>') .
            '</GetJoinedUsers-Response>';
        $xmlResponse = wv_response($tid, $sessId, $content);
    }
} elseif (strpos($xml, '<AddGroupMembers-Request') !== false) {
    // CSP 1.2 §10.6 (DTD: GroupID, UserList) -> Status. Anade a la lista de
    // miembros (no los mete en el grupo: para eso esta JoinGroup/Invite).
    // §7.3.3: "an owner or user with sufficient privileges may add users to the
    // list of members" -> solo Admin (816).
    $userId = $mobileSessionUserId > 0 ? $mobileSessionUserId : 0;
    $gid = wv_top_group_id($xml);
    if (group_get($gid) === null) {
        $xmlResponse = wv_status($tid, $sessId, 800, 'Group does not exist');
    } elseif (!group_is_admin($gid, $userId)) {
        $xmlResponse = wv_status($tid, $sessId, 816, 'Insufficient group privileges');
    } else {
        preg_match_all('/<UserID>(.*?)<\/UserID>/s', wv_inner($xml, 'UserList'), $mIds);
        foreach (array_unique($mIds[1] ?? []) as $rawId) {
            $n = clean_nick($rawId);
            if ($n === '') continue;
            $uid = user_id_for($n);
            if (group_member_row($gid, $uid) === null) {
                group_member_set($gid, $uid, ['privilege' => 'User', 'member' => 1, 'joined' => 0, 'screen_name' => $n]);
            } else {
                group_member_set($gid, $uid, ['member' => 1, 'rejected' => 0]);
            }
            wv_log('GRP: ' . $gid . ' += ' . $n . "\n");
        }
        $xmlResponse = wv_status($tid, $sessId, 200, 'Successfully completed.');
    }
} elseif (strpos($xml, '<RemoveGroupMembers-Request') !== false) {
    // CSP 1.2 §10.6 (DTD: GroupID, UserList) -> Status. Igual que para anadir:
    // solo el dueno o un Admin (§7.3.3) -> 816.
    $userId = $mobileSessionUserId > 0 ? $mobileSessionUserId : 0;
    $gid = wv_top_group_id($xml);
    if (group_get($gid) === null) {
        $xmlResponse = wv_status($tid, $sessId, 800, 'Group does not exist');
    } elseif (!group_is_admin($gid, $userId)) {
        $xmlResponse = wv_status($tid, $sessId, 816, 'Insufficient group privileges');
    } else {
        preg_match_all('/<UserID>(.*?)<\/UserID>/s', wv_inner($xml, 'UserList'), $mIds);
        foreach (array_unique($mIds[1] ?? []) as $rawId) {
            $n = clean_nick($rawId);
            if ($n === '') continue;
            db()->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?')->execute([$gid, user_id_for($n)]);
        }
        $xmlResponse = wv_status($tid, $sessId, 200, 'Successfully completed.');
    }
} elseif (strpos($xml, '<MemberAccess-Request') !== false) {
    // CSP 1.2 §10.6 (DTD: GroupID, Admin?, Mod?, Users?) -> Status. Repartir
    // los privilegios es lo mas "admin" que hay: solo el dueno o un Admin, y
    // nadie se los cambia a si mismo si no lo es (816).
    $userId = $mobileSessionUserId > 0 ? $mobileSessionUserId : 0;
    $gid = wv_top_group_id($xml);
    if (group_get($gid) === null) {
        $xmlResponse = wv_status($tid, $sessId, 800, 'Group does not exist');
    } elseif (!group_is_admin($gid, $userId)) {
        $xmlResponse = wv_status($tid, $sessId, 816, 'Insufficient group privileges');
    } else {
        foreach (['Admin' => 'Admin', 'Mod' => 'Mod', 'Users' => 'User'] as $tag => $priv) {
            $inner = wv_inner($xml, $tag);
            if ($inner === '') continue;
            preg_match_all('/<UserID>(.*?)<\/UserID>/s', $inner, $mIds);
            foreach (array_unique($mIds[1] ?? []) as $rawId) {
                $n = clean_nick($rawId);
                if ($n === '') continue;
                $uid = user_id_for($n);
                if (group_member_row($gid, $uid) !== null) {
                    group_member_set($gid, $uid, ['privilege' => $priv]);
                }
            }
        }
        $xmlResponse = wv_status($tid, $sessId, 200, 'Successfully completed.');
    }
} elseif (strpos($xml, '<GetGroupProps-Request') !== false) {
    // CSP 1.2 §10.7 -> GetGroupProps-Response (GroupProperties, OwnProperties).
    $userId = $mobileSessionUserId > 0 ? $mobileSessionUserId : 0;
    $gid = wv_top_group_id($xml);
    $g = group_get($gid);
    if ($g === null) {
        $xmlResponse = wv_status($tid, $sessId, 800, 'Group does not exist');
    } else {
        $props = group_props($gid);
        $props['ActiveUsers'] = (string)count(group_members($gid, true));
        $row = group_member_row($gid, $userId);
        $own = [
            'PrivateMessaging' => $row ? ((int)$row['private_messaging'] === 1 ? 'T' : 'F') : 'F',
            'IsMember' => $row !== null ? 'T' : 'F',
            'PrivilegeLevel' => $row !== null ? (string)$row['privilege'] : 'User',
        ];
        $content = '<GetGroupProps-Response><GroupProperties>' . wv_props_xml($props);
        if (trim((string)$g['welcome']) !== '') {
            $content .= '<WelcomeNote>' . $g['welcome'] . '</WelcomeNote>';
        }
        $content .= '</GroupProperties><OwnProperties>' . wv_props_xml($own) . '</OwnProperties></GetGroupProps-Response>';
        $xmlResponse = wv_response($tid, $sessId, $content);
    }
} elseif (strpos($xml, '<SetGroupProps-Request') !== false) {
    // CSP 1.2 §10.7 (DTD: GroupID, GroupProperties?, OwnProperties?) -> Status.
    // Cambiar las propiedades DEL GRUPO es de "un usuario con privilegios
    // suficientes" (§7.3.4) -> Admin (816). Cambiar las TUYAS (tu ScreenName
    // dentro del grupo) solo necesita ser miembro (810).
    $userId = $mobileSessionUserId > 0 ? $mobileSessionUserId : 0;
    $gid = wv_top_group_id($xml);
    $g = group_get($gid);
    $gp = wv_parse_props(wv_inner($xml, 'GroupProperties'));
    $op = wv_parse_props(wv_inner($xml, 'OwnProperties'));
    if ($g === null) {
        $xmlResponse = wv_status($tid, $sessId, 800, 'Group does not exist');
    } elseif ($gp && !group_is_admin($gid, $userId)) {
        $xmlResponse = wv_status($tid, $sessId, 816, 'Insufficient group privileges');
    } elseif ($op && group_privilege($gid, $userId) === '') {
        $xmlResponse = wv_status($tid, $sessId, 810, 'Not a member of the group');
    } else {
        if ($gp) {
            group_store($gid, (int)$g['owner_id'], array_merge(group_props($gid), $gp), (string)$g['welcome']);
        }
        if ($op && isset($op['ScreenName'])) {
            group_member_set($gid, $userId, ['screen_name' => $op['ScreenName']]);
        }
        $xmlResponse = wv_status($tid, $sessId, 200, 'Successfully completed.');
    }
} elseif (strpos($xml, '<RejectList-Request') !== false) {
    // CSP 1.2 §10.8 (DTD: GroupID, AddList?, RemoveList?) ->
    // RejectList-Response (UserList?). Echar a alguien del grupo es gestion de
    // la lista de miembros: solo el dueno o un Admin (§7.3.3) -> 816.
    $userId = $mobileSessionUserId > 0 ? $mobileSessionUserId : 0;
    $gid = wv_top_group_id($xml);
    if (group_get($gid) === null) {
        $xmlResponse = wv_status($tid, $sessId, 800, 'Group does not exist');
    } elseif (!group_is_admin($gid, $userId)) {
        $xmlResponse = wv_status($tid, $sessId, 816, 'Insufficient group privileges');
    } else {
        foreach (['AddList' => 1, 'RemoveList' => 0] as $tag => $flag) {
            $inner = wv_inner($xml, $tag);
            if ($inner === '') continue;
            preg_match_all('/<UserID>(.*?)<\/UserID>/s', $inner, $mIds);
            foreach (array_unique($mIds[1] ?? []) as $rawId) {
                $n = clean_nick($rawId);
                if ($n === '') continue;
                $uid = user_id_for($n);
                if (group_member_row($gid, $uid) === null) {
                    group_member_set($gid, $uid, ['privilege' => 'User', 'member' => 1, 'joined' => 0, 'rejected' => $flag]);
                } else {
                    group_member_set($gid, $uid, ['rejected' => $flag, 'joined' => 0]);
                }
            }
        }
        try {
            $st = db()->prepare('SELECT u.nick, gm.screen_name FROM group_members gm JOIN users u ON u.id = gm.user_id WHERE gm.group_id = ? AND gm.rejected = 1');
            $st->execute([$gid]);
            $rej = [];
            foreach ($st->fetchAll() as $r) {
                $rej[] = ['nick' => (string)$r['nick'], 'sname' => (string)$r['screen_name'] !== '' ? (string)$r['screen_name'] : (string)$r['nick']];
            }
        } catch (Throwable $e) {
            $rej = [];
        }
        $xmlResponse = wv_response($tid, $sessId, '<RejectList-Response>' . ($rej ? wv_user_list_xml($rej) : '') . '</RejectList-Response>');
    }
} elseif (strpos($xml, '<SubscribeGroupNotice-Request') !== false) {
    // CSP 1.2 §10.9: get (SubscribeType=G) -> SubscribeGroupNotice-Response
    // (Value); set (S) / unset (U) -> Status. Suscribirse a los avisos delata
    // quien entra y quien sale del grupo, asi que es cosa de sus miembros (810).
    $userId = $mobileSessionUserId > 0 ? $mobileSessionUserId : 0;
    $gid = wv_top_group_id($xml);
    $type = strtoupper(trim(wv_inner($xml, 'SubscribeType')));
    if (group_get($gid) === null) {
        $xmlResponse = wv_status($tid, $sessId, 800, 'Group does not exist');
    } elseif (group_privilege($gid, $userId) === '') {
        $xmlResponse = wv_status($tid, $sessId, 810, 'Not a member of the group');
    } elseif ($type === 'G') {
        $row = group_member_row($gid, $userId);
        $cur = $row !== null ? (int)$row['subscribed'] : 0;
        $xmlResponse = wv_response($tid, $sessId, '<SubscribeGroupNotice-Response><Value>' . ($cur ? 'T' : 'F') . '</Value></SubscribeGroupNotice-Response>');
    } else {
        group_member_set($gid, $userId, ['subscribed' => $type === 'S' ? 1 : 0]);
        $xmlResponse = wv_status($tid, $sessId, 200, 'Successfully completed.');
    }
} elseif (strpos($xml, '<Invite-Request') !== false) {
    // CSP 1.2 §7.13 / §5.3.6: invitacion general (InviteType=GR = a un grupo).
    // -> Status, y al invitado se le manda InviteUser-Request (por polling).
    // Invitar lo puede hacer "un usuario" (§7.2: "A user MAY invite other
    // user(s)..."), pero tiene que ser del grupo: si no, 810. Un Invite de tipo
    // UC (a un contacto, sin GroupID) sigue funcionando igual.
    $userId = $mobileSessionUserId > 0 ? $mobileSessionUserId : 0;
    $inviterNick = user_nick_by_id($userId);
    $gid = wv_top_group_id($xml);
    if ($gid !== '' && group_get($gid) === null) {
        $xmlResponse = wv_status($tid, $sessId, 800, 'Group does not exist');
    } elseif ($gid !== '' && group_privilege($gid, $userId) === '') {
        $xmlResponse = wv_status($tid, $sessId, 810, 'Not a member of the group');
    } else {
        $inviteId = trim(wv_inner($xml, 'InviteID'));
        if ($inviteId === '') {
            $inviteId = 'inv-' . bin2hex(random_bytes(4));
        }
        $inviteType = strtoupper(trim(wv_inner($xml, 'InviteType')));
        if ($inviteType === '') {
            $inviteType = 'GR';
        }
        $note = trim(wv_inner($xml, 'InviteNote'));
        $sname = trim(wv_inner(wv_inner($xml, 'ScreenName'), 'SName'));
        $invitees = [];
        preg_match_all('/<Recipient>(.*?)<\/Recipient>/s', $xml, $mRecs);
        foreach ($mRecs[1] ?? [] as $rec) {
            preg_match_all('/<UserID>(.*?)<\/UserID>/s', $rec, $mIds);
            foreach ($mIds[1] ?? [] as $rawId) {
                $n = clean_nick($rawId);
                if ($n !== '') {
                    $invitees[$n] = true;
                }
            }
        }
        $sender = $sname !== '' ? $sname : $inviterNick;
        foreach (array_keys($invitees) as $n) {
            $uid = user_id_for($n);
            // La invitacion NO mete al invitado en la lista de miembros: eso
            // pasa al aceptar (InviteUser-Response). Se guarda la invitacion y
            // se le encola el InviteUser-Request. Misma funcion que usa la web
            // al invitar.
            group_invite_send($gid, $userId, $sender, $uid, $note, $inviteId, $inviteType);
            wv_log('GRP: invitacion ' . $inviteId . ' ' . $inviterNick . ' -> ' . $n . ' (' . $gid . ")\n");
        }
        $xmlResponse = wv_status($tid, $sessId, 200, 'Successfully completed.');
    }
} elseif (strpos($xml, '<InviteUser-Response') !== false) {
    // Respuesta del invitado: acepta (Acceptance=T) o rechaza. Si la invitacion
    // no existe (o no es para ti): 423 Invalid Invitation-ID.
    $userId = $mobileSessionUserId > 0 ? $mobileSessionUserId : 0;
    $inviteId = trim(wv_inner($xml, 'InviteID'));
    $acc = strtoupper(trim(wv_inner($xml, 'Acceptance'))) === 'T';
    $sname = trim(wv_inner(wv_inner($xml, 'ScreenName'), 'SName'));
    try {
        $st = db()->prepare('SELECT group_id FROM group_invites WHERE invite_id = ? AND invitee_id = ?');
        $st->execute([$inviteId, $userId]);
        $inv = $st->fetch();
        if ($inv === false) {
            $xmlResponse = wv_status($tid, $sessId, 423, 'Invalid Invitation-ID');
        } else {
            $gid = (string)$inv['group_id'];
            db()->prepare('UPDATE group_invites SET state = ? WHERE invite_id = ? AND invitee_id = ?')
                ->execute([$acc ? 'accepted' : 'rejected', $inviteId, $userId]);
            if ($gid !== '' && $acc) {
                $prev = group_member_row($gid, $userId);
                $wasIn = $prev !== null && (int)$prev['joined'] === 1;
                if ($prev === null) {
                    group_member_set($gid, $userId, ['privilege' => 'User', 'member' => 1, 'joined' => 1, 'screen_name' => $sname !== '' ? $sname : user_nick_by_id($userId), 'subscribed' => 1]);
                } else {
                    group_member_set($gid, $userId, ['member' => 1, 'joined' => 1, 'subscribed' => 1]);
                }
                if (!$wasIn) {
                    group_add_event($gid, 'join', user_nick_by_id($userId));
                }
                // Aceptar = entrar: el movil recibe tambien el nombre del grupo.
                group_notify_join($gid, $userId, true);
            }
            $xmlResponse = wv_status($tid, $sessId, 200, 'Successfully completed.');
        }
    } catch (Throwable $e) {
        $xmlResponse = wv_status($tid, $sessId, 200, 'Successfully completed.');
    }
} elseif (strpos($xml, '<CancelInvite-Request') !== false || strpos($xml, '<CancelInviteUser-Request') !== false) {
    // CSP 1.2 §7.14: retirar una invitacion -> Status. Solo el que la mando
    // puede retirarla: si no es suya (o no existe), 423.
    $userId = $mobileSessionUserId > 0 ? $mobileSessionUserId : 0;
    $inviteId = trim(wv_inner($xml, 'InviteID'));
    try {
        $st = db()->prepare('DELETE FROM group_invites WHERE invite_id = ? AND inviter_id = ?');
        $st->execute([$inviteId, $userId]);
        $xmlResponse = $st->rowCount() > 0
            ? wv_status($tid, $sessId, 200, 'Successfully completed.')
            : wv_status($tid, $sessId, 423, 'Invalid Invitation-ID');
    } catch (Throwable $e) {
        $xmlResponse = wv_status($tid, $sessId, 200, 'Successfully completed.');
    }
} elseif (strpos($xml, '<SendMessage-Request') !== false && group_recipient_in($xml) !== '') {
    // Mensaje dirigido a un grupo (CSP 1.2 §9: <Recipient><Group><GroupID>..).
    // Se guarda una sola vez en group_messages; cada miembro lo recibe por
    // Polling (movil) o lo lee en la web.
    $groupTo = group_recipient_in($xml);
    preg_match('/<Sender>\s*<User>\s*<UserID>(.*?)<\/UserID>\s*<\/User>\s*<\/Sender>/s', $xml, $mFrom);
    preg_match('/<ContentData>(.*?)<\/ContentData>/s', $xml, $mData);
    // El remitente sale de la sesion, no del <Sender> del XML: si no,
    // cualquiera podria firmar mensajes de grupo como otro usuario.
    $from = $mobileSessionUserId > 0 ? user_nick_by_id($mobileSessionUserId) : '';
    $xmlFrom = explode('/', trim(preg_replace('/^wv:/i', '', $mFrom[1] ?? '', 1)))[0];
    if ($xmlFrom !== '' && $xmlFrom !== $from) {
        wv_log('SENDER MISMATCH (group) xml=' . $xmlFrom . ' session=' . $from . "\n", true);
    }
    $body = trim($mData[1] ?? '');
    $senderId = $mobileSessionUserId;
    $g = group_get($groupTo);
    $mRow = group_member_row($groupTo, $senderId);
    if ($g === null) {
        $xmlResponse = wv_status($tid, $sessId, 800, 'Group does not exist');
    } elseif ($mRow === null || (int)$mRow['joined'] !== 1) {
        $xmlResponse = wv_status($tid, $sessId, 808, 'Group was not joined before transaction');
    } else {
        try {
            $mid = group_add_message($groupTo, $from, $body, 'text/plain');
            // OJO: no se toca last_msg_id. Si se pusiera al id de este mensaje
            // (que es mayor que todo lo pendiente) se saltaria la cola: los
            // mensajes de grupo que me habian llegado antes no se entregarian
            // nunca. El eco propio se evita filtrando from_user en la entrega.
            $msgId = 'msg-' . $mid;
            wv_log('GRP: mensaje ' . $from . ' -> ' . $groupTo . ' (' . strlen($body) . " bytes)\n");
        } catch (Throwable $e) {
            $msgId = 'msg-' . bin2hex(random_bytes(6));
        }
        $xmlResponse = wv_response($tid, $sessId, '<SendMessage-Response><Result><Code>200</Code><Description>OK</Description></Result>' .
            '<MessageID>' . htmlspecialchars($msgId, ENT_XML1, 'UTF-8') . '</MessageID></SendMessage-Response>');
        $xmlResponse = wv_piggyback($xmlResponse, $mobileSessionUserId, wv_multitrans($sessId) - 1);
    }
} elseif (strpos($xml, '<SendMessage-Request') !== false) {
    preg_match('/<Sender>\s*<User>\s*<UserID>(.*?)<\/UserID>\s*<\/User>\s*<\/Sender>/s', $xml, $mFrom);
    preg_match('/<Recipient>\s*<User>\s*<UserID>(.*?)<\/UserID>\s*<\/User>\s*<\/Recipient>/s', $xml, $mTo);
    preg_match('/<ContentData>(.*?)<\/ContentData>/s', $xml, $mData);
    // El remitente es siempre el dueno de la sesion. El <Sender> del XML se
    // ignora (solo se registra si no coincide).
    $from = $mobileSessionUserId > 0 ? user_nick_by_id($mobileSessionUserId) : '';
    $xmlFrom = explode('/', trim(preg_replace('/^wv:/i', '', $mFrom[1] ?? '', 1)))[0];
    if ($xmlFrom !== '' && $xmlFrom !== $from) {
        wv_log('SENDER MISMATCH xml=' . $xmlFrom . ' session=' . $from . "\n", true);
    }
    $to = trim(preg_replace('/^wv:/i', '', $mTo[1] ?? '', 1));
    $to = explode('/', $to)[0];
    $body = trim($mData[1] ?? '');
    if ($body === '') {
        $body = '';
    }
    $msgId = 'msg-' . bin2hex(random_bytes(6));
    $recipientBlocked = false;
    try {
        // Lista de bloqueados: si el destinatario ha bloqueado al remitente, no se entrega.
        $blk = db()->prepare('SELECT 1 FROM blocked b JOIN users u ON u.id = b.owner_id WHERE u.nick = ? AND b.contact_nick = ? LIMIT 1');
        $blk->execute([$to, $from]);
        if ($blk->fetchColumn()) {
            $recipientBlocked = true;
            wv_log('BLOCKED: ' . $from . ' -> ' . $to . " (no entregado)\n");
        } else {
            db()->prepare('INSERT INTO messages (from_user, to_user, body, content_type) VALUES (?, ?, ?, ?)')
                ->execute([$from, $to, $body, 'text/plain']);
            $msgId = 'msg-' . db()->lastInsertId();
            // Historial acotado: los mensajes mas viejos de esta conversacion se van.
            prune_private_history($from, $to);
        }
    } catch (Throwable $e) {}
    if ($recipientBlocked) {
        // CSP 1.1 §11.5.12: 532 = "Message Recipient Blocked the Sender". La spec
        // permite devolverlo (revela el bloqueo) o simular exito; devolvemos el
        // error para que el movil avise al usuario.
        $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
            '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
            '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
            '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
            '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
            '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
            '<Status><Result><Code>532</Code><Description>Message Recipient Blocked the Sender</Description></Result></Status>' .
            '</TransactionContent></Transaction></Session></WV-CSP-Message>';
    } else {
        $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
            '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
            '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
            '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
            '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
            '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
            '<SendMessage-Response>' .
            '<Result><Code>200</Code><Description>OK</Description></Result>' .
            '<MessageID>' . htmlspecialchars($msgId, ENT_XML1, 'UTF-8') . '</MessageID>' .
            '</SendMessage-Response>' .
            '</TransactionContent></Transaction></Session></WV-CSP-Message>';
        // Lo que me hubiera llegado se entrega ya, en la misma respuesta: si no,
        // el mensaje que me acaban de mandar no sale hasta el siguiente Polling
        // (2 min) y en pantalla aparece antes el mio.
        $xmlResponse = wv_piggyback($xmlResponse, $mobileSessionUserId, wv_multitrans($sessId) - 1);
    }
} elseif (strpos($xml, '<Polling-Request') !== false) {
    $mobileUserId = user_id_by_session($sessId);
    $mobileUser = $mobileUserId > 0 ? user_nick_by_id($mobileUserId) : '';
    // Antes de entregar nada: los usuarios web que cerraron la pestana ya no
    // estan "dentro" de sus grupos. Asi el movil recibe el Left en este mismo
    // poll aunque no haya nadie usando la web en ese momento.
    try {
        group_leave_stale();
    } catch (Throwable $e) {}
    // MultiTrans: el cliente indica cuantas primitivas puede manejar en un mismo
    // mensaje (el W200i declara 2). Lo usamos para vaciar la cola mas rapido.
    $maxPerPoll = 1;
    try {
        $mt = db()->prepare('SELECT multitrans FROM wv_sessions WHERE session_id = ? ORDER BY id DESC LIMIT 1');
        $mt->execute([$sessId]);
        $v = (int)$mt->fetchColumn();
        if ($v > 0) { $maxPerPoll = min($v, 10); }
    } catch (Throwable $e) {}
    // Sincronizacion de la lista de participantes: la primera vez que esta sesion
    // hace Polling se le manda la lista completa de cada grupo en el que esta
    // dentro. El movil no pide la lista (no usa GetJoinedUsers), la aprende de los
    // GroupChangeNotice, asi que al reconectar hay que reenviarsela.
    if ($mobileUserId > 0) {
        try {
            $sy = db()->prepare('SELECT groups_synced FROM wv_sessions WHERE session_id = ? ORDER BY id DESC LIMIT 1');
            $sy->execute([$sessId]);
            if ((int)$sy->fetchColumn() === 0) {
                db()->prepare('UPDATE wv_sessions SET groups_synced = 1 WHERE session_id = ?')->execute([$sessId]);
                $gr = db()->prepare('SELECT group_id FROM group_members WHERE user_id = ? AND joined = 1');
                $gr->execute([$mobileUserId]);
                foreach ($gr->fetchAll(PDO::FETCH_COLUMN) as $gidSync) {
                    // $primero = true: la lista de participantes va al principio
                    // de la entrega (el movil debe saber quien esta dentro antes
                    // de que le llegue el primer mensaje).
                    // $withName = true: al reconectar, el movil recupera tambien
                    // el NOMBRE de cada grupo (no solo su GroupID).
                    group_queue_change_notice($mobileUserId, (string)$gidSync, array_values(group_members_info((string)$gidSync, true)), [], true, true);
                }
            }
        } catch (Throwable $e) {}
    }
    $requests = wv_pending_primitives($mobileUserId, $maxPerPoll);
    if (empty($requests) && $mobileUserId > 0) {
        // Una notificacion de presencia por poll (los mensajes tienen prioridad).
        foreach (presence_notifications($mobileUserId, 1) as $notif) {
            $requests[] = $notif;
        }
    }
    if (!empty($requests)) {
        // Cada primitiva pendiente (NewMessage / PresenceNotification) va como
        // PETICION del servidor (Client <- Server) con su propio TransactionID,
        // hasta MultiTrans transacciones dentro del mismo Session.
        $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
            '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
            '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
            wv_requests_xml($requests, true) .
            '</Session></WV-CSP-Message>';
    } else {
        $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
            '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
            '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
            '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
            '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
            '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
            '<Polling-Request/>' .
            '</TransactionContent></Transaction></Session></WV-CSP-Message>';
    }
} elseif (strpos($xml, '<MessageDelivered') !== false) {
    // Acuse de recibo del cliente tras un NewMessage (CSP 1.1, tabla 73).
    $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<Status><Result><Code>200</Code><Description>Message delivered</Description></Result></Status>' .
        '</TransactionContent></Transaction></Session></WV-CSP-Message>';
} elseif (strpos($xml, '<Service-Request>') !== false) {
    preg_match('/<Functions>(.*?)<\/Functions>/s', $xml, $mFunc);
    $functionsXml = '<WVCSPFeat><FundamentalFeat><SearchFunc/></FundamentalFeat></WVCSPFeat>';
    $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<Service-Response>' .
        '<ClientID><URL>http://valleyresidents.mooo.com/village/imps.php</URL></ClientID>' .
        '<Functions>' . $functionsXml . '</Functions>' .
        '<AllFunctions><WVCSPFeat/></AllFunctions>' .
        '</Service-Response>' .
        '</TransactionContent></Transaction></Session></WV-CSP-Message>';
} elseif (strpos($xml, '<KeepAlive-Request') !== false) {
    $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<Status><Result><Code>200</Code><Description>OK</Description></Result></Status>' .
        '</TransactionContent></Transaction></Session></WV-CSP-Message>';
} elseif (strpos($xml, '<Logout-Request') !== false) {
    try {
        // CSP 1.2 §6.5: al salir se abandona los grupos en los que estabamos
        // dentro (la pertenencia se mantiene). Al reconectar, el movil pide
        // JoinGroup otra vez y vuelve a entrar.
        if ($mobileSessionUserId > 0) {
            group_leave_all($mobileSessionUserId);
        }
        db()->prepare('DELETE FROM wv_sessions WHERE session_id = ?')->execute([$sessId]);
        if ($mobileSessionUserId > 0) {
            set_offline($mobileSessionUserId);
        }
    } catch (Throwable $e) {}
    // CSP 1.1 §6.4 / §7.7.2: a un Logout-Request el servidor responde con una
    // primitiva Disconnect (SessionType Inband, mismo SessionID), no con Status.
    $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Inband</SessionType><SessionID>' . htmlspecialchars($sessId, ENT_XML1, 'UTF-8') . '</SessionID></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<Disconnect><Result><Code>200</Code><Description>OK</Description></Result></Disconnect>' .
        '</TransactionContent></Transaction></Session></WV-CSP-Message>';
} else {
    $xmlResponse = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<WV-CSP-Message xmlns="http://www.wireless-village.org/CSP1.1">' .
        '<Session><SessionDescriptor><SessionType>Outband</SessionType></SessionDescriptor>' .
        '<Transaction><TransactionDescriptor><TransactionMode>Response</TransactionMode>' .
        '<TransactionID>' . htmlspecialchars($tid, ENT_XML1, 'UTF-8') . '</TransactionID><Poll>F</Poll></TransactionDescriptor>' .
        '<TransactionContent xmlns="http://www.wireless-village.org/TRC1.1">' .
        '<Status><Result><Code>500</Code><Description>Not implemented</Description></Result></Status>' .
        '</TransactionContent></Transaction></Session></WV-CSP-Message>';
}

wv_send($xmlResponse, $binary, $forceText);
