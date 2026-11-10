<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Presentation\Copilot;

final class OpenApiDocumentFactory
{
    /** @return array<string,mixed> */
    public function create(string $serverUrl): array
    {
        $periodSchema = [
            'type' => 'object',
            'properties' => [
                'date_from' => ['type' => 'string', 'format' => 'date', 'description' => 'Inclusive start date.'],
                'date_to' => ['type' => 'string', 'format' => 'date', 'description' => 'Inclusive end date.'],
                'entity_id' => ['type' => 'integer', 'minimum' => 1],
                'category_id' => ['type' => 'integer', 'minimum' => 1],
                'type' => ['type' => 'string', 'description' => 'Incident or Request.'],
            ],
        ];

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'UXConnector Copilot Gateway',
                'version' => '2.18.6',
                'description' =>
                    'Read-only semantic and analytical ITSM tools for Microsoft Copilot Studio backed by GLPI.',
            ],
            'servers' => [['url' => rtrim($serverUrl, '/')]],
            'security' => [['UxconnectorApiKey' => []]],
            'components' => [
                'securitySchemes' => [
                    'UxconnectorApiKey' => ['type' => 'apiKey', 'in' => 'header',
                        'name' => 'X-UXConnector-Copilot-Key'],
                ],
                'schemas' => ['AnalyticsPeriod' => $periodSchema],
            ],
            'paths' => [
                '/plugins/uxconnector/front/copilot-capabilities.php' => [
                    'get' => ['operationId' => 'getCapabilities',
                        'summary' => 'Discover the GLPI ITSM capabilities exposed by UXConnector.', 'responses' => [
                            '200' => ['description' => 'Capabilities']]],
                ],
                '/plugins/uxconnector/front/copilot-search.php' => [
                    'post' => [
                        'operationId' => 'searchTickets',
                        'summary' => 'Search GLPI tickets by keyword, period and status.',
                        'description' => 'Use when the user asks to find or list tickets. Returns enriched canonical '
                            . 'tickets including requester, assigned groups/users and linked assets when '
                            . 'available.',
                        'requestBody' => $this->jsonBody([
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string'],
                                'date_from' => ['type' => 'string', 'format' => 'date'],
                                'date_to' => ['type' => 'string', 'format' => 'date'],
                                'status' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                            ],
                        ]),
                        'responses' => ['200' => ['description' => 'Canonical ticket search results']],
                    ],
                ],
                '/plugins/uxconnector/front/copilot-context.php' => [
                    'get' => [
                        'operationId' => 'getTicketContext',
                        'summary' => 'Get the complete operational context of one GLPI ticket.',
                        'description' => 'Use to summarize or investigate one ticket. Returns actors by name, groups, '
                            . 'canonical timeline, human-readable durations, final solution, tasks, '
                            . 'validations, knowledge and assets.',
                        'parameters' => [['name' => 'id', 'in' => 'query', 'required' => true, 'schema' => [
                                    'type' => 'integer', 'minimum' => 1]]],
                        'responses' => ['200' => ['description' => 'Complete ticket context']],
                    ],
                ],
                '/plugins/uxconnector/front/copilot-ticket-summary.php' => [
                    'post' => $this->analyticsOperation(
                        'analyzeTicketVolume',
                        'Summarize ticket activity for a period.',
                        'Use for ticket volume, incident/request split, backlog, pending tickets and average '
                        . 'ownership/resolution times.',
                        $periodSchema
                    ),
                ],
                '/plugins/uxconnector/front/copilot-ticket-trends.php' => [
                    'post' => $this->analyticsOperation(
                        'analyzeTicketTrends',
                        'Analyze ticket trends over time.',
                        'Use when the user asks how ticket volumes evolve by day, week or month.',
                        [
                        'allOf' => [
                            $periodSchema,
                            ['type' => 'object', 'properties' => ['bucket' => ['type' => 'string', 'enum' => ['day',
                                            'week', 'month'], 'default' => 'day']]],
                        ],
                        ]
                    ),
                ],
                '/plugins/uxconnector/front/copilot-categories.php' => [
                    'post' => $this->analyticsOperation(
                        'analyzeCategories',
                        'Analyze ITIL categories.',
                        'Use for top categories, incident/request mix and average resolution time by category.',
                        [
                        'allOf' => [$periodSchema, ['type' => 'object', 'properties' => [
                                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50,
                                        'default' => 15]]]],
                        ]
                    ),
                ],
                '/plugins/uxconnector/front/copilot-sla.php' => [
                    'post' => $this->analyticsOperation(
                        'analyzeSlaPerformance',
                        'Analyze SLA performance and breaches.',
                        'Use for TTO/TTR compliance or breached tickets. Only tickets with parseable GLPI SLA '
                        . 'deadlines are counted in compliance rates.',
                        [
                        'allOf' => [$periodSchema, ['type' => 'object', 'properties' => [
                                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100,
                                        'default' => 25]]]],
                        ]
                    ),
                ],
                '/plugins/uxconnector/front/copilot-sla-breaches.php' => [
                    'post' => $this->analyticsOperation(
                        'analyzeSlaBreaches',
                        'List SLA breaches.',
                        'Use when the user asks which tickets breached TTO or TTR. Returns only evaluable '
                        . 'GLPI deadline breaches.',
                        [
                        'allOf' => [$periodSchema, ['type' => 'object', 'properties' => [
                                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100,
                                        'default' => 25]]]],
                        ]
                    ),
                ],
                '/plugins/uxconnector/front/copilot-groups.php' => [
                    'post' => $this->analyticsOperation(
                        'analyzeGroupPerformance',
                        'Analyze support group activity.',
                        'Use to compare assigned ticket volumes, active backlog and average resolution time '
                        . 'between support groups.',
                        $periodSchema
                    ),
                ],
                '/plugins/uxconnector/front/copilot-technicians.php' => [
                    'post' => $this->analyticsOperation(
                        'analyzeTechnicianWorkload',
                        'Analyze technician workload and work time.',
                        'Use to compare assigned tickets, distinct tickets where a technician performed '
                        . 'tasks, backlog, task count and cumulative task action time. No account is excluded '
                        . 'automatically.',
                        [
                        'allOf' => [
                            $periodSchema,
                            ['type' => 'object', 'properties' => [
                                'exclude_user_ids' => ['type' => 'array', 'items' => ['type' => 'integer',
                                        'minimum' => 1],
                                    'description' =>
                                    'Optional GLPI user IDs to exclude, for example technical accounts.'],
                            ]],
                        ],
                        ]
                    ),
                ],
                '/plugins/uxconnector/front/copilot-incidentology-groups.php' => [
                    'post' => $this->analyticsOperation(
                        'listIncidentologyGroups',
                        'Resolve GLPI technical support groups by name.',
                        'Use before analyzeIncidentologyGlobal or analyzeIncidentologyTypologies when the '
                            . 'user provides a support-group name instead of a GLPI group ID. Pass the '
                            . 'user-facing group name in query. Prefer one unique exact match. If several exact '
                            . 'matches exist, ask the user to disambiguate. Do not ask the user for an internal '
                            . 'GLPI group ID when this resolver can be used.',
                        [
                            'type' => 'object',
                            'properties' => [
                                'query' => [
                                    'type' => 'string',
                                    'description' => 'Support-group name or partial name, for example Support N1.',
                                ],
                                'limit' => [
                                    'type' => 'integer',
                                    'minimum' => 1,
                                    'maximum' => 100,
                                    'default' => 50,
                                ],
                            ],
                        ],
                    ),
                ],
                '/plugins/uxconnector/front/copilot-incidentology-global.php' => [
                    'post' => $this->analyticsOperation(
                        'analyzeIncidentologyGlobal',
                        'Analyze one support group with Incidentologue semantics.',
                        'Use for a complete incidentology analysis of one GLPI technical support group '
                            . 'over a period: ticket volume, internal-resolution autonomy, Pareto categories '
                            . 'and subcategories, channels, locations, resolution duration, temporal '
                            . 'distribution, IG/IP/IC consolidation when IGManager is available, UXKnowDash '
                            . 'support-level and target references when available, and explicit '
                            . 'data-quality/provenance indicators. The group scope is reconstructed from the '
                            . 'initial technical assignment group. Do not interpret resolutionRate as SLA '
                            . 'compliance or UXKnowDash FCR.',
                        [
                            'allOf' => [
                                $periodSchema,
                                [
                                    'type' => 'object',
                                    'required' => ['group_id'],
                                    'properties' => [
                                        'group_id' => [
                                            'type' => 'integer',
                                            'minimum' => 1,
                                            'description' =>
                                                'GLPI technical support group ID. Incidentologue scope uses '
                                                . 'the resolved initial technical assignment group.',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ),
                ],

                '/plugins/uxconnector/front/copilot-incidentology-typologies.php' => [
                    'post' => $this->analyticsOperation(
                        'analyzeIncidentologyTypologies',
                        'Analyze one support group by validated Incidentologue typologies.',
                        'Use after resolving the GLPI technical support group. First call without '
                            . 'typology_mapping to retrieve categoriesToMap. Propose a category-to-typology '
                            . 'mapping only where classification is confident, then ask the user to validate or '
                            . 'correct it. Call again with the validated mapping to obtain ticket volume, '
                            . 'Pareto, internal-resolution autonomy, subcategories, channels, locations, '
                            . 'resolution duration, IGManager and UXKnowDash enrichments by typology. This tool '
                            . 'classifies GLPI ITIL categories into the qualitative Incidentologue typologies '
                            . 'Accès, Bureautique, Matériel, Réseau, Téléphonie and Application Métiers. It is '
                            . 'not semantic classification of ticket descriptions. Categories omitted from the '
                            . 'supplied mapping are assigned to Non classifiée and reported in '
                            . 'unmappedCategories.',
                        [
                            'type' => 'object',
                            'required' => ['group_id'],
                            'properties' => [
                                'group_id' => [
                                    'type' => 'integer',
                                    'minimum' => 1,
                                    'description' =>
                                        'GLPI technical support group ID. Resolve a user-facing group name '
                                        . 'with listIncidentologyGroups first.',
                                ],
                                'date_from' => [
                                    'type' => 'string',
                                    'format' => 'date',
                                    'description' => 'Inclusive start date, YYYY-MM-DD.',
                                ],
                                'date_to' => [
                                    'type' => 'string',
                                    'format' => 'date',
                                    'description' => 'Inclusive end date, YYYY-MM-DD.',
                                ],
                                'entity_id' => [
                                    'type' => 'integer',
                                    'minimum' => 0,
                                    'description' => 'Optional GLPI entity ID.',
                                ],
                                'category_id' => [
                                    'type' => 'integer',
                                    'minimum' => 0,
                                    'description' => 'Optional GLPI ITIL category ID filter.',
                                ],
                                'type' => [
                                    'type' => 'string',
                                    'description' => 'Optional ticket type filter accepted by UXConnector analytics.',
                                ],
                                'typology_mapping' => [
                                    'type' => 'array',
                                    'description' =>
                                        'Optional validated category-to-typology mapping. Omit on the first '
                                        . 'call to receive categoriesToMap. Categories omitted from a supplied '
                                        . 'mapping fall back to Non classifiée.',
                                    'items' => [
                                        'type' => 'object',
                                        'required' => ['category', 'typology'],
                                        'properties' => [
                                            'category' => [
                                                'type' => 'string',
                                                'description' =>
                                                    'Incidentologue category label returned in categoriesToMap.',
                                            ],
                                            'typology' => [
                                                'type' => 'string',
                                                'enum' => [
                                                    'Accès',
                                                    'Bureautique',
                                                    'Matériel',
                                                    'Réseau',
                                                    'Téléphonie',
                                                    'Application Métiers',
                                                    'Non classifiée',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                                'typology_mapping_json' => [
                                    'type' => 'string',
                                    'description' =>
                                        'Alternative JSON-encoded mapping accepted by the backend. Prefer '
                                        . 'typology_mapping when possible.',
                                ],
                            ],
                        ],
                    ),
                ],
                '/plugins/uxconnector/front/copilot-semantic-prepare.php' => [
                    'post' => $this->analyticsOperation(
                        'prepareSemanticTicketClassification',
                        'Prepare GLPI tickets for enterprise semantic classification.',
                        'Internal Power Platform orchestration step. Returns cleaned GLPI tickets, '
                            . 'classifier_version, confidence_threshold and scope_fingerprint. Do not answer '
                            . 'the end user from this intermediate result.',
                        [
                            'type' => 'object',
                            'properties' => [
                                'date_from' => ['type' => 'string', 'format' => 'date'],
                                'date_to' => ['type' => 'string', 'format' => 'date'],
                                'entity_id' => ['type' => 'integer', 'minimum' => 0],
                                'category_id' => ['type' => 'integer', 'minimum' => 0],
                                'type' => ['type' => 'string'],
                                'max_tickets' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000,
                                    'default' => 500],
                                'result_limit' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 500,
                                    'default' => 100],
                            ],
                        ],
                    ),
                ],

                '/plugins/uxconnector/front/copilot-semantic-finalize.php' => [
                    'post' => $this->analyticsOperation(
                        'finalizeSemanticTicketClassification',
                        'Validate and aggregate enterprise LLM classifications.',
                        'Internal Power Platform orchestration step. Pass the same scope, '
                            . 'classifier_version and scope_fingerprint returned by prepare plus '
                            . 'classifications from the enterprise prompt. UXConnector validates results, '
                            . 'applies its server threshold and calculates final metrics. Do not recalculate '
                            . 'returned metrics.',
                        [
                            'type' => 'object',
                            'required' => ['classifier_version', 'scope_fingerprint', 'classifications'],
                            'properties' => [
                                'date_from' => ['type' => 'string', 'format' => 'date'],
                                'date_to' => ['type' => 'string', 'format' => 'date'],
                                'entity_id' => ['type' => 'integer', 'minimum' => 0],
                                'category_id' => ['type' => 'integer', 'minimum' => 0],
                                'type' => ['type' => 'string'],
                                'max_tickets' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000,
                                    'default' => 500],
                                'result_limit' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 500,
                                    'default' => 100],
                                'classifier_version' => ['type' => 'string', 'enum' => ['1.0.0']],
                                'scope_fingerprint' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                                'classifications' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'required' => ['ticket_id', 'class', 'confidence', 'reason_code'],
                                        'properties' => [
                                            'ticket_id' => ['type' => 'integer', 'minimum' => 1],
                                            'class' => [
                                                'type' => 'string',
                                                'enum' => [
                                                    'technical_incident',
                                                    'user_assistance',
                                                    'access_request',
                                                    'usage_error',
                                                    'other',
                                                    'uncertain',
                                                ],
                                            ],
                                            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                                            'reason_code' => ['type' => 'string'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ),
                ],
                '/plugins/uxconnector/front/copilot-semantic-classification.php' => [
                    'post' => $this->analyticsOperation(
                        'analyzeSemanticTicketClassification',
                        'Classify GLPI tickets semantically from their title and description.',
                        'Use when the user asks what tickets are really about based on their textual '
                            . 'content, whether GLPI-declared Incidents look like user assistance, access '
                            . 'requests, usage errors or technical incidents, or when comparing the registered '
                            . 'ITIL classification with a computed semantic classification. UXConnector keeps '
                            . 'declared GLPI type, registered ITIL category and computed semantic class '
                            . 'separate. Percentages, coverage and misclassification ratios returned by this '
                            . 'tool are calculated by UXConnector and must not be recalculated by Copilot. If '
                            . 'scope.scope_complete is false, do not present the result as exhaustive. V1 does '
                            . 'not expose a support-group filter because historical group attribution has not '
                            . 'yet been shared with this semantic source.',
                        [
                            'type' => 'object',
                            'properties' => [
                                'date_from' => [
                                    'type' => 'string',
                                    'format' => 'date',
                                    'description' => 'Inclusive GLPI ticket creation start date, YYYY-MM-DD.',
                                ],
                                'date_to' => [
                                    'type' => 'string',
                                    'format' => 'date',
                                    'description' => 'Inclusive GLPI ticket creation end date, YYYY-MM-DD.',
                                ],
                                'entity_id' => [
                                    'type' => 'integer',
                                    'minimum' => 0,
                                    'description' => 'Optional GLPI entity ID filter.',
                                ],
                                'category_id' => [
                                    'type' => 'integer',
                                    'minimum' => 0,
                                    'description' => 'Optional GLPI ITIL category ID filter.',
                                ],
                                'type' => [
                                    'type' => 'string',
                                    'description' =>
                                        'Optional GLPI declared ticket-type filter. Accepted backend values '
                                        . 'include Incident, Request, Demande, 1 and 2.',
                                ],
                                'max_tickets' => [
                                    'type' => 'integer',
                                    'minimum' => 1,
                                    'maximum' => 10000,
                                    'default' => 500,
                                    'description' =>
                                        'Maximum matching tickets to classify synchronously. If the matching '
                                        . 'scope is larger, scope.scope_complete is false and '
                                        . 'dataQuality.selection_truncated is true.',
                                ],
                                'result_limit' => [
                                    'type' => 'integer',
                                    'minimum' => 0,
                                    'maximum' => 500,
                                    'default' => 100,
                                    'description' =>
                                        'Maximum per-ticket classification detail rows returned. Aggregate '
                                        . 'metrics still use the complete selected sample.',
                                ],
                            ],
                        ],
                    ),
                ],
                '/plugins/uxconnector/front/copilot-compare.php' => [
                    'post' => $this->analyticsOperation(
                        'comparePeriods',
                        'Compare two ITSM periods.',
                        'Use when the user asks to compare two periods and explain differences in volume, '
                        . 'backlog and resolution metrics. The response flags partial periods and provides '
                        . 'normalized daily rates when raw totals are not directly comparable.',
                        [
                        'type' => 'object',
                        'required' => ['period_a', 'period_b'],
                        'properties' => [
                            'period_a' => $periodSchema,
                            'period_b' => $periodSchema,
                        ],
                        ]
                    ),
                ],
            ],
        ];
    }

    /**
    * @param array<string, mixed> $schema
    *
    * @return array<string, mixed>
    */
    private function analyticsOperation(string $operationId, string $summary, string $description, array $schema): array
    {
        return [
            'operationId' => $operationId,
            'summary' => $summary,
            'description' => $description,
            'requestBody' => $this->jsonBody($schema),
            'responses' => ['200' => ['description' => 'ITSM analytical result']],
        ];
    }

    /**
    * @param array<string, mixed> $schema
    *
    * @return array<string, mixed>
    */
    private function jsonBody(array $schema): array
    {
        return ['required' => true, 'content' => ['application/json' => ['schema' => $schema]]];
    }
}
