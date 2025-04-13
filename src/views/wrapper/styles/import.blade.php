<head>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const debounce = (func, delay) => {
        let timeout;
        return (...args) => {
            clearTimeout(timeout);
            timeout = setTimeout(() => func(...args), delay);
        };
    };

    const updateServerBackgrounds = debounce(async () => {

        const toggleSwitch = document.querySelector('input[name="show_all_servers"]');
        const apiUrl = toggleSwitch && toggleSwitch.checked 
            ? '/api/client?page=1&type=admin' 
            : '/api/client?page=1';

        console.log(`Fetching data from: ${apiUrl}`);

        try {
            const [serverData, configuredEggs, configuredServerBackgrounds] = await Promise.all([
                fetch(apiUrl).then(response => response.json()),
                fetch('/extensions/serverbackgrounds/configured-egg-backgrounds').then(response => response.json()),
                fetch('/extensions/serverbackgrounds/configured-server-backgrounds').then(response => response.json())
            ]);

            if (!serverData.data || !configuredEggs || !configuredServerBackgrounds) {
                console.warn("No server data, configured egg backgrounds, or configured server backgrounds found.");
                return;
            }

            const filteredServers = serverData.data.map(server => ({
                egg_id: server.attributes.BlueprintFramework.egg_id,
                name: server.attributes.name,
                uuid: server.attributes.uuid,
                identifier: server.attributes.identifier
            }));

            document.querySelectorAll('.dyLna-D').forEach((container) => {
                const hrefLink = container.getAttribute('href');
                if (!hrefLink) return;

                const serverId = hrefLink.split('/').pop();
                const server = filteredServers.find(s => s.identifier === serverId);
                if (!server) return;

                const configuredServerBackground = configuredServerBackgrounds.find(bg => bg.uuid === server.uuid);
                const configuredEgg = configuredEggs.find(egg => egg.id === server.egg_id);

                let backgroundImageUrl = '';
                let backgroundOpacity = 1;

                if (configuredServerBackground) {
                    backgroundImageUrl = configuredServerBackground.image_url;
                    backgroundOpacity = configuredServerBackground.opacity;
                } else if (configuredEgg) {
                    backgroundImageUrl = configuredEgg.image_url;
                    backgroundOpacity = configuredEgg.opacity;
                }

                if (backgroundImageUrl) {
                    // **Check if background already exists**
                    if (!container.querySelector('.background-image')) {
                        const backgroundImageDiv = document.createElement('div');
                        backgroundImageDiv.className = 'background-image';
                        backgroundImageDiv.style.backgroundImage = `url('${backgroundImageUrl}?cache_bust=${Date.now()}')`;
                        backgroundImageDiv.style.backgroundSize = 'cover';
                        backgroundImageDiv.style.backgroundPosition = 'center center';
                        backgroundImageDiv.style.filter = `opacity(${backgroundOpacity})`;
                        backgroundImageDiv.style.position = 'absolute';
                        backgroundImageDiv.style.top = '0';
                        backgroundImageDiv.style.left = '0';
                        backgroundImageDiv.style.right = '0';
                        backgroundImageDiv.style.bottom = '0';
                        container.appendChild(backgroundImageDiv);
                    }
                }
            });
        } catch (error) {
            console.error('Error fetching server data or configured backgrounds:', error);
        }
    }, 500);

    const forceBackgroundRefresh = () => {
        console.log("Force background refresh triggered.");
        document.querySelectorAll('.background-image').forEach(el => el.remove());
        updateServerBackgrounds();
    };

    const reattachToggleListener = () => {
        const toggleSwitch = document.querySelector('input[name="show_all_servers"]');
        if (toggleSwitch) {
            toggleSwitch.removeEventListener('change', forceBackgroundRefresh);
            toggleSwitch.addEventListener('change', forceBackgroundRefresh);
        }
    };

    const initializePage = () => {
        if (window.location.pathname === '/') {
            updateServerBackgrounds();
            reattachToggleListener();
        }
    };

    initializePage();

    window.addEventListener('load', () => {
        initializePage();
    });

    window.addEventListener('popstate', () => {
        forceBackgroundRefresh();
    });

    let lastPathname = window.location.pathname;
    setInterval(() => {
        if (window.location.pathname !== lastPathname) {
            lastPathname = window.location.pathname;
            forceBackgroundRefresh();
        }
    }, 500);

    const observer = new MutationObserver(() => {
        console.log("DOM changed, refreshing backgrounds.");
        updateServerBackgrounds();
    });

    const appDiv = document.querySelector("#app");
    if (appDiv) {
        observer.observe(appDiv, { childList: true, subtree: true });
    }
});
</script>
<style>
#app > div.App___StyledDiv-sc-2l91w7-0.fnfeQw > div.Fade__Container-sc-1p0gm8n-0.hcgQjy > section > div.ContentContainer-sc-x3r2dw-0.PageContentBlock___StyledContentContainer-sc-kbxq2g-0.jyeSuy.HeRWk.fade-appear-done.fade-enter-done > a {
    position: relative; /* Required for absolute positioning of the background image div */
    overflow: hidden; /* Ensure no overflow */
}

.background-image {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 0; /* Place it behind any content */
}
</style>
</head>
