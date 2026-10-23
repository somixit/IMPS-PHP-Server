CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nick TEXT NOT NULL UNIQUE,
  domain TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  -- Contrasena propia del cliente IMPS (cifrada con AES-256-GCM; ver
  -- imps_crypto.php). Es independiente de la contrasena web.
  imps_password TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS wv_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  session_id TEXT NOT NULL,
  session_cookie TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  last_seen TEXT NOT NULL DEFAULT (datetime('now')),
  multitrans INTEGER NOT NULL DEFAULT 1,
  -- 0 mientras no se le haya mandado a esta sesion la lista de participantes de
  -- sus grupos (se manda en su primer Polling).
  groups_synced INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY(user_id) REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_wv_sessions_session ON wv_sessions(session_id);

-- Retos de login IMPS (challenge-response). El nonce se guarda aqui y solo
-- vale una vez, con una caducidad corta. Ver imps.php (Login-Request).
CREATE TABLE IF NOT EXISTS imps_challenges (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  nick TEXT NOT NULL,
  client_url TEXT NOT NULL DEFAULT '',
  nonce TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_imps_challenges_nick ON imps_challenges(nick);
-- Listas de contactos del usuario (GetList/CreateList/DeleteList del estandar
-- IMPS). El movil SE crea listas propias (~pep1.0_subscriptions-1 = "My friends",
-- ~pep1.0_privatelist, etc.); hay que recordarlas para que GetList las devuelva
-- y el movil no las recree vacias en cada reconexion.
CREATE TABLE IF NOT EXISTS lists (
  owner_id INTEGER NOT NULL,
  list_name TEXT NOT NULL,
  display_name TEXT,
  is_default INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY(owner_id, list_name),
  FOREIGN KEY(owner_id) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS contacts (
  owner_id INTEGER NOT NULL,
  contact_nick TEXT NOT NULL,
  list_name TEXT NOT NULL DEFAULT 'Friends',
  alias TEXT NOT NULL DEFAULT '',
  -- auto=1 cuando el contacto se anadio automaticamente al recibir un mensaje
  -- de alguien que no estaba en la lista (solo para poder ver/responder). No
  -- cuenta como "contacto" a efectos de autorizacion de presencia.
  auto INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY(owner_id, contact_nick),
  FOREIGN KEY(owner_id) REFERENCES users(id)
);
CREATE TABLE IF NOT EXISTS messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  from_user TEXT NOT NULL,
  to_user TEXT NOT NULL,
  body TEXT NOT NULL,
  content_type TEXT NOT NULL DEFAULT 'text/plain',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  delivered_at TEXT,
  read_at TEXT
);
CREATE TABLE IF NOT EXISTS presence (
  user_id INTEGER PRIMARY KEY,
  status_code TEXT NOT NULL DEFAULT 'AVAILABLE',
  status_mood TEXT,
  status_text TEXT,
  -- Imagen/logo de estado (WV-029 StatusContent): base64 (kind='direct') o URL
  -- (kind='referred'), con su ContentType (p.ej. image/jpg). El movil la envia
  -- dentro de UpdatePresence-Request.
  status_content TEXT,
  content_type TEXT,
  content_kind TEXT,
  -- online=1 mientras el usuario esta conectado (movil o web). Se pone a 0 en el
  -- logout; si el cliente desaparece sin logout, el latido (updated_at/last_seen)
  -- caduca y el usuario se considera offline igualmente.
  online INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS presence_notices (
  mobile_user_id INTEGER NOT NULL,
  contact_user_id INTEGER NOT NULL,
  sent_at TEXT NOT NULL,
  PRIMARY KEY(mobile_user_id, contact_user_id)
);

CREATE TABLE IF NOT EXISTS presence_track (
  mobile_user_id INTEGER NOT NULL,
  contact_user_id INTEGER NOT NULL,
  online INTEGER NOT NULL DEFAULT 0,
  state TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY(mobile_user_id, contact_user_id)
);

-- Lista de bloqueados (BlockList del estandar IMPS): el propietario no ve el
-- estado de estos contactos ni recibe sus mensajes.
CREATE TABLE IF NOT EXISTS blocked (
  owner_id INTEGER NOT NULL,
  contact_nick TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY(owner_id, contact_nick),
  FOREIGN KEY(owner_id) REFERENCES users(id)
);

-- Listas de atributos de presencia (CSP 1.2 §8.2). Definen la "proactive
-- authorization": que atributos de presencia puede ver cada observador. El
-- movil SE las usa para "Mostrar mi estado: a todos / solo contactos".
--   kind='user'    target=<nick>            atributos autorizados a ese usuario
--   kind='list'    target=<lista de contactos>  atributos autorizados a esa lista
--   kind='default' target=''                atributos autorizados a los demas
-- attrs = nombres separados por comas ('' = ninguno autorizado).
CREATE TABLE IF NOT EXISTS attr_lists (
  owner_id INTEGER NOT NULL,
  kind TEXT NOT NULL,
  target TEXT NOT NULL DEFAULT '',
  attrs TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY(owner_id, kind, target),
  FOREIGN KEY(owner_id) REFERENCES users(id)
);

-- --- Chat grupal (CSP 1.2 seccion 10, "Group Feature") ---------------------
-- Un grupo tiene un GroupID que el cliente elige (p.ej.
-- "wv:jat/los_fiesteros_20261016t115452z") y un propietario. Las propiedades
-- del grupo (Name, Accesstype, ...) se guardan en 'props' como pares
-- Nombre=Valor separados por \n.
CREATE TABLE IF NOT EXISTS groups (
  group_id TEXT PRIMARY KEY,
  owner_id INTEGER NOT NULL,
  name TEXT NOT NULL DEFAULT '',
  accesstype TEXT NOT NULL DEFAULT 'Open',
  props TEXT NOT NULL DEFAULT '',
  welcome TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(owner_id) REFERENCES users(id)
);

-- Miembros del grupo. member=1 -> esta en la lista de miembros (puede unirse
-- aunque el grupo sea Restricted); joined=1 -> esta dentro ahora mismo (recibe
-- los mensajes del grupo). privilege: Admin | Mod | User. last_msg_id sirve
-- para entregar al movil solo los mensajes nuevos por polling; last_read_id es
-- el ultimo mensaje leido en la web (para el contador de no leidos).
CREATE TABLE IF NOT EXISTS group_members (
  group_id TEXT NOT NULL,
  user_id INTEGER NOT NULL,
  privilege TEXT NOT NULL DEFAULT 'User',
  member INTEGER NOT NULL DEFAULT 1,
  joined INTEGER NOT NULL DEFAULT 0,
  rejected INTEGER NOT NULL DEFAULT 0,
  screen_name TEXT NOT NULL DEFAULT '',
  show_id TEXT NOT NULL DEFAULT 'F',
  private_messaging TEXT NOT NULL DEFAULT 'F',
  subscribed INTEGER NOT NULL DEFAULT 0,
  last_msg_id INTEGER NOT NULL DEFAULT 0,
  last_read_id INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY(group_id, user_id),
  FOREIGN KEY(group_id) REFERENCES groups(group_id),
  FOREIGN KEY(user_id) REFERENCES users(id)
);

-- Mensajes de un grupo. Se guardan una sola vez; cada miembro los lee de aqui
-- (la web) o los recibe por polling (el movil) segun su last_msg_id.
CREATE TABLE IF NOT EXISTS group_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  group_id TEXT NOT NULL,
  from_user TEXT NOT NULL,
  body TEXT NOT NULL,
  content_type TEXT NOT NULL DEFAULT 'text/plain',
  -- 'msg' = mensaje normal; 'join'/'leave' = linea de sistema (entradas y
  -- salidas, solo para la web: al movil se le avisa con GroupChangeNotice).
  kind TEXT NOT NULL DEFAULT 'msg',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  FOREIGN KEY(group_id) REFERENCES groups(group_id)
);

-- Invitaciones a un grupo (Invite-Request con InviteType=GR). La web las
-- muestra para aceptar o rechazar; el movil recibe InviteUser-Request.
CREATE TABLE IF NOT EXISTS group_invites (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  invite_id TEXT NOT NULL,
  group_id TEXT NOT NULL,
  inviter_id INTEGER NOT NULL,
  invitee_id INTEGER NOT NULL,
  note TEXT NOT NULL DEFAULT '',
  state TEXT NOT NULL DEFAULT 'pending',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(invite_id, invitee_id)
);

-- Cola generica de primitivas que el servidor debe enviar al cliente en su
-- proximo Polling (GroupChangeNotice, InviteUser-Request, LeaveGroupResponse
-- iniciada por el servidor...). 'xml' es la primitiva ya serializada.
CREATE TABLE IF NOT EXISTS wv_notices (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  xml TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  sent_at TEXT,
  -- 1 = el aviso va antes que todo lo demas en la misma entrega. Lo usa la
  -- sincronizacion de la lista de participantes (va al principio del Polling).
  sort_first INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY(user_id) REFERENCES users(id)
);

-- Conversaciones abiertas en la web: el equivalente a la "zona de chats" del
-- movil, pero persistente (el telefono las vacia al desconectar, CSP 1.2 §6.5;
-- la web las mantiene hasta que se cierran con la X). kind: 'user' | 'group';
-- target: nick del contacto o GroupID. El orden no depende de opened_at: se
-- ordena por el ultimo mensaje, asi que reabrir no reordena la lista.
CREATE TABLE IF NOT EXISTS web_conversations (
  user_id INTEGER NOT NULL,
  kind TEXT NOT NULL,
  target TEXT NOT NULL,
  opened_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (user_id, kind, target),
  FOREIGN KEY(user_id) REFERENCES users(id)
);

-- Intentos de login/registro por IP (anti fuerza bruta, security.php).
CREATE TABLE IF NOT EXISTS login_attempts (
  ip TEXT PRIMARY KEY,
  attempts INTEGER NOT NULL DEFAULT 0,
  first_attempt TEXT NOT NULL DEFAULT (datetime('now')),
  last_attempt TEXT NOT NULL DEFAULT (datetime('now')),
  blocked_until TEXT
);
