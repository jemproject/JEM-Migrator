/**
 * Visual category mapping for JEM Migrator. The submitted mapping is keyed
 * by source category reference; SVG connections are only a presentation.
 */
(() => {
    'use strict';

    const parse = (value, fallback) => {
        try {
            return JSON.parse(value || '') || fallback;
        } catch (error) {
            return fallback;
        }
    };

    const node = (tag, className, label) => {
        const element = document.createElement(tag);
        element.className = className;

        if (label !== undefined) {
            element.textContent = label;
        }

        return element;
    };

    document.querySelectorAll('[data-jem-category-map]').forEach((board) => {
        const form = board.closest('form');
        const card = board.closest('.card');
        const sourcePanel = board.querySelector('[data-map-source]');
        const resultPanel = board.querySelector('[data-map-result]');
        const svg = board.querySelector('[data-map-lines]');
        const mapInput = board.querySelector('[data-map-input]');
        const targetSelect = board.querySelector('[data-map-target]');
        const dirtyAlert = board.querySelector('[data-map-dirty]');
        const importButton = card.querySelector('[data-map-import-button]');
        const parentSelect = form.querySelector('[name$="parent_catid"]');
        const parentSetting = form.querySelector('[data-map-parent-setting]');
        const singleSetting = form.querySelector('[data-map-single-setting]');
        const modeInputs = form.querySelectorAll('[name$="category_mode"]');
        const source = parse(board.dataset.sourceTree, []);
        const targets = parse(board.dataset.targetTree, []);
        const preview = parse(board.dataset.previewCategories, []);
        const map = parse(board.dataset.initialMap, {});
        const sourceByRef = new Map(source.map((item) => [String(item.ref), item]));
        const targetById = new Map(targets.map((item) => [Number(item.id), item]));
        const previewByRef = new Map(preview.map((item) => [String(item.ref), item]));
        const visible = preview.length > 0
            ? new Set(preview.map((item) => String(item.ref)))
            : new Set(sourceByRef.keys());
        let selected = '';
        let changed = false;

        importButton?.addEventListener('click', (event) => {
            if (importButton.getAttribute('aria-disabled') === 'true') {
                event.preventDefault();
            }
        });

        const mode = () => form.querySelector('[name$="category_mode"]:checked')?.value || 'tree';

        const markChanged = () => {
            changed = true;
            dirtyAlert.classList.remove('d-none');

            if (importButton) {
                importButton.classList.add('disabled');
                importButton.setAttribute('aria-disabled', 'true');
            }
        };

        const resolve = (ref, projected, visiting = new Set()) => {
            const item = sourceByRef.get(ref);

            if (!item || visiting.has(ref)) {
                return 'existing:' + Number(parentSelect.value || 1);
            }

            const targetId = Number(map[ref] || 0);

            if (targetId > 1 && targetById.has(targetId)) {
                return 'existing:' + targetId;
            }

            const previous = !changed ? previewByRef.get(ref) : null;

            if (previous?.status === 'existing' && Number(previous.jem_id) > 1) {
                return 'existing:' + Number(previous.jem_id);
            }

            const key = 'new:' + ref;

            if (!projected.has(key)) {
                const next = new Set(visiting);
                next.add(ref);
                const parentRef = String(item.parent_ref || '');
                const parent = parentRef && sourceByRef.has(parentRef)
                    ? resolve(parentRef, projected, next)
                    : 'existing:' + Number(parentSelect.value || 1);

                projected.set(key, {
                    key,
                    parent,
                    title: String(item.title || ref),
                    isNew: true,
                });
            }

            return key;
        };

        const addRow = (panel, item, depth, kind, key) => {
            const row = node('div', 'jem-category-map__row jem-category-map__row--' + kind);
            row.style.setProperty('--depth', Math.min(depth, 12));
            const button = node('button', 'jem-category-map__node', String(item.title));
            button.type = 'button';
            button.dataset.mapKey = key;

            if (kind === 'source') {
                button.dataset.sourceRef = key;
                button.setAttribute('aria-pressed', key === selected ? 'true' : 'false');
                row.classList.toggle('is-selected', key === selected);
            } else if (kind === 'existing') {
                button.dataset.targetId = key.slice('existing:'.length);
                button.disabled = key === 'existing:1';
            } else {
                button.disabled = true;
            }

            row.appendChild(button);
            const label = kind === 'new' ? board.dataset.labelNew
                : kind === 'existing' ? board.dataset.labelExisting
                    : (Number(map[key] || 0) > 1 ? board.dataset.labelExisting : board.dataset.labelInherited);
            row.appendChild(node('span', 'jem-category-map__badge', label));
            panel.appendChild(row);
        };

        const drawLines = (connections) => {
            svg.replaceChildren();
            const bounds = svg.getBoundingClientRect();

            if (!bounds.width || !bounds.height) {
                return;
            }

            svg.setAttribute('viewBox', `0 0 ${bounds.width} ${bounds.height}`);
            const sources = new Map(Array.from(sourcePanel.querySelectorAll('[data-source-ref]'))
                .map((element) => [element.dataset.sourceRef, element]));
            const results = new Map(Array.from(resultPanel.querySelectorAll('[data-map-key]'))
                .map((element) => [element.dataset.mapKey, element]));

            connections.forEach(([ref, key]) => {
                const from = sources.get(ref);
                const to = results.get(key);

                if (!from || !to) {
                    return;
                }

                const first = from.getBoundingClientRect();
                const last = to.getBoundingClientRect();
                const y1 = first.top + first.height / 2 - bounds.top;
                const y2 = last.top + last.height / 2 - bounds.top;
                const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                path.setAttribute('d', `M 0 ${y1} C ${bounds.width * 0.42} ${y1}, ${bounds.width * 0.58} ${y2}, ${bounds.width} ${y2}`);
                path.setAttribute('class', key.startsWith('new:') ? 'jem-category-map__line--new' : 'jem-category-map__line--existing');
                svg.appendChild(path);
            });
        };

        const render = () => {
            parentSetting.hidden = mode() === 'single';
            singleSetting.hidden = mode() !== 'single';
            board.hidden = mode() !== 'manual';

            if (board.hidden) {
                return;
            }

            sourcePanel.replaceChildren();
            resultPanel.replaceChildren();
            const projected = new Map();
            const connections = [];
            const sourceChildren = new Map();

            source.forEach((item) => {
                const ref = String(item.ref);

                if (!visible.has(ref)) {
                    return;
                }

                const parent = visible.has(String(item.parent_ref || '')) ? String(item.parent_ref) : '';
                const children = sourceChildren.get(parent) || [];
                children.push(ref);
                sourceChildren.set(parent, children);
            });

            const sourceSeen = new Set();
            const walkSource = (ref, depth) => {
                if (sourceSeen.has(ref)) {
                    return;
                }

                sourceSeen.add(ref);
                addRow(sourcePanel, sourceByRef.get(ref), depth, 'source', ref);
                connections.push([ref, resolve(ref, projected)]);
                (sourceChildren.get(ref) || []).forEach((child) => walkSource(child, depth + 1));
            };

            (sourceChildren.get('') || []).forEach((ref) => walkSource(ref, 0));

            if (!connections.length) {
                sourcePanel.appendChild(node('p', 'text-muted small', 'No source categories to map.'));
            }

            const children = new Map();
            const addChild = (parent, item) => {
                const list = children.get(parent) || [];
                list.push(item);
                children.set(parent, list);
            };

            targets.forEach((item) => addChild('existing:' + Number(item.parent_id), {
                key: 'existing:' + Number(item.id),
                title: String(item.title),
                isNew: false,
            }));
            projected.forEach((item) => addChild(item.parent, item));

            const targetSeen = new Set();
            const walkTarget = (item, depth) => {
                if (targetSeen.has(item.key)) {
                    return;
                }

                targetSeen.add(item.key);
                addRow(resultPanel, item, depth, item.isNew ? 'new' : 'existing', item.key);
                (children.get(item.key) || []).forEach((child) => walkTarget(child, depth + 1));
            };

            const root = targetById.get(1);
            walkTarget({key: 'existing:1', title: root?.title || 'JEM root', isNew: false}, 0);
            mapInput.value = JSON.stringify(map);
            targetSelect.value = selected && Number(map[selected]) > 1 ? String(map[selected]) : '0';
            svg.style.height = '0';
            svg.style.height = board.querySelector('.jem-category-map__grid').offsetHeight + 'px';
            requestAnimationFrame(() => drawLines(connections));
        };

        sourcePanel.addEventListener('click', (event) => {
            const button = event.target.closest('[data-source-ref]');

            if (button) {
                selected = button.dataset.sourceRef;
                render();
            }
        });

        resultPanel.addEventListener('click', (event) => {
            const button = event.target.closest('[data-target-id]');

            if (!button || !selected || Number(button.dataset.targetId) <= 1) {
                return;
            }

            map[selected] = Number(button.dataset.targetId);
            markChanged();
            render();
        });

        targetSelect.addEventListener('change', () => {
            if (!selected) {
                return;
            }

            if (Number(targetSelect.value) > 1) {
                map[selected] = Number(targetSelect.value);
            } else {
                delete map[selected];
            }

            markChanged();
            render();
        });

        parentSelect.addEventListener('change', () => {
            markChanged();
            render();
        });
        modeInputs.forEach((input) => input.addEventListener('change', () => {
            markChanged();
            render();
        }));
        window.addEventListener('resize', render);
        selected = source.find((item) => visible.has(String(item.ref)))?.ref || '';
        render();
    });
})();
