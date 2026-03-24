<script>
  import Header from './Header.svelte';
  import Sidebar from './Sidebar.svelte';
  import ContentArea from './ContentArea.svelte';

  let { onLogout } = $props();

  let activeNavId = $state(null);

  function handleNavigate(item) {
    activeNavId = item.id;
    // Future: open tab in ContentArea
  }
</script>

<div class="shpd-shell">
  <Header {onLogout} />

  <div class="shpd-shell__body">
    <Sidebar onNavigate={handleNavigate} activeId={activeNavId} />
    <ContentArea />
  </div>
</div>

<style>
  .shpd-shell {
    display: flex;
    flex-direction: column;
    height: 100vh;
    overflow: hidden;
  }

  .shpd-shell__body {
    display: flex;
    flex: 1;
    overflow: hidden;
  }
</style>
