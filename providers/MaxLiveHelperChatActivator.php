<?php

namespace LiveHelperChatExtension\lhcmax\providers;

#[\AllowDynamicProperties]
class MaxLiveHelperChatActivator
{
    public static function remove()
    {
        $incomingWebhook = \erLhcoreClassModelChatIncomingWebhook::findOne(array('filter' => array('name' => 'MaxWebhook')));
        if (!$incomingWebhook) {
            $incomingWebhook = \erLhcoreClassModelChatIncomingWebhook::findOne(array('filter' => array('scope' => 'max')));
        }
        if ($incomingWebhook) {
            $incomingWebhook->removeThis();
        }

        if ($restAPI = \erLhcoreClassModelGenericBotRestAPI::findOne(array('filter' => array('name' => 'Max')))) {
            $restAPI->removeThis();
        }
    }

    public static function installOrUpdate()
    {
        $ext = \erLhcoreClassExtensionLhcmax::getInstance();
        $token = trim((string)$ext->getSetting('token', ''));
        $host = rtrim((string)$ext->getSetting('host', 'https://platform-api2.max.ru'), '/');

        self::installIncomingWebhook($ext);

        $template = self::loadRestTemplate();
        if (!is_array($template)) {
            return;
        }
        $templateConfig = self::decodeConfiguration($template['configuration'] ?? '');
        if (!is_array($templateConfig)) {
            return;
        }

        $restAPI = \erLhcoreClassModelGenericBotRestAPI::findOne(array('filter' => array('name' => 'Max')));
        if (!$restAPI) {
            $config = $templateConfig;
            $config['host'] = $host;
            self::applyTokenToParameters($config['parameters'], $token);
            $template['configuration'] = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($template['configuration'] === false) {
                return;
            }

            $restAPI = new \erLhcoreClassModelGenericBotRestAPI();
            $restAPI->setState($template);
            $restAPI->name = 'Max';
        } else {
            // Existing REST actions may contain local conditions, buttons, or
            // credentials. Keep them intact and only add missing template
            // actions; never remove the text sender that production uses.
            $config = self::decodeConfiguration((string)$restAPI->configuration);
            if (!is_array($config)) {
                return;
            }
            $config['host'] = $host;
            self::applyTokenToParameters($config['parameters'], $token);
            self::normalizeTextMethod($config);
            self::appendMissingParameters($config, $templateConfig, $token);
            $encoded = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($encoded === false) {
                return;
            }
            $restAPI->configuration = $encoded;
        }

        $restAPI->saveThis();
    }

    private static function installIncomingWebhook($ext)
    {
        $incomingWebhookFile = dirname(__DIR__) . '/doc/max/incoming-webhook.json';
        if (!file_exists($incomingWebhookFile)) {
            return;
        }

        $content = json_decode(file_get_contents($incomingWebhookFile), true);
        if (!is_array($content)) {
            return;
        }

        $incomingWebhook = \erLhcoreClassModelChatIncomingWebhook::findOne(array('filter' => array('name' => 'MaxWebhook')));
        if (!$incomingWebhook) {
            // Older installations may have renamed the webhook. Reuse the
            // scoped Max record instead of creating a duplicate callback.
            $incomingWebhook = \erLhcoreClassModelChatIncomingWebhook::findOne(array('filter' => array('scope' => 'max')));
        }
        if (!$incomingWebhook) {
            $incomingWebhook = new \erLhcoreClassModelChatIncomingWebhook();
            $incomingWebhook->setState($content);
            $incomingWebhook->dep_id = max(1, (int)$ext->getSetting('department_id', 0));
            $incomingWebhook->name = 'MaxWebhook';
            $incomingWebhook->identifier = \erLhcoreClassModelForgotPassword::randomPassword(20);
        } else {
            $depId = (int)$incomingWebhook->dep_id;
            $identifier = (string)$incomingWebhook->identifier;
            $disabled = (int)$incomingWebhook->disabled;
            // Preserve routing, callback secret, and enabled state. The parser
            // definition is the only extension-owned part; attribute values
            // (hide_buttons_on_click, disable_link_preview) belong to the admin.
            $incomingWebhook->setState(array(
                'icon' => $content['icon'] ?? '',
                'icon_color' => $content['icon_color'] ?? '',
                'scope' => $content['scope'] ?? 'max',
                'configuration' => self::keepAttributeValues((string)$incomingWebhook->configuration, (string)($content['configuration'] ?? '')),
                'log_incoming' => $content['log_incoming'] ?? 0,
                'log_failed_parse' => $content['log_failed_parse'] ?? 1
            ));
            $incomingWebhook->dep_id = $depId;
            $incomingWebhook->identifier = $identifier;
            $incomingWebhook->disabled = $disabled;
            $incomingWebhook->name = 'MaxWebhook';
        }
        $incomingWebhook->saveThis();
    }

    private static function keepAttributeValues($currentConfiguration, $templateConfiguration)
    {
        $current = self::decodeConfiguration($currentConfiguration);
        $template = self::decodeConfiguration($templateConfiguration);
        if (!is_array($current) || !is_array($template) || !is_array($current['attr'] ?? null) || !is_array($template['attr'] ?? null)) {
            return $templateConfiguration;
        }

        $currentValues = array();
        foreach ($current['attr'] as $attr) {
            if (is_array($attr) && isset($attr['key'])) {
                $currentValues[(string)$attr['key']] = $attr['value'] ?? '';
            }
        }

        $seen = array();
        foreach ($template['attr'] as &$attr) {
            if (is_array($attr) && isset($attr['key'])) {
                $seen[(string)$attr['key']] = true;
                if (array_key_exists((string)$attr['key'], $currentValues)) {
                    $attr['value'] = $currentValues[(string)$attr['key']];
                }
            }
        }
        unset($attr);

        // Attributes added by the administrator stay as they are.
        foreach ($current['attr'] as $attr) {
            if (is_array($attr) && isset($attr['key']) && !isset($seen[(string)$attr['key']])) {
                $template['attr'][] = $attr;
            }
        }

        $encoded = json_encode($template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return $encoded === false ? $templateConfiguration : $encoded;
    }

    private static function loadRestTemplate()
    {
        $restApiFile = dirname(__DIR__) . '/doc/max/rest-api.json';
        if (!file_exists($restApiFile)) {
            return null;
        }

        $content = json_decode(file_get_contents($restApiFile), true);
        return is_array($content) && !empty($content['configuration']) ? $content : null;
    }

    private static function decodeConfiguration($configuration)
    {
        $decoded = json_decode((string)$configuration, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function applyTokenToParameters(&$parameters, $token)
    {
        if (!is_array($parameters) || $token === '') {
            return;
        }
        foreach ($parameters as &$method) {
            self::applyTokenToMethod($method, $token);
        }
        unset($method);
    }

    private static function applyTokenToMethod(&$method, $token)
    {
        if (!is_array($method) || $token === '' || !is_array($method['header'] ?? null)) {
            return;
        }
        foreach ($method['header'] as &$header) {
            if (is_array($header) && ($header['key'] ?? '') === 'Authorization') {
                $header['value'] = $token;
            }
        }
        unset($header);
    }

    private static function normalizeTextMethod(&$config)
    {
        if (!is_array($config) || !is_array($config['parameters'] ?? null)) {
            return;
        }

        foreach ($config['parameters'] as &$method) {
            if (!is_array($method) || (($method['id'] ?? '') !== 'temp1767254647970' && ($method['name'] ?? '') !== 'SendMessage')) {
                continue;
            }

            // Text delivery stays in the core REST action. Remove only the
            // legacy media fields that refer to the retired core upload path.
            foreach (array('suburl_file', 'suburl_file_convert', 'suburl_file_skip_ext', 'body_raw_file', 'multi_step_upload') as $field) {
                unset($method[$field]);
            }

            if (!is_array($method['conditions'] ?? null)) {
                $method['conditions'] = array();
            }

            $mediaGuardPresent = false;
            foreach ($method['conditions'] as $condition) {
                if (is_array($condition)
                    && ($condition['key'] ?? '') === '{{media}}'
                    && ($condition['success_condition'] ?? '') === 'eq'
                    && (string)($condition['value'] ?? '') === '[]') {
                    $mediaGuardPresent = true;
                    break;
                }
            }
            if (!$mediaGuardPresent) {
                $method['conditions'][] = array(
                    'key' => '{{media}}',
                    'success_condition' => 'eq',
                    'value' => '[]'
                );
            }

            // Link previews are controlled by the incoming webhook attribute.
            if (isset($method['suburl']) && strpos((string)$method['suburl'], 'disable_link_preview') === false) {
                $method['suburl'] .= (strpos((string)$method['suburl'], '?') === false ? '?' : '&') . 'disable_link_preview={{args.chat.incoming_chat.incoming.attributes.disable_link_preview}}';
            }
        }
        unset($method);
    }

    private static function appendMissingParameters(&$config, $template, $token)
    {
        if (!is_array($config) || !is_array($template) || !is_array($template['parameters'] ?? null)) {
            return;
        }
        if (!is_array($config['parameters'] ?? null)) {
            $config['parameters'] = array();
        }
        self::applyTokenToParameters($config['parameters'], $token);

        $seen = array();
        foreach ($config['parameters'] as $method) {
            if (is_array($method) && !empty($method['id'])) {
                $seen[(string)$method['id']] = true;
            }
        }
        foreach ($template['parameters'] as $method) {
            if (!is_array($method) || empty($method['id']) || isset($seen[(string)$method['id']])) {
                continue;
            }
            self::applyTokenToMethod($method, $token);
            $config['parameters'][] = $method;
            $seen[(string)$method['id']] = true;
        }
    }
}
