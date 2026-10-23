<?php
require __DIR__ . '/codepages.php';

class WbxmlCodec
{
    private static $data = '';
    private static $pos = 0;
    private static $strTable = '';
    private static $page = 0;
    private static $encPage = 0;

    public static function decode(string $wbxml): string
    {
        self::$pos = 0;
        self::$data = $wbxml;
        self::$page = 0;

        if (ord(self::$data[0]) !== 3) {
            throw new RuntimeException('Unsupported WBXML version');
        }
        self::$pos = 1;

        // public id
        self::readMbUint();
        // charset
        self::readMbUint();
        $stLen = self::readMbUint();
        self::$strTable = substr(self::$data, self::$pos, $stLen);
        self::$pos += $stLen;

        $xml = self::parseContent();
        return trim($xml);
    }

    private static function readByte(): int
    {
        if (self::$pos >= strlen(self::$data)) {
            throw new RuntimeException('Unexpected EOF');
        }
        return ord(self::$data[self::$pos++]);
    }

    private static function readMbUint(): int
    {
        $v = 0;
        for ($i = 0; $i < 5; $i++) {
            $b = self::readByte();
            $v = ($v << 7) | ($b & 0x7f);
            if (($b & 0x80) === 0) {
                return $v;
            }
        }
        throw new RuntimeException('Bad mbuint');
    }

    private static function strTableAt(int $offset): string
    {
        $idx = strpos(self::$strTable, "\x00", $offset);
        if ($idx === false) {
            $idx = strlen(self::$strTable);
        }
        return substr(self::$strTable, $offset, $idx - $offset);
    }

    private static function attrPrefix(int $token): string
    {
        $map = [
            0x05 => 'http://www.wireless-village.org/CSP',
            0x06 => 'http://www.wireless-village.org/PA',
            0x07 => 'http://www.wireless-village.org/TRC',
            0x08 => 'http://www.openmobilealliance.org/DTD/WV-CSP',
            0x09 => 'http://www.openmobilealliance.org/DTD/WV-PA',
            0x0A => 'http://www.openmobilealliance.org/DTD/WV-TRC',
        ];
        return $map[$token] ?? '';
    }

    private static function parseContent(): string
    {
        $out = '';
        $len = strlen(self::$data);
        while (self::$pos < $len) {
            $t = ord(self::$data[self::$pos]);
            if ($t === 0x01) { // END
                self::$pos++;
                break;
            }
            if ($t === 0x00) { // switch page
                self::$pos++;
                self::$page = self::readByte();
                continue;
            }
            if ($t === 0x02) { // entity
                self::$pos++;
                $v = self::readMbUint();
                $out .= '&#' . $v . ';';
                continue;
            }
            if ($t === 0x03) { // STR_I
                self::$pos++;
                $start = self::$pos;
                $end = strpos(self::$data, "\x00", self::$pos);
                if ($end === false) $end = $len;
                $str = substr(self::$data, $start, $end - $start);
                self::$pos = $end + 1;
                $out .= htmlspecialchars($str, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                continue;
            }
            if ($t === 0x83) { // STR_T
                self::$pos++;
                $off = self::readMbUint();
                $out .= htmlspecialchars(self::strTableAt($off), ENT_XML1 | ENT_QUOTES, 'UTF-8');
                continue;
            }
            if ($t === 0xC3) { // OPAQUE binary data
                self::$pos++;
                $l = self::readMbUint();
                $bin = substr(self::$data, self::$pos, $l);
                self::$pos += $l;
                // WV fields are often integers in OPAQUE form; longer blobs remain base64
                if (strlen($bin) >= 1 && strlen($bin) <= 4) {
                    $v = 0;
                    foreach (str_split($bin) as $ch) {
                        $v = ($v << 8) | ord($ch);
                    }
                    $out .= $v;
                } else {
                    $out .= base64_encode($bin);
                }
                continue;
            }
            if ($t === 0x80) { // EXT_T_0
                self::$pos++;
                $vtok = self::readByte();
                $name = $GLOBALS['WV_VALUE_TOKENS'][$vtok] ?? null;
                if ($name !== null) {
                    $out .= htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                }
                continue;
            }
            // tag token (o LITERAL: nombre de tag fuera de la code page, p.ej. BlockEntity-Request)
            $hasAttr = ($t & 0x80) !== 0;
            $hasContent = ($t & 0x40) !== 0;
            $tagId = $t & 0x3f;
            self::$pos++;
            if ($tagId === 0x04) {
                // LITERAL: sigue una referencia a la string table o una cadena inline
                $v = self::readMbUint();
                if ($v < strlen(self::$strTable)) {
                    $tagName = self::strTableAt($v);
                } else {
                    $tagName = substr(self::$data, self::$pos, $v);
                    self::$pos += $v;
                }
            } else {
                $tagName = $GLOBALS['WV_TAG_PAGES'][self::$page][$tagId] ?? ('Unknown' . dechex($tagId));
            }

            $attrs = '';
            if ($hasAttr) {
                while (self::$pos < $len) {
                    $a = ord(self::$data[self::$pos]);
                    if ($a === 0x00) {
                        self::$pos++;
                        self::$page = self::readByte();
                        continue;
                    }
                    if ($a === 0x01) {
                        self::$pos++;
                        break;
                    }
                    $attrTok = $a & 0x3f;
                    $prefix = self::attrPrefix($attrTok);
                    self::$pos++;
                    $value = '';
                    $nt = ord(self::$data[self::$pos] ?? 0);
                    if ($nt === 0x03) {
                        self::$pos++;
                        $start = self::$pos;
                        $end = strpos(self::$data, "\x00", self::$pos);
                        if ($end === false) $end = $len;
                        $value = substr(self::$data, $start, $end - $start);
                        self::$pos = $end + 1;
                    } elseif ($nt === 0x83) {
                        self::$pos++;
                        $off = self::readMbUint();
                        $value = self::strTableAt($off);
                    }
                    $attrs .= ' xmlns="' . htmlspecialchars($prefix . $value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"';
                }
            }

            $content = '';
            if ($hasContent) {
                $content = self::parseContent();
            }

            $out .= '<' . $tagName . $attrs . '>' . $content . '</' . $tagName . '>';
        }
        return $out;
    }

    public static function encode(string $xml, int $publicId = 0x10): string
    {
        // minimal encoder: page management, tags, attrs, text/value tokens, header without string table
        $doc = simplexml_load_string($xml);
        if ($doc === false) {
            throw new RuntimeException('Invalid XML for WBXML encode');
        }
        self::$encPage = 0;
        $body = self::encodeNode($doc);
        // header
        $header = chr(3) . self::mbUint(0x10) . self::mbUint(106) . self::mbUint(0);
        return $header . $body;
    }

    private static function encodeNode($node): string
    {
        // returns body bytes; manages codepage via switch-page
        $name = $node->getName();
        // find page
        $page = null;
        foreach ($GLOBALS['WV_TAG_PAGES'] as $p => $map) {
            $rev = array_flip($map);
            if (isset($rev[$name])) {
                $page = (int)$p;
                $tagId = $rev[$name];
                break;
            }
        }
        if ($page === null) {
            throw new RuntimeException('Unknown tag for WBXML: ' . $name);
        }
        $out = '';
        if ($page !== self::$encPage) {
            $out .= "\x00" . chr($page);
            self::$encPage = $page;
        }
        $cp = $page;

        $children = $node->children();
        $hasContent = false;
        $content = '';
        $hasChildElem = count($children) > 0;
        $text = trim((string)$node);
        // attributes
        $attrs = '';
        $hasAttr = false;
        foreach ($node->attributes() as $k => $v) {
            $hasAttr = true;
            $value = (string)$v;
            if ($k === 'xmlns') {
                // choose token by prefix
                [$attrTok, $prefix] = self::attrTokenForXmlns($value);
                $attrs .= chr($attrTok);
                $suffix = substr($value, strlen($prefix));
                if ($suffix !== '') {
                    $attrs .= "\x03" . $suffix . "\x00";
                }
                $attrs .= "\x01";
            }
        }

        // inject xmlns attrs for WV 1.1 (client may require them)
        if ($name === 'WV-CSP-Message') {
            $attrs = (isset($attrs) ? $attrs : '') . "\x05\x03" . '1.1' . "\x00\x01";
            $hasAttr = true;
        }
        if ($name === 'TransactionContent') {
            $attrs = (isset($attrs) ? $attrs : '') . "\x07\x03" . '1.1' . "\x00\x01";
            $hasAttr = true;
        }
        if ($name === 'PresenceSubList') {
            // SimpleXML does not enumerate xmlns declarations, so emit the PA
            // namespace attribute explicitly (phones expect it, like their own requests)
            $attrs = (isset($attrs) ? $attrs : '') . "\x06\x03" . '1.1' . "\x00\x01";
            $hasAttr = true;
        }

        // content
        $contentBytes = '';
        foreach ($children as $child) {
            $hasContent = true;
            $contentBytes .= self::encodeNode($child);
        }
        if ($text !== '' && count($children) === 0 && in_array($name, ['MultiTrans','ParserSize','AcceptedContentLength','UDPPort','TCPPort','ServerPollMin','TimeToLive','KeepAliveTime'], true) && ctype_digit($text)) {
            $n = (int)$text;
            if ($n <= 255) {
                $contentBytes = "\xc3\x01" . chr($n);
                $hasContent = true;
            } elseif ($n <= 65535) {
                $contentBytes = "\xc3\x02" . chr(($n >> 8) & 0xff) . chr($n & 0xff);
                $hasContent = true;
            } else {
                $contentBytes = "\xc3\x04" . chr(($n >> 24) & 0xff) . chr(($n >> 16) & 0xff) . chr(($n >> 8) & 0xff) . chr($n & 0xff);
                $hasContent = true;
            }
        } elseif ($text !== '' && count($children) === 0) {
            // try value token
            $valTok = array_search($text, $GLOBALS['WV_VALUE_TOKENS'], true);
            if ($valTok !== false) {
                $contentBytes = "\x80" . chr($valTok);
                $hasContent = true;
            } else {
                $contentBytes = "\x03" . $text . "\x00";
                $hasContent = true;
            }
        }

        $flags = 0;
        if ($hasAttr) $flags |= 0x80;
        if ($hasContent) $flags |= 0x40;
        $out .= chr(($flags | ($tagId & 0x3f)));
        $out .= $attrs;
        $out .= $contentBytes;
        if ($hasContent) {
            $out .= "\x01";
        }
        return $out;
    }

    private static function attrTokenForXmlns(string $value): array
    {
        $prefixes = [
            ['http://www.wireless-village.org/CSP', 0x05],
            ['http://www.wireless-village.org/PA', 0x06],
            ['http://www.wireless-village.org/TRC', 0x07],
            ['http://www.openmobilealliance.org/DTD/WV-CSP', 0x08],
            ['http://www.openmobilealliance.org/DTD/WV-PA', 0x09],
            ['http://www.openmobilealliance.org/DTD/WV-TRC', 0x0A],
        ];
        foreach ($prefixes as [$pref, $tok]) {
            if (strpos($value, $pref) === 0) {
                return [$tok, $pref];
            }
        }
        // fallback: common CSP
        return [0x05, 'http://www.wireless-village.org/CSP'];
    }

    private static function mbUint(int $v): string
    {
        $out = '';
        $bytes = [];
        $bytes[] = $v & 0x7f;
        $v >>= 7;
        while ($v > 0) {
            $bytes[] = 0x80 | ($v & 0x7f);
            $v >>= 7;
        }
        $bytes = array_reverse($bytes);
        foreach ($bytes as $b) $out .= chr($b);
        return $out;
    }
}
