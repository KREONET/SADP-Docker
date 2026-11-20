// Deploy canvas (Svelte): mounted on the /canvas page into [data-deploy-canvas],
// on the first load and after Livewire SPA navigation.

import { mount, unmount } from 'svelte';
import { mountOnPage } from '../../canvas-core.js';
import DeployCanvas from './DeployCanvas.svelte';
import { init } from './state.svelte.js';
import { injectStyles } from './styles.js';
import { loadTranslations } from '../translate.js';

export function initializeDeployCanvas() {
  mountOnPage('[data-deploy-canvas]', '.cv-root', container => {
    injectStyles();
    loadTranslations(container);
    init(container);
    container.replaceChildren();
    const app = mount(DeployCanvas, { target: container });
    return { root: container.querySelector('.cv-root'), unmount: () => unmount(app) };
  });
}
