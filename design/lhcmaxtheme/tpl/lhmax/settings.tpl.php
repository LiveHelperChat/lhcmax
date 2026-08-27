<h1 class="attr-header">Max Messenger Options</h1>

<?php if (isset($updated) && $updated == 'done') : $msg = erTranslationClassLhTranslation::getInstance()->getTranslation('chat/onlineusers','Settings updated'); ?>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/alert_success.tpl.php'));?>
<?php endif; ?>

<?php if (isset($testResult)) : ?>
    <?php if ($testResult['success']) : ?>
        <div class="alert alert-success" role="alert">
            <h5>Connection successful (HTTP <?php echo (int)$testResult['http_code']; ?>)</h5>
            <small>Bot API token is valid and media upload initialization endpoint responded correctly.</small>
        </div>
    <?php else : ?>
        <div class="alert alert-danger" role="alert">
            <h5>Connection failed (HTTP <?php echo (int)$testResult['http_code']; ?>)</h5>
            <pre class="mb-0 fs12"><?php echo htmlspecialchars($testResult['curl_error'] !== '' ? $testResult['curl_error'] : $testResult['response']); ?></pre>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if (!$incomingWebhook || !$restAPI) : ?>
    <div class="card border-primary mb-3 bg-light">
        <div class="card-body">
            <h5 class="card-title text-primary"><i class="material-icons align-middle">rocket_launch</i> Quick Setup Guide for New Installation</h5>
            <p class="card-text mb-2">Welcome to the <strong>Max Messenger</strong> extension! To set up two-way integration in seconds:</p>
            <ol class="mb-3">
                <li>Enter your <strong>Bot API Token</strong> below and click <code>Save settings</code>.</li>
                <li>Click <strong>Save and Activate Max configuration</strong> at the bottom of this page. This will automatically generate the <em>Incoming Webhook</em> and <em>REST API</em> templates.</li>
                <li>Copy the generated <strong>Callback URL</strong> and paste it into your Max bot webhook settings.</li>
            </ol>
        </div>
    </div>
<?php endif; ?>

<form action="<?php echo erLhcoreClassDesign::baseurl('max/settings')?>" method="post" autocomplete="off" ng-non-bindable>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/csfr_token.tpl.php'));?>

    <div class="card mb-3">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <span class="fw-bold">Max Platform API Configuration</span>
            <?php if ($settings['enabled']) : ?>
                <span class="badge bg-success">Active</span>
            <?php else : ?>
                <span class="badge bg-secondary">Disabled</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="form-group mb-3">
                <label class="form-label fw-bold">
                    <input type="checkbox" name="enabled" value="1" <?php if ($settings['enabled']) : ?>checked="checked"<?php endif;?> />
                    Enable Max Messenger Integration
                </label>
            </div>

            <div class="form-group mb-3">
                <label class="form-label fw-bold">API Host</label>
                <input type="text" class="form-control" name="host" autocomplete="off" value="<?php echo htmlspecialchars($settings['host']);?>" placeholder="https://platform-api2.max.ru">
                <div class="form-text text-muted">Base URL of Max Bot API platform (default: <code>https://platform-api2.max.ru</code>).</div>
            </div>

            <div class="form-group mb-3">
                <label class="form-label fw-bold">Bot API Token</label>
                <input type="password" class="form-control font-monospace" name="token" autocomplete="new-password" spellcheck="false" value="<?php echo htmlspecialchars($settings['token']);?>" placeholder="Max bot token">
                <div class="form-text text-muted">Secret Bot Token from Max platform used for message dispatch and 3-step media upload.</div>
            </div>

            <div class="form-group mb-3">
                <label class="form-label fw-bold">Fallback Department ID</label>
                <input type="number" min="0" class="form-control" name="department_id" value="<?php echo (int)($settings['department_id'] ?? 0);?>">
                <div class="form-text text-muted">Optional. Keep 0 when Max chats use an incoming webhook with scope <code>max</code>.</div>
            </div>

            <div class="form-group mb-3">
                <label class="form-label">
                    <input type="checkbox" name="debug" value="1" <?php if ($settings['debug']) : ?>checked="checked"<?php endif;?> />
                    Enable Debug Logging
                </label>
            </div>
        </div>
        <div class="card-footer bg-light d-flex gap-2">
            <button type="submit" name="SaveSettings" class="btn btn-primary btn-sm"><i class="material-icons">save</i> Save settings</button>
            <button type="submit" name="TestConnection" class="btn btn-outline-success btn-sm"><i class="material-icons">flash_on</i> Test connection</button>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header bg-light fw-bold">
            Component Status and Configuration
        </div>
        <div class="card-body">
            <div class="mb-3">
                <h6 class="fw-bold mb-1">1. Incoming Webhook</h6>
                <?php if ($incomingWebhook) : ?>
                    <span class="badge bg-success">Exists</span> (Scope: <code><?php echo htmlspecialchars($incomingWebhook->scope);?></code>, Dep ID: <code><?php echo (int)$incomingWebhook->dep_id;?></code>)
                    <div class="form-group mt-2">
                        <label class="form-label fw-bold small">Callback URL for Max integration:</label>
                        <input readonly type="text" class="form-control form-control-sm font-monospace" value="<?php echo htmlspecialchars(erLhcoreClassSystem::getHost() . erLhcoreClassDesign::baseurl('genericbot/incomingwebhook') . '/' . (int)$incomingWebhook->id . '/' . $incomingWebhook->identifier); ?>">
                    </div>
                    <p class="small text-muted mt-1 mb-0"><a target="_blank" href="<?php echo erLhcoreClassDesign::baseurl('webhooks/editincoming')?>/<?php echo (int)$incomingWebhook->id?>">Edit Incoming Webhook settings &rarr;</a></p>
                <?php else : ?>
                    <span class="badge bg-danger">Missing</span>
                    <p class="small text-muted mb-0">No incoming webhook found. Click "Save and Activate Max configuration" below to create it automatically.</p>
                <?php endif; ?>
            </div>

            <hr class="my-3">

            <div class="mb-3">
                <h6 class="fw-bold mb-1">2. Rest API Configuration</h6>
                <?php if ($restAPI) : ?>
                    <span class="badge bg-success">Exists</span> (ID: <code>#<?php echo (int)$restAPI->id;?></code>, Name: <code><?php echo htmlspecialchars($restAPI->name);?></code>)
                    <p class="small text-muted mt-1 mb-0"><a target="_blank" href="<?php echo erLhcoreClassDesign::baseurl('genericbot/editrestapi')?>/<?php echo (int)$restAPI->id?>">Edit REST API settings &rarr;</a></p>
                <?php else : ?>
                    <span class="badge bg-danger">Missing</span>
                    <p class="small text-muted mb-0">REST API template is not configured. Click "Save and Activate Max configuration" below to create it automatically.</p>
                <?php endif; ?>
            </div>

            <hr class="my-3">

            <div class="mb-3">
                <h6 class="fw-bold mb-1">3. Event Listener and Media Upload</h6>
                <p class="text-success small mb-1"><i class="material-icons fs14 align-middle">check_circle</i> chat.web_add_msg_admin (Native 3-step upload: Init &rarr; Upload &rarr; Dispatch)</p>
                <p class="text-success small mb-0"><i class="material-icons fs14 align-middle">check_circle</i> Message & quote synchronization (2-way mid tracking)</p>
            </div>
        </div>
        <div class="card-footer bg-light d-flex gap-2">
            <button type="submit" class="btn btn-secondary btn-sm" name="StoreOptionsMax"><i class="material-icons">bolt</i> Save and Activate Max configuration</button>
            <button type="submit" class="btn btn-warning btn-sm" name="StoreOptionsMaxRemove"><i class="material-icons">delete</i> Save and Remove Max configuration</button>
        </div>
    </div>
</form>
