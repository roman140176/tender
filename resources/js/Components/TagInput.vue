<script setup>
import { ref } from 'vue';

const model = defineModel({ type: Array, default: () => [] });
defineProps({ placeholder: { type: String, default: 'Введите и нажмите Enter' } });

const input = ref('');

const add = () => {
    const value = input.value.trim();
    if (value && !model.value.includes(value)) {
        model.value = [...model.value, value];
    }
    input.value = '';
};

const remove = (index) => {
    model.value = model.value.filter((_, i) => i !== index);
};
</script>

<template>
    <div>
        <div v-if="model.length" class="mb-2 flex flex-wrap gap-2">
            <span
                v-for="(tag, i) in model"
                :key="tag"
                class="inline-flex items-center gap-1 rounded bg-gray-100 px-2 py-1 text-sm text-gray-800"
            >
                {{ tag }}
                <button type="button" class="text-gray-400 hover:text-red-600" @click="remove(i)">×</button>
            </span>
        </div>
        <input
            v-model="input"
            type="text"
            :placeholder="placeholder"
            class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
            @keydown.enter.prevent="add"
            @blur="add"
        />
    </div>
</template>
