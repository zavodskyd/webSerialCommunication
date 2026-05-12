import './bootstrap';

document.addEventListener('DOMContentLoaded', () => {
    const exportButtons = document.querySelectorAll('[data-backup-export-url]');

    if (exportButtons.length === 0) {
        return;
    }

    for (const button of exportButtons) {
        button.addEventListener('click', async () => {
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
                window.alert('Native export zálohy nie je momentálne dostupný.');

                return;
            }

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
                    window.alert(`Záloha bola uložená do:\n${response.data.path}`);

                    return;
                }

                window.alert('Export zálohy skončil bez potvrdenej cieľovej cesty.');
            } catch (error) {
                const message = error?.response?.data?.message ?? 'Export zálohy zlyhal.';

                window.alert(message);
            } finally {
                button.disabled = false;
                button.textContent = originalLabel;
                button.setAttribute('aria-busy', 'false');
            }
        });
    }
});
