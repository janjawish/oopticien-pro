        </div>
        <footer class="footer">
            <span>Oopticien Pro</span>
            <span>Cosium reste le logiciel métier principal</span>
        </footer>
    </main>
</div>
<div class="assistant-widget" data-assistant-widget data-endpoint="<?= e(app_url('actions/assistant_chat.php')) ?>">
    <section class="assistant-widget-panel" data-assistant-panel hidden aria-label="Assistant d’action">
        <header class="assistant-widget-header">
            <div><span class="assistant-widget-mark">✦</span><span><strong>Assistant Oopticien</strong><small>Traitement local · validation obligatoire</small></span></div>
            <button type="button" data-assistant-close aria-label="Fermer l’assistant">×</button>
        </header>
        <div class="assistant-widget-body" data-assistant-messages aria-live="polite">
            <div class="assistant-widget-message is-assistant"><div>Bonjour ! Dites-moi ce que vous voulez faire. Pour modifier un dossier, indiquez d’abord le nom et le prénom du client.</div></div>
        </div>
        <div class="assistant-widget-suggestions" data-assistant-suggestions>
            <?php foreach ([
                'Résume Nadia Dupont',
                'Modifie le RO, le RC et le RAC pour Nadia Dupont',
                'Passe le dossier de Nadia Dupont en SAV',
                'Ouvre les dossiers à facturer',
            ] as $assistantSuggestion): ?>
                <button type="button" data-assistant-prompt="<?= e($assistantSuggestion) ?>"><?= e($assistantSuggestion) ?></button>
            <?php endforeach; ?>
        </div>
        <form class="assistant-widget-composer" data-assistant-form>
            <?= csrf_field() ?>
            <label><span class="sr-only">Votre message à l’assistant</span><textarea name="prompt" maxlength="1500" rows="2" required placeholder="Écrivez votre demande…"></textarea></label>
            <button type="submit" aria-label="Envoyer le message">➤</button>
        </form>
    </section>
    <button class="assistant-widget-bubble" type="button" data-assistant-toggle aria-expanded="false" aria-label="Ouvrir l’assistant">
        <span>✦</span><small>Assistant</small>
    </button>
</div>
<?php $appJsVersion = filemtime(dirname(__DIR__) . '/assets/js/app.js') ?: 1; ?>
<script src="<?= e(app_url('assets/js/app.js?v='.$appJsVersion)) ?>"></script>
</body>
</html>
