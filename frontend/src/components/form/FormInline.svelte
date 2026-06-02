<script>
  import Input from '../ui/Input.svelte';
  import Select from '../ui/Select.svelte';
  import DateInput from '../ui/DateInput.svelte';
  import NumberInput from '../ui/NumberInput.svelte';
  import { layoutStore } from '../../stores/layout.svelte.js';

  let {
    element,
    formData = $bindable({}),
    fieldErrors = {},
    disabled = false,
    onTrigger,
    id,
  } = $props();

  function innerId(_idx) {
    return `${id}-${_idx}`;
  }

  function handleChange(inner) {
    if (inner.triggers === 'reload' && inner.column) {
      onTrigger?.(inner.column);
    }
  }
</script>

<!-- Vstupní pole pro jeden inner element. Sdílené oběma větvemi (desktop
     flex skupina i mobilní rozpad), aby se input-typový {#if} neduplikoval.
     bind:value cílí na outer-scope $bindable formData[inner.column] —
     funguje i uvnitř snippetu (snippet uzavírá komponentní scope). -->
{#snippet inputFor(inner, i)}
  {#if inner.type === 'select'}
    <Select
      id={i === 0 ? id : innerId(i)}
      bind:value={formData[inner.column]}
      options={inner.options ?? []}
      required={inner.required ?? false}
      disabled={disabled || inner.read_only === true}
      error={fieldErrors[inner.column] ?? null}
      onchange={() => handleChange(inner)}
    />
  {:else if inner.input_type === 'date'}
    <DateInput
      id={i === 0 ? id : innerId(i)}
      bind:value={formData[inner.column]}
      required={inner.required ?? false}
      disabled={disabled || inner.read_only === true}
      error={fieldErrors[inner.column] ?? null}
    />
  {:else if inner.input_type === 'number'}
    <NumberInput
      id={i === 0 ? id : innerId(i)}
      bind:value={formData[inner.column]}
      required={inner.required ?? false}
      disabled={disabled || inner.read_only === true}
      error={fieldErrors[inner.column] ?? null}
    />
  {:else}
    <Input
      id={i === 0 ? id : innerId(i)}
      type={inner.input_type ?? 'text'}
      bind:value={formData[inner.column]}
      placeholder={inner.placeholder}
      required={inner.required ?? false}
      disabled={disabled || inner.read_only === true}
      error={fieldErrors[inner.column] ?? null}
    />
  {/if}
{/snippet}

{#if !element.hidden}
  {#if layoutStore.isMobile}
    <!-- MOBIL: inline skupina se rozpadne na samostatná pole pod sebou.
         Každý inner element = jedna label+input dvojice, emitovaná jako
         dva grid sourozenci (stejně jako FormFieldRow), takže grid
         FormColumn je naskládá pod sebe a labely zarovná. Mini-labely
         i flex skupina zanikají — každé pole má svůj plnohodnotný label. -->
    {#each element.elements as inner, i (inner.column ?? i)}
      <label class="shpd-form-field-row__label" for={i === 0 ? id : innerId(i)}>
        {inner.label ?? ''}{#if inner.required}<span class="shpd-form-field-row__required">*</span>{/if}
      </label>
      <div class="shpd-form-field-row__input">
        {@render inputFor(inner, i)}
      </div>
    {/each}
  {:else}
    <!-- DESKTOP: beze změny — jeden velký label + flex skupina s mini-labely. -->
    <label class="shpd-form-field-row__label" for={id}>
      {element.elements[0].label ?? ''}
    </label>
    <div class="shpd-form-inline">
      {#each element.elements as inner, i (inner.column ?? i)}
        <span class="shpd-form-inline__item">
          {#if i > 0}<span class="shpd-form-inline__mini-label">{inner.label ?? ''}</span>{/if}
          {@render inputFor(inner, i)}
        </span>
      {/each}
    </div>
  {/if}
{/if}

<style>
  .shpd-form-inline {
    display: flex;
    gap: var(--shpd-space-md);
    align-items: baseline;
    flex-wrap: nowrap;
  }
  .shpd-form-inline__item {
    display: flex;
    gap: var(--shpd-space-md);
    align-items: baseline;
    flex: 1 1 0;
    min-width: 0;
  }
  /*
   * Mini-labely u 2. a dalších polí v inline skupině. Vizuálně shodné
   * s velkým labelem (.shpd-form-field-row__label) — stejná velikost, váha
   * a barva, aby mezi velkým a mini-labelem nebyl rozdíl. Bez dvojtečky
   * v template ze stejného důvodu.
   */
  .shpd-form-inline__mini-label {
    font-size: var(--shpd-font-size-sm);
    font-weight: 500;
    color: var(--shpd-color-text);
    white-space: nowrap;
  }
</style>
