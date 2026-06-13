<script setup>
defineProps({
    companyTender: { type: Object, required: true },
    statusOptions: { type: Object, required: true },
    assignableUsers: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
});
</script>

<template>
    <div class="bg-white p-6 shadow-sm sm:rounded-lg">
        <h3 class="mb-3 font-semibold text-gray-800">Статус</h3>
        <!-- наполняется в Задачах 7 (статус) и 9 (ответственный) -->
    </div>
</template>
