<script lang="ts">
  import Modal from '../ui/Modal.svelte';
  import Icon from '../ui/Icon.svelte';
  import FormEditor from './FormEditor.svelte';
  import FormStateBadge from './FormStateBadge.svelte';
  import { resolveIcon } from '../../icons.js';
  import { t } from '../../i18n/index.js';

  type HeaderInfoItem = { label: string; value: string };
  type HeaderInfo = {
    title: string;
    info: HeaderInfoItem[];
    icon: string | null;
    summary: HeaderInfoItem[];
  };

  interface Props {
    table: string;
    recordId?: number | null;
    open: boolean;
    onClose: () => void;
    onSaved?: (record: Record<string, unknown>) => void;
    defaultData?: Record<string, unknown>;
  }

  let {
    table,
    recordId = null,
    open,
    onClose,
    onSaved,
    defaultData = {},
  }: Props = $props();

  // Aktuální titulek a doc_states z formuláře — aktualizuje se přes onFormLoaded
  // callback z FormEditor po každém (re)loadu formuláře.
  let currentTitle = $state('');
  let currentDocStates = $state<Record<string, unknown> | null>(null);
  // Strukturovaná hlavička (title + info + icon + summary). Nastavuje se jen
  // při load/save, ne při recalculate — header odráží uložená data, ne živý formData.
  let savedHeaderInfo = $state<HeaderInfo | null>(null);

  // Dirty stav formuláře — propagován z FormEditor přes onDirtyChange callback.
  // Při pokusu o zavření (Esc, klik na overlay, křížek) se zobrazí potvrzovací dialog.
  let isDirty = $state(false);

  $effect(() => {
    if (open) {
      currentTitle = '';
      currentDocStates = null;
      savedHeaderInfo = null;
      isDirty = false;
    }
  });

  function handleClose(opts?: { force?: boolean }) {
    // force=true — přeskočí dirty kontrolu. Používá se po úspěšném save+closeForm,
    // kde FormEditor ví, že data jsou uložená, a nesmí se zobrazit confirm.
    if (isDirty && !opts?.force) {
      const confirmed = window.confirm(t('form.unsavedChanges'));
      if (!confirmed) return;
    }
    isDirty = false;
    onClose();
  }

  function handleSaved(record: Record<string, unknown>) {
    onSaved?.(record);
    // Nezavíráme formulář zde — o zavření rozhoduje FormEditor sám
    // na základě closeForm flagu nebo akce uživatele
  }

  // Volá FormEditor po načtení / přepočtu formuláře. Aktualizuje header modalu.
  function handleFormLoaded(info: {
    title: string;
    docStates: Record<string, unknown> | null;
    headerInfo: HeaderInfo | null;
  }) {
    currentTitle = info.title;
    currentDocStates = info.docStates;
    savedHeaderInfo = info.headerInfo;
  }

  // Volá FormEditor při změně dirty stavu (po editaci polí nebo po načtení/uložení).
  function handleDirtyChange(dirty: boolean) {
    isDirty = dirty;
  }

  // Modal nesmí zůstat s prázdným titulem dokud se formDef nenačte. Pokud
  // server pošle strukturované header_info, jeho `title` má přednost před
  // generickým `currentTitle` (formDef.title — typicky „Osoba", „Faktura"…).
  const headerTitle = $derived(
    savedHeaderInfo?.title || currentTitle || t('common.loading')
  );

  // Spojený řádek info pro subtitle: „IČO 68253848 · Kód osoby TEST-0098".
  // Prázdný `label` znamená „jen hodnota bez prefixu" (např. „Přijatá faktura"
  // u dokladu — typ je samopopisný, žádný „Typ:" prefix tam nepatří).
  // Renderuje se jen pokud máme aspoň jednu položku info — jinak subtitle
  // snippet vrátí prázdno a Modal subtitle row je vidět jen kvůli badge (pokud je).
  const subtitleText = $derived(
    savedHeaderInfo && savedHeaderInfo.info.length > 0
      ? savedHeaderInfo.info.map(i => i.label ? `${i.label} ${i.value}` : i.value).join(' · ')
      : ''
  );

  // Subtitle row se renderuje, jen když máme strukturovanou hlavičku. Tím se
  // zachovává původní layout (badge vedle titulku, jednořádková hlavička) pro
  // formuláře bez `buildHeaderInfo()` override (typicky JSONC sub-formy).
  const hasHeaderInfo = $derived(savedHeaderInfo !== null);
  const hasSummary = $derived((savedHeaderInfo?.summary?.length ?? 0) > 0);
  const hasIcon = $derived(!!savedHeaderInfo?.icon);
</script>

{#snippet subtitleSnippet()}
  {#if subtitleText}
    {subtitleText}
  {/if}
{/snippet}

{#snippet iconSnippet()}
  {#if savedHeaderInfo?.icon}
    <Icon icon={resolveIcon(savedHeaderInfo.icon)} />
  {/if}
{/snippet}

{#snippet summarySnippet()}
  {#if savedHeaderInfo}
    {#each savedHeaderInfo.summary as item (item.label)}
      <span>{item.label}</span>
      <span>{item.value}</span>
    {/each}
  {/if}
{/snippet}

{#snippet headerExtraSnippet()}
  {#if currentDocStates}
    <FormStateBadge docStates={currentDocStates} />
  {/if}
{/snippet}

{#if open}
  <Modal
    title={headerTitle}
    open={true}
    onClose={handleClose}
    width="clamp(1200px, 80vw, 1700px)"
    height="clamp(720px, 88vh, 1100px)"
    subtitle={hasHeaderInfo ? subtitleSnippet : undefined}
    iconSlot={hasIcon ? iconSnippet : undefined}
    summary={hasSummary ? summarySnippet : undefined}
    headerExtra={headerExtraSnippet}
  >
    <FormEditor
      {table}
      {recordId}
      onClose={handleClose}
      onSaved={handleSaved}
      onFormLoaded={handleFormLoaded}
      onDirtyChange={handleDirtyChange}
      {defaultData}
    />
  </Modal>
{/if}
