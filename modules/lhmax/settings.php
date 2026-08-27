<?php

$tpl = erLhcoreClassTemplate::getInstance('lhmax/settings.tpl.php');
$ext = erLhcoreClassExtensionLhcmax::getInstance();

$testResult = null;

if (isset($_POST['SaveSettings']) || isset($_POST['StoreOptionsMax']) || isset($_POST['StoreOptionsMaxRemove'])) {
    if (!isset($_POST['csfr_token']) || !$currentUser->validateCSFRToken($_POST['csfr_token'])) {
        erLhcoreClassModule::redirect('max/settings');
        exit;
    }

    $ext->saveSettings([
        'enabled' => isset($_POST['enabled']),
        'host' => trim($_POST['host']),
        'token' => trim($_POST['token']),
        'debug' => isset($_POST['debug'])
    ]);

    if (isset($_POST['StoreOptionsMax'])) {
        \LiveHelperChatExtension\lhcmax\providers\MaxLiveHelperChatActivator::installOrUpdate();
    }

    if (isset($_POST['StoreOptionsMaxRemove'])) {
        \LiveHelperChatExtension\lhcmax\providers\MaxLiveHelperChatActivator::remove();
    }

    $tpl->set('updated', 'done');
}

$settings = $ext->getSettings();

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

$incomingWebhook = erLhcoreClassModelChatIncomingWebhook::findOne(['filter' => ['name' => 'MaxWebhook']]);
if (!$incomingWebhook) {
    $incomingWebhook = erLhcoreClassModelChatIncomingWebhook::findOne(['filter' => ['scope' => 'max']]);
}

$restAPI = erLhcoreClassModelGenericBotRestAPI::findOne(['filter' => ['name' => 'Max']]);

$tpl->set('settings', $settings);
$tpl->set('testResult', $testResult);
$tpl->set('incomingWebhook', $incomingWebhook);
$tpl->set('restAPI', $restAPI);

$Result['content'] = $tpl->fetch();
$Result['path'] = array(
    array('url' => erLhcoreClassDesign::baseurl('system/configuration'), 'title' => erTranslationClassLhTranslation::getInstance()->getTranslation('system/configuration', 'System configuration')),
    array('title' => 'Max Messenger Settings')
);

?>