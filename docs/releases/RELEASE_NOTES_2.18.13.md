# UXConnector 2.18.13 - candidate for integration testing

Based on supplied **2.18.12-test** archive. This is a candidate, not a GLPI Marketplace-approved release.

## Fixes
- Telecom specialized batch processing now records SIM and equipment enrichment warnings in the persisted SyncFailure repository (execution ID, source ID, operation, error category, page/cursor). Source payloads and credentials are excluded from these records.
- Specialized batch processors can receive execution context via an optional interface while maintaining backward compatibility for existing processors.
- Exhausted HTTP stream errors exposed as RuntimeException are marked interrupted/resumable in sync orchestration rather than terminal failed; the existing persisted checkpoint is preserved.
- Version manifest synchronized at 2.18.13.

## Known limitations - not yet implemented
- No confirmed fix of the actual Zendesk long-running transport root cause: retry exhaustion is now interrupted rather than terminal, subject to existing resume behavior.
- SIM creation/API permission and devicesimcards_id requirements must be tested on GLPI 11; this release increases warning visibility but does not guarantee SIM creation.
- Generic Fields discovery, custom REST dynamic destination fields, network and Microsoft license transformation jobs are NOT implemented in this candidate.
- Runtime integration, GLPI Marketplace acceptance, full PHPUnit regression and security review are pending.

## Required acceptance tests
1. Reproduce a transport stream failure after page checkpoint; verify interruption, preserved cursor and explicit resume, no duplicate objects.
2. Force SIM and equipment write errors; verify counted warnings appear with source ID in Failed items for the same execution.
3. Validate SIM creation and association using permitted GLPI APIs on test records.
4. Replay GLPI10 migration twice; compare source/target native and Fields values and duplicates.
5. Run full PHPUnit suite on supported GLPI11+PHP environment and independent security code review.
