(function () {
    'use strict';

    function value(id) {
        return document.getElementById(id).value || '';
    }

    function checked(id) {
        var node = document.getElementById(id);
        return !!(node && node.checked);
    }

    function setChecked(id, value) {
        var node = document.getElementById(id);
        if (node) {
            node.checked = !!value;
        }
    }

    function post(path) {
        return fetch(OC.generateUrl('/apps/sunaqfind' + path), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: '{}'
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (data) {
                if (!response.ok || data.ok === false) {
                    throw new Error(data.error || ('HTTP ' + response.status));
                }
                return data.result || {};
            });
        });
    }

    function bindConnectionTest(buttonId, resultId, path, successText) {
        var button = document.getElementById(buttonId);
        var result = document.getElementById(resultId);
        if (!button || !result) { return; }

        button.addEventListener('click', function () {
            button.disabled = true;
            result.textContent = t('sunaqfind', 'Testing …');
            post(path).then(function (data) {
                result.textContent = successText(data);
            }).catch(function (error) {
                result.textContent = t('sunaqfind', 'Error: ') + error.message;
            }).finally(function () {
                button.disabled = false;
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        bindConnectionTest(
            'sunaqfind-test-elastic',
            'sunaqfind-test-elastic-result',
            '/admin/test/elasticsearch',
            function (data) {
                return t('sunaqfind', 'OK – Index ') + String(data.index || '') + ' (' + String(data.source || t('sunaqfind', 'configured')) + ')';
            }
        );
        bindConnectionTest(
            'sunaqfind-test-llm',
            'sunaqfind-test-llm-result',
            '/admin/test/llm',
            function (data) {
                return t('sunaqfind', 'OK – Model ') + String(data.model || '');
            }
        );

        var button = document.getElementById('sunaqfind-save');
        var result = document.getElementById('sunaqfind-admin-result');
        if (!button || !result) { return; }

        button.addEventListener('click', function () {
            button.disabled = true;
            result.textContent = t('sunaqfind', 'Saving …');

            fetch(OC.generateUrl('/apps/sunaqfind/admin/config'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    elasticUrl: value('sunaqfind-elastic-url'),
                    elasticIndex: value('sunaqfind-elastic-index'),
                    elasticUsername: value('sunaqfind-elastic-username'),
                    elasticPassword: value('sunaqfind-elastic-password'),
                    llmUrl: value('sunaqfind-llm-url'),
                    llmModel: value('sunaqfind-llm-model'),
                    llmApiKey: value('sunaqfind-llm-api-key'),
                    retrievalLanguages: value('sunaqfind-retrieval-languages'),
                    aclPrefilterEnabled: checked('sunaqfind-acl-prefilter'),
                    searchDocumentLimit: value('sunaqfind-search-document-limit'),
                    llmDocumentLimit: value('sunaqfind-llm-document-limit'),
                    allowPrivateNetwork: checked('sunaqfind-allow-private'),
                    allowSelfSigned: checked('sunaqfind-allow-self-signed'),
                    analysisMode: checked('sunaqfind-analysis-mode'),
                    directLexicalRouting: checked('sunaqfind-direct-lexical-routing'),
                    showDocumentExcerpts: checked('sunaqfind-show-document-excerpts'),
                    showSearchStrategy: checked('sunaqfind-show-search-strategy')
                })
            }).then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || data.ok === false) {
                        throw new Error(data.error || ('HTTP ' + response.status));
                    }
                    return data;
                });
            }).then(function (data) {
                var config = data && data.config ? data.config : {};
                setChecked('sunaqfind-analysis-mode', config.analysis_mode);
                setChecked('sunaqfind-acl-prefilter', config.acl_prefilter_enabled);
                setChecked('sunaqfind-direct-lexical-routing', config.direct_lexical_routing);
                setChecked('sunaqfind-show-document-excerpts', config.show_document_excerpts);
                setChecked('sunaqfind-show-search-strategy', config.show_search_strategy);
                result.textContent = t('sunaqfind', 'Saved – analysis mode ') + (config.analysis_mode ? t('sunaqfind', 'on') : t('sunaqfind', 'off'));
                document.getElementById('sunaqfind-elastic-password').value = '';
                document.getElementById('sunaqfind-llm-api-key').value = '';
            }).catch(function (error) {
                result.textContent = t('sunaqfind', 'Error: ') + error.message;
            }).finally(function () {
                button.disabled = false;
            });
        });
    });
}());
