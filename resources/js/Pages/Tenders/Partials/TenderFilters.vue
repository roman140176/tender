<script setup>
import { reactive, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
});

const form = reactive({
    q: props.filters.q ?? '',
    status: props.filters.status ?? '',
    level: props.filters.level ?? '',
    law_type: props.filters.law_type ?? '',
    source_id: props.filters.source_id ?? '',
    region: props.filters.region ?? '',
    customer: props.filters.customer ?? '',
    price_min: props.filters.price_min ?? '',
    price_max: props.filters.price_max ?? '',
    deadline_to: props.filters.deadline_to ?? '',
});

let timer = null;
const apply = () => {
    const query = Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '' && v !== null));
    router.get(route('tenders.index'), query, { preserveState: true, preserveScroll: true, replace: true });
};
const applyNow = () => {
    clearTimeout(timer);
    apply();
};

// дебаунс только для текстовых полей; select/date применяются сразу через @change
watch(
    () => [form.q, form.region, form.customer, form.price_min, form.price_max, form.deadline_to],
    () => {
        clearTimeout(timer);
        timer = setTimeout(apply, 400);
    },
);

const reset = () => {
    Object.keys(form).forEach((k) => (form[k] = ''));
    applyNow();
};
</script>

<template>
    <div class="grid grid-cols-1 gap-4 bg-white p-4 shadow-sm sm:grid-cols-2 sm:rounded-lg lg:grid-cols-4">
        <div class="lg:col-span-2">
            <InputLabel for="q" value="Поиск (название/описание)" />
            <TextInput id="q" v-model="form.q" class="mt-1 block w-full" />
        </div>
        <div>
            <InputLabel for="status" value="Статус" />
            <select id="status" v-model="form.status" class="mt-1 block w-full rounded-md border-gray-300 text-sm" @change="applyNow">
                <option value="">— любой —</option>
                <option v-for="(label, value) in options.statuses" :key="value" :value="value">{{ label }}</option>
            </select>
        </div>
        <div>
            <InputLabel for="level" value="Релевантность" />
            <select id="level" v-model="form.level" class="mt-1 block w-full rounded-md border-gray-300 text-sm" @change="applyNow">
                <option value="">— любая —</option>
                <option v-for="(label, value) in options.levels" :key="value" :value="value">{{ label }}</option>
            </select>
        </div>
        <div>
            <InputLabel for="law" value="Закон" />
            <select id="law" v-model="form.law_type" class="mt-1 block w-full rounded-md border-gray-300 text-sm" @change="applyNow">
                <option value="">— любой —</option>
                <option v-for="(label, value) in options.laws" :key="value" :value="value">{{ label }}</option>
            </select>
        </div>
        <div>
            <InputLabel for="source" value="Источник" />
            <select id="source" v-model="form.source_id" class="mt-1 block w-full rounded-md border-gray-300 text-sm" @change="applyNow">
                <option value="">— любой —</option>
                <option v-for="s in options.sources" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
        </div>
        <div>
            <InputLabel for="region" value="Регион" />
            <TextInput id="region" v-model="form.region" class="mt-1 block w-full" />
        </div>
        <div>
            <InputLabel for="customer" value="Заказчик" />
            <TextInput id="customer" v-model="form.customer" class="mt-1 block w-full" />
        </div>
        <div>
            <InputLabel for="price_min" value="Цена от" />
            <TextInput id="price_min" v-model="form.price_min" class="mt-1 block w-full" />
        </div>
        <div>
            <InputLabel for="price_max" value="Цена до" />
            <TextInput id="price_max" v-model="form.price_max" class="mt-1 block w-full" />
        </div>
        <div>
            <InputLabel for="deadline_to" value="Дедлайн до" />
            <TextInput id="deadline_to" v-model="form.deadline_to" type="date" class="mt-1 block w-full" />
        </div>
        <div class="flex items-end">
            <SecondaryButton type="button" @click="reset">Сбросить</SecondaryButton>
        </div>
    </div>
</template>
