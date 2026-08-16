<script nonce="{{ Vite::cspNonce() }}">
    document.addEventListener('livewire:init', () => {
        Livewire.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                // Session expired (CSRF token mismatch) or Unauthorized
                if (status === 419 || status === 401) {
                    preventDefault();
                    window.location.reload();
                }
            });
        });
    });
</script>
