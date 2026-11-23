/* UXConnector UI. External, delegated listeners work without inline-script CSP allowances. */
(() => {
    'use strict';

    const bulkGroups = [
        {
            form: 'uxconnector-bulk-jobs',
            checkboxes: '.uxconnector-job-checkbox',
            toggles: ['uxconnector-select-all-jobs', 'uxconnector-select-all-jobs-toolbar'],
            inputs: 'uxconnector-bulk-selected-inputs',
            count: 'uxconnector-selected-count',
        },
        {
            form: 'uxconnector-connection-bulk-jobs',
            checkboxes: '.uxconnector-connection-job-checkbox',
            toggles: ['uxconnector-connection-select-all-jobs'],
            inputs: 'uxconnector-connection-bulk-selected-inputs',
            count: 'uxconnector-connection-selected-count',
        },
    ];

    const selectedRows = (group) => Array.from(document.querySelectorAll(group.checkboxes))
        .filter((checkbox) => checkbox.checked && !checkbox.disabled);

    const refreshBulk = (group) => {
        const form = document.getElementById(group.form);
        if (!form) return;
        const checkboxes = Array.from(document.querySelectorAll(group.checkboxes))
            .filter((checkbox) => !checkbox.disabled);
        const selected = selectedRows(group);
        group.toggles.forEach((id) => {
            const toggle = document.getElementById(id);
            if (toggle) {
                toggle.checked = checkboxes.length > 0 && selected.length === checkboxes.length;
                toggle.indeterminate = selected.length > 0 && selected.length < checkboxes.length;
            }
        });
        const count = document.getElementById(group.count);
        if (count) count.textContent = `${selected.length} ${count.dataset.uxconnectorSelectedLabel}`;
        form.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.disabled = selected.length === 0;
        });
    };

    const refreshCredentials = () => {
        const method = document.getElementById('uxconnector-auth-method');
        const generic = document.getElementById('uxconnector-generic-credential');
        const glpiRest = document.getElementById('uxconnector-glpi-rest-credentials');
        if (!method || !generic || !glpiRest) return;
        const isGlpiRest = method.value === 'glpi_rest_v1';
        generic.hidden = isGlpiRest;
        glpiRest.hidden = !isGlpiRest;
        if (isGlpiRest) glpiRest.open = true;
    };

    document.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;
        if (target.matches('[data-uxconnector-connector-selector]')) {
            window.location.assign(`connection.form.php?connector=${encodeURIComponent(target.value)}`);
            return;
        }
        if (target.id === 'uxconnector-auth-method') refreshCredentials();
        bulkGroups.forEach((group) => {
            if (group.toggles.includes(target.id)) {
                document.querySelectorAll(group.checkboxes).forEach((checkbox) => {
                    if (!checkbox.disabled) checkbox.checked = target.checked;
                });
                refreshBulk(group);
            } else if (target.matches(group.checkboxes)) {
                refreshBulk(group);
            }
        });
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
        const cancel = () => {
            event.preventDefault();
            event.stopImmediatePropagation();
        };
        if (form.classList.contains('uxconnector-run-form') && form.dataset.uxSubmitting === '1') {
            cancel();
            return;
        }
        const message = event.submitter?.dataset.uxconnectorConfirm ?? form.dataset.uxconnectorConfirm;
        if (message && !window.confirm(message)) {
            cancel();
            return;
        }
        const group = bulkGroups.find((candidate) => candidate.form === form.id);
        if (group) {
            const inputs = document.getElementById(group.inputs);
            const selected = selectedRows(group);
            if (!inputs || selected.length === 0) {
                cancel();
                return;
            }
            inputs.replaceChildren();
            selected.forEach((checkbox) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'job_ids[]';
                input.value = checkbox.value;
                inputs.appendChild(input);
            });
        }
        if (form.classList.contains('uxconnector-run-form')) {
            form.dataset.uxSubmitting = '1';
            // Preserve the submitter's name/value in the POST, and respect later listeners cancelling submission.
            window.setTimeout(() => {
                if (event.defaultPrevented) {
                    delete form.dataset.uxSubmitting;
                } else if (event.submitter) {
                    event.submitter.dataset.uxconnectorSubmissionDisabled = '1';
                    event.submitter.disabled = true;
                }
            }, 0);
        }
    }, true);

    const initialize = () => {
        refreshCredentials();
        bulkGroups.forEach(refreshBulk);
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, {once: true});
    } else {
        initialize();
    }
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('.uxconnector-run-form').forEach((form) => {
            delete form.dataset.uxSubmitting;
        });
        document.querySelectorAll('[data-uxconnector-submission-disabled]').forEach((button) => {
            button.disabled = false;
            delete button.dataset.uxconnectorSubmissionDisabled;
        });
        initialize();
    });
})();
