<script setup>
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    profileId: Number,
    keywords: Array,
});

const TYPES = [
    { value: 'main', label: 'Основные', hint: '+20 в названии / +15 в описании' },
    { value: 'exact', label: 'Точные фразы', hint: '+30 при точном совпадении в названии' },
    { value: 'broad', label: 'Расширенные', hint: 'как основные, для широкого охвата' },
    { value: 'negative', label: 'Минус-слова', hint: '−20 или полное исключение' },
    { value: 'high_priority', label: 'Высокий приоритет', hint: 'дополнительно +10' },
    { value: 'low_priority', label: 'Низкий приоритет', hint: '+2 вместо стандартного балла' },
];

const form = useForm({ value: '', type: 'main' });

const grouped = computed(() =>
    TYPES.map((t) => ({
        ...t,
        items: props.keywords.filter((k) => k.type === t.value),
    })),
);

const submit = () => {
    form.post(route('profiles.keywords.store', props.profileId), {
        preserveScroll: true,
        onSuccess: () => form.reset('value'),
    });
};

const remove = (keyword) => {
    router.delete(route('keywords.destroy', keyword.id), { preserveScroll: true });
};
</script>

<template>
    <section>
        <header class="mb-4">
            <h3 class="text-lg font-medium text-gray-900">Ключевые слова</h3>
            <p class="mt-1 text-sm text-gray-600">
                Для активации направления нужен хотя бы один позитивный ключ
                (основной, точная фраза или расширенный).
            </p>
        </header>

        <form class="flex items-start gap-2" @submit.prevent="submit">
            <div class="flex-1">
                <TextInput v-model="form.value" class="block w-full" placeholder="металлоконструкции" />
                <InputError class="mt-1" :message="form.errors.value" />
            </div>
            <select
                v-model="form.type"
                class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            >
                <option v-for="t in TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
            <PrimaryButton :disabled="form.processing">Добавить</PrimaryButton>
        </form>
        <InputError class="mt-1" :message="form.errors.type" />

        <div class="mt-6 space-y-4">
            <div v-for="group in grouped" :key="group.value">
                <template v-if="group.items.length">
                    <h4 class="text-sm font-medium text-gray-700">
                        {{ group.label }}
                        <span class="font-normal text-gray-400">— {{ group.hint }}</span>
                    </h4>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <span
                            v-for="keyword in group.items"
                            :key="keyword.id"
                            :class="group.value === 'negative'
                                ? 'bg-red-50 text-red-800'
                                : 'bg-indigo-50 text-indigo-800'"
                            class="inline-flex items-center gap-1 rounded px-2 py-1 text-sm"
                        >
                            {{ keyword.value }}
                            <button type="button" class="text-gray-400 hover:text-red-600" @click="remove(keyword)">×</button>
                        </span>
                    </div>
                </template>
            </div>
        </div>
    </section>
</template>
