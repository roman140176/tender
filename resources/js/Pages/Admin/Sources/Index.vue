<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';

defineProps({ sources: { type: Array, required: true } });

const TYPE_LABELS = {
    open_data: 'Открытые данные',
    api: 'API',
    manual: 'Ручной импорт',
    parsing: 'Парсинг',
};

const triggerCollect = (source) => {
    if (!confirm(`Запустить сбор источника «${source.name}»?`)) return;
    router.post(route('admin.sources.collect', source.id), {}, { preserveScroll: true });
};

const date = (v) => (v ? new Date(v).toLocaleString('ru-RU') : '—');
</script>

<template>
    <Head title="Источники (Суперадмин)" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Источники — управление сбором</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-4 py-3">Код</th>
                                <th class="px-4 py-3">Название</th>
                                <th class="px-4 py-3">Тип</th>
                                <th class="px-4 py-3">Расписание</th>
                                <th class="px-4 py-3">Последний сбор</th>
                                <th class="px-4 py-3">Адаптер</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y bg-white">
                            <tr v-for="source in sources" :key="source.id">
                                <td class="px-4 py-3 font-mono text-gray-700">{{ source.code }}</td>
                                <td class="px-4 py-3 font-medium text-gray-900">{{ source.name }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ TYPE_LABELS[source.connection_type] ?? source.connection_type }}</td>
                                <td class="px-4 py-3 font-mono text-gray-500">{{ source.schedule_cron ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-500">{{ date(source.last_collected_at) }}</td>
                                <td class="px-4 py-3 text-xs text-gray-400 truncate max-w-xs" :title="source.adapter_class">
                                    {{ source.adapter_class ? source.adapter_class.split('\\').pop() : '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <button
                                        v-if="source.adapter_class"
                                        type="button"
                                        class="rounded bg-indigo-600 px-3 py-1 text-xs text-white hover:bg-indigo-700 disabled:opacity-50"
                                        @click="triggerCollect(source)"
                                    >
                                        Запустить
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="!sources.length">
                                <td colspan="7" class="px-4 py-4 text-gray-400">Источники не найдены.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
