<h1 class="attr-header">Max Messenger Settings</h1>

<?php if (isset($updated) && $updated == 'done') : $msg = erTranslationClassLhTranslation::getInstance()->getTranslation('chat/onlineusers','Settings updated'); ?>
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/alert_success.tpl.php'));?>
<?php endif; ?>

<?php if (isset($testResult)) : ?>
    <?php if ($testResult['success']) : ?>
        <div class="alert alert-success" role="alert">
            <h5>✅ Connection successful! (HTTP <?php echo (int)$testResult['http_code']; ?>)</h5>
            <small>Bot API token is valid and media upload initialization endpoint responded correctly.</small>
        </div>
    <?php else : ?>
        <div class="alert alert-danger" role="alert">
            <h5>❌ Connection failed! (HTTP <?php echo (int)$testResult['http_code']; ?>)</h5>
            <pre class="mb-0 fs12"><?php echo htmlspecialchars($testResult['response']); ?></pre>
        </div>
    <?php endif; ?>
<?php endif; ?>

<form action="<?php echo erLhcoreClassDesign::baseurl('max/settings')?>" method="post" autocomplete="off">
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
                <input type="text" class="form-control font-monospace" name="token" autocomplete="off" spellcheck="false" value="<?php echo htmlspecialchars($settings['token']);?>" placeholder="f9LHodD0cO...">
                <div class="form-text text-muted">Secret Bot Token from Max platform used for message dispatch and 3-step media upload.</div>
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
</form>

<div class="card mb-3">
    <div class="card-header bg-light fw-bold">
        📋 Integration Overview & Status
    </div>
    <div class="card-body">
        <h6>1. Incoming Webhook</h6>
        <?php if ($incomingWebhook) : ?>
            <p><span class="badge bg-success">Configured</span> (Scope: <code><?php echo htmlspecialchars($incomingWebhook->scope);?></code>, Dep ID: <code><?php echo (int)$incomingWebhook->dep_id;?></code>)</p>
            <div class="form-group mb-3">
                <label class="form-label fw-bold">Callback URL (Set this in Max bot settings):</label>
                <input readonly type="text" class="form-control form-control-sm font-monospace" value="https://<?php echo $_SERVER['HTTP_HOST']?><?php echo erLhcoreClassDesign::baseurl('genericbot/incomingwebhook')?>/<?php echo (int)$incomingWebhook->id?>/<?php echo htmlspecialchars($incomingWebhook->identifier)?>">
            </div>
        <?php else : ?>
            <p><span class="badge bg-warning text-dark">Missing</span> Create an incoming webhook with scope <code>max</code> in <em>Generic Bot &rarr; Incoming webhooks</em>.</p>
        <?php endif; ?>

        <h6>2. Outgoing Messages & Media Upload Flow</h6>
        <p class="text-muted mb-2">This extension automatically hooks into Live Helper Chat event system to send operator and bot replies:</p>
        <ul class="mb-0 text-muted">
            <li><strong>Text & Quotes:</strong> Dispatches messages via <code>POST /messages</code> with native quote / reply-to linking (<code>mid</code>).</li>
            <li><strong>Media Upload:</strong> Automatically handles 3-step upload for photos, audio, voice, video, and documents (<code>POST /uploads</code> &rarr; binary upload &rarr; <code>POST /messages</code> with attachment token).</li>
        </ul>
    </div>
</div>
