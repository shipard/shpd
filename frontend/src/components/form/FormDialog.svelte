<script lang="ts">
  import { get } from '../../api/client.js';
  import Modal from '../ui/Modal.svelte';
  import FormEditor from './FormEditor.svelte';

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

  let fullSize = $state(false);
  let metaLoaded = $state(false);

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
      checkFullSize(table, recordId);
    }
  });

  const modalTitle = $derived(recordId !== null ? 'Upravit záznam' : 'Nový záznam');

  function handleClose() {
    metaLoaded = false;
    onClose();
  }

  function handleSaved(record: Record<string, unknown>) {
    onSaved?.(record);
    handleClose();
  }
</script>

{#if open}
  {#if !metaLoaded}
    <!-- waiting for meta check -->
  {:else if fullSize}
    <div class="shpd-form-fullsize-overlay">
      <FormEditor
        {table}
        {recordId}
        onClose={handleClose}
        onSaved={handleSaved}
        {defaultData}
      />
    </div>
  {:else}
    <Modal title={modalTitle} open={true} onClose={handleClose} width="720px">
      <FormEditor
        {table}
        {recordId}
        onClose={handleClose}
        onSaved={handleSaved}
        {defaultData}
      />
    </Modal>
  {/if}
{/if}

<style>
  .shpd-form-fullsize-overlay {
    position: fixed;
    inset: 0;
    z-index: 500;
    background: var(--shpd-color-bg);
    display: flex;
    flex-direction: column;
  }
</style>
