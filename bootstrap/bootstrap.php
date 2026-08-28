<?php

#[\AllowDynamicProperties]
class erLhcoreClassExtensionLhcmax
{
    private static $instance = null;
    private $settings = [];

    public function __construct()
    {
        $this->loadSettings();
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function run()
    {
        $dispatcher = erLhcoreClassChatEventDispatcher::getInstance();

        // Text is delivered by the configured REST API trigger. The extension
        // owns only native three-step media uploads.
        $dispatcher->listen('chat.web_add_msg_admin', array($this, 'onWebAddMsgAdmin'));
    }

    public function loadSettings()
    {
        $settingsFile = dirname(__DIR__) . '/settings/settings.ini.php';
        if (file_exists($settingsFile)) {
            $this->settings = include $settingsFile;
        } else {
            $this->settings = [
                'enabled' => true,
                'host' => 'https://platform-api2.max.ru',
                'token' => '',
                'department_id' => 0,
                'debug' => false
            ];
        }
        return $this->settings;
    }

    public function getSettings()
    {
        if (empty($this->settings)) {
            $this->loadSettings();
        }
        return $this->settings;
    }

    public function saveSettings($newSettings)
    {
        $this->settings = array_merge($this->getSettings(), $newSettings);
        $settingsFile = dirname(__DIR__) . '/settings/settings.ini.php';
        $content = "<?php\n\nreturn " . var_export($this->settings, true) . ";\n\n?>";
        return file_put_contents($settingsFile, $content) !== false;
    }

    public function getSetting($key, $default = null)
    {
        return isset($this->settings[$key]) ? $this->settings[$key] : $default;
    }

    public function isMaxChat($chat)
    {
        if (!($chat instanceof erLhcoreClassModelChat)) {
            return false;
        }

        // Check if chat is incoming chat with scope 'max'
        if ($chat->iwh_id > 0) {
            $iwh = $chat->incoming_chat;
            if ($iwh instanceof erLhcoreClassModelChatIncoming && $iwh->incoming instanceof erLhcoreClassModelChatIncomingWebhook) {
                if ($iwh->incoming->scope === 'max') {
                    return true;
                }
            }
        }

        // Optional department fallback for installations that do not attach the
        // Max scope to their incoming webhook. Department IDs are local.
        $departmentId = (int)$this->getSetting('department_id', 0);
        if ($departmentId > 0 && (int)$chat->dep_id === $departmentId) {
            return true;
        }

        return false;
    }

    public function onWebAddMsgAdmin($params)
    {
        if (!is_array($params) || !isset($params['msg'], $params['chat']) || !is_object($params['msg']) || !is_object($params['chat'])) {
            return;
        }

        $msg = $params['msg'];
        $chat = $params['chat'];

        // Incoming webhook visitor messages use the same event as outbound
        // admin/bot messages. Never echo a visitor message back to Max.
        if (isset($msg->user_id) && (int)$msg->user_id === 0) {
            return;
        }

        if (!$this->isMaxChat($chat) || !$this->getSetting('enabled', true)) {
            return;
        }

        $files = $this->extractMessageFiles($msg);

        // REST handles text (and its own message ID bookkeeping). Media must
        // use the native three-step flow because the REST worker cannot safely
        // reopen the local file after its first upload attempt.
        if (!empty($files)) {
            $cleanCaption = $this->cleanMessageText($msg->msg ?? '');
            $replyMid = $this->extractReplyMid($msg, $chat);

            $fileIndex = 0;
            foreach ($files as $fileItem) {
                // A multi-file LHC message is one logical message. Keep its
                // text as the caption on the first upload only.
                $caption = $fileIndex === 0
                    ? (!empty($fileItem['caption']) ? $this->cleanMessageText($fileItem['caption']) : $cleanCaption)
                    : '';
                $this->sendMaxMediaFile($chat, $fileItem['file'], $caption, $replyMid, $msg);
                $fileIndex++;
            }
        }
    }

    public function cleanMessageText($text)
    {
        // 1. Remove [file=ID_HASH] embeds
        $clean = preg_replace('#\[file=([0-9]+)_([a-z0-9]+)\]#i', '', (string)$text);
        
        // 2. Remove both numeric and local-only [quote]...[/quote] BBCode.
        $clean = preg_replace('#\[quote(?:=[^\]]+)?\](.*?)\[/quote\]#is', '', $clean);

        // Captions are sent as plain Max text. Strip raw markup while leaving
        // LHC BBCode untouched for installations that render it downstream.
        return trim(strip_tags($clean));
    }

    public function extractReplyMid($msg, $chat)
    {
        if (!is_object($msg) || !is_object($chat) || (int)($chat->id ?? 0) <= 0) {
            return null;
        }

        $meta = $this->getMessageMeta($msg);

        // 1. Direct iwh_msg_id in meta
        if (!empty($meta['content']['reply_to']['iwh_msg_id'])) {
            $mid = (string)$meta['content']['reply_to']['iwh_msg_id'];
            return $this->isMidInChat($mid, $chat) ? $mid : null;
        }

        // 2. Referenced db_msg_id
        if (!empty($meta['content']['reply_to']['db_msg_id'])) {
            $target = erLhcoreClassModelmsg::fetch((int)$meta['content']['reply_to']['db_msg_id']);
            if ($this->isMessageInChat($target, $chat)) {
                $targetMeta = $this->getMessageMeta($target);
                if (!empty($targetMeta['iwh_msg_id'])) {
                    return (string)$targetMeta['iwh_msg_id'];
                }
            }
        }

        // 3. BBCode [quote=123]
        if (preg_match('#\[quote="?([0-9]+)"?\]#is', (string)$msg->msg, $m)) {
            $target = erLhcoreClassModelmsg::fetch((int)$m[1]);
            if ($this->isMessageInChat($target, $chat)) {
                $targetMeta = $this->getMessageMeta($target);
                if (!empty($targetMeta['iwh_msg_id'])) {
                    return (string)$targetMeta['iwh_msg_id'];
                }
            }
        }

        return null;
    }

    public function sendMaxMediaFile($chat, $file, $caption = '', $replyMid = null, $msg = null)
    {
        $chatExternalId = $this->getChatExternalId($chat);
        $host = $this->getApiHost();
        $token = $this->getApiToken();
        if ($chatExternalId === '' || $host === null || $token === '' || !($file instanceof erLhcoreClassModelChatFile)) {
            return false;
        }

        $filePath = $file->file_path_server;
        if (!is_string($filePath) || !is_file($filePath) || !is_readable($filePath)) {
            return false;
        }

        // Determine type: image, audio, video, file
        $fileType = 'file';
        if (strpos($file->type, 'image/') === 0 || in_array(strtolower($file->extension), ['jpg','jpeg','png','gif','webp','bmp'])) {
            $fileType = 'image';
        } elseif (strpos($file->type, 'audio/') === 0 || in_array(strtolower($file->extension), ['mp3','ogg','oga','wav','m4a','aac','opus'])) {
            $fileType = 'audio';
        } elseif (strpos($file->type, 'video/') === 0 || in_array(strtolower($file->extension), ['mp4','mov','avi','mkv','webm'])) {
            $fileType = 'video';
        }

        // Step 1: Init / Request Upload URL
        $initUrl = self::appendQuery($host, '/uploads', array('type' => $fileType));
        $initResp = $this->executeCurl($initUrl, 'POST', array(), $this->authHeaders(false, $token));

        if (!self::isSuccessfulResponse($initResp) || !is_array($initResp['data'])) {
            return false;
        }

        $uploadUrl = self::responseValue($initResp['data'], array('url', 'upload_url', 'data.url', 'data.upload_url'));
        $uploadToken = self::responseValue($initResp['data'], array('token', 'upload_token', 'id', 'file_id', 'data.token', 'data.upload_token', 'data.id'));

        if (!is_string($uploadUrl) || trim($uploadUrl) === '') {
            return false;
        }

        $uploadUrl = self::resolveUrl($initUrl, $uploadUrl);
        if ($uploadUrl === null) {
            return false;
        }

        // Step 2: Binary / Multipart Upload
        $mime = preg_replace('/[\x00-\x1F\x7F]/', '', (string)$file->type);
        $name = basename(preg_replace('/[\x00-\x1F\x7F]/', '', (string)$file->upload_name));
        $cFile = new CurlFile($filePath, $mime !== '' ? $mime : 'application/octet-stream', $name !== '' ? $name : 'file');
        $postFields = ['data' => $cFile];

        // The upload URL is a signed, type-specific storage endpoint. Keep
        // the bot token on the Max API calls only; it must not be sent to the
        // external upload host. cURL generates the multipart boundary.
        $uploadResp = $this->executeCurl($uploadUrl, 'POST_MULTIPART', $postFields, []);
        if (!self::isSuccessfulResponse($uploadResp)) {
            return false;
        }

        // Parse token from nested structures if present
        if (is_array($uploadResp['data'])) {
            $responseToken = self::responseValue($uploadResp['data'], array(
                'token', 'upload_token', 'id', 'file_id',
                'data.token', 'data.upload_token', 'data.id', 'photos.0.token', 'photos.0.id', 'photos.*.token', 'photos.*.id', 'data.photos.*.token', 'data.photos.*.id'
            ));
            if ($responseToken !== null && $responseToken !== '') {
                $uploadToken = $responseToken;
            }
        }

        if (!is_scalar($uploadToken) || trim((string)$uploadToken) === '') {
            return false;
        }

        // Step 3: Message dispatch with attachment token
        $msgUrl = self::appendQuery($host, '/messages', array('chat_id' => $chatExternalId));
        $msgPayload = [
            'text' => $caption,
            'attachments' => [
                [
                    'type' => $fileType,
                    'payload' => [
                        'token' => $uploadToken
                    ]
                ]
            ]
        ];

        if (!empty($replyMid)) {
            $msgPayload['link'] = [
                'type' => 'reply',
                'mid' => (string)$replyMid
            ];
        }

        // Max may accept the upload before its attachment processor has made
        // the token usable by /messages. Give the processor a short bounded
        // window and retry only the documented not-ready response.
        $finalResp = $this->sendMaxMessageWithAttachmentRetry($msgUrl, $msgPayload, $this->authHeaders(true, $token));

        $mid = self::extractMessageMid($finalResp);
        if ($mid !== null && $msg instanceof erLhcoreClassModelmsg) {
            $this->saveMessageMid($msg, $mid);
        }

        return self::isSuccessfulResponse($finalResp);
    }

    private function sendMaxMessageWithAttachmentRetry($url, array $payload, array $headers)
    {
        $response = array('http_code' => 0, 'body' => '', 'data' => null, 'curl_error' => '');
        // The first delay is intentional: Max documents a short processing
        // pause after upload, followed by increasing intervals on failure.
        foreach (array(500000, 1000000, 2000000) as $delay) {
            usleep($delay);
            $response = $this->executeCurl($url, 'POST', $payload, $headers);
            if (!self::isAttachmentNotReadyResponse($response)) {
                break;
            }
        }

        return $response;
    }

    private function saveMessageMid($msg, $mid)
    {
        $mid = trim((string)$mid);
        if ($mid === '' || !($msg instanceof erLhcoreClassModelmsg) || (int)$msg->id <= 0) {
            return false;
        }

        $db = ezcDbInstance::get();
        $ownsTransaction = !$db->inTransaction();
        if ($ownsTransaction) {
            $db->beginTransaction();
        }

        try {
            $driver = strtolower((string)$db->getAttribute(PDO::ATTR_DRIVER_NAME));
            $sql = 'SELECT meta_msg FROM lh_msg WHERE id = :id';
            if (in_array($driver, array('mysql', 'pgsql'), true)) {
                $sql .= ' FOR UPDATE';
            }
            $select = $db->prepare($sql);
            $select->bindValue(':id', (int)$msg->id, PDO::PARAM_INT);
            $select->execute();
            $row = $select->fetch(PDO::FETCH_ASSOC);

            $meta = array();
            if (is_array($row) && isset($row['meta_msg']) && $row['meta_msg'] !== '') {
                $decoded = json_decode($row['meta_msg'], true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            if (empty($meta)) {
                $meta = $this->getMessageMeta($msg);
            }

            $ids = isset($meta['iwh_msg_ids']) && is_array($meta['iwh_msg_ids']) ? $meta['iwh_msg_ids'] : array();
            $ids[] = $mid;
            $ids = array_values(array_unique(array_filter(array_map('strval', $ids), function ($value) {
                return trim($value) !== '';
            })));
            $meta['iwh_msg_ids'] = $ids;
            $meta['iwh_msg_id'] = $mid;
            unset($meta['lhcmax_pending']);
            $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded === false) {
                throw new RuntimeException('Unable to encode Max message metadata');
            }

            $stmt = $db->prepare('UPDATE lh_msg SET meta_msg = :meta_msg WHERE id = :id');
            $stmt->bindValue(':meta_msg', $encoded);
            $stmt->bindValue(':id', (int)$msg->id, PDO::PARAM_INT);
            $stmt->execute();

            if ($ownsTransaction) {
                $db->commit();
            }
            $msg->meta_msg_array = $meta;
            $msg->meta_msg = $encoded;
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            return false;
        }
    }

    private function extractMessageFiles($msg)
    {
        $files = [];
        if (preg_match_all('#\[file=([0-9]+)_([a-z0-9]+)\]#i', (string)$msg->msg, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $file = erLhcoreClassModelChatFile::fetch((int)$match[1]);
                if ($file instanceof erLhcoreClassModelChatFile && $file->security_hash === $match[2]) {
                    $files[] = [
                        'file' => $file,
                        'embed' => $match[0],
                        'caption' => ''
                    ];
                }
            }
        }
        return $files;
    }

    private function getMessageMeta($msg)
    {
        if (!is_object($msg)) {
            return array();
        }
        if (isset($msg->meta_msg_array) && is_array($msg->meta_msg_array)) {
            return $msg->meta_msg_array;
        }
        if (isset($msg->meta_msg) && is_string($msg->meta_msg) && $msg->meta_msg !== '') {
            $decoded = json_decode($msg->meta_msg, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return array();
    }

    private function isMessageInChat($target, $chat)
    {
        return $target instanceof erLhcoreClassModelmsg
            && is_object($chat)
            && (int)$target->chat_id > 0
            && (int)$target->chat_id === (int)($chat->id ?? 0);
    }

    private function isMidInChat($mid, $chat)
    {
        $mid = trim((string)$mid);
        $chatId = (int)($chat->id ?? 0);
        if ($mid === '' || $chatId <= 0 || !class_exists('erLhcoreClassModelmsg')) {
            return false;
        }

        try {
            $quoted = ezcDbInstance::get()->quote($mid);
            $target = erLhcoreClassModelmsg::findOne(array(
                'filter' => array('chat_id' => $chatId),
                'customfilter' => array('meta_msg != \'\' AND JSON_VALID(meta_msg) AND (JSON_UNQUOTE(JSON_EXTRACT(meta_msg,\'$.iwh_msg_id\')) = ' . $quoted . ' OR JSON_SEARCH(JSON_EXTRACT(meta_msg,\'$.iwh_msg_ids\'), \'one\', ' . $quoted . ') IS NOT NULL)')
            ));
            return $target instanceof erLhcoreClassModelmsg && (int)$target->chat_id === $chatId;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function getChatExternalId($chat)
    {
        if (!is_object($chat)) {
            return '';
        }
        if (isset($chat->incoming_chat) && $chat->incoming_chat instanceof erLhcoreClassModelChatIncoming) {
            return trim((string)$chat->incoming_chat->chat_external_id);
        }
        return trim((string)($chat->chat_external_id ?? ''));
    }

    private function getApiToken()
    {
        return trim((string)$this->getSetting('token', ''));
    }

    private function getApiHost()
    {
        $configured = trim((string)$this->getSetting('host', 'https://platform-api2.max.ru'));
        if ($configured === '') {
            return null;
        }
        try {
            $parts = parse_url($configured);
            if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (!empty($parts['path']) && trim($parts['path'], '/') !== '')) {
                return null;
            }
            self::validateUrl($configured);
            return rtrim($configured, '/');
        } catch (Throwable $e) {
            return null;
        }
    }

    private function authHeaders($json, $token)
    {
        $headers = array('Authorization: ' . str_replace(array("\r", "\n"), '', (string)$token));
        if ($json) {
            $headers[] = 'Content-Type: application/json';
        }
        return $headers;
    }

    public function testConnection($host = null, $token = null)
    {
        $host = $host === null ? $this->getApiHost() : rtrim(trim((string)$host), '/');
        $token = $token === null ? $this->getApiToken() : trim((string)$token);
        if ($host === null || $token === '') {
            return array('http_code' => 0, 'response' => 'Invalid host or empty token', 'success' => false, 'curl_error' => '');
        }
        try {
            $parts = parse_url($host);
            if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (!empty($parts['path']) && trim($parts['path'], '/') !== '')) {
                throw new RuntimeException('API host must be an origin without path, query, userinfo, or fragment');
            }
            self::validateUrl($host);
        } catch (Throwable $e) {
            return array('http_code' => 0, 'response' => $e->getMessage(), 'success' => false, 'curl_error' => $e->getMessage());
        }
        $response = $this->executeCurl(self::appendQuery($host, '/uploads', array('type' => 'image')), 'POST', array(), $this->authHeaders(false, $token));
        return array(
            'http_code' => (int)($response['http_code'] ?? 0),
            'response' => (string)($response['body'] ?? ''),
            'curl_error' => (string)($response['curl_error'] ?? ''),
            'success' => self::isSuccessfulResponse($response)
        );
    }

    private static function isSuccessfulResponse($response)
    {
        return is_array($response) && isset($response['http_code']) && (int)$response['http_code'] >= 200 && (int)$response['http_code'] < 300 && ($response['body'] ?? false) !== false;
    }

    private static function isAttachmentNotReadyResponse($response)
    {
        if (!is_array($response)) {
            return false;
        }

        $containsCode = function ($value) use (&$containsCode) {
            if (is_array($value)) {
                foreach ($value as $child) {
                    if ($containsCode($child)) {
                        return true;
                    }
                }
                return false;
            }

            return is_scalar($value) && strpos(strtolower((string)$value), 'attachment.not.ready') !== false;
        };

        return $containsCode($response['data'] ?? null) || $containsCode($response['body'] ?? null);
    }

    private static function extractMessageMid($response)
    {
        if (!is_array($response) || !is_array($response['data'] ?? null)) {
            return null;
        }
        $mid = self::responseValue($response['data'], array(
            'message.body.mid', 'body.mid', 'mid', 'data.message.body.mid', 'data.body.mid', 'data.mid'
        ));
        return is_scalar($mid) && trim((string)$mid) !== '' ? (string)$mid : null;
    }

    private static function responseValue($data, $paths)
    {
        if (!is_array($data)) {
            return null;
        }
        $read = function ($value, $parts, $index) use (&$read) {
            if ($index >= count($parts)) {
                return is_scalar($value) && trim((string)$value) !== '' ? $value : null;
            }
            if (!is_array($value)) {
                return null;
            }
            $part = $parts[$index];
            if ($part === '*') {
                foreach ($value as $child) {
                    $found = $read($child, $parts, $index + 1);
                    if ($found !== null) {
                        return $found;
                    }
                }
                return null;
            }
            return array_key_exists($part, $value) ? $read($value[$part], $parts, $index + 1) : null;
        };
        foreach ($paths as $path) {
            $value = $read($data, explode('.', (string)$path), 0);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    public static function appendQuery($baseUrl, $path, $params = array())
    {
        $baseUrl = rtrim((string)$baseUrl, '/');
        $path = '/' . ltrim((string)$path, '/');
        $url = $baseUrl . $path;
        if (empty($params)) {
            return $url;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }
        $query = array();
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        foreach ($params as $key => $value) {
            $query[$key] = $value;
        }
        $authority = self::urlAuthority($parts);
        return $parts['scheme'] . '://' . $authority . ($parts['path'] ?? '') . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    public static function resolveUrl($baseUrl, $reference)
    {
        $reference = trim((string)$reference);
        if ($reference === '' || preg_match('/[\x00-\x1F\x7F]/', $reference)) {
            return null;
        }
        if (preg_match('#^https?://#i', $reference)) {
            return $reference;
        }
        $base = parse_url((string)$baseUrl);
        if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
            return null;
        }
        $authority = self::urlAuthority($base);
        $prefix = strtolower($base['scheme']) . '://' . $authority;
        if (strpos($reference, '//') === 0) {
            return strtolower($base['scheme']) . ':' . $reference;
        }
        if ($reference[0] === '?') {
            return $prefix . ($base['path'] ?? '/') . $reference;
        }
        $refParts = parse_url($reference);
        $refPath = is_array($refParts) && isset($refParts['path']) ? $refParts['path'] : $reference;
        $refQuery = is_array($refParts) && isset($refParts['query']) ? '?' . $refParts['query'] : '';
        if ($refPath === '' || $refPath[0] !== '/') {
            $basePath = $base['path'] ?? '/';
            $slash = strrpos($basePath, '/');
            $dir = $slash === false ? '/' : substr($basePath, 0, $slash + 1);
            $refPath = $dir . $refPath;
        }
        $segments = array();
        foreach (explode('/', $refPath) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return $prefix . '/' . implode('/', $segments) . $refQuery;
    }

    private static function urlAuthority($parts)
    {
        $host = (string)($parts['host'] ?? '');
        if (strpos($host, ':') !== false && $host[0] !== '[') {
            $host = '[' . $host . ']';
        }
        return $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : '');
    }

    public static function validateUrl($url)
    {
        if (!is_string($url) || $url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            throw new RuntimeException('Blocked: invalid URL');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true)) {
            throw new RuntimeException('Only HTTP/HTTPS URLs are supported');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new RuntimeException('Blocked: URL userinfo/fragment is not allowed');
        }
        $host = trim((string)$parts['host'], '[]');
        $scheme = strtolower($parts['scheme']);
        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($host === '' || !in_array($port, array(80, 443), true) || strtolower($host) === 'localhost' || str_ends_with(strtolower($host), '.localhost')) {
            throw new RuntimeException('Blocked: private or unsupported destination');
        }

        $addresses = array();
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses[] = $host;
        } else {
            $records = function_exists('dns_get_record') ? @dns_get_record($host, DNS_A | DNS_AAAA) : array();
            if (is_array($records)) {
                foreach ($records as $record) {
                    if (isset($record['ip'])) {
                        $addresses[] = $record['ip'];
                    } elseif (isset($record['ipv6'])) {
                        $addresses[] = $record['ipv6'];
                    }
                }
            }
            if (empty($addresses) && function_exists('gethostbynamel')) {
                $addresses = (array)@gethostbynamel($host);
            }
        }
        $addresses = array_values(array_unique(array_filter($addresses, function ($address) {
            return filter_var($address, FILTER_VALIDATE_IP) !== false;
        })));
        if (empty($addresses)) {
            throw new RuntimeException('Unable to resolve destination host');
        }
        foreach ($addresses as $address) {
            if (self::isPrivateIp($address)) {
                throw new RuntimeException('Blocked: private destination address');
            }
        }
        return array($host, $port, $addresses[0]);
    }

    private static function isPrivateIp($address)
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = sprintf('%u', ip2long($address));
            foreach (array(array(0, 16777215), array(167772160, 184549375), array(1681915904, 1686110207), array(2130706432, 2147483647), array(2851995648, 2852061183), array(2886729728, 2887778303), array(3221225472, 3221226239), array(3232235520, 3232301055), array(3325256704, 3325256959), array(3323068416, 3323199487), array(3405803776, 3405804031), array(3758096384, 4294967295)) as $range) {
                if ($long >= $range[0] && $long <= $range[1]) {
                    return true;
                }
            }
            return false;
        }
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return true;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        $packed = @inet_pton($address);
        if ($packed === false) {
            return true;
        }
        $first = ord($packed[0]);
        return $packed === str_repeat("\0", 16)
            || $packed === (str_repeat("\0", 15) . "\1")
            || ($first & 0xfe) === 0xfc
            || ($first & 0xfe) === 0xfe
            || ($first & 0xff) === 0xff;
    }

    private static function resolveEntry($host, $port, $ip)
    {
        $host = strpos($host, ':') !== false && $host[0] !== '[' ? '[' . $host . ']' : $host;
        $ip = strpos($ip, ':') !== false && $ip[0] !== '[' ? '[' . $ip . ']' : $ip;
        return $host . ':' . (int)$port . ':' . $ip;
    }

    private function executeCurl($url, $method, $data, $headers = [])
    {
        $result = array('http_code' => 0, 'body' => '', 'data' => null, 'curl_error' => '');
        try {
            list($host, $port, $resolvedIp) = self::validateUrl($url);
        } catch (Throwable $e) {
            $result['curl_error'] = $e->getMessage();
            return $result;
        }

        $ch = curl_init();
        if ($ch === false) {
            $result['curl_error'] = 'Unable to initialize cURL';
            return $result;
        }
        $safeHeaders = array();
        foreach ((array)$headers as $header) {
            $header = (string)$header;
            if ($header !== '' && !preg_match('/[\x00-\x1F\x7F]/', $header)) {
                $safeHeaders[] = $header;
            }
        }
        curl_setopt_array($ch, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOPROXY => '*',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => array(self::resolveEntry($host, $port, $resolvedIp)),
            CURLOPT_HTTPHEADER => $safeHeaders
        ));

        if ($method === 'POST') {
            $encoded = is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : (string)$data;
            if ($encoded === false) {
                $result['curl_error'] = 'Unable to encode JSON request';
                curl_close($ch);
                return $result;
            }
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded === '' ? '{}' : $encoded);
        } elseif ($method === 'POST_MULTIPART') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, (array)$data);
        } else {
            $result['curl_error'] = 'Unsupported HTTP method';
            curl_close($ch);
            return $result;
        }

        $body = curl_exec($ch);
        $result['http_code'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $result['curl_error'] = (string)curl_error($ch);
        if ($body !== false) {
            $result['body'] = (string)$body;
            if (trim($result['body']) !== '') {
                $decoded = json_decode($result['body'], true);
                $result['data'] = is_array($decoded) ? $decoded : null;
            }
        } else {
            $result['body'] = '';
        }
        curl_close($ch);
        return $result;
    }
}

?>
