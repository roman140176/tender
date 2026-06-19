<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    counters: { type: Object, required: true }, // { new, relevant }
    highPriority: { type: Array, required: true }, // [{ id, best_score, best_level, tender: {...} }]
    upcomingDeadlines: { type: Array, required: true }, // [{ id, tender: {...} }]
    recentRuns: { type: Object, required: true }, // { radar: [...], collect: [...] }
    dynamics: { type: Array, required: true }, // [{ date, count }]
});

const money = (v) => (v === null || v === undefined ? '—' : Number(v).toLocaleString('ru-RU', { maximumFractionDigits: 0 }) + ' ₽');
const date = (v) => (v ? new Date(v).toLocaleDateString('ru-RU') : '—');
const dateTime = (v) => (v ? new Date(v).toLocaleString('ru-RU') : '—');

const levelClass = (level) => ({
    high: 'bg-green-100 text-green-800',
    medium: 'bg-blue-100 text-blue-800',
    weak: 'bg-yellow-100 text-yellow-800',
    low: 'bg-gray-100 text-gray-600',
}[level] ?? 'bg-gray-100 text-gray-600');

const statusClass = (s) => ({ ok: 'text-green-700', error: 'text-red-700', running: 'text-gray-500' }[s] ?? 'text-gray-700');
const statusLabel = (v) => ({ running: 'Запущен', ok: 'Успешно', error: 'Ошибка' }[v] ?? v);
const triggerLabel = (v) => ({ import: 'Импорт', schedule: 'Расписание', manual: 'Вручную' }[v] ?? v);

// --- inline-SVG bar-chart по dynamics ---
const chartW = 720;
const chartH = 120;
const barGap = 2;
const maxCount = computed(() => Math.max(1, ...props.dynamics.map((d) => d.count)));
const barWidth = computed(() => props.dynamics.length ? (chartW - barGap * (props.dynamics.length - 1)) / props.dynamics.length : 0);
const bars = computed(() =>
    props.dynamics.map((d, i) => {
        const h = Math.round((d.count / maxCount.value) * (chartH - 16));
        return {
            x: i * (barWidth.value + barGap),
            y: chartH - h,
            w: barWidth.value,
            h,
            date: d.date,
            count: d.count,
        };
    }),
);
const totalFound = computed(() => props.dynamics.reduce((acc, d) => acc + d.count, 0));
</script>

<template>
    <Head title="Дашборд" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Дашборд</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">

                <!-- 1. Счётчики -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="rounded-lg bg-white p-6 shadow-sm">
                        <div class="text-sm text-gray-500">Новых закупок (30 дней)</div>
                        <div class="mt-1 text-3xl font-semibold text-gray-900">{{ counters.new }}</div>
                    </div>
                    <div class="rounded-lg bg-white p-6 shadow-sm">
                        <div class="text-sm text-gray-500">Релевантных (30 дней)</div>
                        <div class="mt-1 text-3xl font-semibold text-indigo-600">{{ counters.relevant }}</div>
                    </div>
                </div>

                <!-- 5. График динамики (inline-SVG) -->
                <div class="rounded-lg bg-white p-6 shadow-sm">
                    <div class="mb-3 flex items-baseline justify-between">
                        <h3 class="font-semibold text-gray-800">Динамика найденных (30 дней)</h3>
                        <span class="text-sm text-gray-500">всего: {{ totalFound }}</span>
                    </div>
                    <svg
                        :viewBox="`0 0 ${chartW} ${chartH}`"
                        class="h-32 w-full"
                        preserveAspectRatio="none"
                        role="img"
                        aria-label="График динамики найденных закупок за 30 дней"
                    >
                        <rect
                            v-for="b in bars"
                            :key="b.date"
                            :x="b.x"
                            :y="b.y"
                            :width="b.w"
                            :height="b.h"
                            rx="1"
                            class="fill-indigo-400"
                        >
                            <title>{{ b.date }}: {{ b.count }}</title>
                        </rect>
                    </svg>
                </div>

                <!-- 2. Высокоприоритетные закупки -->
                <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="mb-3 font-semibold text-gray-800">Высокий приоритет</h3>
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-3 py-2">Название</th>
                                <th class="px-3 py-2">Регион</th>
                                <th class="px-3 py-2">Сумма</th>
                                <th class="px-3 py-2">Дедлайн</th>
                                <th class="px-3 py-2">Балл</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y bg-white">
                            <tr v-for="ct in highPriority" :key="'h' + ct.id" class="hover:bg-gray-50">
                                <td class="px-3 py-2">
                                    <Link :href="route('tenders.show', ct.id)" class="font-medium text-indigo-600 hover:underline">
                                        {{ ct.tender?.title ?? '—' }}
                                    </Link>
                                </td>
                                <td class="px-3 py-2 text-gray-600">{{ ct.tender.region ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ money(ct.tender.price) }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ date(ct.tender.deadline_at) }}</td>
                                <td class="px-3 py-2">
                                    <span class="rounded px-2 py-1 text-xs font-medium" :class="levelClass(ct.best_level)">{{ ct.best_score }}</span>
                                </td>
                            </tr>
                            <tr v-if="!highPriority.length"><td colspan="5" class="px-3 py-4 text-gray-400">Высокоприоритетных закупок нет.</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- 3. Ближайшие дедлайны -->
                <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <h3 class="mb-3 font-semibold text-gray-800">Ближайшие дедлайны</h3>
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                            <tr>
                                <th class="px-3 py-2">Название</th>
                                <th class="px-3 py-2">Регион</th>
                                <th class="px-3 py-2">Сумма</th>
                                <th class="px-3 py-2">Дедлайн</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y bg-white">
                            <tr v-for="ct in upcomingDeadlines" :key="'d' + ct.id" class="hover:bg-gray-50">
                                <td class="px-3 py-2">
                                    <Link :href="route('tenders.show', ct.id)" class="font-medium text-indigo-600 hover:underline">
                                        {{ ct.tender?.title ?? '—' }}
                                    </Link>
                                </td>
                                <td class="px-3 py-2 text-gray-600">{{ ct.tender.region ?? '—' }}</td>
                                <td class="px-3 py-2 text-gray-600">{{ money(ct.tender.price) }}</td>
                                <td class="px-3 py-2 font-medium text-gray-800">{{ date(ct.tender.deadline_at) }}</td>
                            </tr>
                            <tr v-if="!upcomingDeadlines.length"><td colspan="4" class="px-3 py-4 text-gray-400">Предстоящих дедлайнов нет.</td></tr>
                        </tbody>
                    </table>
                </div>

                <!-- 4. Последние запуски + ошибки источников -->
                <div class="grid gap-6 lg:grid-cols-2">
                    <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                        <h3 class="mb-3 font-semibold text-gray-800">Последние запуски радара</h3>
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                <tr>
                                    <th class="px-3 py-2">Направление</th>
                                    <th class="px-3 py-2">Триггер</th>
                                    <th class="px-3 py-2">Статус</th>
                                    <th class="px-3 py-2">Обраб./Матчей</th>
                                    <th class="px-3 py-2">Начало</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y bg-white">
                                <tr v-for="r in recentRuns.radar" :key="'rr' + r.id">
                                    <td class="px-3 py-2">{{ r.searchProfile?.name ?? '—' }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ triggerLabel(r.trigger) }}</td>
                                    <td class="px-3 py-2" :class="statusClass(r.status)">{{ statusLabel(r.status) }}</td>
                                    <td class="px-3 py-2">{{ r.processed_count }} / {{ r.matched_count }}</td>
                                    <td class="px-3 py-2 text-gray-500">{{ dateTime(r.started_at) }}</td>
                                </tr>
                                <tr v-if="!recentRuns.radar.length"><td colspan="5" class="px-3 py-4 text-gray-400">Запусков радара нет.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                        <h3 class="mb-3 font-semibold text-gray-800">Последний сбор источников</h3>
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                                <tr>
                                    <th class="px-3 py-2">Источник</th>
                                    <th class="px-3 py-2">Триггер</th>
                                    <th class="px-3 py-2">Статус</th>
                                    <th class="px-3 py-2">Найдено/Новых</th>
                                    <th class="px-3 py-2">Обновлено</th>
                                    <th class="px-3 py-2">Ошибок</th>
                                    <th class="px-3 py-2">Начало</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y bg-white">
                                <tr v-for="r in recentRuns.collect" :key="'cr' + r.id">
                                    <td class="px-3 py-2">{{ r.source?.name ?? '—' }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ triggerLabel(r.trigger) }}</td>
                                    <td class="px-3 py-2" :class="statusClass(r.status)">{{ statusLabel(r.status) }}</td>
                                    <td class="px-3 py-2">{{ r.fetched_count }} / {{ r.new_count }}</td>
                                    <td class="px-3 py-2 text-gray-600">{{ r.updated_count }}</td>
                                    <td class="px-3 py-2" :class="r.error_count > 0 ? 'font-semibold text-red-700' : 'text-gray-500'">{{ r.error_count }}</td>
                                    <td class="px-3 py-2 text-gray-500">{{ dateTime(r.started_at) }}</td>
                                </tr>
                                <tr v-if="!recentRuns.collect.length"><td colspan="7" class="px-3 py-4 text-gray-400">Сборов нет.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
