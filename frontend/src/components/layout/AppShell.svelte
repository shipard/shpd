<script>
  import Header from './Header.svelte';
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
  <Header {onLogout} />

  <div class="shpd-shell__body">
    <Sidebar onNavigate={handleNavigate} activeId={navigationStore.activeTabId} />

    <div class="shpd-shell__main">
      <TabBar />
      <ContentArea activeTab={navigationStore.activeTab} />
    </div>
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

  .shpd-shell__main {
    display: flex;
    flex-direction: column;
    flex: 1;
    overflow: hidden;
  }
</style>
