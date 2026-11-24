<script setup lang="ts">
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'

interface CategoryOption {
  id: number
  name: string
}

interface ItemRow {
  id: number
  category_id: number
  name: string
  description: string | null
  price: number
  is_available: boolean
  category_name: string
  image_url: string | null
}

const props = defineProps<{
  items: ItemRow[]
  categories: CategoryOption[]
}>()

// null = "Tous" (aucun filtre de catégorie)
const activeCategory = ref<number | null>(null)
const search = ref('')

const filteredItems = computed(() => {
  const q = search.value.trim().toLowerCase()
  return props.items.filter(i => {
    const matchesCategory = activeCategory.value === null || i.category_id === activeCategory.value
    const matchesSearch = !q 
      || i.name.toLowerCase().includes(q) 
      || (i.description && i.description.toLowerCase().includes(q))
    return matchesCategory && matchesSearch
  })
})

function countFor(categoryId: number): number {
  return props.items.filter(i => i.category_id === categoryId).length
}

function fmt(n: number): string {
  return Number(n).toLocaleString('fr-FR') + ' F'
}

function destroyItem(id: number, name: string) {
  if (confirm(`Supprimer "${name}" du menu ? Cette action est définitive.`)) {
    router.delete(`/admin/items/${id}`)
  }
}
</script>

<template>
  <div class="p-4 sm:p-6 max-w-5xl mx-auto w-full min-w-0">
    <!-- En-tête : Titre + Barre de recherche de plats -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
      <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Gérer les plats</h1>

      <!-- Barre de recherche -->
      <div class="relative w-full sm:w-72">
        <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
          </svg>
        </span>
        <input
          v-model="search"
          type="text"
          placeholder="Rechercher un plat..."
          class="w-full pl-9 pr-8 py-2 text-sm border border-gray-200 rounded-xl bg-white focus:outline-none focus:ring-2 focus:ring-gray-900 focus:border-transparent transition-all shadow-xs"
        />
        <button
          v-if="search"
          type="button"
          @click="search = ''"
          class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600 text-sm"
          title="Effacer la recherche"
        >
          ✕
        </button>
      </div>
    </div>

    <!-- Pastilles de catégories : Fixe (sticky) et scrollable horizontalement -->
    <div class="sticky top-0 z-20 bg-white/95 backdrop-blur-sm -mx-4 sm:-mx-6 px-4 sm:px-6 py-3 mb-5 border-b border-gray-100 shadow-xs">
      <div class="flex gap-2 overflow-x-auto max-w-full pb-0.5">
        <button
          type="button"
          @click="activeCategory = null"
          :class="[
            'shrink-0 whitespace-nowrap px-4 py-1.5 rounded-full text-sm font-medium border transition-colors',
            activeCategory === null
              ? 'bg-gray-900 text-white border-gray-900'
              : 'bg-white text-gray-700 border-gray-200 hover:border-gray-400'
          ]"
        >
          Tous ({{ items.length }})
        </button>
        <button
          v-for="c in categories"
          :key="c.id"
          type="button"
          @click="activeCategory = c.id"
          :class="[
            'shrink-0 whitespace-nowrap px-4 py-1.5 rounded-full text-sm font-medium border transition-colors',
            activeCategory === c.id
              ? 'bg-gray-900 text-white border-gray-900'
              : 'bg-white text-gray-700 border-gray-200 hover:border-gray-400'
          ]"
        >
          {{ c.name }} ({{ countFor(c.id) }})
        </button>
      </div>
    </div>

    <!-- Grille de cartes -->
    <div class="grid grid-cols-[repeat(auto-fill,minmax(min(100%,18rem),1fr))] gap-4">
      <!-- Message si aucun plat ne correspond à la recherche -->
      <div v-if="filteredItems.length === 0 && search" class="col-span-full py-12 text-center text-gray-400 text-sm">
        Aucun plat trouvé pour <span class="font-semibold text-gray-600">"{{ search }}"</span>.
      </div>

      <div
        v-for="item in filteredItems"
        :key="item.id"
        class="border border-gray-200 rounded-xl p-4 bg-white flex flex-col gap-4 shadow-xs hover:shadow-sm transition-shadow"
      >
        <div class="flex gap-3">
          <img
            v-if="item.image_url" :src="item.image_url" :alt="item.name"
            class="h-20 w-20 shrink-0 rounded-lg object-cover border border-gray-100"
          >
          <div v-else class="h-20 w-20 shrink-0 rounded-lg bg-gray-50 border border-gray-100 flex items-center justify-center text-xs text-gray-300">
            Sans photo
          </div>

          <div class="min-w-0 flex-1">
            <div class="font-semibold text-gray-900 truncate">{{ item.name }}</div>
            <!-- La catégorie n'est affichée que si aucun filtre spécifique n'est actif -->
            <div v-if="activeCategory === null" class="text-xs text-gray-400 truncate">
              {{ item.category_name }}
            </div>
            <div class="text-gray-500 text-sm mt-0.5">{{ fmt(item.price) }}</div>
            <span
              :class="[
                'inline-block mt-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium',
                item.is_available ? 'bg-green-50 text-green-600' : 'bg-red-50 text-red-600'
              ]"
            >
              {{ item.is_available ? 'Disponible' : 'Indisponible' }}
            </span>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-2 mt-auto">
          <Link
            :href="`/admin/items/${item.id}/edit`"
            class="text-center border border-gray-200 rounded-lg py-2 text-sm font-medium text-gray-800 hover:bg-gray-50 transition-colors"
          >
            Modifier
          </Link>
          <button
            type="button"
            @click="destroyItem(item.id, item.name)"
            class="border border-red-200 text-red-500 rounded-lg py-2 text-sm hover:bg-red-50 transition-colors"
          >
            Supprimer
          </button>
        </div>
      </div>

      <!-- Carte pointillée "Ajouter un plat" -->
      <Link
        href="/admin/items/create"
        class="border-2 border-dashed border-gray-200 rounded-xl min-h-38 flex flex-col items-center justify-center text-gray-400 hover:border-gray-300 hover:text-gray-500 transition-colors"
      >
        <span class="text-2xl leading-none">+</span>
        <span class="text-sm mt-1">Ajouter un plat</span>
      </Link>
    </div>
  </div>
</template>
