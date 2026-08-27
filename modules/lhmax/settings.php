<?php

$tpl = erLhcoreClassTemplate::getInstance('lhmax/settings.tpl.php');

$settingsFile = dirname(dirname(__DIR__)) . '/settings/settings.ini.php';
$settings = file_exists($settingsFile) ? include $settingsFile : [
    'enabled' => true,
    'host' => 'https://platform-api2.max.ru',
    'token' => '',
    'debug' => true
];

$testResult = null;

if (isset($_POST['SaveSettings'])) {
    if (!isset($_POST['csfr_token']) || !$currentUser->validateCSFRToken($_POST['csfr_token'])) {
        erLhcoreClassModule::redirect('max/settings');
        exit;
    }

    $settings['enabled'] = isset($_POST['enabled']);
    $settings['host'] = trim($_POST['host']);
    $settings['token'] = trim($_POST['token']);
    $settings['debug'] = isset($_POST['debug']);

    $content = "<?php\n\nreturn " . var_export($settings, true) . ";\n\n?>";
    file_put_contents($settingsFile, $content);

    $tpl->set('updated', true);
}

if (isset($_POST['TestConnection'])) {
    $token = trim($_POST['token'] ?? $settings['token']);
    $host = rtrim(trim($_POST['host'] ?? $settings['host']), '/');

    $ch = curl_init($host . '/uploads?type=image');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "{}");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: ' . $token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $testResult = [
        'http_code' => $code,
        'response' => $res,
        'success' => ($code >= 200 && $code < 300)
    ];
}

$tpl->set('settings', $settings);
$tpl->set('testResult', $testResult);

$Result['content'] = $tpl->fetch();
$Result['path'] = array(
    array('url' => erLhcoreClassDesign::baseurl('system/configuration'), 'title' => erTranslationClassLhTranslation::getInstance()->getTranslation('system/configuration', 'System configuration')),
    array('title' => 'Max Messenger Settings')
);

?>