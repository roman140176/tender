<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import TenderFilters from './Partials/TenderFilters.vue';
import Pagination from './Partials/Pagination.vue';

defineProps({
    tenders: { type: Object, required: true }, // Laravel paginator
    filters: { type: Object, required: true },
    filterOptions: { type: Object, required: true },
});

const levelClass = (level) => ({
    high: 'bg-green-100 text-green-800',
    medium: 'bg-blue-100 text-blue-800',
    weak: 'bg-yellow-100 text-yellow-800',
    low: 'bg-gray-100 text-gray-600',
}[level] ?? 'bg-gray-100 text-gray-600');

const money = (v) => (v === null ? '—' : Number(v).toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' ₽');
const date = (v) => (v ? new Date(v).toLocaleDateString('ru-RU') : '—');
</script>

<template>
    <Head title="Закупки" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Закупки</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                <TenderFilters :filters="filters" :options="filterOptions" />

                <div class="overflow-x-auto bg-white shadow-sm sm:rounded-lg">
                    <p v-if="!tenders.data.length" class="p-6 text-gray-500">Закупок не найдено.</p>
                    <table v-else class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50 text-left text-xs font-medium uppercase text-gray-500">
                            <tr>
                                <th class="px-4 py-3">Название</th>
                                <th class="px-4 py-3">Заказчик</th>
                                <th class="px-4 py-3">Регион</th>
                                <th class="px-4 py-3">Сумма</th>
                                <th class="px-4 py-3">Дедлайн</th>
                                <th class="px-4 py-3">Релевантность</th>
                                <th class="px-4 py-3">Статус</th>
                                <th class="px-4 py-3">Ответственный</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white text-sm">
                            <tr v-for="ct in tenders.data" :key="ct.id" class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <Link :href="route('tenders.show', ct.id)" class="font-medium text-indigo-600 hover:underline">
                                        {{ ct.tender.title }}
                                    </Link>
                                    <div class="text-xs text-gray-400">{{ ct.tender.source?.name }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ ct.tender.customer_name ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ ct.tender.region ?? '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ money(ct.tender.price) }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ date(ct.tender.deadline_at) }}</td>
                                <td class="px-4 py-3">
                                    <span class="rounded px-2 py-1 text-xs font-medium" :class="levelClass(ct.best_level)">
                                        {{ ct.best_score }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-gray-600">{{ filterOptions.statuses[ct.status] }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ ct.assignee?.name ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <Pagination :links="tenders.links" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
