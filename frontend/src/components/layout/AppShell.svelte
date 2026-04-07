<script>
  import Sidebar from './Sidebar.svelte';
  import TabBar from './TabBar.svelte';
  import ContentArea from './ContentArea.svelte';
  import { navigationStore } from '../../stores/navigation.svelte.js';

  let { onLogout } = $props();

  function handleNavigate(item) {
    navigationStore.openTab(item);
  }
</script>

<div class="shpd-shell">
  <Sidebar onNavigate={handleNavigate} activeId={navigationStore.activeTabId} {onLogout} />

  <div class="shpd-shell__main">
    <TabBar />
    <ContentArea activeTab={navigationStore.activeTab} />
  </div>
</div>

<style>
  .shpd-shell {
    display: flex;
    height: 100vh;
    overflow: hidden;
    position: relative;
  }

  .shpd-shell__main {
    display: flex;
    flex-direction: column;
    flex: 1;
    overflow: hidden;
  }
</style>
