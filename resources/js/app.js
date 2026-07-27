import './bootstrap';

const flashMessageSelector = '[data-flash-message]';
const flashMessageDelay = 5000;

function scheduleFlashMessageDismissal(message) {
    if (message.dataset.flashDismissalScheduled === 'true') {
        return;
    }

    message.dataset.flashDismissalScheduled = 'true';

    window.setTimeout(() => {
        message.classList.add('opacity-0', 'pointer-events-none');

        window.setTimeout(() => message.remove(), 300);
    }, flashMessageDelay);
}

function scheduleFlashMessages(root) {
    if (root instanceof Element && root.matches(flashMessageSelector)) {
        scheduleFlashMessageDismissal(root);
    }

    if (! (root instanceof Element || root instanceof Document)) {
        return;
    }

    root.querySelectorAll(flashMessageSelector).forEach(scheduleFlashMessageDismissal);
}

document.addEventListener('DOMContentLoaded', () => {
    scheduleFlashMessages(document);

    new MutationObserver((records) => {
        records.forEach((record) => {
            record.addedNodes.forEach(scheduleFlashMessages);
        });
    }).observe(document.body, { childList: true, subtree: true });

    document.querySelectorAll('form[data-confirm-message]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const message = form.getAttribute('data-confirm-message');

            if (message && ! window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    document.addEventListener('click', async (event) => {
        if (! (event.target instanceof Element)) {
            return;
        }

        const button = event.target.closest('[data-backup-export-url]');

        if (! (button instanceof HTMLButtonElement)) {
            return;
        }

        const exportUrl = button.getAttribute('data-backup-export-url');
        const nativeRunning = button.getAttribute('data-native-running') === 'true';

        if (! exportUrl) {
            return;
        }

        if (! nativeRunning) {
            window.location.assign(exportUrl);

            return;
        }

        if (! window.axios) {
            window.alert(button.getAttribute('data-export-unavailable-message') ?? 'Native export zálohy nie je momentálne dostupný.');

            return;
        }

        const originalContent = button.innerHTML;
        const originalLabel = button.textContent?.trim() ?? '';
        const exportingLabel = button.getAttribute('data-exporting-label') ?? originalLabel;

        button.disabled = true;
        button.textContent = exportingLabel;
        button.setAttribute('aria-busy', 'true');

        try {
            const response = await window.axios.get(exportUrl, {
                headers: {
                    Accept: 'application/json',
                },
            });

            if (response.data?.cancelled) {
                return;
            }

            if (typeof response.data?.path === 'string' && response.data.path !== '') {
                const savedMessage = button.getAttribute('data-export-saved-message') ?? 'Záloha bola uložená do:';
                window.alert(`${savedMessage}\n${response.data.path}`);

                return;
            }

            window.alert(button.getAttribute('data-export-empty-message') ?? 'Export zálohy skončil bez potvrdenej cieľovej cesty.');
        } catch (error) {
            const message = error?.response?.data?.message
                ?? button.getAttribute('data-export-error-message')
                ?? 'Export zálohy zlyhal.';

            window.alert(message);
        } finally {
            button.disabled = false;
            button.innerHTML = originalContent;
            button.setAttribute('aria-busy', 'false');
        }
    });
});
