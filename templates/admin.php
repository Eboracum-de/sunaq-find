<?php
script('sunaqfind', 'admin');
style('sunaqfind', 'probe');
?>
<div id="sunaqfind-admin" class="section">
    <h2>SunaQ Find</h2>
    <p><strong><?php p($l->t('Version:')); ?></strong> <code><?php p($_['app_version']); ?></code></p>
    <p><?php p($l->t('Leave Elasticsearch URL/index empty to use the first host and index detected from fulltextsearch_elasticsearch.')); ?></p>
    <p><em><?php p($l->t('Connection tests use the currently saved configuration. Save changes before testing.')); ?></em></p>

    <h3>Elasticsearch</h3>
    <p>
        <label for="sunaqfind-elastic-url">URL</label><br>
        <input id="sunaqfind-elastic-url" class="long" type="url" value="<?php p($_['elastic_url']); ?>" placeholder="http://elasticsearch:9200">
    </p>
    <p>
        <label for="sunaqfind-elastic-index"><?php p($l->t('Index')); ?></label><br>
        <input id="sunaqfind-elastic-index" class="long" type="text" value="<?php p($_['elastic_index']); ?>" placeholder="nextcloud_fulltext">
    </p>
    <p>
        <label for="sunaqfind-elastic-username"><?php p($l->t('Username (optional)')); ?></label><br>
        <input id="sunaqfind-elastic-username" class="long" type="text" value="<?php p($_['elastic_username']); ?>" autocomplete="off">
    </p>
    <p>
        <label for="sunaqfind-elastic-password"><?php p($l->t('Password (optional)')); ?></label><br>
        <input id="sunaqfind-elastic-password" class="long" type="password" value="" autocomplete="new-password" placeholder="<?php p($_['has_elastic_password'] ? $l->t('stored – leave empty to keep') : ''); ?>">
        <?php if ($_['has_elastic_password']) { ?>
            <br><label><input id="sunaqfind-clear-elastic-password" type="checkbox"> <?php p($l->t('Clear stored password')); ?></label>
        <?php } ?>
    </p>
    <p><?php p($l->t('Detected FullTextSearch configuration:')); ?>
        <code><?php p(($_['detected_elastic']['url'] ?: $l->t('not detected')) . ' / ' . ($_['detected_elastic']['index'] ?: $l->t('no index'))); ?></code>
    </p>
    <p class="sunaqfind-admin-test-row">
        <button id="sunaqfind-test-elastic" type="button"><?php p($l->t('Test Elasticsearch')); ?></button>
        <span id="sunaqfind-test-elastic-result" class="sunaqfind-admin-test-result" aria-live="polite"></span>
    </p>

    <h3>LLM</h3>
    <p>
        <label for="sunaqfind-llm-url"><?php p($l->t('OpenAI-compatible base URL')); ?></label><br>
        <input id="sunaqfind-llm-url" class="long" type="url" value="<?php p($_['llm_url']); ?>" placeholder="http://ollama:11434/v1">
    </p>
    <p>
        <label for="sunaqfind-llm-model"><?php p($l->t('Model')); ?></label><br>
        <input id="sunaqfind-llm-model" class="long" type="text" value="<?php p($_['llm_model']); ?>" placeholder="qwen3:8b">
    </p>
    <p>
        <label for="sunaqfind-llm-api-key"><?php p($l->t('API key (optional)')); ?></label><br>
        <input id="sunaqfind-llm-api-key" class="long" type="password" value="" autocomplete="new-password" placeholder="<?php p($_['has_llm_api_key'] ? $l->t('stored – leave empty to keep') : ''); ?>">
        <?php if ($_['has_llm_api_key']) { ?>
            <br><label><input id="sunaqfind-clear-llm-api-key" type="checkbox"> <?php p($l->t('Clear stored API key')); ?></label>
        <?php } ?>
    </p>
    <p class="sunaqfind-admin-test-row">
        <button id="sunaqfind-test-llm" type="button"><?php p($l->t('Test AI')); ?></button>
        <span id="sunaqfind-test-llm-result" class="sunaqfind-admin-test-result" aria-live="polite"></span>
    </p>

    <h3><?php p($l->t('Retrieval')); ?></h3>
    <p>
        <label for="sunaqfind-retrieval-languages"><?php p($l->t('Retrieval language(s)')); ?></label><br>
        <input id="sunaqfind-retrieval-languages" class="long" type="text" value="<?php p($_['retrieval_languages']); ?>" placeholder="de">
        <br><em><?php p($l->t('Comma-separated language tags used to constrain lexical expansion, for example de or de,en.')); ?></em>
    </p>
    <p>
        <label>
            <input id="sunaqfind-acl-prefilter" type="checkbox" <?php if ($_['acl_prefilter_enabled']) { print_unescaped('checked'); } ?>>
            <?php p($l->t('Use ACL metadata prefilter')); ?>
        </label><br>
        <em><?php p($l->t('Filters Elasticsearch candidates using FullTextSearch ACL metadata before the live Nextcloud ACL check. The live ACL check always remains active.')); ?></em>
    </p>
    <p>
        <label for="sunaqfind-search-document-limit"><?php p($l->t('Maximum Elasticsearch hits')); ?></label><br>
        <input id="sunaqfind-search-document-limit" type="number" min="1" max="20" step="1" value="<?php p((string)$_['search_document_limit']); ?>">
        <br><em><?php p($l->t('Hard limit for documents returned by Elasticsearch. No automatic refill or overfetch is performed.')); ?></em>
    </p>
    <p>
        <label for="sunaqfind-llm-document-limit"><?php p($l->t('Maximum documents for AI evaluation')); ?></label><br>
        <input id="sunaqfind-llm-document-limit" type="number" min="1" max="20" step="1" value="<?php p((string)$_['llm_document_limit']); ?>">
        <br><em><?php p($l->t('Maximum number of live-ACL-authorized search results included in the evidence sent to the AI. Must not exceed the Elasticsearch hit limit.')); ?></em>
    </p>
    <p>
        <label>
            <input id="sunaqfind-direct-lexical-routing" type="checkbox" <?php if ($_['direct_lexical_routing']) { print_unescaped('checked'); } ?>>
            <?php p($l->t('Use direct lexical search')); ?>
        </label><br>
        <em><?php p($l->t('If the planner rates a query as direct_lexical with high lexical sufficiency, search directly without semantic MUST expansion. Highly probable typos may still be considered as additional spelling variants.')); ?></em>
    </p>

    <h3><?php p($l->t('Display')); ?></h3>
    <p>
        <label>
            <input id="sunaqfind-analysis-mode" type="checkbox" <?php if ($_['analysis_mode']) { print_unescaped('checked'); } ?>>
            <?php p($l->t('Show analysis mode')); ?>
        </label><br>
        <em><?php p($l->t('Administrator diagnostics for SearchSpec, retrieval and ACL checks. Keep this disabled during normal operation.')); ?></em>
    </p>
    <p>
        <label>
            <input id="sunaqfind-show-document-excerpts" type="checkbox" <?php if ($_['show_document_excerpts']) { print_unescaped('checked'); } ?>>
            <?php p($l->t('Show document excerpts')); ?>
        </label><br>
        <em><?php p($l->t('Adds expandable details with text excerpts and file paths below sources.')); ?></em>
    </p>
    <p>
        <label>
            <input id="sunaqfind-show-search-strategy" type="checkbox" <?php if ($_['show_search_strategy']) { print_unescaped('checked'); } ?>>
            <?php p($l->t('Show search strategy')); ?>
        </label><br>
        <em><?php p($l->t('Shows the search terms used and the criteria for sufficient evidence.')); ?></em>
    </p>

    <h3><?php p($l->t('Network policy')); ?></h3>
    <p><label><input id="sunaqfind-allow-private" type="checkbox" <?php if ($_['allow_private_network']) { print_unescaped('checked'); } ?>> <?php p($l->t('Allow configured ES/LLM endpoints on private/local networks')); ?></label></p>
    <p><label><input id="sunaqfind-allow-self-signed" type="checkbox" <?php if ($_['allow_self_signed']) { print_unescaped('checked'); } ?>> <?php p($l->t('Allow self-signed TLS certificates (lab use only)')); ?></label></p>

    <p>
        <button id="sunaqfind-save" class="primary" type="button"><?php p($l->t('Save')); ?></button>
        <span id="sunaqfind-admin-result"></span>
    </p>
</div>
