<script>
  // Trvalý pruh „Zdroj dat je jen pro čtení" (#56 fáze 2). Renderuje
  // ContentArea nad obsahem — sdílí ho všechny shelly, banner je tak na
  // jednom místě. Server zápisy odmítá 403, pruh dává srozumitelnost.
  import { t } from '../../i18n/index.js';
</script>

<div class="shpd-readonly-banner" role="status" data-testid="readonly-banner">
  {t('dsState.readOnly.banner')}
</div>

<style>
  .shpd-readonly-banner {
    position: sticky;
    top: 0;
    z-index: 5;
    padding: var(--shpd-space-xs) var(--shpd-space-lg);
    background: var(--shpd-color-alert-warning-bg);
    color: var(--shpd-color-alert-warning-text);
    border-bottom: 2px solid var(--shpd-color-alert-warning-bar);
    font-size: var(--shpd-font-size-sm);
    font-weight: 600;
    text-align: center;
  }
</style>
