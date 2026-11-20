<script>
  import { t } from '../translate.js';
  import { TYPES } from '../../deploy-canvas.js';
  import Icon from './Icon.svelte';
  import { dragFromPalette } from './state.svelte.js';

  // A step in the palette (drag it, or click to add it to the chain), or in
  // the "Add the next step" menu (onpick).
  let { type, onpick = null } = $props();
  const step = $derived(TYPES[type]);
</script>

<button
  type="button"
  class="cv-item"
  title="{t(step.title)} — {t(step.sub)}"
  onclick={onpick}
  onpointerdown={onpick ? null : e => dragFromPalette(type, e)}
>
  <i><Icon name={step.icon} /></i>
  <span><b>{t(step.title)}</b><small>{t(step.sub)}</small></span>
</button>
