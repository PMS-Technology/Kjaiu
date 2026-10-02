<template>
    <router-view />
</template>

<script setup>
// Root component: the router decides between the login page and the
// authenticated layout.
</script>

<style>
html,
body {
    margin: 0;
    height: 100%;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    background: #f5f7fa;
    color: #1f2d3d;
}

#admin-app {
    height: 100%;
}

.page-card {
    background: #fff;
    border-radius: 8px;
    padding: 18px 20px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
}

.toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin-bottom: 14px;
}

.toolbar .spacer {
    flex: 1;
}
</style>
