<?php

namespace LiveHelperChatExtension\lhcmax\providers;

#[\AllowDynamicProperties]
class MaxLiveHelperChatActivator {

    public static function remove()
    {
        if ($incomingWebhook = \erLhcoreClassModelChatIncomingWebhook::findOne(['filter' => ['name' => 'MaxWebhook']])) {
            $incomingWebhook->removeThis();
        }

        if ($restAPI = \erLhcoreClassModelGenericBotRestAPI::findOne(['filter' => ['name' => 'Max']])) {
            $restAPI->removeThis();
        }
    }

    public static function installOrUpdate()
    {
        // 1. Incoming Webhook
        $incomingWebhook = \erLhcoreClassModelChatIncomingWebhook::findOne(['filter' => ['name' => 'MaxWebhook']]);
        if (!$incomingWebhook) {
            $incomingWebhook = \erLhcoreClassModelChatIncomingWebhook::findOne(['filter' => ['scope' => 'max']]);
        }

        $incomingWebhookFile = dirname(__DIR__) . '/doc/max/incoming-webhook.json';
        if (file_exists($incomingWebhookFile)) {
            $content = json_decode(file_get_contents($incomingWebhookFile), true);
            if (!$incomingWebhook) {
                $incomingWebhook = new \erLhcoreClassModelChatIncomingWebhook();
                $incomingWebhook->setState($content);
                $incomingWebhook->dep_id = 8;
                $incomingWebhook->name = 'MaxWebhook';
                $incomingWebhook->identifier = \erLhcoreClassModelForgotPassword::randomPassword(20);
            } else {
                $dep_id = $incomingWebhook->dep_id;
                $identifier = $incomingWebhook->identifier;
                $incomingWebhook->setState($content);
                $incomingWebhook->dep_id = $dep_id;
                $incomingWebhook->identifier = $identifier;
                $incomingWebhook->name = 'MaxWebhook';
            }
            $incomingWebhook->saveThis();
        }

        // 2. Rest API
        $restAPI = \erLhcoreClassModelGenericBotRestAPI::findOne(['filter' => ['name' => 'Max']]);
        $restApiFile = dirname(__DIR__) . '/doc/max/rest-api.json';
        if (file_exists($restApiFile)) {
            $content = json_decode(file_get_contents($restApiFile), true);
            if (!$restAPI) {
                $restAPI = new \erLhcoreClassModelGenericBotRestAPI();
                $restAPI->setState($content);
                $restAPI->name = 'Max';
            } else {
                $restAPI->setState($content);
                $restAPI->name = 'Max';
            }
            $restAPI->saveThis();
        }
    }
}
