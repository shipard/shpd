<script>
  // Standalone demo for EntityPicker — not used in production. Mount this
  // anywhere in the app tree while developing Phase 3b to verify picker
  // search + select behaviour against a real DS table.
  //
  // To mount: temporarily add to App.svelte or wherever convenient:
  //
  //   import EntityPickerDemo from './components/exchange/EntityPickerDemo.svelte';
  //   <EntityPickerDemo />
  //
  // Delete the import + render once 3b lands its real callers.

  import EntityPicker from '../ui/EntityPicker.svelte';

  let open = $state(false);
  let lastSelected = $state(null);

  function handleSelect(row) {
    lastSelected = row;
  }
</script>

<div class="shpd-picker-demo">
  <h2>EntityPicker demo</h2>
  <p>Standalone harness — Phase 3a verification only.</p>

  <button class="shpd-picker-demo__open" onclick={() => (open = true)}>
    Open picker (base_persons_persons)
  </button>

  {#if lastSelected}
    <div class="shpd-picker-demo__result">
      <h3>Last selected</h3>
      <pre>{JSON.stringify(lastSelected, null, 2)}</pre>
    </div>
  {/if}
</div>

<EntityPicker
  {open}
  tableName="base_persons_persons"
  searchFields={['full_name']}
  displayPattern={(row) => {
    const name = row.full_name ?? `#${row.id}`;
    const ico = row.company_id ? ` · IČO ${row.company_id}` : '';
    return `${name}${ico}`;
  }}
  onSelect={handleSelect}
  onClose={() => (open = false)}
/>

<style>
  .shpd-picker-demo {
    padding: var(--shpd-space-lg);
    max-width: 600px;
    margin: 0 auto;
  }

  .shpd-picker-demo__open {
    padding: var(--shpd-space-sm) var(--shpd-space-md);
    background: var(--shpd-color-primary);
    color: white;
    border: 0;
    border-radius: 4px;
    cursor: pointer;
    font-size: 1rem;
  }

  .shpd-picker-demo__open:hover {
    background: var(--shpd-color-primary-hover);
  }

  .shpd-picker-demo__result {
    margin-top: var(--shpd-space-lg);
    padding: var(--shpd-space-md);
    background: var(--shpd-color-surface-alt, #f5f5f5);
    border-radius: 4px;
  }

  .shpd-picker-demo__result pre {
    margin: 0;
    font-size: 0.8125rem;
    overflow: auto;
  }
</style>
