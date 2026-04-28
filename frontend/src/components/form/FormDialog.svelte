<script lang="ts">
  import { get } from '../../api/client.js';
  import Modal from '../ui/Modal.svelte';
  import FormEditor from './FormEditor.svelte';
  import FormStateBadge from './FormStateBadge.svelte';

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

  // Velikosti modalu pro hlavní (full_size: true) a sub (full_size: false) formuláře.
  const LARGE_WIDTH = '1200px';
  const LARGE_HEIGHT = '900px';
  const SMALL_WIDTH = '720px';

  // Meta načtená před otevřením modalu — určuje velikost a (po načtení FormEditorem)
  // titulek a stavový badge v headeru.
  let fullSize = $state(false);
  let metaLoaded = $state(false);

  // Aktuální titulek a doc_states z formuláře — aktualizuje se přes onFormLoaded
  // callback z FormEditor po každém (re)loadu formuláře.
  let currentTitle = $state('');
  let currentDocStates = $state<Record<string, unknown> | null>(null);

  // Dirty stav formuláře — propagován z FormEditor přes onDirtyChange callback.
  // Při pokusu o zavření (Esc, klik na overlay, křížek) se zobrazí potvrzovací dialog.
  let isDirty = $state(false);

  async function checkFullSize(tbl: string, id: number | null) {
    const path = id != null ? `/_ui/form/${tbl}/meta/${id}` : `/_ui/form/${tbl}/meta`;
    const res = await get(path);
    if (res?.success) {
      fullSize = res.data?.formDefinition?.full_size ?? false;
    }
    metaLoaded = true;
  }

  $effect(() => {
    if (open) {
      metaLoaded = false;
      fullSize = false;
      currentTitle = '';
      currentDocStates = null;
      isDirty = false;
      checkFullSize(table, recordId);
    }
  });

  function handleClose(opts?: { force?: boolean }) {
    // force=true — přeskočí dirty kontrolu. Používá se po úspěšném save+closeForm,
    // kde FormEditor ví, že data jsou uložená, a nesmí se zobrazit confirm.
    if (isDirty && !opts?.force) {
      const confirmed = window.confirm('Máte neuložené změny. Opravdu chcete zavřít formulář?');
      if (!confirmed) return;
    }
    metaLoaded = false;
    isDirty = false;
    onClose();
  }

  function handleSaved(record: Record<string, unknown>) {
    onSaved?.(record);
    // Nezavíráme formulář zde — o zavření rozhoduje FormEditor sám
    // na základě closeForm flagu nebo akce uživatele
  }

  // Volá FormEditor po načtení / přepočtu formuláře. Aktualizuje header modalu.
  function handleFormLoaded(info: { title: string; docStates: Record<string, unknown> | null }) {
    currentTitle = info.title;
    currentDocStates = info.docStates;
  }

  // Volá FormEditor při změně dirty stavu (po editaci polí nebo po načtení/uložení).
  function handleDirtyChange(dirty: boolean) {
    isDirty = dirty;
  }

  // Modal nesmí zůstat s prázdným titulem dokud se formDef nenačte.
  const headerTitle = $derived(currentTitle || 'Načítám…');
</script>

{#if open && metaLoaded}
  <Modal
    title={headerTitle}
    open={true}
    onClose={handleClose}
    width={fullSize ? LARGE_WIDTH : SMALL_WIDTH}
    height={fullSize ? LARGE_HEIGHT : undefined}
  >
    {#snippet headerExtra()}
      {#if currentDocStates}
        <FormStateBadge docStates={currentDocStates} />
      {/if}
    {/snippet}

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
