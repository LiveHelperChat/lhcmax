<h1>🟣 Настройки интеграции Max Messenger</h1>

<?php if (isset($updated) && $updated == true) : ?>
    <div class="alert alert-success" role="alert">
        ✅ Настройки успешно сохранены!
    </div>
<?php endif; ?>

<?php if (isset($testResult)) : ?>
    <?php if ($testResult['success']) : ?>
        <div class="alert alert-success" role="alert">
            <h5>✅ Подключение к Max API успешно! (HTTP <?php echo (int)$testResult['http_code']; ?>)</h5>
            <small>Токен валиден, сервер Max ответил корректно.</small>
        </div>
    <?php else : ?>
        <div class="alert alert-danger" role="alert">
            <h5>❌ Ошибка подключения к Max API (HTTP <?php echo (int)$testResult['http_code']; ?>)</h5>
            <pre class="mb-0 fs12"><?php echo htmlspecialchars($testResult['response']); ?></pre>
        </div>
    <?php endif; ?>
<?php endif; ?>

<form action="<?php echo erLhcoreClassDesign::baseurl('max/settings')?>" method="post">
    <?php include(erLhcoreClassDesign::designtpl('lhkernel/csfr_token.tpl.php'));?>

    <div class="card mb-3">
        <div class="card-header bg-light fw-bold">
            Параметры подключения к API платформы Max
        </div>
        <div class="card-body">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="enabled" name="enabled" value="1" <?php if ($settings['enabled']) : ?>checked="checked"<?php endif;?>>
                <label class="form-check-label fw-bold" for="enabled">Включить интеграцию Max</label>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">API Host</label>
                <input type="text" class="form-control" name="host" value="<?php echo htmlspecialchars($settings['host']);?>" placeholder="https://platform-api2.max.ru">
                <div class="form-text">Базовый адрес платформы API Max (по умолчанию: <code>https://platform-api2.max.ru</code>).</div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-bold">Bot API Token</label>
                <input type="password" class="form-control font-monospace" name="token" value="<?php echo htmlspecialchars($settings['token']);?>" placeholder="f9LHodD0cO...">
                <div class="form-text">Секретный токен бота Max для отправки сообщений и 3-шаговой загрузки файлов.</div>
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="debug" name="debug" value="1" <?php if ($settings['debug']) : ?>checked="checked"<?php endif;?>>
                <label class="form-check-label" for="debug">Включить логирование отладки (Debug)</label>
            </div>
        </div>
        <div class="card-footer bg-light d-flex gap-2">
            <button type="submit" name="SaveSettings" class="btn btn-primary">💾 Сохранить настройки</button>
            <button type="submit" name="TestConnection" class="btn btn-outline-success">🔌 Проверить подключение</button>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header bg-light fw-bold">
        ℹ️ Информация о модуле
    </div>
    <div class="card-body">
        <ul>
            <li><strong>Модуль:</strong> <code>extension/lhcmax</code></li>
            <li><strong>Поддержка файлов:</strong> Нативная 3-шаговая загрузка (Init <code>/uploads</code> &rarr; Binary multipart upload &rarr; <code>/messages</code>).</li>
            <li><strong>Цитаты и реплаи:</strong> Полная двухсторонняя привязка по <code>mid</code>.</li>
            <li><strong>Совместимость с ядром LHC:</strong> 100% независимый модуль, не затрагивает ядро.</li>
        </ul>
    </div>
</div>
