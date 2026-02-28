(function () {
    'use strict';

    function initSelector(container) {
        var searchInput = container.querySelector('.model-selector__search');
        var chipsContainer = container.querySelector('.model-selector__chips');
        var panel = container.querySelector('.model-selector__panel');
        var noResults = container.querySelector('.model-selector__no-results');
        var defaultCollapsed = container.getAttribute('data-default-collapsed') === 'true';
        var isGrouped = container.getAttribute('data-grouped') !== 'false';
        var filterSelect = container.querySelector('.model-selector__filter');
        var groups = panel.querySelectorAll('.model-selector__group');
        var userCollapseState = {};
        var activeFilter = 'all';

        // Initialize collapse state (grouped mode only)
        if (isGrouped) {
            groups.forEach(function (group) {
                var groupKey = group.getAttribute('data-group');
                if (defaultCollapsed) {
                    group.classList.add('model-selector__group--collapsed');
                }
                userCollapseState[groupKey] = defaultCollapsed;
            });
        }

        // Build initial chips for pre-checked checkboxes
        var checked = panel.querySelectorAll('input[type="checkbox"]:checked');
        checked.forEach(function (cb) {
            addChip(cb);
        });

        // Build stale chips
        var staleData = container.getAttribute('data-stale-models');
        if (staleData) {
            try {
                var staleModels = JSON.parse(staleData);
                staleModels.forEach(function (modelId) {
                    addStaleChip(modelId);
                });
            } catch (e) {
                // ignore parse errors
            }
        }

        if (isGrouped) {
            updateAllGroupCounts();
        }

        // Group header toggle (grouped mode only)
        if (isGrouped) {
            panel.addEventListener('click', function (e) {
                var header = e.target.closest('.model-selector__group-header');
                if (!header) return;
                e.preventDefault();
                var group = header.closest('.model-selector__group');
                var groupKey = group.getAttribute('data-group');
                var isCollapsed = group.classList.toggle('model-selector__group--collapsed');
                userCollapseState[groupKey] = isCollapsed;
            });
        }

        // Checkbox change via event delegation
        panel.addEventListener('change', function (e) {
            if (e.target.type !== 'checkbox') return;
            var cb = e.target;
            if (cb.checked) {
                addChip(cb);
            } else {
                removeChip(cb.value);
            }
            if (isGrouped) {
                updateGroupCount(cb.closest('.model-selector__group'));
            }
        });

        // Filter dropdown
        if (filterSelect) {
            filterSelect.addEventListener('change', function () {
                activeFilter = filterSelect.value;
                applyFilters();
            });
        }

        // Search
        searchInput.addEventListener('input', function () {
            applyFilters();
        });

        function itemMatchesQuery(item, query) {
            var modelId = (item.getAttribute('data-model-id') || '').toLowerCase();
            var modelName = (item.getAttribute('data-model-name') || '').toLowerCase();
            return modelId.indexOf(query) !== -1 || modelName.indexOf(query) !== -1;
        }

        function itemPassesFilter(item) {
            if (activeFilter === 'all') return true;
            var freeAttr = item.getAttribute('data-free');
            if (freeAttr === null) return true;
            if (activeFilter === 'free') return freeAttr === '1';
            if (activeFilter === 'paid') return freeAttr === '0';
            return true;
        }

        function applyFilters() {
            var query = searchInput.value.toLowerCase().trim();
            var hasActiveFilters = query !== '' || activeFilter !== 'all';

            if (!hasActiveFilters) {
                // Restore all items
                panel.querySelectorAll('.model-selector__item').forEach(function (item) {
                    item.classList.remove('model-selector__item--hidden');
                });
                if (isGrouped) {
                    groups.forEach(function (group) {
                        group.classList.remove('model-selector__group--hidden');
                        var groupKey = group.getAttribute('data-group');
                        if (userCollapseState[groupKey]) {
                            group.classList.add('model-selector__group--collapsed');
                        } else {
                            group.classList.remove('model-selector__group--collapsed');
                        }
                    });
                }
                noResults.classList.remove('model-selector__no-results--visible');
                return;
            }

            var anyVisible = false;

            if (isGrouped) {
                groups.forEach(function (group) {
                    var items = group.querySelectorAll('.model-selector__item');
                    var groupHasVisible = false;

                    items.forEach(function (item) {
                        var passesQuery = query === '' || itemMatchesQuery(item, query);
                        var passesFilter = itemPassesFilter(item);

                        if (passesQuery && passesFilter) {
                            item.classList.remove('model-selector__item--hidden');
                            groupHasVisible = true;
                        } else {
                            item.classList.add('model-selector__item--hidden');
                        }
                    });

                    if (groupHasVisible) {
                        group.classList.remove('model-selector__group--hidden');
                        group.classList.remove('model-selector__group--collapsed');
                        anyVisible = true;
                    } else {
                        group.classList.add('model-selector__group--hidden');
                    }
                });
            } else {
                var items = panel.querySelectorAll('.model-selector__item');
                items.forEach(function (item) {
                    var passesQuery = query === '' || itemMatchesQuery(item, query);
                    var passesFilter = itemPassesFilter(item);

                    if (passesQuery && passesFilter) {
                        item.classList.remove('model-selector__item--hidden');
                        anyVisible = true;
                    } else {
                        item.classList.add('model-selector__item--hidden');
                    }
                });
            }

            if (anyVisible) {
                noResults.classList.remove('model-selector__no-results--visible');
            } else {
                noResults.classList.add('model-selector__no-results--visible');
            }
        }

        function addChip(cb) {
            var modelId = cb.value;
            var item = cb.closest('.model-selector__item');
            var label = item.querySelector('.model-selector__item-label');
            var chipText = label ? label.textContent : modelId;

            var chip = document.createElement('span');
            chip.className = 'model-selector__chip';
            chip.setAttribute('data-model-id', modelId);
            chip.textContent = chipText;

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'model-selector__chip-remove';
            removeBtn.textContent = '\u00d7';
            removeBtn.setAttribute('aria-label', 'Remove ' + chipText);
            removeBtn.addEventListener('click', function () {
                var selector = 'input[type="checkbox"][value="' + CSS.escape(modelId) + '"]';
                var checkbox = panel.querySelector(selector);
                if (checkbox) {
                    checkbox.checked = false;
                    if (isGrouped) {
                        updateGroupCount(checkbox.closest('.model-selector__group'));
                    }
                }
                chip.remove();
            });

            chip.appendChild(removeBtn);
            chipsContainer.appendChild(chip);
        }

        function addStaleChip(modelId) {
            var chip = document.createElement('span');
            chip.className = 'model-selector__chip model-selector__chip--stale';
            chip.setAttribute('data-model-id', modelId);
            chip.setAttribute('title', 'This model is no longer available from the provider and will be removed on save.');
            chip.textContent = modelId;
            chipsContainer.appendChild(chip);
        }

        function removeChip(modelId) {
            var chip = chipsContainer.querySelector(
                '.model-selector__chip[data-model-id="' + CSS.escape(modelId) + '"]'
            );
            if (chip) chip.remove();
        }

        function updateGroupCount(group) {
            var badge = group.querySelector('.model-selector__group-count');
            var total = group.querySelectorAll('input[type="checkbox"]').length;
            var selected = group.querySelectorAll('input[type="checkbox"]:checked').length;

            if (selected > 0) {
                badge.textContent = selected + ' selected';
                badge.classList.add('model-selector__group-count--active');
            } else {
                badge.textContent = total + ' models';
                badge.classList.remove('model-selector__group-count--active');
            }
        }

        function updateAllGroupCounts() {
            groups.forEach(function (group) {
                updateGroupCount(group);
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var containers = document.querySelectorAll('.model-selector');
        containers.forEach(function (container) {
            initSelector(container);
        });
    });
})();
