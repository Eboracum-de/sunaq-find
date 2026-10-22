<?php
script('sunaqfind', 'search');
script('sunaqfind', 'variance-test');
style('sunaqfind', 'probe');
?>
<div id="app-content">
<main id="sunaqfind-probe" class="section sunaqfind-probe">
    <header class="sunaqfind-header">
        <h2>SunaQ Find</h2>
        <p><?php p($l->t('Ask questions about your Nextcloud documents with AI.')); ?></p>
    </header>

    <form id="sunaqfind-form" class="sunaqfind-searchbar" role="search">
        <label class="hidden-visually" for="sunaqfind-query"><?php p($l->t('Search query')); ?></label>
        <input
            id="sunaqfind-query"
            type="search"
            autocomplete="off"
            placeholder="<?php p($l->t('Ask a question about your documents …')); ?>"
            maxlength="1200"
        >
        <button id="sunaqfind-search" class="sunaqfind-submit" type="submit" aria-label="<?php p($l->t('Search')); ?>" title="<?php p($l->t('Search')); ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M9.5 3a6.5 6.5 0 1 0 3.98 11.64L18.84 20 20 18.84l-5.36-5.36A6.5 6.5 0 0 0 9.5 3Zm0 1.75a4.75 4.75 0 1 1 0 9.5 4.75 4.75 0 0 1 0-9.5Z"/>
            </svg>
        </button>
    </form>

    <p id="sunaqfind-error" class="sunaqfind-error" role="alert"></p>
    <div id="sunaqfind-loading" class="sunaqfind-loading" hidden aria-live="polite">
        <span class="sunaqfind-spinner" aria-hidden="true"></span>
        <span><?php p($l->t('Searching …')); ?></span>
    </div>

    <section id="sunaqfind-answer-card" class="sunaqfind-card" hidden>
        <h3 id="sunaqfind-answer-title"><?php p($l->t('Answer')); ?></h3>
        <div id="sunaqfind-answer" class="sunaqfind-answer" aria-live="polite"></div>
        <div id="sunaqfind-strategy-disclosure" class="sunaqfind-disclosure" hidden>
            <button
                id="sunaqfind-strategy-toggle"
                class="sunaqfind-disclosure-toggle"
                type="button"
                aria-expanded="false"
                aria-controls="sunaqfind-strategy-panel"
            ><?php p($l->t('Search strategy')); ?></button>
            <div
                id="sunaqfind-strategy-panel"
                class="sunaqfind-disclosure-panel sunaqfind-strategy-panel"
                hidden
            ></div>
        </div>
    </section>

    <section id="sunaqfind-sources-card" class="sunaqfind-card" hidden>
        <h3><?php p($l->t('Sources')); ?></h3>
        <ol id="sunaqfind-sources" class="sunaqfind-source-list"></ol>
    </section>

    <details id="sunaqfind-analysis-card" class="sunaqfind-card sunaqfind-analysis" hidden>
        <summary><?php p($l->t('Query analysis and retrieval diagnostics')); ?></summary>
        <pre id="sunaqfind-analysis"></pre>

        <div id="sunaqfind-variance-tool" class="sunaqfind-variance-tool" hidden>
            <h4><?php p($l->t('Variance test')); ?></h4>
            <p class="sunaqfind-variance-note">
                <?php p($l->t('Repeats the current query sequentially through the same search endpoint. This does not change search logic and uses the current Nextcloud session.')); ?>
            </p>

            <div class="sunaqfind-variance-controls">
                <label>
                    <?php p($l->t('Runs')); ?>
                    <select id="sunaqfind-variance-runs">
                        <option value="10" selected>10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </label>
                <label>
                    Planner reasoning
                    <select id="sunaqfind-variance-reasoning">
                        <option value="current" selected><?php p($l->t('Current')); ?></option>
                        <option value="none">No thinking</option>
                        <option value="default"><?php p($l->t('Provider default')); ?></option>
                        <option value="thinking">Thinking</option>
                    </select>
                </label>
                <label>
                    <?php p($l->t('Pause')); ?>
                    <select id="sunaqfind-variance-delay">
                        <option value="0">0 ms</option>
                        <option value="250" selected>250 ms</option>
                        <option value="500">500 ms</option>
                        <option value="1000">1000 ms</option>
                    </select>
                </label>
                <button id="sunaqfind-variance-start" type="button"><?php p($l->t('Start variance test')); ?></button>
                <button id="sunaqfind-variance-cancel" type="button" hidden><?php p($l->t('Cancel')); ?></button>
            </div>

            <p id="sunaqfind-variance-progress" class="sunaqfind-variance-progress" aria-live="polite"></p>

            <div id="sunaqfind-variance-result" hidden>
                <p id="sunaqfind-variance-summary"></p>
                <div class="sunaqfind-variance-table-wrap">
                    <table class="sunaqfind-variance-table">
                        <thead>
                            <tr>
                                <th><?php p($l->t('Files variant')); ?></th>
                                <th><?php p($l->t('Runs')); ?></th>
                                <th><?php p($l->t('Candidates')); ?></th>
                                <th><?php p($l->t('Sources')); ?></th>
                                <th><?php p($l->t('Lexical groups')); ?></th>
                            </tr>
                        </thead>
                        <tbody id="sunaqfind-variance-table-body"></tbody>
                    </table>
                </div>
                <div class="sunaqfind-variance-actions">
                    <button id="sunaqfind-variance-copy" type="button"><?php p($l->t('Copy JSON')); ?></button>
                    <button id="sunaqfind-variance-download" type="button"><?php p($l->t('Download JSON')); ?></button>
                </div>
                <p class="sunaqfind-variance-note">
                    <?php p($l->t('The export contains answers and only sources already visible to this user. It contains no document content rejected by the live ACL gate.')); ?>
                </p>
            </div>
        </div>
    </details>
</main>
</div>
