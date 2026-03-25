<script lang="ts">
  import Input from '../ui/Input.svelte';
  import TextArea from '../ui/TextArea.svelte';
  import Checkbox from '../ui/Checkbox.svelte';
  import Select from '../ui/Select.svelte';
  import NumberInput from '../ui/NumberInput.svelte';
  import DateInput from '../ui/DateInput.svelte';

  /** Column metadata shape as returned by the _meta API */
  interface ColumnMeta {
    id: string;
    name: string;
    type: string;
    nullable?: boolean;
    length?: number;
    precision?: number;
    scale?: number;
    group?: string;
    cfgItem?: string;
    default?: unknown;
  }

  interface Props {
    column: ColumnMeta;
    value?: unknown;
    error?: string | null;
    disabled?: boolean;
  }

  let {
    column,
    value = $bindable(undefined),
    error = null,
    disabled = false,
  }: Props = $props();

  // Auto-managed fields — never rendered in forms
  const AUTO_MANAGED = ['id', 'created', 'modified'];

  const skip = $derived(AUTO_MANAGED.includes(column.id));

  // required = not nullable (booleans are never required)
  const required = $derived(column.type !== 'boolean' && column.nullable === false);

  // Derive step from numeric scale, e.g. scale=2 → 0.01
  const numericStep = $derived(
    column.scale != null && column.scale > 0
      ? parseFloat((1 / Math.pow(10, column.scale)).toFixed(column.scale))
      : 1
  );
</script>

{#if !skip}
  {#if column.type === 'boolean'}
    <Checkbox
      label={column.name}
      bind:checked={value as boolean}
      {disabled}
    />

  {:else if column.type === 'text' || column.type === 'longtext'}
    <TextArea
      label={column.name}
      bind:value={value as string}
      {required}
      {disabled}
      {error}
    />

  {:else if column.type === 'int' || column.type === 'smallint' || column.type === 'bigint' || column.type === 'tinyint'}
    <NumberInput
      label={column.name}
      bind:value={value as number}
      step={1}
      {required}
      {disabled}
      {error}
    />

  {:else if column.type === 'numeric'}
    <NumberInput
      label={column.name}
      bind:value={value as number}
      step={numericStep}
      {required}
      {disabled}
      {error}
    />

  {:else if column.type === 'date'}
    <DateInput
      label={column.name}
      bind:value={value as string}
      {required}
      {disabled}
      {error}
    />

  {:else if column.type === 'datetime'}
    <Input
      label={column.name}
      type="datetime-local"
      bind:value={value as string}
      {required}
      {disabled}
      {error}
    />

  {:else if column.type === 'time'}
    <Input
      label={column.name}
      type="time"
      bind:value={value as string}
      {required}
      {disabled}
      {error}
    />

  {:else if column.type === 'enumInt' || column.type === 'enumString'}
    <!-- Options are empty for now; future: load from cfgItem config -->
    <Select
      label={column.name}
      bind:value={value as string | number}
      options={[]}
      {required}
      {disabled}
      {error}
    />

  {:else if column.type === 'varchar'}
    <Input
      label={column.name}
      type="text"
      bind:value={value as string}
      maxlength={column.length}
      {required}
      {disabled}
      {error}
    />

  {:else}
    <!-- Fallback for any unrecognised type -->
    <Input
      label={column.name}
      type="text"
      bind:value={value as string}
      {required}
      {disabled}
      {error}
    />
  {/if}
{/if}
