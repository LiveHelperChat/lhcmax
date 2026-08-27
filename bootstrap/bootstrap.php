<?php

#[\AllowDynamicProperties]
class erLhcoreClassExtensionLhcmax
{
    private static $instance = null;
    private $settings = [];

    public function __construct()
    {
        $settingsFile = dirname(__DIR__) . '/settings/settings.ini.php';
        if (file_exists($settingsFile)) {
            $this->settings = include $settingsFile;
        } else {
            $this->settings = [
                'enabled' => true,
                'host' => 'https://platform-api2.max.ru',
                'token' => '',
                'debug' => false
            ];
        }
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

        // Listen for messages added by operators or bots
        $dispatcher->listen('chat.web_add_msg_admin', array($this, 'onWebAddMsgAdmin'));
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

        // Check department ID 8 (🟣 Max)
        if ($chat->dep_id == 8) {
            return true;
        }

        return false;
    }

    public function onWebAddMsgAdmin($params)
    {
        $msg = $params['msg'];
        $chat = $params['chat'];

        if (!$this->isMaxChat($chat)) {
            return;
        }

        // Skip if message was already delivered
        if (!empty($msg->meta_msg_array['iwh_msg_id']) && empty($msg->meta_msg_array['lhcmax_pending'])) {
            return;
        }

        // Extract files if any
        $files = $this->extractMessageFiles($msg);
        $cleanText = $this->stripFileEmbeds($msg->msg);

        // Find reply mid if operator quoted a message
        $replyMid = $this->extractReplyMid($msg, $chat);

        if (!empty($files)) {
            foreach ($files as $fileItem) {
                $caption = $fileItem['caption'] !== '' ? $fileItem['caption'] : $cleanText;
                $this->sendMaxMediaFile($chat, $fileItem['file'], $caption, $replyMid, $msg);
            }
        } elseif (trim($cleanText) !== '') {
            $this->sendMaxTextMessage($chat, $cleanText, $replyMid, $msg);
        }
    }

    public function extractReplyMid($msg, $chat)
    {
        $meta = is_array($msg->meta_msg_array) ? $msg->meta_msg_array : [];

        // 1. Direct iwh_msg_id in meta
        if (!empty($meta['content']['reply_to']['iwh_msg_id'])) {
            return (string)$meta['content']['reply_to']['iwh_msg_id'];
        }

        // 2. Referenced db_msg_id
        if (!empty($meta['content']['reply_to']['db_msg_id'])) {
            $target = erLhcoreClassModelmsg::fetch((int)$meta['content']['reply_to']['db_msg_id']);
            if ($target instanceof erLhcoreClassModelmsg && !empty($target->meta_msg_array['iwh_msg_id'])) {
                return (string)$target->meta_msg_array['iwh_msg_id'];
            }
        }

        // 3. BBCode [quote=123]
        if (preg_match('#\[quote="?([0-9]+)"?\]#is', (string)$msg->msg, $m)) {
            $target = erLhcoreClassModelmsg::fetch((int)$m[1]);
            if ($target instanceof erLhcoreClassModelmsg && !empty($target->meta_msg_array['iwh_msg_id'])) {
                return (string)$target->meta_msg_array['iwh_msg_id'];
            }
        }

        return null;
    }

    public function sendMaxTextMessage($chat, $text, $replyMid = null, $msg = null)
    {
        $chatExternalId = $chat->incoming_chat instanceof erLhcoreClassModelChatIncoming ? $chat->incoming_chat->chat_external_id : (string)$chat->chat_external_id;
        if (empty($chatExternalId)) {
            return false;
        }

        $payload = [
            'text' => $text,
            'format' => 'html'
        ];

        if (!empty($replyMid)) {
            $payload['link'] = [
                'type' => 'reply',
                'mid' => (string)$replyMid
            ];
        }

        $host = rtrim($this->getSetting('host', 'https://platform-api2.max.ru'), '/');
        $url = $host . '/messages?chat_id=' . urlencode($chatExternalId);

        $response = $this->executeCurl($url, 'POST', $payload, [
            'Content-Type: application/json',
            'Authorization: ' . $this->getSetting('token')
        ]);

        if (!empty($response['data']['body']['mid']) && $msg instanceof erLhcoreClassModelmsg) {
            $this->saveMessageMid($msg, $response['data']['body']['mid']);
        }

        return $response['http_code'] >= 200 && $response['http_code'] < 300;
    }

    public function sendMaxMediaFile($chat, $file, $caption = '', $replyMid = null, $msg = null)
    {
        $chatExternalId = $chat->incoming_chat instanceof erLhcoreClassModelChatIncoming ? $chat->incoming_chat->chat_external_id : (string)$chat->chat_external_id;
        if (empty($chatExternalId) || !($file instanceof erLhcoreClassModelChatFile)) {
            return false;
        }

        $filePath = $file->file_path_server;
        if (!file_exists($filePath)) {
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

        $host = rtrim($this->getSetting('host', 'https://platform-api2.max.ru'), '/');

        // Step 1: Init / Request Upload URL
        $initUrl = $host . '/uploads?type=' . $fileType;
        $initResp = $this->executeCurl($initUrl, 'POST', [], [
            'Authorization: ' . $this->getSetting('token')
        ]);

        if ($initResp['http_code'] < 200 || $initResp['http_code'] >= 300 || empty($initResp['data'])) {
            return false;
        }

        $uploadUrl = !empty($initResp['data']['url']) ? $initResp['data']['url'] : (!empty($initResp['data']['upload_url']) ? $initResp['data']['upload_url'] : '');
        $uploadToken = !empty($initResp['data']['token']) ? $initResp['data']['token'] : (!empty($initResp['data']['upload_token']) ? $initResp['data']['upload_token'] : (!empty($initResp['data']['id']) ? $initResp['data']['id'] : ''));

        if (empty($uploadUrl)) {
            return false;
        }

        if (strpos($uploadUrl, 'http://') !== 0 && strpos($uploadUrl, 'https://') !== 0) {
            $uploadUrl = $host . (strpos($uploadUrl, '/') === 0 ? '' : '/') . $uploadUrl;
        }

        // Step 2: Binary / Multipart Upload
        $cFile = new CurlFile($filePath, $file->type, $file->upload_name);
        $postFields = ['data' => $cFile];

        $uploadResp = $this->executeCurl($uploadUrl, 'POST_MULTIPART', $postFields, []);
        if ($uploadResp['http_code'] < 200 || $uploadResp['http_code'] >= 300) {
            return false;
        }

        // Parse token from nested structures if present
        if (is_array($uploadResp['data'])) {
            if (!empty($uploadResp['data']['token'])) {
                $uploadToken = $uploadResp['data']['token'];
            } elseif (!empty($uploadResp['data']['id'])) {
                $uploadToken = $uploadResp['data']['id'];
            } elseif (!empty($uploadResp['data']['photos']) && is_array($uploadResp['data']['photos'])) {
                $firstPhoto = reset($uploadResp['data']['photos']);
                if (is_array($firstPhoto) && !empty($firstPhoto['token'])) {
                    $uploadToken = $firstPhoto['token'];
                } elseif (is_string($firstPhoto)) {
                    $uploadToken = $firstPhoto;
                }
            }
        }

        if (empty($uploadToken)) {
            return false;
        }

        // Step 3: Message dispatch with attachment token
        $msgUrl = $host . '/messages?chat_id=' . urlencode($chatExternalId);
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

        $finalResp = $this->executeCurl($msgUrl, 'POST', $msgPayload, [
            'Content-Type: application/json',
            'Authorization: ' . $this->getSetting('token')
        ]);

        if (!empty($finalResp['data']['body']['mid']) && $msg instanceof erLhcoreClassModelmsg) {
            $this->saveMessageMid($msg, $finalResp['data']['body']['mid']);
        }

        return $finalResp['http_code'] >= 200 && $finalResp['http_code'] < 300;
    }

    private function saveMessageMid($msg, $mid)
    {
        $meta = is_array($msg->meta_msg_array) ? $msg->meta_msg_array : [];
        $meta['iwh_msg_id'] = (string)$mid;
        $msg->meta_msg_array = $meta;
        $msg->meta_msg = json_encode($meta);

        $stmt = ezcDbInstance::get()->prepare("UPDATE lh_msg SET meta_msg = :meta_msg WHERE id = :id");
        $stmt->bindValue(':meta_msg', $msg->meta_msg);
        $stmt->bindValue(':id', (int)$msg->id, PDO::PARAM_INT);
        $stmt->execute();
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

    private function stripFileEmbeds($text)
    {
        return trim(preg_replace('#\[file=([0-9]+)_([a-z0-9]+)\]#i', '', (string)$text));
    }

    private function executeCurl($url, $method, $data, $headers = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? json_encode($data) : $data);
        } elseif ($method === 'POST_MULTIPART') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'http_code' => $code,
            'body' => $body,
            'data' => json_decode($body, true)
        ];
    }
}

?>