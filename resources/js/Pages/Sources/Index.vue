<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps({ sources: Array });

const TYPE_LABELS = {
    open_data: 'Открытые данные',
    api: 'API',
    manual: 'Ручной импорт',
    parsing: 'Парсинг',
};

const error = computed(() => usePage().props.errors?.enabled);

const toggle = (source) => {
    router.patch(route('sources.update', source.id), { enabled: !source.enabled }, { preserveScroll: true });
};
</script>

<template>
    <Head title="Источники" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Источники закупок</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <p v-if="error" class="mb-4 rounded bg-red-50 p-3 text-sm text-red-700">{{ error }}</p>

                <div class="divide-y divide-gray-200 bg-white shadow-sm sm:rounded-lg">
                    <div v-for="source in sources" :key="source.id" class="flex items-center justify-between p-6">
                        <div>
                            <p class="font-medium text-gray-900">{{ source.name }}</p>
                            <p class="text-sm text-gray-500">{{ TYPE_LABELS[source.connection_type] ?? source.connection_type }}</p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            :aria-checked="source.enabled"
                            :class="source.enabled ? 'bg-indigo-600' : 'bg-gray-200'"
                            class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors"
                            @click="toggle(source)"
                        >
                            <span
                                :class="source.enabled ? 'translate-x-6' : 'translate-x-1'"
                                class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
                            />
                        </button>
                    </div>
                </div>

                <p class="mt-4 text-sm text-gray-500">
                    Включённые источники участвуют в поиске закупок. Число источников ограничено тарифом.
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
