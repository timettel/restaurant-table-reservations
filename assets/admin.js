'use strict';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-confirm-cancel]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm('Diese Reservierung wirklich stornieren? Der Tisch wird sofort wieder freigegeben.')) {
                event.preventDefault();
            }
        });
    });

    const template = document.querySelector('#opening-block-template');
    if (!template) return;

    document.querySelectorAll('[data-schedule-day]').forEach((day) => {
        const enabled = day.querySelector('.schedule-enabled input');
        const updateState = () => day.classList.toggle('is-closed', !enabled.checked);
        enabled.addEventListener('change', updateState);
        updateState();
    });

    document.addEventListener('click', (event) => {
        const addButton = event.target.closest('[data-add-block]');
        if (addButton) {
            const day = addButton.closest('[data-schedule-day]');
            const blocks = day.querySelector('[data-opening-blocks]');
            const weekday = day.dataset.scheduleDay;
            const index = String(Date.now()) + String(Math.floor(Math.random() * 1000));
            const fragment = template.content.cloneNode(true);
            const block = fragment.querySelector('[data-opening-block]');
            block.querySelector('[data-field="start"]').name = `schedule[${weekday}][blocks][${index}][start]`;
            block.querySelector('[data-field="end"]').name = `schedule[${weekday}][blocks][${index}][end]`;
            blocks.insertBefore(fragment, addButton);
            blocks.querySelectorAll('[data-opening-block] input')[blocks.querySelectorAll('[data-opening-block] input').length - 2].focus();
            return;
        }

        const removeButton = event.target.closest('[data-remove-block]');
        if (removeButton) {
            removeButton.closest('[data-opening-block]').remove();
        }
    });
});
