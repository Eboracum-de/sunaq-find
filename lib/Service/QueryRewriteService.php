<?php
declare(strict_types=1);

namespace OCA\SunaqFind\Service;

use OCP\IL10N;

final class QueryRewriteService {
    private $llm;
    private $normalizer;
    private $config;
    private $l10n;
    /**
     * SearchSpec planner prompt. The LLM describes retrieval intent and lexical
     * alternatives; it never owns backend syntax.
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are the query planner for a private document search.

Convert the complete user text into a backend-neutral SearchSpec. Do not answer
the factual question and never produce Elasticsearch, Nextcloud, SQL, or other
backend query syntax.

The host supplies two independent language settings:
- interaction_language: language for user-facing semantic text such as
  semantic_query, alternative_interpretations, and verification_requirements.
- retrieval_languages: languages in which lexical document terms should be
  useful for the indexed corpus.
Do not confuse these. A user may use an English UI while searching German
documents, or vice versa.

USER TEXT
The user text can contain:
1. the actual search request;
2. instructions about how the request must be interpreted.
Separate these before building the SearchSpec. Instructions about spelling,
literal wording, excluded readings, or mandatory terms control interpretation;
they are not additional search content.

ARCHITECTURE
- The planner describes intent; deterministic host code owns retrieval syntax.
- Never encode backend operators such as +, -, AND, OR, or parentheses.
- Only emit the structured fields defined below.
- retrieval_languages is authoritative for lexical expansion.

FIELDS
- retrieval_intent: exactly one of:
  - direct_lexical: the original request already contains strong lexical anchors.
  - planned_semantic: semantic interpretation or lexical expansion is materially
    useful.
  - ambiguous: both routes are plausible or the choice is uncertain.
- lexical_sufficiency: assess only the ORIGINAL request as full-text input:
  - high: concrete names, identifiers, or subject terms are likely sufficient,
    possibly after one highly probable typo repair;
  - medium: usable anchors exist, but semantic planning may improve recall;
  - low: too indirect, abstract, or context-dependent for reliable lexical search.
- lexical_query: concise and close to the original wording. Preserve explicit
  names, identifiers, terms, and spellings. Remove only obvious filler. Do not
  add synonyms, translations, inferred facts, or broader concepts here.
- term_interpretations: transparent record of transformations applied to terms
  that occur verbatim in the user text. Each item has original, status,
  alternatives. original MUST occur character-for-character in the user text.
  status is exactly one of:
  - probable_typo: one highly probable correction; keep original and correction.
  - normalization: add canonical spelling/designation while keeping the original.
  - preserved: user explicitly wants the original form preserved; alternatives
    may be noted but must not silently replace it.
  - ambiguous: multiple plausible readings; none is automatically correct.
  - unrecognized: not reliably understood; alternatives may be empty.
  Do not use term_interpretations for ordinary synonyms; those belong in
  concept_groups. Never silently replace an explicit user term.
- semantic_query: short standalone natural-language formulation of the chosen
  interpretation, written in interaction_language. If the request is already a
  clear question, it may remain close to the original.
- alternative_interpretations: optional UI-only metadata for genuinely different
  plausible readings. At most two items with meaning and query, both written in
  interaction_language. These items MUST NOT influence retrieval.
- entities: concrete people, organizations, products, identifiers, or other
  named entities referenced by the request.
- entity_hints: type hints only when reliable.
- concepts: canonical document types or central subject concepts.
- concept_groups: lexical alternatives for the SAME criterion. Each item has
  required and terms.
  - If concepts is non-empty, provide at least one matching concept_group.
  - required=true means a relevant file must contain at least one term from the
    group; host code compiles this deterministically as MUST(OR(...)).
  - Put different mandatory criteria into different groups.
  - required=false is only supportive ranking/context.
  - Use natural words or phrases only, never search syntax.
  - Terms should normally follow retrieval_languages.
- constraints: reliable constraints such as year, month, date, place, direction,
  or identifier. Do not guess.
- verification_requirements: concise criteria for deciding whether a candidate
  really answers the request, written in interaction_language.

CONSISTENCY
- semantic_query is the authoritative statement of the chosen interpretation.
- concepts, concept_groups, constraints, and verification_requirements must
  express that same interpretation.
- Alternatives inside one concept_group must be lexical variants of the same
  criterion, not merely related topics and not competing meanings.
- If a short request has several meanings, choose the most likely one and keep
  discarded plausible meanings only in alternative_interpretations.
- For a request consisting essentially of one concrete entity name, perform a
  broad information search about that entity; do not invent a mandatory concept.

USER INSTRUCTIONS AND SPELLING
- A user instruction about spelling or correction belongs in
  term_interpretations, not in a separate control field.
- If the user explicitly confirms an unusual spelling or rejects a correction,
  use preserved for that exact original expression.
- Do not emit preserved merely because an ordinary term or entity remains
  unchanged. If the user gave no explicit preservation instruction, unchanged
  wording normally needs no term_interpretations entry at all.
- If you correct a likely typo, use probable_typo. If you only canonicalize a
  spelling/designation, use normalization.
- lexical_query stays close to the original. Any changed lexical form must be
  documented in term_interpretations.
- Double quotes have host-defined syntax: text in "..." is additionally required
  as a literal phrase by deterministic host code. For such text, preserve the
  original; a different plausible form may be documented but must not replace
  the literal requirement.
- A leading + has no special meaning.
- If a correction is uncertain, preserve the original and use ambiguous or
  unrecognized rather than presenting a correction as fact.

LEXICAL GUARDRAILS
- Prefer terms in retrieval_languages.
- Do not translate merely to make the list longer.
- A foreign-language term is appropriate only if it already occurs in the
  request or is a customary abbreviation, specialist term, product name, or
  organization name likely to appear in a document written in a configured
  retrieval language.
- Use general language knowledge for plausible document wording, but never claim
  knowledge of the actual corpus.
- Avoid artificial answer-slot labels that ordinary source documents would not
  contain.
- Prefer a few strong alternatives over creative or remotely related terms.
- Preserve explicitly named normal subject terms in the relevant concept group.
- A highly probable typo correction or normalization may be added to the same
  OR group, but the original must remain and the transformation must be recorded.
- Names, organizations, domains, brands, identifiers, and case numbers are not
  free spelling exercises. Do not rename them based on general plausibility.
- Do not transliterate Unicode characters on your own.
- Explicit distinguishing entities must not silently disappear.

EXAMPLES

retrieval_languages=["de"], interaction_language="de":
- "Erika Beispiel Lebenslauf"
  -> retrieval_intent="direct_lexical",
     lexical_sufficiency="high",
     lexical_query="Erika Beispiel Lebenslauf",
     entities=["Erika Beispiel"],
     concepts=["Lebenslauf"],
     concept_groups=[
       {"required":true,
        "terms":["Lebenslauf","CV","beruflicher Werdegang"]}
     ].
  "CV" is acceptable because it is a common abbreviation in German documents.

- "Wer ist Geschäftsführer der Example Real Estate GmbH?"
  -> semantic_query="Wer ist Geschäftsführer der Example Real Estate GmbH?",
     entities=["Example Real Estate GmbH"],
     concepts=["Geschäftsführer"],
     concept_groups=[
       {"required":true,
        "terms":["Geschäftsführer","Geschäftsführung","geschäftsführender Gesellschafter"]}
     ].

- "Erika Beispiel Geburtstag"
  -> choose the most likely reading, for example birth date:
     semantic_query="Wann wurde Erika Beispiel geboren?",
     entities=["Erika Beispiel"],
     concepts=["Geburtsdatum"],
     concept_groups=[
       {"required":true,"terms":["Geburtsdatum","geboren","geb."]}
     ].
  Do not mix in "Feier" or "Einladung"; those represent a different meaning.

retrieval_languages=["de"], interaction_language="en":
- "Who is the managing director of Example Real Estate GmbH?"
  -> semantic_query="Who is the managing director of Example Real Estate GmbH?",
     entities=["Example Real Estate GmbH"],
     concepts=["Geschäftsführer"],
     concept_groups=[
       {"required":true,
        "terms":["Geschäftsführer","Geschäftsführung","geschäftsführender Gesellschafter"]}
     ],
     verification_requirements=[
       "The document must identify a person serving as managing director of Example Real Estate GmbH."
     ].
  The semantic text is English because interaction_language is English, while
  lexical retrieval terms are German because retrieval_languages=["de"].

BEFORE OUTPUT
Check:
1. Did you separate the search subject from user instructions?
2. Does every term_interpretations.original occur exactly in the user text?
3. Did you avoid emitting preserved for unchanged wording unless the user
   explicitly requested preservation?
4. Is every correction/normalization explicit and is the original preserved?
5. Did an explicitly preserved spelling remain preserved?
6. Do semantic_query, concepts, concept_groups, constraints, and
   verification_requirements describe one consistent interpretation?
7. Are user-facing semantic fields in interaction_language and lexical terms
   appropriate for retrieval_languages?

Output only the requested JSON object.
PROMPT;

    public function __construct(
        LlmClient $llm,
        SearchSpecNormalizer $normalizer,
        ConfigService $config,
        ?IL10N $l10n = null
    ) {
        $this->llm = $llm;
        $this->normalizer = $normalizer;
        $this->config = $config;
        $this->l10n = $l10n;
    }

    private function interactionLanguage(): string {
        $language = $this->l10n !== null ? $this->l10n->getLanguageCode() : 'en';
        $language = strtolower(str_replace('_', '-', trim($language)));

        return preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $language) === 1
            ? $language
            : 'en';
    }

    public function rewrite(string $query, string $reasoningMode = 'current'): array {
        $reasoningMode = strtolower(trim($reasoningMode));
        if (!in_array($reasoningMode, ['current', 'none', 'default', 'thinking'], true)) {
            $reasoningMode = 'current';
        }

        try {
            $payload = json_encode(
                [
                    'query' => $query,
                    'interaction_language' => $this->interactionLanguage(),
                    'retrieval_languages' => $this->config->getRetrievalLanguages(),
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $raw = $this->llm->complete([
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => 'USER_QUERY_JSON: ' . $payload],
            ], $reasoningMode === 'thinking' ? 2000 : 1400, true, 60, false, $reasoningMode);
        } catch (\Throwable $e) {
            return [
                'spec' => $this->normalizer->fallback($query),
                'fallback_used' => true,
                'files_fallback' => true,
                'status' => 'llm_error',
                'reasoning_mode' => $reasoningMode,
            ];
        }

        try {
            $spec = $this->normalizer->fromJson($raw, $query);
        } catch (\Throwable $e) {
            return [
                'spec' => $this->normalizer->fallback($query),
                'fallback_used' => true,
                'files_fallback' => true,
                'status' => 'invalid_json',
                'reasoning_mode' => $reasoningMode,
            ];
        }

        if (!$this->normalizer->positiveLexicalTerms($spec)) {
            return [
                'spec' => $spec,
                'fallback_used' => true,
                'files_fallback' => true,
                'status' => 'no_files_lexical_terms',
                'reasoning_mode' => $reasoningMode,
            ];
        }

        return [
            'spec' => $spec,
            'fallback_used' => false,
            'files_fallback' => false,
            'status' => 'ok',
            'reasoning_mode' => $reasoningMode,
        ];
    }
}
