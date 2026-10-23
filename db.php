<?php
// db.php
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require __DIR__ . '/config.php';
    $pdo = new PDO('sqlite:' . $config['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Espera a que se libere el bloqueo de escritura en vez de fallar al
    // instante: la web y el movil pueden escribir a la vez (WAL permite
    // lectores concurrentes, pero solo un escritor).
    $pdo->exec('PRAGMA busy_timeout = 5000;');
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $schema = file_get_contents($config['schema_path']);
    $pdo->exec($schema);
    try {
        $pdo->exec('ALTER TABLE messages ADD COLUMN read_at TEXT');
    } catch (Throwable $e) {
        // column already exists
    }
    try {
        $pdo->exec('ALTER TABLE presence ADD COLUMN status_mood TEXT');
    } catch (Throwable $e) {
        // column already exists
    }
    try {
        $pdo->exec('ALTER TABLE presence ADD COLUMN online INTEGER NOT NULL DEFAULT 0');
    } catch (Throwable $e) {
        // column already exists
    }
    try {
        $pdo->exec('ALTER TABLE wv_sessions ADD COLUMN multitrans INTEGER NOT NULL DEFAULT 1');
    } catch (Throwable $e) {
        // column already exists
    }
    try {
        $pdo->exec("ALTER TABLE presence_track ADD COLUMN state TEXT NOT NULL DEFAULT ''");
    } catch (Throwable $e) {
        // column already exists
    }
    try {
        $pdo->exec("ALTER TABLE contacts ADD COLUMN alias TEXT NOT NULL DEFAULT ''");
    } catch (Throwable $e) {
        // column already exists
    }
    // Contactos anadidos automaticamente al recibir un mensaje (no cuentan como
    // contacto a efectos de autorizacion de presencia).
    try {
        $pdo->exec('ALTER TABLE contacts ADD COLUMN auto INTEGER NOT NULL DEFAULT 0');
    } catch (Throwable $e) {
        // column already exists
    }
    // Grupos: la primera vez que una sesion hace Polling se le manda la lista de
    // participantes de cada grupo (asi el movil se sincroniza al reconectar).
    try {
        $pdo->exec('ALTER TABLE wv_sessions ADD COLUMN groups_synced INTEGER NOT NULL DEFAULT 0');
    } catch (Throwable $e) {
        // column already exists
    }
    // Imagen/logo de estado (WV-029 StatusContent).
    try {
        $pdo->exec('ALTER TABLE presence ADD COLUMN status_content TEXT');
    } catch (Throwable $e) {
        // column already exists
    }
    try {
        $pdo->exec('ALTER TABLE presence ADD COLUMN content_type TEXT');
    } catch (Throwable $e) {
        // column already exists
    }
    try {
        $pdo->exec('ALTER TABLE presence ADD COLUMN content_kind TEXT');
    } catch (Throwable $e) {
        // column already exists
    }
    // Entradas y salidas del grupo como lineas de sistema en la conversacion
    // de la web ('msg' = mensaje normal, 'join'/'leave' = aviso).
    try {
        $pdo->exec("ALTER TABLE group_messages ADD COLUMN kind TEXT NOT NULL DEFAULT 'msg'");
    } catch (Throwable $e) {
        // column already exists
    }
    // Avisos que deben ir al principio de la entrega (sincronizacion de la
    // lista de participantes: el movil tiene que saber quien esta dentro antes
    // de que le llegue el primer mensaje).
    try {
        $pdo->exec('ALTER TABLE wv_notices ADD COLUMN sort_first INTEGER NOT NULL DEFAULT 0');
    } catch (Throwable $e) {
        // column already exists
    }
    // Contrasena propia del cliente IMPS (cifrada). Ver imps_crypto.php.
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN imps_password TEXT NOT NULL DEFAULT ''");
    } catch (Throwable $e) {
        // column already exists
    }
    // Normaliza valores de disponibilidad antiguos a los del estandar WV-029
    try {
        $pdo->exec("UPDATE presence SET status_code = 'AVAILABLE' WHERE status_code IS NULL OR status_code NOT IN ('AVAILABLE','NOT_AVAILABLE','DISCREET','INVISIBLE')");
    } catch (Throwable $e) {
        // ignore
    }
    return $pdo;
}

// --- Autorizacion de presencia (listas de atributos, CSP 1.2 §8.2) ---------
// "An attribute list is a set of presence attributes. The list set will define
// proactive authorization" y "Only those attributes that are pro- or reactively
// authorized will be distributed" (§8.3.2). El movil expresa asi su ajuste
// "Mostrar mi estado: a todos / solo contactos": en cada login crea la lista de
// atributos de la lista de contactos (estado completo) y la lista por defecto
// (atributos basicos, o VACIA cuando elige "solo contactos").
function parse_attr_list(string $attrs): array
{
    $attrs = trim($attrs);
    if ($attrs === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $attrs)), static fn($a) => $a !== ''));
}

// Atributos de presencia de $ownerId autorizados para $viewerNick. Devuelve null
// cuando el propietario no ha definido ninguna lista de atributos: en ese caso
// todo esta autorizado (comportamiento por defecto del estandar).
function authorized_attrs(int $ownerId, string $viewerNick): ?array
{
    if ($ownerId <= 0 || $viewerNick === '') {
        return null;
    }
    try {
        $st = db()->prepare('SELECT nick FROM users WHERE id = ?');
        $st->execute([$ownerId]);
        $ownerNick = (string)$st->fetchColumn();
        if ($ownerNick === '' || $ownerNick === $viewerNick) {
            return null; // uno siempre se ve a si mismo
        }
        $st = db()->prepare('SELECT kind, target, attrs FROM attr_lists WHERE owner_id = ?');
        $st->execute([$ownerId]);
        $rows = $st->fetchAll();
        if (!$rows) {
            return null; // sin listas de atributos: todo autorizado
        }
        $map = [];
        foreach ($rows as $r) {
            $map[(string)$r['kind'] . ':' . (string)$r['target']] = (string)$r['attrs'];
        }
        // 1) lista especifica del usuario observador
        if (isset($map['user:' . $viewerNick])) {
            return parse_attr_list($map['user:' . $viewerNick]);
        }
        // 2) el observador es uno de mis contactos: mis listas de contactos le
        //    autorizan el estado completo. (El movil guarda a sus amigos en
        //    ~pep1.0_subscriptions-N y la lista de atributos apunta a
        //    ~pep1.0_privatelist, por eso se mira "es contacto", no el nombre de
        //    la lista concreta.) Los contactos anadidos automaticamente (auto=1)
        //    no cuentan: un desconocido que te escribe no es un amigo.
        $st = db()->prepare('SELECT 1 FROM contacts WHERE owner_id = ? AND contact_nick = ? AND auto = 0 LIMIT 1');
        $st->execute([$ownerId, $viewerNick]);
        if ($st->fetchColumn()) {
            $listAttrs = [];
            foreach ($map as $key => $value) {
                if (str_starts_with($key, 'list:')) {
                    $listAttrs = array_merge($listAttrs, parse_attr_list($value));
                }
            }
            return $listAttrs ? array_values(array_unique($listAttrs)) : null;
        }
        // 3) lista por defecto: todos los demas. El movil solo autoriza aqui
        //    atributos basicos, pero el ajuste del telefono ("Mostrar mi estado")
        //    es un filtro de dos estados: una lista por defecto VACIA significa
        //    "solo contactos" (los demas no ven nada) y una lista no vacia
        //    significa "a todos" (publico: se ve todo, incluidos animo, texto y
        //    foto). Por eso solo se oculta cuando la lista por defecto esta vacia.
        if (isset($map['default:'])) {
            return parse_attr_list($map['default:']) === [] ? [] : null;
        }
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

// Aplica la autorizacion a una instantanea de presencia (presence_snapshot).
// Si falta OnlineStatus el usuario aparece desconectado; si faltan los demas
// atributos se omiten (sin animo, sin texto y sin imagen de estado).
function filter_presence(array $p, ?array $allowed): array
{
    if ($allowed === null) {
        return $p;
    }
    $has = static fn(string $a): bool => in_array($a, $allowed, true);
    if (!$has('OnlineStatus')) {
        $p['online'] = false;
    }
    if (empty($p['online']) || !$has('UserAvailability')) {
        $p['status_code'] = 'NOT_AVAILABLE';
    }
    if (!$has('StatusMood')) {
        $p['status_mood'] = '';
    }
    if (!$has('StatusText')) {
        $p['status_text'] = '';
    }
    if (!$has('StatusContent')) {
        $p['status_content'] = '';
        $p['content_type'] = '';
        $p['content_kind'] = '';
    }
    return $p;
}

// --- Avisos de cambio de grupo (CSP 1.2 §10.9, GroupChangeNotice) -----------
// El movil NO pide la lista de participantes con GetJoinedUsers: la construye
// con los GroupChangeNotice que le van llegando por Polling (el telefono los
// acusa con un Status). Por eso hay que avisarle cada vez que alguien entra o
// sale, y el aviso de entrada lleva la lista COMPLETA de los que estan dentro
// (SName + UserID): el UserID es lo que le permite poner el nombre al remitente
// de cada mensaje del grupo (si no, muestra "wv:nick" tal cual).

// Miembros del grupo indexados por user_id, con el nombre que se muestra.
function group_members_info(string $gid, bool $joinedOnly = false): array
{
    try {
        $sql = 'SELECT gm.user_id, gm.screen_name, u.nick FROM group_members gm JOIN users u ON u.id = gm.user_id
            WHERE gm.group_id = ? AND gm.member = 1 AND gm.rejected = 0';
        if ($joinedOnly) {
            $sql .= ' AND gm.joined = 1';
        }
        $st = db()->prepare($sql);
        $st->execute([$gid]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[(int)$r['user_id']] = [
            'nick' => (string)$r['nick'],
            'sname' => (string)$r['screen_name'] !== '' ? (string)$r['screen_name'] : (string)$r['nick'],
        ];
    }
    return $out;
}


// --- Acceso a datos de grupos (miembros) ------------------------------------
// Viven aqui (y no en imps.php) porque tambien los usa logout.php para sacar
// al usuario de sus grupos al desconectar.
function group_member_row(string $gid, int $userId): ?array
{
    try {
        $st = db()->prepare('SELECT * FROM group_members WHERE group_id = ? AND user_id = ?');
        $st->execute([$gid, $userId]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

// Nivel de privilegio de un usuario en el grupo: 'Admin', 'Mod', 'User' o ''
// si no es miembro (o esta rechazado). CSP 1.2 (§10.6-§10.8) reserva la gestion
// del grupo a "el dueno o un usuario con privilegios suficientes"; imps.php lo
// usa para autorizar AddGroupMembers, RemoveGroupMembers, MemberAccess,
// SetGroupProps y RejectList (error 816 si no eres Admin, 810 si no eres
// miembro). Vive aqui y no en imps.php porque db.php tambien lo carga la web.
function group_privilege(string $gid, int $userId): string
{
    $row = group_member_row($gid, $userId);
    if ($row === null || (int)$row['member'] !== 1 || (int)$row['rejected'] === 1) {
        return '';
    }
    return (string)$row['privilege'];
}

function group_member_set(string $gid, int $userId, array $fields): void
{
    $row = group_member_row($gid, $userId);
    $now = date('Y-m-d H:i:s');
    if ($row === null) {
        db()->prepare('INSERT INTO group_members (group_id, user_id, privilege, member, joined, rejected, screen_name, show_id, private_messaging, subscribed, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $gid, $userId,
                (string)($fields['privilege'] ?? 'User'),
                (int)($fields['member'] ?? 1),
                (int)($fields['joined'] ?? 0),
                (int)($fields['rejected'] ?? 0),
                (string)($fields['screen_name'] ?? ''),
                (string)($fields['show_id'] ?? 'F'),
                (string)($fields['private_messaging'] ?? 'F'),
                (int)($fields['subscribed'] ?? 0),
                $now,
            ]);
        return;
    }
    $sets = [];
    $args = [];
    foreach (['privilege', 'member', 'joined', 'rejected', 'screen_name', 'show_id', 'private_messaging', 'subscribed'] as $col) {
        if (array_key_exists($col, $fields)) {
            $sets[] = $col . ' = ?';
            $args[] = $fields[$col];
        }
    }
    if (!$sets) {
        return;
    }
    $sets[] = 'updated_at = ?';
    $args[] = $now;
    $args[] = $gid;
    $args[] = $userId;
    db()->prepare('UPDATE group_members SET ' . implode(', ', $sets) . ' WHERE group_id = ? AND user_id = ?')->execute($args);
}

// Miembros del grupo con su nick. $joinedOnly limita a los que estan dentro.
function group_members(string $gid, bool $joinedOnly = false): array
{
    try {
        $sql = 'SELECT gm.*, u.nick FROM group_members gm JOIN users u ON u.id = gm.user_id WHERE gm.group_id = ? AND gm.member = 1 AND gm.rejected = 0';
        if ($joinedOnly) {
            $sql .= ' AND gm.joined = 1';
        }
        $sql .= ' ORDER BY CASE gm.privilege WHEN "Admin" THEN 0 WHEN "Mod" THEN 1 ELSE 2 END, u.nick';
        $st = db()->prepare($sql);
        $st->execute([$gid]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'user_id' => (int)$r['user_id'],
            'nick' => (string)$r['nick'],
            'sname' => (string)$r['screen_name'] !== '' ? (string)$r['screen_name'] : (string)$r['nick'],
            'privilege' => (string)$r['privilege'],
            'joined' => (int)$r['joined'],
            'subscribed' => (int)$r['subscribed'],
            'show_id' => (string)$r['show_id'],
        ];
    }
    return $out;
}

// Entradas y salidas del grupo como lineas de sistema de la conversacion web.
// Se guardan en group_messages (kind='join'/'leave', sin remitente) para que
// conserven el orden real respecto a los mensajes: el chat de la web las pinta
// intercaladas por id. Al movil NO se le mandan como NewMessage (su consulta de
// Polling filtra kind='msg'); el movil ya se entera por GroupChangeNotice.
function group_add_event(string $gid, string $kind, string $who): void
{
    $who = trim($who);
    if ($who === '' || ($kind !== 'join' && $kind !== 'leave')) {
        return;
    }
    try {
        db()->prepare('INSERT INTO group_messages (group_id, from_user, body, content_type, kind) VALUES (?, "", ?, "text/plain", ?)')
            ->execute([$gid, $who . ($kind === 'join' ? ' joined the group' : ' left the group'), $kind]);
        prune_group_history($gid);
    } catch (Throwable $e) {
    }
}

// Nombre con el que un usuario aparece dentro de un grupo (SName). Si no
// consta, se usa su nick.
function group_screen_name(string $gid, string $nick): string
{
    try {
        $st = db()->prepare('SELECT gm.screen_name FROM group_members gm JOIN users u ON u.id = gm.user_id
            WHERE gm.group_id = ? AND u.nick = ? LIMIT 1');
        $st->execute([$gid, $nick]);
        $s = (string)$st->fetchColumn();
        if ($s !== '') {
            return $s;
        }
    } catch (Throwable $e) {
    }
    return $nick;
}

// Invitar a alguien a un grupo (CSP 1.2 §5.3.6). La usan los dos mundos: el
// movil con Invite-Request y la web con el boton "Anadir miembro". Guarda la
// invitacion pendiente (group_invites) y le encola al invitado un
// InviteUser-Request, que es lo que hace que en el movil salga el aviso de "X te
// invita" y en la web la fila con el ✓ y la ×.
//
// Invitar NO mete al invitado dentro del grupo: entra cuando acepta
// (InviteUser-Response en el movil, group_invite_response en la web). Antes la
// web lo metia de golpe, y quedaba dentro en el servidor sin haberse enterado
// ni haber aceptado (su movil seguia fuera: no le habia llegado nada).
//
// $inviteId se reutiliza cuando se invita a varios a la vez. $inviterName es el
// SName del que invita (su nick, o su nombre dentro del grupo).
function group_invite_send(string $gid, int $inviterId, string $inviterName, int $inviteeId, string $note = '', string $inviteId = '', string $inviteType = 'GR'): string
{
    if ($inviteeId <= 0) {
        return '';
    }
    if ($inviteId === '') {
        $inviteId = 'inv-' . bin2hex(random_bytes(4));
    }
    $note = trim($note);
    $who = trim($inviterName) !== '' ? trim($inviterName) : ('usuario ' . $inviterId);
    try {
        db()->prepare('INSERT INTO group_invites (invite_id, group_id, inviter_id, invitee_id, note, state) VALUES (?, ?, ?, ?, ?, "pending")
            ON CONFLICT(invite_id, invitee_id) DO UPDATE SET note = excluded.note, state = "pending"')
            ->execute([$inviteId, $gid, $inviterId, $inviteeId, $note]);
    } catch (Throwable $e) {
    }
    $xml = '<InviteUser-Request><InviteID>' . htmlspecialchars($inviteId, ENT_XML1, 'UTF-8') . '</InviteID>' .
        '<InviteType>' . htmlspecialchars($inviteType !== '' ? $inviteType : 'GR', ENT_XML1, 'UTF-8') . '</InviteType>' .
        '<Sender>' . ($gid !== ''
            ? '<Group><ScreenName><SName>' . htmlspecialchars($who, ENT_XML1, 'UTF-8') . '</SName><GroupID>' . htmlspecialchars($gid, ENT_XML1, 'UTF-8') . '</GroupID></ScreenName></Group>'
            : '<User><UserID>wv:' . htmlspecialchars($who, ENT_XML1, 'UTF-8') . '</UserID></User>') . '</Sender>' .
        ($gid !== '' ? '<GroupID>' . htmlspecialchars($gid, ENT_XML1, 'UTF-8') . '</GroupID>' : '') .
        ($note !== '' ? '<InviteNote>' . htmlspecialchars($note, ENT_XML1, 'UTF-8') . '</InviteNote>' : '') .
        '</InviteUser-Request>';
    try {
        db()->prepare('INSERT INTO wv_notices (user_id, xml) VALUES (?, ?)')->execute([$inviteeId, $xml]);
        // La web no tiene Polling: si no se limpia, la cola de un usuario web
        // crece sin fin. Se descartan los avisos con mas de 3 dias.
        db()->prepare('DELETE FROM wv_notices WHERE user_id = ? AND created_at < datetime("now", "-3 days")')
            ->execute([$inviteeId]);
    } catch (Throwable $e) {
    }
    return $inviteId;
}

// Al entrar en un grupo, las invitaciones que tuvieras pendientes para el se dan
// por aceptadas. Hace falta porque el W200i "acepta" mandando JoinGroup (nunca
// manda InviteUser-Response): sin esto su invitacion se quedaba "pending" para
// siempre y la web la seguia ofreciendo en la pestana de invitaciones.
// Comprueba si un GroupID ya existe (no es libre).
// Devuelve true si el grupo no existe (libre), false si ya existe.
function group_id_free(string $gid): bool
{
    try {
        $st = db()->prepare('SELECT 1 FROM groups WHERE group_id = ? LIMIT 1');
        $st->execute([$gid]);
        return $st->fetchColumn() === false;
    } catch (Throwable $e) {
        return true;
    }
}

function group_invites_accept_pending(string $gid, int $userId): void
{
    if ($gid === '' || $userId <= 0) {
        return;
    }
    try {
        db()->prepare('UPDATE group_invites SET state = "accepted" WHERE group_id = ? AND invitee_id = ? AND state = "pending"')
            ->execute([$gid, $userId]);
    } catch (Throwable $e) {
    }
}

// GroupChangeNotice (DTD: GroupID, Joined?, Left?, GroupProperties?, OwnProperties?).
// El W200i habla CSP 1.1, donde Joined y Left son UserList de ScreenName
// (SName + GroupID). Los UserMapList/Mapping son de CSP 1.2 (tokens de la pagina
// de codigo 7 que el telefono no conoce) y le rompen el parseo.
//
// $withName = true anade GroupProperties con el Name del grupo, al final (el DTD
// lo pone tras Joined/Left). Es la UNICA primitiva de CSP 1.1 que puede llevar
// el nombre hasta el movil: JoinGroup-Response solo admite UserList/WelcomeNote
// y el W200i nunca pide GetGroupProps. Sin esto el movil titula el grupo con su
// GroupID ("loko/grupoweb_20261019t203942z").
function group_change_notice_xml(string $gid, array $joined, array $left, bool $withName = false): string
{
    $g = htmlspecialchars($gid, ENT_XML1, 'UTF-8');
    $x = '<GroupChangeNotice><GroupID>' . $g . '</GroupID>';
    foreach (['Joined' => $joined, 'Left' => $left] as $tag => $list) {
        if (!$list) {
            continue;
        }
        $s = '';
        foreach ($list as $m) {
            $s .= '<ScreenName><SName>' . htmlspecialchars((string)$m['sname'], ENT_XML1, 'UTF-8') . '</SName>' .
                '<GroupID>' . $g . '</GroupID></ScreenName>';
        }
        $x .= '<' . $tag . '><UserList>' . $s . '</UserList></' . $tag . '>';
    }
    if ($withName) {
        $nombre = '';
        try {
            $st = db()->prepare('SELECT name FROM groups WHERE group_id = ?');
            $st->execute([$gid]);
            $nombre = (string)$st->fetchColumn();
            $st->closeCursor();
        } catch (Throwable $e) {
        }
        if ($nombre !== '') {
            $x .= '<GroupProperties><Property><Name>Name</Name><Value>' .
                htmlspecialchars($nombre, ENT_XML1, 'UTF-8') . '</Value></Property></GroupProperties>';
        }
    }
    return $x . '</GroupChangeNotice>';
}

// Encola un aviso para un usuario concreto (su movil lo recibira en el Polling).
// $primero = true lo pone al principio de la entrega, por delante de los mensajes
// que hubiera en cola (se usa para la sincronizacion de la lista de
// participantes; ver wv_pending_primitives).
// $withName = true (solo tiene sentido sin Joined/Left) manda el nombre del
// grupo; se repite cada vez que el movil abre el grupo, asi que si el anterior
// aun no se ha entregado no se encola otro igual.
function group_queue_change_notice(int $userId, string $gid, array $joined, array $left, bool $primero = false, bool $withName = false): void
{
    if ($userId <= 0 || $gid === '' || (!$joined && !$left && !$withName)) {
        return;
    }
    try {
        $xml = group_change_notice_xml($gid, $joined, $left, $withName);
        if (!$joined && !$left) {
            $st = db()->prepare('SELECT 1 FROM wv_notices WHERE user_id = ? AND xml = ? AND (sent_at IS NULL OR sent_at = "") LIMIT 1');
            $st->execute([$userId, $xml]);
            $repetido = $st->fetchColumn() !== false;
            $st->closeCursor();
            if ($repetido) {
                return;
            }
        }
        db()->prepare('INSERT INTO wv_notices (user_id, xml, sort_first) VALUES (?, ?, ?)')
            ->execute([$userId, $xml, $primero ? 1 : 0]);
        // La web no tiene Polling: si no se limpia, la cola de un usuario web
        // crece sin fin. Se descartan los avisos con mas de 3 dias.
        db()->prepare('DELETE FROM wv_notices WHERE user_id = ? AND created_at < datetime("now", "-3 days")')
            ->execute([$userId]);
    } catch (Throwable $e) {
    }
}

// Encola el aviso para todos los que estan en el grupo menos quien lo provoca.
function group_send_change_notice(string $gid, array $joined, array $left, int $exceptUserId = 0): void
{
    if ($gid === '' || (!$joined && !$left)) {
        return;
    }
    $xml = group_change_notice_xml($gid, $joined, $left);
    try {
        $st = db()->prepare('SELECT user_id FROM group_members WHERE group_id = ? AND member = 1 AND rejected = 0
            AND (subscribed = 1 OR joined = 1)' . ($exceptUserId > 0 ? ' AND user_id <> ?' : ''));
        $st->execute($exceptUserId > 0 ? [$gid, $exceptUserId] : [$gid]);
        $uids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return;
    }
    foreach ($uids as $uid) {
        group_queue_change_notice($uid, $gid, $joined, $left);
    }
}

// Alguien entra en el grupo. Se manda la lista COMPLETA de los que estan dentro
// (no solo el que entra): asi el movil se recupera solo aunque pierda un aviso.
// $tellJoiner: ademas se le manda a quien entra un aviso SOLO con GroupProperties
// (sin Joined/Left, que el DTD permite) para que aprenda el NOMBRE del grupo.
// Hace falta cuando el grupo lo creo otro (p. ej. desde la web): el movil no lo
// sabe y lo titula con el GroupID. Tambien se manda al abrir un grupo en el que
// ya estaba, para arreglar el titulo sin tener que salir y volver a entrar.
function group_notify_join(string $gid, int $exceptUserId = 0, bool $tellJoiner = false): void
{
    group_send_change_notice($gid, array_values(group_members_info($gid, true)), [], $exceptUserId);
    if ($tellJoiner && $exceptUserId > 0) {
        group_queue_change_notice($exceptUserId, $gid, [], [], false, true);
    }
}

// Alguien sale del grupo.
function group_notify_leave(string $gid, int $leaverId, int $exceptUserId = 0): void
{
    $info = group_members_info($gid);
    if (isset($info[$leaverId])) {
        $left = [$info[$leaverId]];
    } else {
        $left = [];
        try {
            $st = db()->prepare('SELECT nick FROM users WHERE id = ?');
            $st->execute([$leaverId]);
            $nick = (string)$st->fetchColumn();
            if ($nick !== '') {
                $left = [['nick' => $nick, 'sname' => $nick]];
            }
        } catch (Throwable $e) {
        }
    }
    group_send_change_notice($gid, [], $left, $exceptUserId);
}

// CSP 1.2 §6.5: si el usuario esta dentro de algun grupo cuando pide el logout,
// el servidor le saca de todos (la pertenencia, member, se mantiene) y avisa a
// los demas. Al reconectar el movil vuelve a entrar con JoinGroup.
function group_leave_all(int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    try {
        $st = db()->prepare('SELECT group_id FROM group_members WHERE user_id = ? AND joined = 1');
        $st->execute([$userId]);
        $gids = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return;
    }
    $nick = '';
    try {
        $st = db()->prepare('SELECT nick FROM users WHERE id = ?');
        $st->execute([$userId]);
        $nick = (string)$st->fetchColumn();
    } catch (Throwable $e) {
    }
    foreach ($gids as $gid) {
        try {
            group_member_set($gid, $userId, ['joined' => 0, 'subscribed' => 0]);
            group_add_event($gid, 'leave', $nick);
            group_notify_leave($gid, $userId, $userId);
        } catch (Throwable $e) {
        }
    }
}

// Cerrar la pestana no manda ningun Logout-Request, asi que un usuario web se
// quedaria "dentro" de sus grupos para siempre. Aqui se saca de sus grupos a
// quien ya no da senales: sin sesion movil viva (wv_sessions.last_seen) y con
// el latido de presencia caducado. La web late cada pocos segundos mientras la
// pestana esta abierta (touch_presence en cada peticion a api.php), asi que el
// margen solo pilla a las pestanas cerradas. El movil queda excluido por su
// sesion; si la pierde, es como una desconexion y tambien sale.
function group_leave_stale(int $seconds = 300): void
{
    $seconds = max(60, $seconds);
    try {
        $st = db()->prepare('SELECT DISTINCT gm.user_id FROM group_members gm
            LEFT JOIN presence p ON p.user_id = gm.user_id
            WHERE gm.joined = 1
              AND NOT EXISTS (SELECT 1 FROM wv_sessions s WHERE s.user_id = gm.user_id
                              AND s.last_seen > datetime("now", ?))
              AND (p.user_id IS NULL OR p.online = 0 OR p.updated_at IS NULL
                   OR p.updated_at < datetime("now", ?))');
        $st->execute(['-' . $seconds . ' seconds', '-' . $seconds . ' seconds']);
        $uids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
        return;
    }
    foreach ($uids as $uid) {
        group_leave_all($uid);
    }
}

// El bloqueo oculta la presencia en una sola direccion: quien bloquea se vuelve
// invisible para el bloqueado (como MSN). Si el contacto ha bloqueado al owner,
// el owner no ve la presencia del contacto (lo ve "desconectado"); el bloqueador
// sigue viendo con normalidad a quien bloqueo.
function presence_hidden(int $ownerId, string $contactNick): bool
{
    if ($ownerId <= 0 || $contactNick === '') {
        return false;
    }
    try {
        $st = db()->prepare('SELECT nick FROM users WHERE id = ?');
        $st->execute([$ownerId]);
        $ownerNick = (string)$st->fetchColumn();
        $st = db()->prepare('SELECT 1 FROM blocked WHERE owner_id = (SELECT id FROM users WHERE nick = ?) AND contact_nick = ? LIMIT 1');
        $st->execute([$contactNick, $ownerNick]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

// --- Limite de historial -----------------------------------------------------
// En un alojamiento compartido la base no debe crecer sin fin: de cada
// conversacion se guardan los ultimos WV_HISTORY_LIMIT mensajes y los mas
// viejos se van borrando al escribir. Los ids no se reutilizan (las tablas son
// AUTOINCREMENT), asi que los cursores de entrega siguen valiendo: el sondeo
// pide `id > last_read_id` / `id > last_msg_id` y lo borrado simplemente ya no
// esta. Se recorta en el momento de escribir, que es cuando crece.
defined('WV_HISTORY_LIMIT') || define('WV_HISTORY_LIMIT', 150);

// Conversacion privada (los dos sentidos). Devuelve cuantos borro.
function prune_private_history(string $a, string $b, int $keep = 0): int
{
    $keep = $keep > 0 ? $keep : WV_HISTORY_LIMIT;
    if ($a === '' || $b === '') {
        return 0;
    }
    try {
        // Id del mensaje mas viejo que se conserva (el "keep"-esimo desde el
        // final). Los ids crecen con la fecha, asi que borrar los menores es
        // borrar los mas viejos.
        $st = db()->prepare('SELECT id FROM messages WHERE (from_user = ? AND to_user = ?) OR (from_user = ? AND to_user = ?) ORDER BY id DESC LIMIT 1 OFFSET ' . ($keep - 1));
        $st->execute([$a, $b, $b, $a]);
        $desde = $st->fetchColumn();
        $st->closeCursor();
        if ($desde === false || $desde === null) {
            return 0; // aun no hay suficientes mensajes
        }
        $del = db()->prepare('DELETE FROM messages WHERE id < ? AND ((from_user = ? AND to_user = ?) OR (from_user = ? AND to_user = ?))');
        $del->execute([(int)$desde, $a, $b, $b, $a]);
        $n = $del->rowCount();
        $del->closeCursor();
        return $n;
    } catch (Throwable $e) {
        return 0;
    }
}

// Historial de un grupo (mensajes y lineas de entrar/salir).
function prune_group_history(string $gid, int $keep = 0): int
{
    $keep = $keep > 0 ? $keep : WV_HISTORY_LIMIT;
    if ($gid === '') {
        return 0;
    }
    try {
        $st = db()->prepare('SELECT id FROM group_messages WHERE group_id = ? ORDER BY id DESC LIMIT 1 OFFSET ' . ($keep - 1));
        $st->execute([$gid]);
        $desde = $st->fetchColumn();
        $st->closeCursor();
        if ($desde === false || $desde === null) {
            return 0;
        }
        $del = db()->prepare('DELETE FROM group_messages WHERE group_id = ? AND id < ?');
        $del->execute([$gid, (int)$desde]);
        $n = $del->rowCount();
        $del->closeCursor();
        return $n;
    } catch (Throwable $e) {
        return 0;
    }
}
