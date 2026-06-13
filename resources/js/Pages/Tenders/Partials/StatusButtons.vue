<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
    companyTender: { type: Object, required: true },
    statusOptions: { type: Object, required: true },
    assignableUsers: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
});

const processing = ref(false);

const setStatus = (event) => {
    if (processing.value) return;
    processing.value = true;
    router.patch(route('tenders.status', props.companyTender.id), { status: event.target.value }, {
        preserveScroll: true,
        onFinish: () => { processing.value = false; },
    });
};

const assign = (event) => {
    if (processing.value) return;
    processing.value = true;
    router.patch(route('tenders.assign', props.companyTender.id), { assigned_user_id: event.target.value || null }, {
        preserveScroll: true,
        onFinish: () => { processing.value = false; },
    });
};
</script>

<template>
    <div class="bg-white p-6 shadow-sm sm:rounded-lg">
        <h3 class="mb-3 font-semibold text-gray-800">Статус</h3>
        <select
            :value="companyTender.status"
            :disabled="!canManage || processing"
            class="block w-full rounded-md border-gray-300 text-sm disabled:bg-gray-100"
            @change="setStatus"
        >
            <option v-for="(label, value) in statusOptions" :key="value" :value="value">{{ label }}</option>
        </select>

        <h3 class="mb-2 mt-5 font-semibold text-gray-800">Ответственный</h3>
        <select
            :value="companyTender.assigned_user_id ?? ''"
            :disabled="!canManage || processing"
            class="block w-full rounded-md border-gray-300 text-sm disabled:bg-gray-100"
            @change="assign"
        >
            <option value="">— не назначен —</option>
            <option v-for="u in assignableUsers" :key="u.id" :value="u.id">{{ u.name }}</option>
        </select>
        <p v-if="!canManage" class="mt-2 text-xs text-gray-400">Только просмотр.</p>
    </div>
</template>
