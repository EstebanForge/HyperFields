(function () {
    'use strict';

    function normalizeText(value) {
        return String(value || '').toLowerCase().trim();
    }

    function updateFilterCount(countElement, visibleCount, totalCount) {
        if (!countElement) {
            return;
        }
        countElement.textContent = 'Showing ' + String(visibleCount) + ' of ' + String(totalCount);
    }

    function initFilter(container) {
        var input = container.querySelector('[data-hf-export-filter]');
        if (!input) {
            return;
        }

        var clearButton = container.querySelector('[data-hf-export-filter-clear]');
        var countElement = container.querySelector('[data-hf-export-filter-count]');
        var rows = Array.prototype.slice.call(
            container.querySelectorAll('.hf-export-options-table tbody tr')
        ).map(function (row) {
            return {
                row: row,
                text: normalizeText(row.textContent),
            };
        });

        var totalRows = rows.length;
        updateFilterCount(countElement, totalRows, totalRows);

        function applyFilter() {
            var term = normalizeText(input.value);
            var visibleCount = 0;

            rows.forEach(function (item) {
                var isVisible = term === '' || item.text.indexOf(term) !== -1;
                item.row.hidden = !isVisible;
                if (isVisible) {
                    visibleCount += 1;
                }
            });

            updateFilterCount(countElement, visibleCount, totalRows);
        }

        input.addEventListener('input', applyFilter);

        if (clearButton) {
            clearButton.addEventListener('click', function () {
                input.value = '';
                applyFilter();
                input.focus();
            });
        }
    }

    function getCheckboxes(container) {
        return Array.prototype.slice.call(
            container.querySelectorAll('input[type="checkbox"][name="hf_export_options[]"]')
        );
    }

    function setChecked(container, checked) {
        var checkboxes = getCheckboxes(container);
        checkboxes.forEach(function (checkbox) {
            checkbox.checked = checked;
        });
    }

    function invertChecked(container) {
        var checkboxes = getCheckboxes(container);
        checkboxes.forEach(function (checkbox) {
            checkbox.checked = !checkbox.checked;
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-hf-export-toggle]');
        if (!button) {
            return;
        }

        var fieldset = button.closest('.hf-export-options');
        if (!fieldset) {
            return;
        }

        var action = button.getAttribute('data-hf-export-toggle');
        if (action === 'all') {
            setChecked(fieldset, true);
            return;
        }

        if (action === 'none') {
            setChecked(fieldset, false);
            return;
        }

        if (action === 'invert') {
            invertChecked(fieldset);
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        var exportGroups = Array.prototype.slice.call(document.querySelectorAll('.hf-export-options'));
        exportGroups.forEach(initFilter);

        var exportButton = document.querySelector('[name="hf_export_submit"], [name="portus_export_submit"]');
        if (exportButton) {
            exportButton.closest('form').addEventListener('submit', function () {
                var spinner = exportButton.closest('.submit').querySelector('.spinner');
                // Defer disabling until after the browser has serialized the form,
                // so the button name/value is included in the POST payload.
                setTimeout(function () {
                    exportButton.disabled = true;
                    if (spinner) {
                        spinner.classList.add('is-active');
                    }
                }, 0);
            });
        }
    });
})();
