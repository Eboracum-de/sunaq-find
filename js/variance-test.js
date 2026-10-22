(function () {
    'use strict';

    var latestReport = null;
    var cancelRequested = false;
    var activeController = null;

    function appUrl(path) {
        return OC.generateUrl('/apps/sunaqfind' + path);
    }

    function sleep(ms) {
        return new Promise(function (resolve) {
            window.setTimeout(resolve, ms);
        });
    }

    function stableStringify(value) {
        if (Array.isArray(value)) {
            return '[' + value.map(stableStringify).join(',') + ']';
        }
        if (value && typeof value === 'object') {
            return '{' + Object.keys(value)
                .sort()
                .map(function (key) {
                    return JSON.stringify(key) + ':' + stableStringify(value[key]);
                })
                .join(',') + '}';
        }
        return JSON.stringify(value);
    }

    function filesRelevantSpec(spec) {
        spec = spec || {};
        return {
            elastic_query: spec.elastic_query || '',
            entities: spec.entities || [],
            concept_groups: spec.concept_groups || [],
            must: spec.must || [],
            should: spec.should || [],
            must_not: spec.must_not || [],
            phrases: spec.phrases || [],
            must_not_phrases: spec.must_not_phrases || []
        };
    }

    function distributionAdd(target, value) {
        var key = value === null || value === undefined ? 'null' : String(value);
        target[key] = (target[key] || 0) + 1;
    }

    function distributionText(distribution) {
        return Object.keys(distribution)
            .sort(function (a, b) {
                var an = Number(a);
                var bn = Number(b);
                if (Number.isFinite(an) && Number.isFinite(bn)) {
                    return an - bn;
                }
                return a.localeCompare(b);
            })
            .map(function (key) {
                return key + '×' + distribution[key];
            })
            .join(', ');
    }

    function lexicalGroupsText(spec) {
        var groups = [];
        (spec.entities || []).forEach(function (entity) {
            groups.push('Entity: ' + entity);
        });
        (spec.concept_groups || []).forEach(function (group) {
            var prefix = group.required ? 'MUST' : 'SHOULD';
            groups.push(prefix + ': ' + (group.terms || []).join(' | '));
        });
        if (groups.length === 0 && spec.elastic_query) {
            groups.push('elastic_query: ' + spec.elastic_query);
        }
        return groups.join(' · ');
    }

    function decodeResponse(response) {
        return response.text().then(function (text) {
            var data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                throw new Error(t('sunaqfind', 'Server error HTTP ') + response.status + t('sunaqfind', ' (no JSON response)'));
            }
            if (!response.ok || data.ok === false) {
                throw new Error(data.error || ('HTTP ' + response.status));
            }
            return {
                status: response.status,
                result: data.result || {}
            };
        });
    }

    function requestSearch(query, reasoningMode, signal) {
        return fetch(appUrl('/search'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: JSON.stringify({query: query, plannerReasoning: reasoningMode}),
            signal: signal
        }).then(decodeResponse);
    }

    function captureRun(run, elapsedMs, response) {
        var result = response.result || {};
        var analysis = result.query_analysis || {};
        var retrieval = result.retrieval || {};
        var spec = analysis.search_spec || {};
        var sources = Array.isArray(result.sources) ? result.sources : [];
        var relevant = filesRelevantSpec(spec);

        return {
            run: run,
            ok: true,
            status: response.status,
            elapsed_ms: elapsedMs,
            rewrite_status: analysis.rewrite_status || '',
            fallback_used: !!analysis.fallback_used,
            planner_reasoning: analysis.planner_reasoning || 'current',
            retrieval_intent: spec.retrieval_intent || 'unknown',
            lexical_sufficiency: spec.lexical_sufficiency || 'unknown',
            lexical_query: spec.lexical_query || '',
            search_spec: spec,
            search_spec_fingerprint: stableStringify(spec),
            files_retrieval_spec: relevant,
            files_retrieval_fingerprint: stableStringify(relevant),
            retrieval_plan: analysis.retrieval_plan || {},
            candidate_count: retrieval.candidate_count ?? null,
            authorized_count: retrieval.authorized_count ?? null,
            denied_count: retrieval.denied_count ?? null,
            unresolved_count: retrieval.unresolved_count ?? null,
            source_count: sources.length,
            source_ids: sources.map(function (source) {
                return source.file_id ?? null;
            }),
            source_names: sources.map(function (source) {
                return source.name || '';
            }),
            answer_error: !!result.answer_error,
            answer_found: !!result.answer_found,
            answer: result.answer || ''
        };
    }

    function buildGroups(results) {
        var groupsByFingerprint = {};
        var fullSpecs = {};

        results.forEach(function (row) {
            if (!row.ok) {
                return;
            }

            fullSpecs[row.search_spec_fingerprint] = true;

            var key = row.files_retrieval_fingerprint;
            if (!groupsByFingerprint[key]) {
                groupsByFingerprint[key] = {
                    fingerprint: key,
                    count: 0,
                    runs: [],
                    candidates: {},
                    authorized: {},
                    sources: {},
                    source_sets: {},
                    rewrite_statuses: {},
                    retrieval_intents: {},
                    lexical_sufficiencies: {},
                    lexical_queries: {},
                    fallback_count: 0,
                    spec: row.files_retrieval_spec
                };
            }

            var group = groupsByFingerprint[key];
            group.count += 1;
            group.runs.push(row.run);
            distributionAdd(group.candidates, row.candidate_count);
            distributionAdd(group.authorized, row.authorized_count);
            distributionAdd(group.sources, row.source_count);
            distributionAdd(group.source_sets, stableStringify(row.source_ids));
            distributionAdd(group.rewrite_statuses, row.rewrite_status);
            distributionAdd(group.retrieval_intents, row.retrieval_intent);
            distributionAdd(group.lexical_sufficiencies, row.lexical_sufficiency);
            distributionAdd(group.lexical_queries, row.lexical_query);
            if (row.fallback_used) {
                group.fallback_count += 1;
            }
        });

        return {
            files_groups: Object.values(groupsByFingerprint),
            full_search_spec_variants: Object.keys(fullSpecs).length
        };
    }

    function summarize(query, requestedRuns, delayMs, reasoningMode, rows, startedAt, finishedAt) {
        var okRows = rows.filter(function (row) { return row.ok; });
        var failedRows = rows.filter(function (row) { return !row.ok; });
        var candidateDistribution = {};
        var sourceDistribution = {};
        var answerFoundDistribution = {};
        var retrievalIntentDistribution = {};
        var lexicalSufficiencyDistribution = {};
        var lexicalQueryDistribution = {};
        var grouping = buildGroups(rows);

        okRows.forEach(function (row) {
            distributionAdd(candidateDistribution, row.candidate_count);
            distributionAdd(sourceDistribution, row.source_count);
            distributionAdd(answerFoundDistribution, row.answer_found);
            distributionAdd(retrievalIntentDistribution, row.retrieval_intent);
            distributionAdd(lexicalSufficiencyDistribution, row.lexical_sufficiency);
            distributionAdd(lexicalQueryDistribution, row.lexical_query);
        });

        return {
            schema_version: 2,
            tool: 'sunaq-search-variance',
            generated_at: finishedAt,
            query: query,
            requested_runs: requestedRuns,
            completed_runs: rows.length,
            successful_runs: okRows.length,
            failed_runs: failedRows.length,
            delay_ms: delayMs,
            planner_reasoning: reasoningMode,
            started_at: startedAt,
            finished_at: finishedAt,
            full_search_spec_variants: grouping.full_search_spec_variants,
            files_retrieval_variants: grouping.files_groups.length,
            candidate_distribution: candidateDistribution,
            source_distribution: sourceDistribution,
            answer_found_distribution: answerFoundDistribution,
            retrieval_intent_distribution: retrievalIntentDistribution,
            lexical_sufficiency_distribution: lexicalSufficiencyDistribution,
            lexical_query_distribution: lexicalQueryDistribution,
            files_groups: grouping.files_groups,
            results: rows
        };
    }

    function clearChildren(node) {
        if (node) {
            node.replaceChildren();
        }
    }

    function appendCell(row, text) {
        var cell = document.createElement('td');
        cell.textContent = text;
        row.appendChild(cell);
    }

    function renderReport(report) {
        latestReport = report;

        var result = document.getElementById('sunaqfind-variance-result');
        var summary = document.getElementById('sunaqfind-variance-summary');
        var body = document.getElementById('sunaqfind-variance-table-body');

        if (summary) {
            summary.textContent =
                report.successful_runs + '/' + report.completed_runs + ' ' + t('sunaqfind', 'successful runs') + ' · ' +
                report.full_search_spec_variants + ' ' + t('sunaqfind', 'complete SearchSpec variants') + ' · ' +
                report.files_retrieval_variants + ' ' + t('sunaqfind', 'files-relevant variants') + ' · Reasoning: ' +
                report.planner_reasoning + ' · Intent: ' +
                distributionText(report.retrieval_intent_distribution) + ' · Lexical sufficiency: ' +
                distributionText(report.lexical_sufficiency_distribution) + ' · ' + t('sunaqfind', 'Candidates: ') +
                distributionText(report.candidate_distribution) + ' · ' + t('sunaqfind', 'Sources: ') +
                distributionText(report.source_distribution);
        }

        clearChildren(body);
        report.files_groups.forEach(function (group, index) {
            var row = document.createElement('tr');
            appendCell(row, String(index + 1));
            appendCell(row, group.count + ' (' + group.runs.join(', ') + ')');
            appendCell(row, distributionText(group.candidates));
            appendCell(row, distributionText(group.sources));
            appendCell(row, lexicalGroupsText(group.spec));
            body.appendChild(row);
        });

        if (result) {
            result.hidden = false;
        }
    }

    function setRunning(running) {
        var start = document.getElementById('sunaqfind-variance-start');
        var cancel = document.getElementById('sunaqfind-variance-cancel');
        var search = document.getElementById('sunaqfind-search');
        var runs = document.getElementById('sunaqfind-variance-runs');
        var delay = document.getElementById('sunaqfind-variance-delay');
        var reasoning = document.getElementById('sunaqfind-variance-reasoning');

        if (start) { start.disabled = running; }
        if (search) { search.disabled = running; }
        if (runs) { runs.disabled = running; }
        if (delay) { delay.disabled = running; }
        if (reasoning) { reasoning.disabled = running; }
        if (cancel) { cancel.hidden = !running; }
    }

    async function runVarianceTest() {
        var queryInput = document.getElementById('sunaqfind-query');
        var runsInput = document.getElementById('sunaqfind-variance-runs');
        var delayInput = document.getElementById('sunaqfind-variance-delay');
        var reasoningInput = document.getElementById('sunaqfind-variance-reasoning');
        var progress = document.getElementById('sunaqfind-variance-progress');
        var result = document.getElementById('sunaqfind-variance-result');

        var query = queryInput ? queryInput.value.trim() : '';
        var requestedRuns = Math.max(1, Math.min(100, Number(runsInput ? runsInput.value : 10) || 10));
        var delayMs = Math.max(0, Math.min(5000, Number(delayInput ? delayInput.value : 250) || 0));
        var reasoningMode = reasoningInput ? reasoningInput.value : 'current';
        if (!['current', 'none', 'default', 'thinking'].includes(reasoningMode)) {
            reasoningMode = 'current';
        }

        if (!query) {
            if (progress) {
                progress.textContent = t('sunaqfind', 'Please enter a search query first.');
            }
            return;
        }

        cancelRequested = false;
        latestReport = null;
        if (result) {
            result.hidden = true;
        }
        setRunning(true);

        var rows = [];
        var startedAt = new Date().toISOString();

        try {
            for (var run = 1; run <= requestedRuns; run++) {
                if (cancelRequested) {
                    break;
                }

                activeController = new AbortController();
                var started = performance.now();

                try {
                    var response = await requestSearch(query, reasoningMode, activeController.signal);
                    var elapsed = Math.round(performance.now() - started);

                    if (!response.result.analysis_enabled) {
                        throw new Error(t('sunaqfind', 'Analysis mode is not active.'));
                    }

                    var row = captureRun(run, elapsed, response);
                    rows.push(row);

                    if (progress) {
                        progress.textContent =
                            run + '/' + requestedRuns +
                            ' · candidates=' + String(row.candidate_count) +
                            ' · sources=' + String(row.source_count) +
                            ' · ' + elapsed + ' ms';
                    }
                } catch (error) {
                    if (cancelRequested && error && error.name === 'AbortError') {
                        break;
                    }
                    rows.push({
                        run: run,
                        ok: false,
                        error: error instanceof Error ? error.message : String(error)
                    });
                    if (progress) {
                        progress.textContent = run + '/' + requestedRuns + ' · ' + t('sunaqfind', 'Error: ') + rows[rows.length - 1].error;
                    }
                } finally {
                    activeController = null;
                }

                if (!cancelRequested && run < requestedRuns && delayMs > 0) {
                    await sleep(delayMs);
                }
            }
        } finally {
            setRunning(false);
        }

        var finishedAt = new Date().toISOString();
        var report = summarize(query, requestedRuns, delayMs, reasoningMode, rows, startedAt, finishedAt);
        renderReport(report);

        if (progress) {
            progress.textContent = cancelRequested
                ? t('sunaqfind', 'Cancelled after ') + rows.length + ' ' + t('sunaqfind', 'runs.')
                : t('sunaqfind', 'Completed: ') + report.successful_runs + '/' + report.completed_runs + ' ' + t('sunaqfind', 'successful runs.');
        }
    }

    function cancelVarianceTest() {
        cancelRequested = true;
        if (activeController) {
            activeController.abort();
        }
    }

    async function copyReport() {
        if (!latestReport) {
            return;
        }
        var text = JSON.stringify(latestReport, null, 2);
        var progress = document.getElementById('sunaqfind-variance-progress');

        try {
            await navigator.clipboard.writeText(text);
            if (progress) {
                progress.textContent = t('sunaqfind', 'JSON copied to clipboard.');
            }
        } catch (error) {
            var area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', 'readonly');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            var copied = document.execCommand('copy');
            area.remove();
            if (progress) {
                progress.textContent = copied
                    ? t('sunaqfind', 'JSON copied to clipboard.')
                    : t('sunaqfind', 'Copy failed; please download the JSON instead.');
            }
        }
    }

    function downloadReport() {
        if (!latestReport) {
            return;
        }
        var stamp = new Date().toISOString().replace(/[:.]/g, '-');
        var blob = new Blob(
            [JSON.stringify(latestReport, null, 2)],
            {type: 'application/json'}
        );
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = 'sunaq-search-variance-' + stamp + '.json';
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.setTimeout(function () {
            URL.revokeObjectURL(url);
        }, 0);
    }

    function syncVisibility() {
        var analysis = document.getElementById('sunaqfind-analysis-card');
        var tool = document.getElementById('sunaqfind-variance-tool');
        if (analysis && tool) {
            tool.hidden = analysis.hidden;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var analysis = document.getElementById('sunaqfind-analysis-card');
        var start = document.getElementById('sunaqfind-variance-start');
        var cancel = document.getElementById('sunaqfind-variance-cancel');
        var copy = document.getElementById('sunaqfind-variance-copy');
        var download = document.getElementById('sunaqfind-variance-download');

        if (analysis) {
            new MutationObserver(syncVisibility).observe(analysis, {
                attributes: true,
                attributeFilter: ['hidden']
            });
            syncVisibility();
        }

        if (start) {
            start.addEventListener('click', runVarianceTest);
        }
        if (cancel) {
            cancel.addEventListener('click', cancelVarianceTest);
        }
        if (copy) {
            copy.addEventListener('click', copyReport);
        }
        if (download) {
            download.addEventListener('click', downloadReport);
        }
    });
}());
