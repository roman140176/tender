<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    radarRuns: { type: Array, required: true },
    collectRuns: { type: Array, required: true },
});

const date = (v) => (v ? new Date(v).toLocaleString('ru-RU') : '—');
const statusClass = (s) => ({ ok: 'text-green-700', error: 'text-red-700', running: 'text-gray-500' }[s] ?? 'text-gray-700');
const triggerLabel = (v) => ({ import: 'Импорт', schedule: 'Расписание', manual: 'Вручную' }[v] ?? v);
const statusLabel = (v) => ({ running: 'Запущен', ok: 'Успешно', error: 'Ошибка' }[v] ?? v);
</script>

<template>
    <Head title="Запуски" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Запуски</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="mb-3 font-semibold text-gray-800">Сбор данных (импорт/источники)</h3>
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr><th class="px-3 py-2">Источник</th><th class="px-3 py-2">Триггер</th><th class="px-3 py-2">Статус</th><th class="px-3 py-2">Найдено/Новых/Ошибок</th><th class="px-3 py-2">Начало</th></tr>
                        </thead>
                        <tbody class="divide-y bg-white">
                            <tr v-for="r in collectRuns" :key="'c' + r.id">
                                <td class="px-3 py-2">{{ r.source?.name ?? '—' }}</td>
                                <td class="px-3 py-2">{{ triggerLabel(r.trigger) }}</td>
                                <td class="px-3 py-2" :class="statusClass(r.status)">{{ statusLabel(r.status) }}</td>
                                <td class="px-3 py-2">{{ r.fetched_count }} / {{ r.new_count }} / {{ r.error_count }}</td>
                                <td class="px-3 py-2 text-gray-500">{{ date(r.started_at) }}</td>
                            </tr>
                            <tr v-if="!collectRuns.length"><td colspan="5" class="px-3 py-4 text-gray-400">Запусков нет.</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="mb-3 font-semibold text-gray-800">Матчинг (радар)</h3>
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr><th class="px-3 py-2">Направление</th><th class="px-3 py-2">Триггер</th><th class="px-3 py-2">Статус</th><th class="px-3 py-2">Обработано/Матчей</th><th class="px-3 py-2">Начало</th></tr>
                        </thead>
                        <tbody class="divide-y bg-white">
                            <tr v-for="r in radarRuns" :key="'r' + r.id">
                                <td class="px-3 py-2">{{ r.searchProfile?.name ?? '—' }}</td>
                                <td class="px-3 py-2">{{ triggerLabel(r.trigger) }}</td>
                                <td class="px-3 py-2" :class="statusClass(r.status)">{{ statusLabel(r.status) }}</td>
                                <td class="px-3 py-2">{{ r.processed_count }} / {{ r.matched_count }}</td>
                                <td class="px-3 py-2 text-gray-500">{{ date(r.started_at) }}</td>
                            </tr>
                            <tr v-if="!radarRuns.length"><td colspan="5" class="px-3 py-4 text-gray-400">Запусков нет.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
