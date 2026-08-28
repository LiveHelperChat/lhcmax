<?php

require_once __DIR__ . '/../bootstrap/bootstrap.php';
require_once __DIR__ . '/../providers/MaxLiveHelperChatActivator.php';

function maxContractExpect($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$class = 'erLhcoreClassExtensionLhcmax';
$reflection = new ReflectionClass($class);
maxContractExpect(!$reflection->hasMethod('sendMaxTextMessage'), 'native text method is not registered');
maxContractExpect($reflection->hasMethod('sendMaxMediaFile'), 'native media method exists');

$url = $class::appendQuery('https://platform-api2.max.ru', '/messages', array('chat_id' => 'a b'));
maxContractExpect($url === 'https://platform-api2.max.ru/messages?chat_id=a%20b', 'query values are RFC3986 encoded');

maxContractExpect(
    $class::resolveUrl('https://platform-api2.max.ru/uploads?type=image', '/upload/path?token=x') === 'https://platform-api2.max.ru/upload/path?token=x',
    'root-relative upload URL is resolved'
);
maxContractExpect(
    $class::resolveUrl('https://platform-api2.max.ru/api/uploads?type=image', 'binary') === 'https://platform-api2.max.ru/api/binary',
    'path-relative upload URL is resolved'
);

foreach (array(
    'file:///etc/passwd',
    'http://127.0.0.1/',
    'http://169.254.169.254/',
    'http://192.0.2.1/',
    'http://192.168.1.1/',
    'http://[::1]/',
    'http://user@example.com/',
    'https://example.com/#fragment'
) as $blockedUrl) {
    $blocked = false;
    try {
        $class::validateUrl($blockedUrl);
    } catch (Throwable $e) {
        $blocked = true;
    }
    maxContractExpect($blocked, 'blocked URL: ' . $blockedUrl);
}

$instance = new $class();
$invalidTest = $instance->testConnection('https://platform-api2.max.ru/path?x=1', 'token');
maxContractExpect($invalidTest['success'] === false && strpos($invalidTest['response'], 'origin') !== false, 'connection test rejects a non-origin host');

$clean = $instance->cleanMessageText('[quote=12]old[/quote] [file=10_abcdef0123456789] <script>x</script> [b]bold[/b]');
maxContractExpect(strpos($clean, '[quote=') === false && strpos($clean, '[file=') === false, 'quote and file embeds are removed');
maxContractExpect(stripos($clean, '<script') === false, 'unsafe HTML is removed');
maxContractExpect(strpos($clean, '[b]bold[/b]') !== false, 'caption text is not rewritten into unsupported HTML');

$midMethod = $reflection->getMethod('extractMessageMid');
$midMethod->setAccessible(true);
foreach (array(
    array('message' => array('body' => array('mid' => 'm1'))),
    array('body' => array('mid' => 'm2')),
    array('mid' => 'm3')
) as $index => $data) {
    $mid = $midMethod->invoke(null, array('http_code' => 200, 'body' => '{}', 'data' => $data));
    maxContractExpect($mid === 'm' . ($index + 1), 'nested final message ID path ' . $index);
}

$responseMethod = $reflection->getMethod('responseValue');
$responseMethod->setAccessible(true);
$token = $responseMethod->invoke(null, array('photos' => array('abc' => array('token' => 'photo-token'))), array('photos.*.token'));
maxContractExpect($token === 'photo-token', 'associative photo token is extracted');

$notReadyMethod = $reflection->getMethod('isAttachmentNotReadyResponse');
$notReadyMethod->setAccessible(true);
maxContractExpect(
    $notReadyMethod->invoke(null, array('http_code' => 400, 'data' => array('code' => 'attachment.not.ready'), 'body' => '{}')) === true,
    'attachment.not.ready response is detected'
);
maxContractExpect(
    $notReadyMethod->invoke(null, array('http_code' => 400, 'data' => array('code' => 'validation.error'), 'body' => '{}')) === false,
    'unrelated Max errors are not retried'
);

$template = json_decode(file_get_contents(__DIR__ . '/../doc/max/rest-api.json'), true);
maxContractExpect(is_array($template) && !empty($template['configuration']), 'REST template is valid outer JSON');
$templateConfiguration = json_decode($template['configuration'], true);
maxContractExpect(is_array($templateConfiguration), 'REST template configuration is valid JSON');
$templateNames = array_map(function ($method) { return $method['name'] ?? ''; }, $templateConfiguration['parameters'] ?? array());
maxContractExpect(in_array('SendMessage', $templateNames, true), 'REST template keeps SendMessage text action');
maxContractExpect(in_array('Send typing', $templateNames, true), 'REST template keeps typing action');
$sendMethod = null;
foreach ($templateConfiguration['parameters'] as $method) {
    if (($method['id'] ?? '') === 'temp1767254647970') {
        $sendMethod = $method;
        break;
    }
}
maxContractExpect(is_array($sendMethod), 'REST template contains the configured SendMessage method');
maxContractExpect(($sendMethod['conditions'][0]['key'] ?? '') === '{{media}}', 'text sender is disabled for media payloads');
maxContractExpect(($sendMethod['conditions'][0]['success_condition'] ?? '') === 'eq' && ($sendMethod['conditions'][0]['value'] ?? '') === '[]', 'media guard only allows empty media');
foreach (array('body_raw_file', 'suburl_file', 'suburl_file_convert', 'suburl_file_skip_ext', 'multi_step_upload') as $mediaField) {
    maxContractExpect(!array_key_exists($mediaField, $sendMethod), 'REST template does not carry retired media field ' . $mediaField);
}
foreach ($templateConfiguration['parameters'] as $method) {
    foreach (($method['header'] ?? array()) as $header) {
        if (is_array($header) && ($header['key'] ?? '') === 'Authorization') {
            maxContractExpect(trim((string)($header['value'] ?? '')) === '', 'REST template must not contain credentials');
        }
    }
}

$activatorReflection = new ReflectionClass('LiveHelperChatExtension\\lhcmax\\providers\\MaxLiveHelperChatActivator');
$appendMethod = $activatorReflection->getMethod('appendMissingParameters');
$appendMethod->setAccessible(true);
$normalizeMethod = $activatorReflection->getMethod('normalizeTextMethod');
$normalizeMethod->setAccessible(true);
$merged = array(
    'host' => 'https://old.example',
    'custom_flag' => true,
    'parameters' => array(
        array('id' => 'temp1767254647970', 'name' => 'Configured SendMessage', 'body_raw_file' => 'legacy', 'suburl_file' => 'legacy', 'multi_step_upload' => array('enabled' => true)),
        array('id' => 'custom_action', 'name' => 'Keep custom action'),
        array('id' => 'smartdiag_max_send_typing', 'name' => 'Old typing', 'header' => array(array('key' => 'Authorization', 'value' => ''))),
    ),
);
$normalizeMethod->invokeArgs(null, array(&$merged));
$normalizedSend = $merged['parameters'][0];
maxContractExpect(!isset($normalizedSend['body_raw_file']) && !isset($normalizedSend['multi_step_upload']), 'existing SendMessage media fields are removed');
maxContractExpect(($normalizedSend['conditions'][0]['key'] ?? '') === '{{media}}', 'existing SendMessage receives media guard');
$templateMethods = array(
    'parameters' => array(
        array('id' => 'temp1767254647970', 'name' => 'Template SendMessage'),
        array('id' => 'smartdiag_max_send_typing', 'name' => 'Send typing', 'header' => array(array('key' => 'Authorization', 'value' => ''))),
        array('id' => 'new_extension_action', 'name' => 'New action'),
    ),
);
$tokenForAppend = 'test-token';
$appendMethod->invokeArgs(null, array(&$merged, $templateMethods, $tokenForAppend));
$mergedIds = array_map(function ($method) { return $method['id'] ?? ''; }, $merged['parameters']);
maxContractExpect(in_array('temp1767254647970', $mergedIds, true), 'configured REST text sender is preserved');
maxContractExpect(in_array('custom_action', $mergedIds, true), 'custom REST method is preserved');
maxContractExpect(in_array('smartdiag_max_send_typing', $mergedIds, true), 'typing method is present');
maxContractExpect(in_array('new_extension_action', $mergedIds, true), 'new template method is appended');
maxContractExpect(($merged['custom_flag'] ?? false) === true, 'custom top-level REST setting is preserved');
$typing = null;
foreach ($merged['parameters'] as $method) {
    if (($method['id'] ?? '') === 'smartdiag_max_send_typing') {
        $typing = $method;
    }
}
maxContractExpect(($typing['header'][0]['value'] ?? '') === 'test-token', 'REST authorization token is synchronized');

echo 'OK' . PHP_EOL;
