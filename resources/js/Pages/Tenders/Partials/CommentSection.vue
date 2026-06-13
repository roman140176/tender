<script setup>
defineProps({
    companyTender: { type: Object, required: true },
    comments: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
});
</script>

<template>
    <div class="bg-white p-6 shadow-sm sm:rounded-lg">
        <h3 class="mb-3 font-semibold text-gray-800">Комментарии</h3>
        <!-- наполняется в Задаче 8 -->
    </div>
</template>
