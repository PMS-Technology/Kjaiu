<template>
    <div class="stat-card">
        <div class="stat-label">{{ label }}</div>
        <div class="stat-value" :class="{ accent }">{{ value }}</div>
        <div v-if="hint" class="stat-hint">{{ hint }}</div>
    </div>
</template>

<script setup>
defineProps({
    label: { type: String, default: '' },
    value: { type: [String, Number], default: 0 },
    hint: { type: String, default: '' },
    accent: { type: Boolean, default: false },
});
</script>

<style scoped>
.stat-card {
    background: #fff;
    border-radius: 8px;
    padding: 16px 18px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
    margin-bottom: 14px;
}

.stat-label {
    font-size: 13px;
    color: #6b7280;
}

.stat-value {
    font-size: 22px;
    font-weight: 600;
    margin-top: 6px;
}

.stat-value.accent {
    color: #2563eb;
}

.stat-hint {
    margin-top: 4px;
    font-size: 12px;
    color: #9ca3af;
}
</style>
