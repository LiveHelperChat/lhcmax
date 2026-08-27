<?php

$tpl = erLhcoreClassTemplate::getInstance('lhmax/settings.tpl.php');
$ext = erLhcoreClassExtensionLhcmax::getInstance();

$testResult = null;
$settingsError = null;

if (isset($_POST['SaveSettings']) || isset($_POST['StoreOptionsMax']) || isset($_POST['StoreOptionsMaxRemove']) || isset($_POST['TestConnection'])) {
    if (!isset($_POST['csfr_token']) || !$currentUser->validateCSFRToken($_POST['csfr_token'])) {
        erLhcoreClassModule::redirect('max/settings');
        exit;
    }

    if (isset($_POST['TestConnection'])) {
        $settings = $ext->getSettings();
        $submittedToken = trim((string)($_POST['token'] ?? ''));
        $token = $submittedToken !== '' ? $submittedToken : trim((string)($settings['token'] ?? ''));
        $host = rtrim(trim((string)($_POST['host'] ?? ($settings['host'] ?? ''))), '/');
        $testResult = $ext->testConnection($host, $token);
    } else {

        $host = rtrim(trim((string)($_POST['host'] ?? $ext->getSetting('host', 'https://platform-api2.max.ru'))), '/');
        try {
            $parts = parse_url($host);
            if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (!empty($parts['path']) && trim($parts['path'], '/') !== '')) {
                throw new RuntimeException('API host must be an origin without path, query, userinfo, or fragment');
            }
            erLhcoreClassExtensionLhcmax::validateUrl($host);
        } catch (Throwable $e) {
            $settingsError = 'Settings were not saved: ' . $e->getMessage();
        }

        if ($settingsError === null) {
            $submittedToken = trim((string)($_POST['token'] ?? ''));
            $currentToken = trim((string)$ext->getSetting('token', ''));
            $ext->saveSettings([
                'enabled' => isset($_POST['enabled']),
                'host' => $host,
                'token' => $submittedToken !== '' ? $submittedToken : $currentToken,
                'department_id' => max(0, (int)($_POST['department_id'] ?? 0)),
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
    }
}

$settings = $ext->getSettings();

$incomingWebhook = erLhcoreClassModelChatIncomingWebhook::findOne(['filter' => ['name' => 'MaxWebhook']]);

$restAPI = erLhcoreClassModelGenericBotRestAPI::findOne(['filter' => ['name' => 'Max']]);

$tpl->set('settings', $settings);
$tpl->set('testResult', $testResult);
$tpl->set('settingsError', $settingsError);
$tpl->set('incomingWebhook', $incomingWebhook);
$tpl->set('restAPI', $restAPI);

$Result['content'] = $tpl->fetch();
$Result['path'] = array(
    array('url' => erLhcoreClassDesign::baseurl('system/configuration'), 'title' => erTranslationClassLhTranslation::getInstance()->getTranslation('system/configuration', 'System configuration')),
    array('title' => 'Max Messenger Settings')
);

?>
