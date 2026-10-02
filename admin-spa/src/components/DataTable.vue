<template>
    <div class="data-table">
        <div v-if="$slots.toolbar" class="toolbar">
            <slot name="toolbar" />
        </div>

        <el-table
            ref="tableRef"
            v-bind="$attrs"
            v-loading="loading"
            :data="rows"
            :row-key="rowKey"
            size="small"
            border
            empty-text="暂无数据"
            @selection-change="(rows) => $emit('selection-change', rows)"
            @sort-change="(payload) => $emit('sort-change', payload)"
        >
            <slot />
        </el-table>

        <div v-if="total > 0" class="pager">
            <el-pagination
                background
                size="small"
                :current-page="page"
                :page-size="limit"
                :page-sizes="pageSizes"
                :total="total"
                layout="total, sizes, prev, pager, next, jumper"
                @current-change="onPageChange"
                @size-change="onSizeChange"
            />
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';

// Attributes (and listeners, e.g. `@sort-change`) fall through to the inner
// `el-table` explicitly, so the wrapper must not also inherit them on its root.
defineOptions({ inheritAttrs: false });

// Shared paginated table wrapper: every admin list view uses the same
// `{list,total,page,limit,total_page}` envelope, so the boilerplate lives here.
const props = defineProps({
    rows: { type: Array, default: () => [] },
    total: { type: Number, default: 0 },
    page: { type: Number, default: 1 },
    limit: { type: Number, default: 20 },
    loading: { type: Boolean, default: false },
    rowKey: { type: String, default: 'id' },
    pageSizes: { type: Array, default: () => [10, 15, 20, 25, 50, 100] },
});

const emit = defineEmits(['update:page', 'update:limit', 'pagination', 'selection-change', 'sort-change']);

const tableRef = ref(null);

const onPageChange = (value) => {
    emit('update:page', value);
    emit('pagination', { page: value, limit: props.limit });
};

const onSizeChange = (value) => {
    emit('update:limit', value);
    emit('pagination', { page: 1, limit: value });
};

defineExpose({ tableRef });
</script>

<style scoped>
.data-table {
    overflow-x: auto;
}

.pager {
    margin-top: 14px;
    display: flex;
    justify-content: flex-end;
}
</style>
