<script>
  /**
   * Univerzální ikona — vykreslí inline SVG z FontAwesome icon definition.
   *
   * Použití:
   *   import Icon from '../ui/Icon.svelte';
   *   import { iconAdd } from '../../icons.js';
   *   <Icon icon={iconAdd} />
   *   <Icon icon={iconAdd} size="lg" />
   *   <Icon icon={iconAdd} spin />
   */

  /** @type {import('@fortawesome/fontawesome-svg-core').IconDefinition} */
  let {
    icon,
    size = 'md',
    spin = false,
    label = undefined,
    class: extraClass = '',
  } = $props();

  // FontAwesome icon definition: icon = [width, height, ligatures, unicode, svgPathData]
  const viewBox = $derived(`0 0 ${icon.icon[0]} ${icon.icon[1]}`);
  const pathData = $derived(icon.icon[4]);
</script>

<svg
  class="shpd-icon shpd-icon--{size} {extraClass}"
  class:shpd-icon--spin={spin}
  viewBox={viewBox}
  xmlns="http://www.w3.org/2000/svg"
  aria-hidden={label ? undefined : 'true'}
  aria-label={label ?? undefined}
  role={label ? 'img' : undefined}
>
  {#if Array.isArray(pathData)}
    {#each pathData as d}
      <path fill="currentColor" {d} />
    {/each}
  {:else}
    <path fill="currentColor" d={pathData} />
  {/if}
</svg>

<style>
  .shpd-icon {
    display: inline-block;
    vertical-align: -0.125em;
    overflow: visible;
    fill: currentColor;
    flex-shrink: 0;
  }

  /* Velikosti */
  .shpd-icon--xs { width: 0.75em; height: 0.75em; }
  .shpd-icon--sm { width: 0.875em; height: 0.875em; }
  .shpd-icon--md { width: 1em; height: 1em; }
  .shpd-icon--lg { width: 1.25em; height: 1.25em; }
  .shpd-icon--xl { width: 1.5em; height: 1.5em; }

  /* Rotace (spinner) */
  .shpd-icon--spin {
    animation: shpd-icon-spin 1s linear infinite;
  }

  @keyframes shpd-icon-spin {
    to { transform: rotate(360deg); }
  }
</style>
