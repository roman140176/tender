<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    links: { type: Array, required: true }, // Laravel paginator.links
});
</script>

<template>
    <nav v-if="links.length > 3" class="flex flex-wrap gap-1">
        <template v-for="(link, i) in links" :key="i">
            <span
                v-if="link.url === null"
                class="rounded px-3 py-1 text-sm text-gray-400"
                v-html="link.label"
            />
            <Link
                v-else
                :href="link.url"
                class="rounded px-3 py-1 text-sm"
                :class="link.active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-100'"
                preserve-scroll
                v-html="link.label"
            />
        </template>
    </nav>
</template>
