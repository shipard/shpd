<script lang="ts">
  import Modal from '../ui/Modal.svelte';
  import FormRenderer from './FormRenderer.svelte';

  interface Props {
    table: string;
    recordId?: number | null;
    open: boolean;
    onClose: () => void;
    onSaved?: (record: Record<string, unknown>) => void;
  }

  let {
    table,
    recordId = null,
    open,
    onClose,
    onSaved,
  }: Props = $props();

  const title = $derived(recordId !== null ? 'Upravit záznam' : 'Nový záznam');

  function handleSave(record: Record<string, unknown>) {
    onSaved?.(record);
    onClose();
  }
</script>

<Modal {title} {open} {onClose} width="720px">
  <FormRenderer
    {table}
    {recordId}
    onSave={handleSave}
    onCancel={onClose}
  />
</Modal>
