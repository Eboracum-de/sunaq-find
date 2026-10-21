(function () {
    'use strict';

    function appUrl(path) {
        return OC.generateUrl('/apps/sunaqfind' + path);
    }

    function setText(id, text) {
        var node = document.getElementById(id);
        if (node) {
            node.textContent = text || '';
        }
    }

    function show(id, visible) {
        var node = document.getElementById(id);
        if (node) {
            node.hidden = !visible;
        }
    }

    function setDisclosureState(button, panel, expanded) {
        if (!button || !panel) { return; }
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        panel.hidden = !expanded;
    }

    function bindDisclosure(button, panel) {
        if (!button || !panel || button.dataset.sunaqBound === '1') { return; }
        button.dataset.sunaqBound = '1';
        button.addEventListener('click', function () {
            var expanded = button.getAttribute('aria-expanded') === 'true';
            setDisclosureState(button, panel, !expanded);
        });
    }

    function createDisclosure(label, panelClass) {
        var wrapper = document.createElement('div');
        wrapper.className = 'sunaqfind-disclosure';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'sunaqfind-disclosure-toggle';
        button.textContent = label;
        button.setAttribute('aria-expanded', 'false');

        var panel = document.createElement('div');
        panel.className = 'sunaqfind-disclosure-panel'
            + (panelClass ? ' ' + panelClass : '');
        panel.hidden = true;

        bindDisclosure(button, panel);
        wrapper.appendChild(button);
        wrapper.appendChild(panel);

        return {
            wrapper: wrapper,
            button: button,
            panel: panel
        };
    }

    function renderSearchStrategy(markdown) {
        var wrapper = document.getElementById('sunaqfind-strategy-disclosure');
        var button = document.getElementById('sunaqfind-strategy-toggle');
        var panel = document.getElementById('sunaqfind-strategy-panel');
        if (!wrapper || !button || !panel) { return; }

        panel.replaceChildren();
        setDisclosureState(button, panel, false);

        var content = String(markdown || '').trim();
        if (!content) {
            wrapper.hidden = true;
            return;
        }

        renderMarkdown(panel, content);
        wrapper.hidden = false;
        bindDisclosure(button, panel);
    }

    function appendInline(parent, text) {
        var pattern = /(\*\*[^*]+\*\*|__[^_]+__|\*[^*]+\*|_[^_]+_)/g;
        var last = 0;
        var match;

        while ((match = pattern.exec(text)) !== null) {
            if (match.index > last) {
                parent.appendChild(document.createTextNode(text.slice(last, match.index)));
            }

            var token = match[0];
            var node;
            if (token.startsWith('**') || token.startsWith('__')) {
                node = document.createElement('strong');
                node.textContent = token.slice(2, -2);
            } else {
                node = document.createElement('em');
                node.textContent = token.slice(1, -1);
            }
            parent.appendChild(node);
            last = pattern.lastIndex;
        }

        if (last < text.length) {
            parent.appendChild(document.createTextNode(text.slice(last)));
        }
    }

    function normalizeCollapsedMarkdownTables(markdown) {
        var text = String(markdown || '').replace(/\r\n/g, '\n');
        var lines = text.split('\n');

        return lines.map(function (line) {
            // Some chat-completions models occasionally serialize a complete
            // Markdown table as one physical line:
            // | h1 | h2 | |---|---| | a | b |
            // Only repair lines that contain a Markdown separator row so normal
            // prose containing pipes is left untouched.
            if (
                line.indexOf('|') !== -1
                && /\|\s+\|\s*:?-{3,}/.test(line)
                && /\|\s+\|/.test(line)
            ) {
                return line.replace(/\|\s+\|/g, '|\n|');
            }
            return line;
        }).join('\n');
    }

    function renderMarkdown(container, markdown) {
        container.replaceChildren();
        var lines = normalizeCollapsedMarkdownTables(markdown).split('\n');
        var currentList = null;
        var currentListType = '';
        var paragraph = [];

        function flushParagraph() {
            if (paragraph.length === 0) { return; }
            var p = document.createElement('p');
            appendInline(p, paragraph.join(' '));
            container.appendChild(p);
            paragraph = [];
        }

        function closeList() {
            currentList = null;
            currentListType = '';
        }

        function splitTableRow(line) {
            var text = String(line || '').trim();
            if (text.startsWith('|')) { text = text.slice(1); }
            if (text.endsWith('|')) { text = text.slice(0, -1); }
            return text.split('|').map(function (cell) { return cell.trim(); });
        }

        function isTableSeparator(line) {
            return /^\|?\s*:?-{3,}:?\s*(?:\|\s*:?-{3,}:?\s*)+\|?$/.test(String(line || '').trim());
        }

        var index = 0;
        while (index < lines.length) {
            var line = lines[index].trim();

            if (line === '') {
                flushParagraph();
                closeList();
                index += 1;
                continue;
            }

            if (
                index + 1 < lines.length
                && line.indexOf('|') !== -1
                && isTableSeparator(lines[index + 1])
            ) {
                flushParagraph();
                closeList();

                var headers = splitTableRow(line);
                var wrap = document.createElement('div');
                wrap.className = 'sunaqfind-markdown-table-wrap';
                var table = document.createElement('table');
                table.className = 'sunaqfind-markdown-table';
                var thead = document.createElement('thead');
                var headRow = document.createElement('tr');

                headers.forEach(function (header) {
                    var th = document.createElement('th');
                    appendInline(th, header);
                    headRow.appendChild(th);
                });
                thead.appendChild(headRow);
                table.appendChild(thead);

                var tbody = document.createElement('tbody');
                index += 2;
                while (index < lines.length) {
                    var rowLine = lines[index].trim();
                    if (rowLine === '' || rowLine.indexOf('|') === -1) {
                        break;
                    }
                    var cells = splitTableRow(rowLine);
                    var tr = document.createElement('tr');
                    headers.forEach(function (_header, column) {
                        var td = document.createElement('td');
                        appendInline(td, cells[column] || '');
                        tr.appendChild(td);
                    });
                    tbody.appendChild(tr);
                    index += 1;
                }
                table.appendChild(tbody);
                wrap.appendChild(table);
                container.appendChild(wrap);
                continue;
            }

            var heading = line.match(/^(#{1,4})\s+(.+)$/);
            if (heading) {
                flushParagraph();
                closeList();
                var level = Math.min(5, heading[1].length + 2);
                var h = document.createElement('h' + level);
                appendInline(h, heading[2]);
                container.appendChild(h);
                index += 1;
                continue;
            }

            var unordered = line.match(/^[-*]\s+(.+)$/);
            var ordered = line.match(/^\d+[.)]\s+(.+)$/);
            if (unordered || ordered) {
                flushParagraph();
                var type = ordered ? 'ol' : 'ul';
                if (!currentList || currentListType !== type) {
                    closeList();
                    currentList = document.createElement(type);
                    currentListType = type;
                    container.appendChild(currentList);
                }
                var li = document.createElement('li');
                var itemText = (ordered || unordered)[1];
                var alternativePrefix = t('sunaqfind', 'Possible alternative search: ');
                if (itemText.startsWith(alternativePrefix)) {
                    var alternativeQuery = itemText.slice(alternativePrefix.length).trim();
                    li.className = 'sunaqfind-alternative-item';
                    li.appendChild(document.createTextNode(alternativePrefix));

                    var alternativeText = document.createElement('span');
                    alternativeText.className = 'sunaqfind-alternative-query';
                    alternativeText.textContent = alternativeQuery;
                    li.appendChild(alternativeText);
                    li.appendChild(document.createTextNode(' '));

                    var alternativeButton = document.createElement('button');
                    alternativeButton.type = 'button';
                    alternativeButton.className = 'sunaqfind-alternative-search';
                    alternativeButton.textContent = t('sunaqfind', 'Search');
                    alternativeButton.title = t('sunaqfind', 'Start alternative search: ') + alternativeQuery;
                    alternativeButton.addEventListener('click', function () {
                        var input = document.getElementById('sunaqfind-query');
                        if (input) {
                            input.value = alternativeQuery;
                        }
                        runSearch();
                    });
                    li.appendChild(alternativeButton);
                } else {
                    appendInline(li, itemText);
                }
                currentList.appendChild(li);
                index += 1;
                continue;
            }

            if (line.startsWith('> ')) {
                flushParagraph();
                closeList();
                var quote = document.createElement('blockquote');
                appendInline(quote, line.slice(2));
                container.appendChild(quote);
                index += 1;
                continue;
            }

            closeList();
            paragraph.push(line);
            index += 1;
        }

        flushParagraph();
    }

    function renderSources(sources) {
        var list = document.getElementById('sunaqfind-sources');
        if (!list) { return; }

        list.replaceChildren();
        (sources || []).forEach(function (source) {
            var item = document.createElement('li');
            item.className = 'sunaqfind-source';

            var link = document.createElement(source.url ? 'a' : 'span');
            link.className = 'sunaqfind-source-title';
            link.textContent = '[' + source.index + '] ' + source.name;
            if (source.url) {
                link.href = source.url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
            }
            item.appendChild(link);

            if (source.snippet || source.path) {
                var disclosure = createDisclosure(t('sunaqfind', 'Details'), 'sunaqfind-source-details-box');

                if (source.snippet) {
                    var snippet = document.createElement('p');
                    snippet.className = 'sunaqfind-source-snippet';
                    snippet.textContent = source.snippet;
                    disclosure.panel.appendChild(snippet);
                }

                if (source.path) {
                    var path = document.createElement('div');
                    path.className = 'sunaqfind-source-path';
                    path.textContent = source.path;
                    disclosure.panel.appendChild(path);
                }

                item.appendChild(disclosure.wrapper);
            }

            list.appendChild(item);
        });

        show('sunaqfind-sources-card', (sources || []).length > 0);
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
            return data.result;
        });
    }

    function setLoadingText(text) {
        var loading = document.getElementById('sunaqfind-loading');
        if (!loading) { return; }
        var labels = loading.getElementsByTagName('span');
        if (labels.length > 1) {
            labels[labels.length - 1].textContent = text || t('sunaqfind', 'Searching …');
        }
    }

    function setLoading(loading) {
        var button = document.getElementById('sunaqfind-search');
        if (button) {
            button.disabled = loading;
        }
        show('sunaqfind-loading', loading);
    }

    function runSearch() {
        var input = document.getElementById('sunaqfind-query');
        var query = input ? input.value.trim() : '';
        if (!query) { return; }

        var answerTitle = document.getElementById('sunaqfind-answer-title');
        if (answerTitle) {
            answerTitle.textContent = t('sunaqfind', 'Answer');
        }
        setLoadingText(t('sunaqfind', 'Searching …'));
        setLoading(true);
        setText('sunaqfind-error', '');
        setText('sunaqfind-analysis', '');
        show('sunaqfind-answer-card', false);
        show('sunaqfind-sources-card', false);
        show('sunaqfind-analysis-card', false);
        renderSearchStrategy('');
        renderSources([]);

        fetch(appUrl('/search'), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: JSON.stringify({query: query})
        }).then(decodeResponse).then(function (result) {
            var answer = document.getElementById('sunaqfind-answer');
            if (answer) {
                renderMarkdown(answer, result.answer || '');
            }
            show('sunaqfind-answer-card', true);
            renderSearchStrategy(result.search_strategy || '');
            renderSources(result.sources || []);

            if (result.analysis_enabled) {
                var analysis = {
                    query_analysis: result.query_analysis || {},
                    retrieval: result.retrieval || {},
                    answer_error: !!result.answer_error
                };
                if (result.answer_error_detail) {
                    analysis.answer_error_detail = String(result.answer_error_detail);
                }
                setText('sunaqfind-analysis', JSON.stringify(analysis, null, 2));
                show('sunaqfind-analysis-card', true);
            }
        }).catch(function (error) {
            setText('sunaqfind-error', error.message);
        }).finally(function () {
            setLoading(false);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('sunaqfind-form');
        var input = document.getElementById('sunaqfind-query');

        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                runSearch();
            });
        }

        if (input) {
            var params = new URLSearchParams(window.location.search);
            var query = params.get('q');
            if (query) {
                input.value = query;
                if (params.get('run') === '1') {
                    window.setTimeout(runSearch, 0);
                }
            }
            input.focus();
        }
    });
}());
