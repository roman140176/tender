<script setup>
import { ref, computed, watch } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link, usePage } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);

const page = usePage();
const flashVisible = ref(false);
const flash = computed(() => page.props.flash ?? {});

watch(
    () => [flash.value.success, flash.value.error],
    ([success, error]) => {
        if (success || error) {
            flashVisible.value = true;
            setTimeout(() => (flashVisible.value = false), 4000);
        }
    },
    { immediate: true },
);
</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-100">
            <nav
                class="border-b border-gray-100 bg-white"
            >
                <!-- Primary Navigation Menu -->
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="flex shrink-0 items-center">
                                <Link :href="route('dashboard')">
                                    <ApplicationLogo
                                        class="block h-9 w-auto fill-current text-gray-800"
                                    />
                                </Link>
                            </div>

                            <!-- Navigation Links -->
                            <div
                                class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex"
                            >
                                <NavLink
                                    :href="route('dashboard')"
                                    :active="route().current('dashboard')"
                                >
                                    Дашборд
                                </NavLink>
                                <template v-if="$page.props.auth.can.viewTenders">
                                    <NavLink :href="route('tenders.index')" :active="route().current('tenders.*')">
                                        Закупки
                                    </NavLink>
                                    <NavLink :href="route('runs.index')" :active="route().current('runs.*')">Запуски</NavLink>
                                </template>
                                <template v-if="$page.props.auth.can.manageCompany">
                                <NavLink :href="route('profiles.index')" :active="route().current('profiles.*')">
                                    Направления
                                </NavLink>
                                <NavLink :href="route('sources.index')" :active="route().current('sources.*')">
                                    Источники
                                </NavLink>
                                <NavLink :href="route('imports.index')" :active="route().current('imports.*')">
                                    Импорт
                                </NavLink>
                                <NavLink :href="route('company.edit')" :active="route().current('company.*')">
                                    Компания
                                </NavLink>
                            </template>
                                <template v-if="$page.props.auth.can.superadmin">
                                    <NavLink :href="route('admin.sources.index')" :active="route().current('admin.sources.*')">
                                        Адм. источники
                                    </NavLink>
                                </template>
                            </div>
                        </div>

                        <div class="hidden sm:ms-6 sm:flex sm:items-center">
                            <!-- Settings Dropdown -->
                            <div class="relative ms-3">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                                            >
                                                {{ $page.props.auth.user.name }}

                                                <svg
                                                    class="-me-0.5 ms-2 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <DropdownLink
                                            :href="route('profile.edit')"
                                        >
                                            Профиль
                                        </DropdownLink>
                                        <DropdownLink
                                            :href="route('logout')"
                                            method="post"
                                            as="button"
                                        >
                                            Выйти
                                        </DropdownLink>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>

                        <!-- Hamburger -->
                        <div class="-me-2 flex items-center sm:hidden">
                            <button
                                @click="
                                    showingNavigationDropdown =
                                        !showingNavigationDropdown
                                "
                                class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none"
                            >
                                <svg
                                    class="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        :class="{
                                            hidden: showingNavigationDropdown,
                                            'inline-flex':
                                                !showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{
                                            hidden: !showingNavigationDropdown,
                                            'inline-flex':
                                                showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div
                    :class="{
                        block: showingNavigationDropdown,
                        hidden: !showingNavigationDropdown,
                    }"
                    class="sm:hidden"
                >
                    <div class="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                            :href="route('dashboard')"
                            :active="route().current('dashboard')"
                        >
                            Дашборд
                        </ResponsiveNavLink>
                        <template v-if="$page.props.auth.can.viewTenders">
                            <ResponsiveNavLink :href="route('tenders.index')" :active="route().current('tenders.*')">
                                Закупки
                            </ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('runs.index')" :active="route().current('runs.*')">Запуски</ResponsiveNavLink>
                        </template>
                        <template v-if="$page.props.auth.can.manageCompany">
                            <ResponsiveNavLink :href="route('profiles.index')" :active="route().current('profiles.*')">
                                Направления
                            </ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('sources.index')" :active="route().current('sources.*')">
                                Источники
                            </ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('imports.index')" :active="route().current('imports.*')">
                                Импорт
                            </ResponsiveNavLink>
                            <ResponsiveNavLink :href="route('company.edit')" :active="route().current('company.*')">
                                Компания
                            </ResponsiveNavLink>
                        </template>
                        <template v-if="$page.props.auth.can.superadmin">
                            <ResponsiveNavLink :href="route('admin.sources.index')" :active="route().current('admin.sources.*')">
                                Адм. источники
                            </ResponsiveNavLink>
                        </template>
                    </div>

                    <!-- Responsive Settings Options -->
                    <div
                        class="border-t border-gray-200 pb-1 pt-4"
                    >
                        <div class="px-4">
                            <div
                                class="text-base font-medium text-gray-800"
                            >
                                {{ $page.props.auth.user.name }}
                            </div>
                            <div class="text-sm font-medium text-gray-500">
                                {{ $page.props.auth.user.email }}
                            </div>
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.edit')">
                                Профиль
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                :href="route('logout')"
                                method="post"
                                as="button"
                            >
                                Выйти
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Page Heading -->
            <header
                class="bg-white shadow"
                v-if="$slots.header"
            >
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Flash Banner -->
            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0 -translate-y-1"
                leave-active-class="transition ease-in duration-200"
                leave-to-class="opacity-0"
            >
                <div v-if="flashVisible && (flash.success || flash.error)" class="mx-auto mt-4 max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div
                        :class="flash.error ? 'bg-red-50 text-red-800' : 'bg-green-50 text-green-800'"
                        class="rounded-md p-3 text-sm shadow-sm"
                        role="status"
                    >
                        {{ flash.error ?? flash.success }}
                    </div>
                </div>
            </Transition>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
