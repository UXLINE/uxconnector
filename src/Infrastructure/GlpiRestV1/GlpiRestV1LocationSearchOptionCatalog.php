<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1;

use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpEngineInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\Http\HttpRequest;

/** Resolves GLPI 9/10/11 Location search options with typed metadata and unambiguous label fallbacks. */
final class GlpiRestV1LocationSearchOptionCatalog
{
    /** @var array<int,array<string,int>> */
    private array $cache = [];

    /** @var array<string,list<string>> */
    private const LABELS = [
        'source_id' => ['id'],
        'full_name' => ['nom complet', 'complete name'],
        'line_number' => ['plugins n de ligne numero de ligne', 'n de ligne numero de ligne', 'numero de ligne'],
        'caller_name' => ['plugins n de ligne utilisateur', 'n de ligne utilisateur',
            'plugins n de ligne nom de l appelant', 'n de ligne nom de l appelant'],
        'status' => ['plugins n de ligne statut', 'n de ligne statut'],
        'operator' => ['plugins n de ligne operateur', 'n de ligne operateur'],
        'billing_account' => ['plugins n de ligne compte facturant', 'n de ligne compte facturant'],
        'subscription' => ['plugins n de ligne abonnements', 'n de ligne abonnements', 'abonnement'],
        'inpark_entity' => ['plugins inpark entite', 'inpark entite'],
        'code_da' => ['plugins inpark code da', 'inpark code da'],
        'code_da_accounting' => ['plugins inpark code da comptable', 'inpark code da comptable'],
        'code_ets' => ['plugins inpark code ets', 'inpark code ets'],
        'code_db' => ['plugins inpark code db', 'inpark code db'],
        'code_na' => ['plugins inpark code na', 'inpark code na'],
        'code_nb' => ['plugins inpark code nb', 'inpark code nb'],
        'business_creation_date' => ['plugins n de ligne date de creation', 'n de ligne date de creation'],
        'suspension_date' => ['plugins n de ligne date de suspension', 'n de ligne date de suspension'],
        'termination_date' => ['plugins n de ligne date de resiliation', 'n de ligne date de resiliation'],
        'equipment' => ['plugins n de ligne equipements', 'n de ligne equipements', 'plugins n de ligne equipement',
            'n de ligne equipement'],
        'equipment_serial' => ['plugins n de ligne n de serie', 'n de ligne n de serie', 'numero de serie'],
        'delivery_date' => ['plugins n de ligne date de remise', 'n de ligne date de remise'],
        'equipment_value' => ['plugins n de ligne tarif equipements', 'n de ligne tarif equipements'],
        'code_rds' => ['plugins n de ligne code rds', 'n de ligne code rds'],
        'comment' => ['plugins n de ligne commentaires', 'n de ligne commentaires'],
        'puk' => ['plugins n de ligne code puk', 'n de ligne code puk', 'code puk'],
        'sim_number' => ['plugins n de ligne numero de sim', 'n de ligne numero de sim', 'plugins n de ligne n de sim',
            'n de ligne n de sim', 'n de sim', 'numero de sim', 'iccid'],
        'renewal_date' => ['plugins n de ligne date de renouvellement', 'n de ligne date de renouvellement'],
        'longitude' => ['plugins inpark longitude', 'inpark longitude'],
        'id_base_parcs' => ['plugins inpark idbaseparcs', 'inpark idbaseparcs'],
        'latitude' => ['plugins inpark latitude', 'inpark latitude'],
        'address' => ['plugins inpark adresse', 'inpark adresse'],
        'postcode' => ['plugins inpark code postal', 'inpark code postal'],
        'town' => ['plugins inpark ville', 'inpark ville'],
        'operation_start' => ['plugins inpark debut exploitation', 'inpark debut exploitation'],
        'site_code' => ['plugins inpark code site', 'inpark code site'],
    ];

    /**
     * Technical names used by Fields on GLPI 10. The source instance's numeric
     * Fields IDs are intentionally never used: they vary across installations.
     * Names are scoped to this legacy telecom adapter, not other GLPI sources.
     * These are identification hints only; the actual search option ID must
     * still be discovered through listSearchOptions/Location.
     *
     * @var array<string,string>
     */
    private const SOURCE_XML_NODES = [
        'line_number' => 'NUMRODELIGNEFIELD',
        'caller_name' => 'UTILISATEURFIELD',
        'status' => 'PLUGIN_FIELDS_STATUTFIELDDROPDOWNS_ID',
        'operator' => 'PLUGIN_FIELDS_OPRATEURFIELDDROPDOWNS_ID',
        'billing_account' => 'PLUGIN_FIELDS_COMPTEFACTURANTFIELDDROPDOWNS_ID',
        'subscription' => 'PLUGIN_FIELDS_ABONNEMENTFIELDDROPDOWNS_ID',
        'inpark_entity' => 'PLUGIN_FIELDS_ENTITFIELDDROPDOWNS_ID',
        'code_da' => 'CODEDAFIELD',
        'code_da_accounting' => 'CODEDACOMPTABLEFIELD',
        'code_ets' => 'CODEETFIELD',
        'code_db' => 'CODEDBFIELD',
        'code_na' => 'CODENAFIELD',
        'code_nb' => 'CODENBFIELD',
        'business_creation_date' => 'DATEDECRATIONFIELD',
        'suspension_date' => 'DATEDESUSPENSIONFIELD',
        'termination_date' => 'DATEDERSILIATIONFIELD',
        'equipment' => 'PLUGIN_FIELDS_EQUIPEMENTFIELDDROPDOWNS_ID',
        'equipment_serial' => 'NDESRIEFIELD',
        'delivery_date' => 'DATEDEREMISEFIELD',
        'equipment_value' => 'PLUGIN_FIELDS_TARIFQUIPEMENTFIELDDROPDOWNS_ID',
        'code_rds' => 'CODERDFIELD',
        'comment' => 'COMMENTAIREFIELD',
        'puk' => 'CODEPUKFIELD',
        'sim_number' => 'NUMRODESIMFIELD',
        'renewal_date' => 'DATEDERENOUVELLEMENTFIELD',
        'longitude' => 'LONGITUDEFIELD',
        'id_base_parcs' => 'IDBASEPARCFIELD',
        'latitude' => 'LATITUDEFIELD',
        'address' => 'ADRESSEFIELD',
        'postcode' => 'CODEPOSTALFIELD',
        'town' => 'VILLEFIELD',
        'operation_start' => 'DBUTEXPLOITATIONFIELD',
        'site_code' => 'CODESITEFIELD',
    ];

    /** @var array<string,string> Exact labels from the source Fields configuration. */
    private const SOURCE_LABELS = [
        'line_number' => 'Numéro de ligne',
        'caller_name' => 'Utilisateur',
        'status' => 'Statut',
        'operator' => 'Opérateur',
        'billing_account' => 'Compte facturant',
        'subscription' => 'Abonnements',
        'inpark_entity' => 'Entité',
        'code_da' => 'Code DA',
        'code_da_accounting' => 'Code DA Comptable',
        'code_ets' => 'Code ETS',
        'code_db' => 'Code DB',
        'code_na' => 'Code NA',
        'code_nb' => 'Code NB',
        'business_creation_date' => 'Date de Création',
        'suspension_date' => 'Date de Suspension',
        'termination_date' => 'Date de Résiliation',
        'equipment' => 'Equipements',
        'equipment_serial' => 'N° de série',
        'delivery_date' => 'Date de remise',
        'equipment_value' => 'Tarif équipements',
        'code_rds' => 'Code RDS',
        'comment' => 'Commentaires',
        'puk' => 'Code PUK',
        'sim_number' => 'Numéro de SIM',
        'renewal_date' => 'Date de renouvellement',
        'longitude' => 'Longitude',
        'id_base_parcs' => 'IdBaseParcs',
        'latitude' => 'Latitude',
        'address' => 'Adresse',
        'postcode' => 'Code Postal',
        'town' => 'Ville',
        'operation_start' => 'Début Exploitation',
        'site_code' => 'Code Site',
    ];

    public function __construct(private readonly HttpEngineInterface $httpEngine)
    {
    }

    /** @return array<string,int> */
    public function resolve(AuthConfig $authConfig): array
    {
        $key = $authConfig->id()?->value() ?? spl_object_id($authConfig);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        return $this->cache[$key] = self::matchCatalog($this->fetchOptions($authConfig));
    }

    /**
     * Read-only diagnostic. Uses the same authenticated, SSRF-guarded HTTP engine
     * as the import. Does not return business records, credentials or option values.
     *
     * @return array{resolved:array<string,int>,ambiguous:array<string,list<int>>,missing:list<string>,candidates:list<array{id:int,name:string,group:string,table:string,field:string}>,total:int}
     */
    public function inspect(AuthConfig $authConfig): array
    {
        return self::inspectCatalog($this->fetchOptions($authConfig));
    }

    /** @return array<string|int,mixed> */
    private function fetchOptions(AuthConfig $authConfig): array
    {
        $response = $this->httpEngine->execute($authConfig, new HttpRequest(
            HttpMethod::GET,
            '/listSearchOptions/Location'
        ));
        if (!$response->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'GLPI source returned HTTP %d for listSearchOptions/Location.',
                $response->statusCode
            ));
        }
        $decoded = json_decode($response->body, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('GLPI source returned an invalid Location search option catalog.');
        }
        return $decoded;
    }

    /**
     * Match the live REST search-option IDs; YAML field IDs are deliberately not
     * substituted. Fail closed for ambiguity (including duplicate native IDs).
     *
     * @param array<string|int,mixed> $options
     * @return array<string,int>
     */
    public static function matchCatalog(array $options): array
    {
        $diagnostic = self::inspectCatalog($options);
        if ($diagnostic['ambiguous'] !== []) {
            $logical = array_key_first($diagnostic['ambiguous']);
            throw new \RuntimeException(sprintf(
                'Ambiguous GLPI Location search option for %s (candidate IDs: %s). Resolve the source '
                    . 'Fields configuration before migration.',
                $logical,
                implode(', ', $diagnostic['ambiguous'][$logical]),
            ));
        }
        $resolved = $diagnostic['resolved'];
        $required = ['source_id', 'full_name', 'line_number'];
        $missing = array_values(array_filter($required, static fn(string $name): bool => !isset($resolved[$name])));
        if ($missing !== []) {
            throw new \RuntimeException('Unable to resolve required GLPI Location search options: ' . implode(
                ', ',
                $missing
            ) . '.');
        }
        return $resolved;
    }

    /**
     * Pure diagnostic to support GLPI 10 and GLPI 11 Fields variations. Presence
     * of a field in the YAML is NOT proof of its presence in search options.
     * Display only whitelisted metadata; never expose dropdown values/records.
     *
     * @param array<string|int,mixed> $options
     * @return array{resolved:array<string,int>,ambiguous:array<string,list<int>>,missing:list<string>,candidates:list<array{id:int,name:string,group:string,table:string,field:string}>,total:int}
     */
    public static function inspectCatalog(array $options): array
    {
        /** @var array<string,array<int,int>> $ranked */
        $ranked = [];
        $candidateOptions = [];
        $total = 0;
        foreach ($options as $optionId => $option) {
            if (!is_array($option) || !is_numeric($optionId) || (int) $optionId <= 0) {
                continue;
            }
            $id = (int) $optionId;
            $table = self::normalizeMetadata($option['table'] ?? '');
            $field = self::normalizeMetadata($option['field'] ?? '');
            $name = self::normalizeMetadata($option['name'] ?? '');
            $group = self::normalizeMetadata($option['groupname'] ?? $option['group'] ?? '');
            $combined = trim($group . ' ' . $name);
            $metadata = implode(' ', array_map(
                static fn(string $key): string => self::normalize(
                    is_scalar($option[$key] ?? null) ? (string) $option[$key] : ''
                ),
                ['table', 'field', 'linkfield', 'name', 'groupname', 'group'],
            ));
            $metadataCompact = str_replace(' ', '', $metadata);
            $fieldCompact = str_replace(' ', '', $field);
            // GLPI 11 Fields exposes dropdowns as dedicated tables with
            // `field = completename`, and prefixes their name with the block
            // label (e.g. `ndeligne - Abonnements`). This prefix is not groupname.
            [$nameBlock, $scopedLabel] = self::splitFieldsOptionName($option['name'] ?? '');
            $isFields = str_contains($metadataCompact, 'pluginfields')
                || str_contains($metadata, 'champs supplementaires')
                || str_contains($metadata, 'custom fields')
                || str_contains($metadata, 'additional fields');
            $isTelecomBlock = $nameBlock === 'telecom'
                || self::isBlock($table, $group, 'ndeligne', 'n de ligne');
            $isInparkBlock = $nameBlock === 'inpark'
                || self::isBlock($table, $group, 'inpark', 'inpark');
            $total++;

            if ($table === 'glpi locations' && $field === 'id') {
                $ranked['source_id'][$id] = 110;
            }
            foreach (self::LABELS as $logical => $aliases) {
                $score = 0;
                $blockMatch = in_array($logical, [
                    'inpark_entity', 'longitude', 'id_base_parcs', 'latitude', 'address',
                    'postcode', 'town', 'operation_start', 'site_code',
                    'code_da', 'code_da_accounting', 'code_ets', 'code_db', 'code_na', 'code_nb',
                ], true) ? $isInparkBlock : $isTelecomBlock;
                // A named Fields block is authoritative. Never accept a
                // technical/label match from a different or unknown block.
                if ($nameBlock !== null && (!$blockMatch || $nameBlock === 'other')) {
                    continue;
                }
                if (isset(self::SOURCE_XML_NODES[$logical])) {
                    $technical = str_replace(' ', '', self::normalize(self::SOURCE_XML_NODES[$logical]));
                    if (
                        $fieldCompact === $technical || ($technical !== '' && str_ends_with(
                            $fieldCompact,
                            $technical
                        ))
                    ) {
                        $score = $blockMatch ? 115 : 100;
                    } elseif ($isFields && $technical !== '' && str_contains($metadataCompact, $technical)) {
                        $score = $blockMatch ? 112 : 95;
                    }
                    // Dropdowns on GLPI 11 are identified by their dedicated
                    // lookup table, not the search-option `field` (completename).
                    // Compare stable technical metadata, never numeric IDs.
                    // Do not accept a conflicting block prefix.
                    $expectedDropdownTable = self::expectedDropdownTable(self::SOURCE_XML_NODES[$logical]);
                    if (
                        $isFields && $expectedDropdownTable !== null
                        && str_replace(' ', '', $table) === $expectedDropdownTable
                        && ($blockMatch || ($nameBlock === null && $name === self::normalize(
                            self::SOURCE_LABELS[$logical]
                        )))
                    ) {
                        $score = max($score, $blockMatch ? 125 : 105);
                    }
                }
                if (isset(self::SOURCE_LABELS[$logical])) {
                    $expectedLabel = self::normalize(self::SOURCE_LABELS[$logical]);
                    if ($name === $expectedLabel && ($blockMatch || $isFields)) {
                        $score = max($score, $isFields ? 75 : 90);
                    }
                    // A scoped label is safe only when its explicit block
                    // matches the expected logical section (Telecom/Inpark).
                    if ($scopedLabel !== '' && $scopedLabel === $expectedLabel && $blockMatch) {
                        $score = max($score, 120);
                    }
                }
                foreach ($aliases as $alias) {
                    if (
                        ($isFields || $blockMatch || $logical === 'full_name')
                        && ($name === $alias || $combined === $alias || str_ends_with($combined, ' ' . $alias))
                    ) {
                        $score = max($score, $isFields ? 80 : ($blockMatch ? 90 : 45));
                        break;
                    }
                }
                if ($score > 0) {
                    $ranked[$logical][$id] = $score;
                }
            }
            // The source diagnostic must show missing dropdown options, without
            // requiring a customer to extract PHP logs or query the database.
            if (($isFields || $isTelecomBlock || $isInparkBlock) && count($candidateOptions) < 250) {
                $candidateOptions[] = [
                    'id' => $id,
                    'name' => self::safeMetadata($option['name'] ?? ''),
                    'group' => self::safeMetadata($option['groupname'] ?? $option['group'] ?? ''),
                    'table' => self::safeMetadata($option['table'] ?? ''),
                    'field' => self::safeMetadata($option['field'] ?? ''),
                ];
            }
        }
        $resolved = [];
        $ambiguous = [];
        foreach ($ranked as $logical => $scores) {
            $best = max($scores);
            $ids = array_keys(array_filter($scores, static fn(int $score): bool => $score === $best));
            sort($ids, SORT_NUMERIC);
            if (count($ids) === 1) {
                $resolved[$logical] = (int) $ids[0];
            } else {
                $ambiguous[$logical] = $ids;
            }
        }
        $missing = array_values(array_diff(array_keys(self::SOURCE_XML_NODES), array_keys($resolved), array_keys(
            $ambiguous
        )));
        return compact('resolved', 'ambiguous', 'missing') + ['candidates' => $candidateOptions, 'total' => $total];
    }

    /**
     * GLPI 11 Fields prefixes search option labels with their parent block.
     * Accept only known explicit prefixes with a separator. Unknown blocks
     * MUST NOT be silently interpreted as telecom fields.
     *
     * @return array{0:'telecom'|'inpark'|'other'|null,1:string}
     */
    private static function splitFieldsOptionName(mixed $raw): array
    {
        if (!is_string($raw) || !str_contains($raw, ' - ')) {
            return [null, ''];
        }
        [$rawBlock, $rawLabel] = explode(' - ', $raw, 2);
        $block = self::normalize($rawBlock);
        $label = self::normalize($rawLabel);
        if (in_array($block, ['ndeligne', 'n de ligne', 'plugins n de ligne'], true)) {
            return ['telecom', $label];
        }
        if (in_array($block, ['inpark', 'plugins inpark'], true)) {
            return ['inpark', $label];
        }
        // Unknown block is marked explicitly as foreign so callers cannot
        // fall back to label-only matching for Fields from the wrong block.
        return ['other', $label];
    }

    /**
     * The dedicated dropdown table name can be derived from the stable Fields
     * xml_node, independent of the source installation's numeric option ID.
     */
    private static function expectedDropdownTable(string $xmlNode): ?string
    {
        $node = strtolower($xmlNode);
        if (!str_starts_with($node, 'plugin_fields_') || !str_ends_with($node, '_id')) {
            return null;
        }
        return str_replace(' ', '', self::normalize('glpi_' . substr($node, 0, -3)));
    }

    private static function isBlock(string $table, string $group, string $token, string $label): bool
    {
        // Require a token boundary, so e.g. an unrelated name containing
        // 'inpark' cannot silently masquerade as a Fields block.
        $normalized = ' ' . $table . ' ' . $group . ' ';
        return preg_match('/(?:^|[^a-z0-9])' . preg_quote($token, '/') . '(?:[^a-z0-9]|$)/', $normalized) === 1
            || preg_match('/(?:^|[^a-z0-9])' . preg_quote($label, '/') . '(?:[^a-z0-9]|$)/', $normalized) === 1;
    }

    private static function normalizeMetadata(mixed $raw): string
    {
        return self::normalize(is_scalar($raw) ? (string) $raw : '');
    }

    private static function safeMetadata(mixed $raw): string
    {
        return !is_scalar($raw) ? ''
            : (function_exists('mb_substr') ? mb_substr((string) $raw, 0, 150) : substr((string) $raw, 0, 150));
    }

    /**
     * Prevent silent data loss in the INDIGO-specific transformation: the source
     * must expose all expected business fields before any target object is written.
     * An existing option whose value is empty is valid; an absent option is not.
     *
     * @param array<string,int> $resolved
     */
    public function assertTelecomSourceOptionsAvailable(array $resolved): void
    {
        $expected = [
            'caller_name' => 'Utilisateur (nom de l’appelant)',
            'equipment' => 'Équipements',
            'equipment_serial' => 'N° de série matériel',
            'delivery_date' => 'Date de remise',
            'equipment_value' => 'Tarif équipements',
            'sim_number' => 'N° de SIM (ICCID)',
            'puk' => 'Code PUK',
            'billing_account' => 'Compte facturant',
            'subscription' => 'Abonnement',
            'inpark_entity' => 'Entité Inpark',
            'code_da' => 'Code DA',
            'code_da_accounting' => 'Code DA Comptable',
            'code_ets' => 'Code ETS',
            'code_db' => 'Code DB',
            'code_na' => 'Code NA',
            'code_nb' => 'Code NB',
            'business_creation_date' => 'Date de création métier',
            'suspension_date' => 'Date de suspension',
            'termination_date' => 'Date de résiliation',
            'code_rds' => 'Code RDS',
            'renewal_date' => 'Date de renouvellement',
            'id_base_parcs' => 'IdBaseParcs',
            'operation_start' => 'Début Exploitation',
        ];
        $missing = [];
        foreach ($expected as $logical => $label) {
            if (!array_key_exists($logical, $resolved)) {
                $missing[] = $label;
            }
        }
        if ($missing !== []) {
            throw new \RuntimeException(
                'Missing source GLPI 10 Location search options: ' . implode(', ', $missing)
                . '. Check /listSearchOptions/Location, source Fields configuration and API permissions '
                    . 'before running the INDIGO telecom job.',
            );
        }
    }

    /**
     * Detect an API payload that failed to return a configured forcedisplay
     * column. A column present with an empty or null value is not missing.
     *
     * @param array<string|int,mixed> $row
     * @param array<string,int> $map
     * @return list<string>
     */
    public function missingTelecomPayloadColumns(array $row, array $map): array
    {
        $tracked = [
            'caller_name' => 'Utilisateur',
            'equipment' => 'Équipements',
            'equipment_serial' => 'N° de série matériel',
            'delivery_date' => 'Date de remise',
            'equipment_value' => 'Tarif équipements',
            'sim_number' => 'N° de SIM',
            'puk' => 'Code PUK',
        ];
        $missing = [];
        foreach ($tracked as $logical => $label) {
            $option = $map[$logical] ?? null;
            if ($option !== null && !array_key_exists($option, $row) && !array_key_exists((string) $option, $row)) {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    /** @return list<int> */
    public function forcedDisplayIds(AuthConfig $authConfig): array
    {
        $ids = array_values(array_unique(array_values($this->resolve($authConfig))));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private static function normalize(string $value): string
    {
        $value = trim(function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value));
        $value = strtr($value, ['°' => '', 'n°' => 'n', 'nº' => 'n', 'é' => 'e','è' => 'e','ê' => 'e','ë' => 'e',
                'à' => 'a','â' => 'a','ä' => 'a','î' => 'i','ï' => 'i','ô' => 'o','ö' => 'o','ù' => 'u','û' => 'u',
                'ü' => 'u','ç' => 'c','œ' => 'oe', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'À' => 'a', 'Â' => 'a',
                'Î' => 'i', 'Ô' => 'o', 'Ù' => 'u', 'Ç' => 'c']);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}
