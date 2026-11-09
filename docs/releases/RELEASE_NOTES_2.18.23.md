# UXConnector 2.18.23 - Zendesk Sujet candidate

## Why

The INDIGO Zendesk categories job failed with `Zendesk Sujet catalog is empty or unavailable` although read-only endpoints for the correct ticket field returned HTTP 200. The bootstrap job relied exclusively on automatic field discovery and filtered tags by `topic__`.

## Corrective change

An **optional per-job** selection is available under the existing **Pagination** JSON `options` object:

```json
{
  "type": "next_link",
  "options": {
    "next_link_response_path": "next_page",
    "zendesk_topic_field_id": "YOUR_ZENDESK_TICKET_FIELD_ID"
  }
}
```

**Preserve the job's existing `type` and all existing `options`.** This is an example matching the current built-in Zendesk bootstrap template; the persisted job may differ. Do not change the authentication connection's Test path as a way to select the field. 

The explicit ID is validated by fetching the field definition and checking type `tagger`. It then reads all pages of the options endpoint and uses stable technical option values (including customer-specific tags not prefixed `topic__`) and human labels to build GLPI ITIL categories. A selected field with no usable options blocks the job before any target changes.

The automatic discovery remains unchanged when this option is absent. No Zendesk credentials or customer-specific field IDs are included in production source.

## Rollout on GLPI 11

1. Back up the currently installed plugin and database.
2. Install the full ZIP `uxconnector-2.18.23-candidate.zip` using the normal controlled process for the GLPI test/client instance, after checking compatibility.
3. Run `sh tools/preflight-2.18.23.sh` inside the installed `plugins/uxconnector` directory, if console access is available.
4. In UXConnector, open the categories job (INDIGO: job #3), edit **Pagination**, and add `"zendesk_topic_field_id": "360000175518"` to `options` **without altering the existing keys**.
5. Restore the Zendesk connection test path to `/api/v2/users/me.json` and optionally Test.
6. Run only the Zendesk categories job once, inspect its execution history and a few category branches; verify counts and no accidental categories before triggering any tickets import.
7. If source options have unexpected labels or the run fails, do not rerun blindly; use the execution message. Revert the job option and/or the plugin to the backed-up version if necessary.

## Scope and limitations

- Verified with local offline synthetic tests only; not yet validated against authenticated INDIGO Zendesk options payload or database.
- HTTP 200 proves endpoint reachability but does not prove usable option contents.
- GLPI Legacy Telecom, Network Equipment, Microsoft Licenses jobs and Fields resolver were not modified.
- The full PHPUnit suite was not run as a part of this candidate verification.
