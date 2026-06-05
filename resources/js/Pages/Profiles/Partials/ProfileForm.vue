<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TagInput from '@/Components/TagInput.vue';
import TextInput from '@/Components/TextInput.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    profile: { type: Object, default: null },
});

const LAW_TYPES = [
    { value: 'fz44', label: '44-ФЗ' },
    { value: 'fz223', label: '223-ФЗ' },
    { value: 'commercial', label: 'Коммерческие' },
];

const form = useForm({
    name: props.profile?.name ?? '',
    law_types: props.profile?.law_types ?? [],
    regions: props.profile?.regions ?? [],
    excluded_regions: props.profile?.excluded_regions ?? [],
    okpd2_codes: props.profile?.okpd2_codes ?? [],
    min_price: props.profile?.min_price ?? null,
    max_price: props.profile?.max_price ?? null,
    deadline_min_days: props.profile?.deadline_min_days ?? 0,
    notification_threshold: props.profile?.notification_threshold ?? 60,
    exclude_on_negative_in_title: props.profile?.exclude_on_negative_in_title ?? false,
});

const submit = () => {
    if (props.profile) {
        form.patch(route('profiles.update', props.profile.id), { preserveScroll: true });
    } else {
        form.post(route('profiles.store'));
    }
};
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <div>
            <InputLabel for="name" value="Название направления" />
            <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required autofocus />
            <InputError class="mt-2" :message="form.errors.name" />
        </div>

        <div>
            <InputLabel value="Типы закупок (пусто — любые)" />
            <div class="mt-2 flex gap-6">
                <label v-for="law in LAW_TYPES" :key="law.value" class="flex items-center gap-2">
                    <Checkbox v-model:checked="form.law_types" :value="law.value" />
                    <span class="text-sm text-gray-700">{{ law.label }}</span>
                </label>
            </div>
            <InputError class="mt-2" :message="form.errors.law_types" />
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div>
                <InputLabel value="Приоритетные регионы (+10 к баллу)" />
                <TagInput v-model="form.regions" class="mt-1" placeholder="Москва" />
                <InputError class="mt-2" :message="form.errors.regions" />
            </div>
            <div>
                <InputLabel value="Исключённые регионы (жёсткий фильтр)" />
                <TagInput v-model="form.excluded_regions" class="mt-1" />
                <InputError class="mt-2" :message="form.errors.excluded_regions" />
            </div>
        </div>

        <div>
            <InputLabel value="Коды ОКПД2 (+5 к баллу, префиксное совпадение)" />
            <TagInput v-model="form.okpd2_codes" class="mt-1" placeholder="25.11" />
            <InputError class="mt-2" :message="form.errors.okpd2_codes" />
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div>
                <InputLabel for="min_price" value="Минимальная сумма, ₽" />
                <TextInput id="min_price" v-model="form.min_price" type="number" min="0" class="mt-1 block w-full" />
                <InputError class="mt-2" :message="form.errors.min_price" />
            </div>
            <div>
                <InputLabel for="max_price" value="Максимальная сумма, ₽" />
                <TextInput id="max_price" v-model="form.max_price" type="number" min="0" class="mt-1 block w-full" />
                <InputError class="mt-2" :message="form.errors.max_price" />
            </div>
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div>
                <InputLabel for="deadline_min_days" value="Мин. дней до дедлайна" />
                <TextInput id="deadline_min_days" v-model="form.deadline_min_days" type="number" min="0" max="365" class="mt-1 block w-full" />
                <InputError class="mt-2" :message="form.errors.deadline_min_days" />
            </div>
            <div>
                <InputLabel for="notification_threshold" value="Порог уведомлений (балл ≥)" />
                <TextInput id="notification_threshold" v-model="form.notification_threshold" type="number" min="0" max="100" class="mt-1 block w-full" />
                <InputError class="mt-2" :message="form.errors.notification_threshold" />
            </div>
        </div>

        <label class="flex items-center gap-2">
            <Checkbox v-model:checked="form.exclude_on_negative_in_title" />
            <span class="text-sm text-gray-700">Полностью исключать закупку при минус-слове в названии</span>
        </label>
        <InputError class="mt-2" :message="form.errors.exclude_on_negative_in_title" />

        <div class="flex items-center gap-4">
            <PrimaryButton :disabled="form.processing">
                {{ profile ? 'Сохранить' : 'Создать направление' }}
            </PrimaryButton>
            <Transition enter-active-class="transition ease-in-out" enter-from-class="opacity-0">
                <p v-if="form.recentlySuccessful" class="text-sm text-gray-600">Сохранено.</p>
            </Transition>
        </div>
    </form>
</template>
