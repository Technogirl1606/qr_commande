<script setup lang="ts">
/**
 * resources/js/Pages/Menu.vue
 * -----------------------------------------------------------------
 * Reçoit "categories" et "items" depuis MenuController@show (Inertia).
 * Le panier ("cart") est un état 100% local pour l'instant : rien n'est
 * envoyé au serveur tant qu'on n'a pas construit OrderController
 * (prochaine étape). Le bouton "Valider" est donc volontairement
 * une simulation locale pour le moment.
 */
import { ref, computed, reactive, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import AppLogoIcon from '@/components/AppLogoIcon.vue'

interface Category {
  id: number
  name: string
}

interface MenuItem {
  id: number
  category_id: number
  name: string
  description: string | null
  price: number
  image_url: string | null
}

interface CartLine extends MenuItem {
  quantite: number
}

interface TableInfo {
  name: string
  code?: string
}

interface TrackedOrder {
  id: number
  number: string
  total: number
  status: string
}

const props = withDefaults(
  defineProps<{
    categories: Category[]
    items: MenuItem[]
    table: TableInfo | null
    initial_order?: TrackedOrder | null
  }>(),
  {
    categories: () => [],
    items: () => [],
    table: null,
    initial_order: null,
  }
)

// --- Nom / slogan du restaurant : en dur ici, comme décidé ---
const restaurant = {
  name: 'Restaurant',
  tagline: "Voici notre menu d'aujourd'hui. Bon appétit !",
}

const activeCategory = ref<number | null>(props.categories[0]?.id ?? null)
const cart = reactive<Record<number, number>>({}) // { [itemId]: quantite }
const cartOpen = ref(false)
const tableLabel = ref(props.table?.name ?? '')
const note = ref('')
const step = ref<'review' | 'confirm'>('review')
const submitting = ref(false)
const errorMsg = ref('')

// --- Type de commande ---
// Si QR code scanné sur une table : 'sur_table' par défaut, mais le client peut choisir 'a_emporter'
// Si accès via lien direct (pas de table) : 'a_emporter' par défaut, 'livraison' possible
type OrderType = 'sur_table' | 'a_emporter' | 'livraison'
const orderType = ref<OrderType>(props.table ? 'sur_table' : 'a_emporter')
const customerName = ref('')
const customerPhone = ref('')
const customerDistrict = ref('')

// Les options disponibles selon le contexte
const orderTypeOptions = computed(() => {
  if (props.table) {
    return [
      { value: 'sur_table', label: '🪑 Manger sur place', desc: props.table.name },
      { value: 'a_emporter', label: '🥡 À emporter', desc: 'Je viens chercher ma commande' },
    ]
  }
  return [
    { value: 'a_emporter', label: '🥡 À emporter', desc: 'Je viens chercher au comptoir' },
    { value: 'livraison', label: '🛵 Livraison', desc: 'Je veux être livré' },
  ]
})

const filteredItems = computed<MenuItem[]>(() =>
  props.items.filter(i => i.category_id === activeCategory.value)
)

const cartCount = computed<number>(() =>
  Object.values(cart).reduce((a, b) => a + b, 0)
)

const cartLines = computed<CartLine[]>(() =>
  Object.entries(cart)
    .map(([id, quantite]): CartLine | null => {
      const item = props.items.find(i => i.id === Number(id))
      return item ? { ...item, quantite } : null
    })
    .filter((line): line is CartLine => line !== null)
)

const cartTotal = computed<number>(() =>
  cartLines.value.reduce((sum, l) => sum + l.price * l.quantite, 0)
)

function fmt(n: number): string {
  return Number(n).toLocaleString('fr-FR') + ' F'
}

function inc(id: number): void {
  cart[id] = (cart[id] || 0) + 1
}
function dec(id: number): void {
  if (!cart[id]) return
  cart[id]--
  if (cart[id] <= 0) delete cart[id]
}

function openCart(): void {
  step.value = 'review'
  cartOpen.value = true
}

// Récupérer les commandes déjà suivies depuis le localStorage (pour ne rien perdre au retour de PayDunya)
const savedOrders = typeof window !== 'undefined' ? localStorage.getItem('tracked_orders') : null
const activeOrders = ref<TrackedOrder[]>(savedOrders ? JSON.parse(savedOrders) : [])

function saveActiveOrders(): void {
  if (typeof window !== 'undefined') {
    localStorage.setItem('tracked_orders', JSON.stringify(activeOrders.value))
  }
}

let pollTimer: ReturnType<typeof setInterval> | undefined

const STATUS_STEPS = [
  { key: 'nouvelle', label: 'Envoyée' },
  { key: 'preparation', label: 'En préparation' },
  { key: 'prete', label: 'Prête' },
  { key: 'servie', label: 'Servie' },
]

function stepIndexFor(status: string): number {
  return STATUS_STEPS.findIndex(s => s.key === status)
}

function labelFor(status: string): string {
  return STATUS_STEPS.find(s => s.key === status)?.label ?? status
}

// Commandes pas encore terminées (ni servies, ni annulées) : celles qu'on continue à sonder.
const pendingOrders = computed(() =>
  activeOrders.value.filter(o => o.status !== 'servie' && o.status !== 'annulee')
)

function openTracking(): void {
  step.value = 'confirm'
  cartOpen.value = true
}

function ensurePolling(): void {
  if (pollTimer) return // déjà en cours, pas besoin d'en relancer un second

  pollTimer = setInterval(async () => {
    for (const order of activeOrders.value) {
      if (order.status === 'servie' || order.status === 'annulee') continue
      try {
        const { data } = await axios.get(`/orders/${order.id}/status`)
        order.status = data.status
        saveActiveOrders()
      } catch (e) {
        // Silencieux : un raté ponctuel de sondage n'a pas besoin d'interrompre l'affichage.
      }
    }
  }, 5000)
}

function stopPollingIfNothingToTrack(): void {
  if (pendingOrders.value.length === 0 && pollTimer) {
    clearInterval(pollTimer)
    pollTimer = undefined
  }
}

// Retire une commande terminée de la liste affichée (le client "range" sa commande servie).
function dismissOrder(id: number): void {
  activeOrders.value = activeOrders.value.filter(o => o.id !== id)
  saveActiveOrders()
  stopPollingIfNothingToTrack()
}

onMounted(() => {
  // Si le client revient après un paiement PayDunya, ouvrir le suivi directement
  if (props.initial_order) {
    const existing = activeOrders.value.find(o => o.id === props.initial_order!.id)
    if (!existing) {
      activeOrders.value.unshift(props.initial_order)
    } else {
      existing.status = props.initial_order.status
    }
    saveActiveOrders()
    openTracking()
  }

  if (pendingOrders.value.length > 0) {
    ensurePolling()
  }
})

onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer)
})

async function confirmOrder(): Promise<void> {
  errorMsg.value = ''
  if (cartLines.value.length === 0) return

  // Validation côté client
  if (orderType.value !== 'sur_table') {
    if (!customerName.value.trim()) { errorMsg.value = 'Veuillez entrer votre nom.'; return }
    if (!customerPhone.value.trim()) { errorMsg.value = 'Veuillez entrer votre numéro de téléphone.'; return }
  }
  if (orderType.value === 'livraison' && !customerDistrict.value.trim()) {
    errorMsg.value = 'Veuillez indiquer votre quartier.'; return
  }

  submitting.value = true
  try {
    const { data } = await axios.post('/orders', {
      table_code:         props.table?.code || null,
      table_label:        tableLabel.value || null,
      order_type:         orderType.value,
      customer_name:      customerName.value.trim() || null,
      customer_phone:     customerPhone.value.trim() || null,
      customer_district:  customerDistrict.value.trim() || null,
      note:               note.value || null,
      items: cartLines.value.map(l => ({
        item_id:  l.id,
        quantite: l.quantite,
      })),
    })

    if (data.order) {
      activeOrders.value.push({
        id: data.order.id,
        number: data.order.number,
        total: data.order.total,
        status: data.order.status || 'nouvelle',
      })
      saveActiveOrders()
    }

    // Le panier en mémoire peut être vidé
    Object.keys(cart).forEach(k => delete cart[Number(k)])

    // Redirection automatique vers PayDunya (Wave / Orange Money)
    if (data.checkout_url || data.payment_url) {
      window.location.href = data.checkout_url || data.payment_url
      return
    }

    ensurePolling()
    step.value = 'confirm'
  } catch (e: any) {
    errorMsg.value = e.response?.data?.message || "Impossible d'initialiser le paiement. Réessaie."
  } finally {
    submitting.value = false
  }
}

function newOrder(): void {
  tableLabel.value = props.table?.name ?? ''
  note.value = ''
  cartOpen.value = false
}
</script>

<template>
  <div class="min-h-screen bg-white pb-28">
    <!-- En-tête + onglets : fixes en haut au scroll -->
    <div class="sticky top-0 z-10 shadow-sm bg-white">
      <!-- HEADER -->
      <header class="bg-[#fde7ea] px-6 pt-10 pb-8 text-center">
        <AppLogoIcon class="h-14 mx-auto mb-2" />
        <p v-if="restaurant.tagline" class="text-red-500 mt-3 text-[15px]">
          {{ restaurant.tagline }}
        </p>
      </header>

      <!-- CATEGORY PILLS -->
      <nav class="border-b border-gray-100" aria-label="Catégories">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
          <div class="flex gap-2 overflow-x-auto py-3 sm:justify-center sm:flex-wrap">
            <button
              v-for="c in categories"
              :key="c.id"
              type="button"
              @click="activeCategory = c.id"
              :class="[
                'whitespace-nowrap px-5 py-2 rounded-full text-sm font-medium border transition-colors',
                activeCategory === c.id
                  ? 'bg-red-600 text-white border-red-600'
                  : 'bg-white text-gray-700 border-gray-200 hover:border-red-300'
              ]"
            >
              {{ c.name }}
            </button>
          </div>
        </div>
      </nav>
    </div>

    <!-- ITEM GRID -->
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
      <p v-if="filteredItems.length === 0" class="text-center text-gray-400 py-10 text-sm">
        Aucun plat dans cette catégorie pour le moment.
      </p>

      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-4 sm:gap-6 py-6 min-h-55">
        <article
          v-for="it in filteredItems"
          :key="it.id"
          class="border border-gray-100 rounded-xl overflow-hidden shadow-sm hover:shadow-md transition-shadow"
        >
          <div class="w-full aspect-square bg-gray-50 flex items-center justify-center overflow-hidden">
            <img
              v-if="it.image_url"
              :src="it.image_url"
              :alt="it.name"
              class="w-full h-full object-cover"
            >
            <span v-else class="text-gray-300 text-xs">Pas de photo</span>
          </div>
          <div class="p-3 sm:p-4">
            <p class="font-medium text-sm sm:text-base text-gray-900">{{ it.name }}</p>
            <p v-if="it.description" class="text-xs sm:text-sm text-gray-500 mb-1.5">{{ it.description }}</p>

            <div class="flex items-center justify-between gap-2 mt-1">
              <span class="text-sm sm:text-base font-medium text-red-600 whitespace-nowrap shrink-0">{{ fmt(it.price) }}</span>

              <div class="flex items-center gap-1.5 sm:gap-2 shrink-0" v-if="cart[it.id]">
                <button
                  type="button" @click="dec(it.id)"
                  class="w-7 h-7 sm:w-8 sm:h-8 rounded-full border-2 border-gray-200 text-gray-600 text-base flex items-center justify-center active:scale-95 transition shrink-0"
                  :aria-label="'Retirer ' + it.name"
                >−</button>
                <span class="text-sm font-bold w-4 text-center shrink-0">{{ cart[it.id] }}</span>
                <button
                  type="button" @click="inc(it.id)"
                  class="w-7 h-7 sm:w-8 sm:h-8 rounded-full bg-red-600 text-white text-base flex items-center justify-center font-bold shadow-sm active:scale-95 transition shrink-0"
                  :aria-label="'Ajouter ' + it.name"
                >+</button>
              </div>
              <button
                v-else type="button" @click="inc(it.id)"
                class="w-7 h-7 sm:w-8 sm:h-8 rounded-full bg-red-600 text-white text-base flex items-center justify-center font-bold shadow-sm active:scale-95 transition shrink-0"
                :aria-label="'Ajouter ' + it.name"
              >+</button>
            </div>
          </div>
        </article>
      </div>
    </div>

    <!-- TRACKING BAR : prioritaire, reste accessible même si le client
         ferme la modale par erreur en touchant à côté -->
    <button
      v-if="activeOrders.length > 0"
      type="button"
      @click="openTracking"
      class="fixed left-4 right-4 bottom-4 max-w-md mx-auto bg-gray-900 text-white rounded-2xl px-5 py-4 flex items-center gap-3 justify-between shadow-xl"
    >
      <div class="flex items-center gap-3">
        <span class="h-2.5 w-2.5 rounded-full bg-green-400 animate-pulse shrink-0"></span>
        <span class="text-left">
          <span v-if="activeOrders.length === 1" class="block font-bold text-sm">
            Commande {{ activeOrders[0].number }}
          </span>
          <span v-else class="block font-bold text-sm">
            {{ activeOrders.length }} commandes
          </span>
          <span class="block text-gray-300 text-xs">
            {{ pendingOrders.length > 0 ? `${pendingOrders.length} en cours` : 'Toutes servies' }} · Voir le suivi
          </span>
        </span>
      </div>
      <span class="text-lg">→</span>
    </button>

    <!-- FLOATING CART BAR -->
    <button
      v-else-if="cartCount > 0"
      type="button"
      @click="openCart"
      class="fixed left-4 right-4 bottom-4 max-w-md mx-auto bg-red-600 text-white rounded-2xl px-5 py-4 flex items-center gap-3 justify-between shadow-xl"
    >
      <div class="flex items-center gap-3">
        <svg class="h-6 w-6 shrink-0" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M3 3h2l.4 2M7 13h10l3.6-8H5.4M7 13L5.4 5M7 13l-1.5 6h11M9 21a1 1 0 100-2 1 1 0 000 2zM17 21a1 1 0 100-2 1 1 0 000 2z"
            stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        <span class="text-left">
          <span class="block font-bold text-sm">{{ cartCount }} article{{ cartCount > 1 ? 's' : '' }}</span>
          <span class="block text-red-100 text-xs">Voir mon panier</span>
        </span>
      </div>
      <span class="font-bold">{{ fmt(cartTotal) }}</span>
    </button>

    <!-- CART MODAL -->
    <div
      v-if="cartOpen"
      class="fixed inset-0 bg-black/50 flex items-end justify-center z-50"
      @click.self="cartOpen = false"
    >
      <div class="bg-white w-full max-w-md rounded-t-2xl p-6 max-h-[85vh] overflow-y-auto">

        <template v-if="step === 'review'">
          <h3 class="text-xl font-bold mb-4">Votre commande</h3>

          <div v-for="l in cartLines" :key="l.id" class="flex items-center gap-3 py-2.5 border-b border-gray-100 text-sm">
            <img
              v-if="l.image_url" :src="l.image_url" alt=""
              class="h-11 w-11 rounded-lg object-cover border border-gray-100 shrink-0"
            >
            <div v-else class="h-11 w-11 rounded-lg bg-gray-50 border border-gray-100 shrink-0"></div>

            <div class="flex-1 min-w-0">
              <div class="truncate">{{ l.name }}</div>
              <div class="text-gray-400 text-xs">x{{ l.quantite }}</div>
            </div>
            <span class="font-medium shrink-0">{{ fmt(l.price * l.quantite) }}</span>
          </div>

          <div class="flex justify-between py-3 font-bold">
            <span>Total</span><span>{{ fmt(cartTotal) }}</span>
          </div>

          <!-- Sélecteur du type de commande -->
          <div class="mt-3 mb-3">
            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mb-2">
              Comment souhaitez-vous votre commande ?
            </label>
            <div class="grid gap-2">
              <label
                v-for="opt in orderTypeOptions"
                :key="opt.value"
                :class="[
                  'flex items-center gap-3 border rounded-xl px-4 py-3 cursor-pointer transition-all',
                  orderType === opt.value
                    ? 'border-red-500 bg-red-50'
                    : 'border-gray-200 hover:border-gray-300'
                ]"
              >
                <input type="radio" v-model="orderType" :value="opt.value" class="accent-red-600" />
                <div>
                  <div class="text-sm font-semibold">{{ opt.label }}</div>
                  <div class="text-xs text-gray-400">{{ opt.desc }}</div>
                </div>
              </label>
            </div>
          </div>

          <!-- Infos client (À emporter ou Livraison) -->
          <template v-if="orderType === 'a_emporter' || orderType === 'livraison'">
            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mt-3 mb-1">
              Votre nom *
            </label>
            <input
              v-model="customerName" type="text" placeholder="Ex : Mamadou Diallo"
              class="w-full border border-gray-200 rounded-lg px-3 py-3 text-sm"
            >

            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mt-3 mb-1">
              Votre téléphone *
            </label>
            <input
              v-model="customerPhone" type="tel" placeholder="Ex : 77 123 45 67"
              class="w-full border border-gray-200 rounded-lg px-3 py-3 text-sm"
            >
          </template>

          <!-- Quartier (Livraison uniquement) -->
          <template v-if="orderType === 'livraison'">
            <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mt-3 mb-1">
              Votre quartier *
            </label>
            <input
              v-model="customerDistrict" type="text" placeholder="Ex : Médina, Leona, Bongré..."
              class="w-full border border-gray-200 rounded-lg px-3 py-3 text-sm"
            >
          </template>

          <label class="block text-xs font-bold uppercase tracking-wide text-gray-500 mt-3 mb-1">
            Note pour la cuisine (optionnel)
          </label>
          <textarea
            v-model="note" rows="2" placeholder="Ex : sans piment, allergie arachide..."
            class="w-full border border-gray-200 rounded-lg px-3 py-3 text-sm"
          ></textarea>

          <p v-if="errorMsg" class="text-red-600 text-xs mt-2">{{ errorMsg }}</p>

         <!-- BOUTON PAYER WAVE & ORANGE MONEY -->
<button
  type="button"
  @click="confirmOrder"
  :disabled="submitting || cartLines.length === 0"
  class="w-full mt-5 bg-red-600 hover:bg-red-700 active:scale-[0.99] text-white font-bold py-3.5 px-4 rounded-xl shadow-md disabled:opacity-50 transition-all flex items-center justify-between"
>
  <div class="flex items-center gap-2.5">
    <!-- Spinner si en cours de redirection -->
    <svg v-if="submitting" class="animate-spin h-5 w-5 text-white" viewBox="0 0 24 24" fill="none">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
    </svg>

    <!-- Icône carte/monnaie normale -->
    <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <rect x="2" y="5" width="20" height="14" rx="2" />
      <line x1="2" y1="10" x2="22" y2="10" />
    </svg>

    <div class="text-left">
      <span class="block text-sm font-bold">
        {{ submitting ? 'Redirection vers PayDunya...' : 'Payer maintenant' }}
      </span>
      <span class="block text-[11px] text-red-100 font-normal">
        Wave · Orange Money
      </span>
    </div>
  </div>

  <!-- Montant total affiché directement sur le bouton -->
  <span class="text-base font-extrabold">{{ fmt(cartTotal) }}</span>
</button>
          <button
            type="button" @click="cartOpen = false"
            class="w-full mt-2 border border-gray-200 font-semibold py-3 rounded-lg text-sm"
          >
            Continuer mes achats
          </button>
        </template>

        <template v-else>
          <div class="text-center">
            <div class="text-xs uppercase tracking-wide text-gray-400 font-bold mb-4">
              Suivi de vos commandes
            </div>

            <div class="space-y-4 text-left">
              <div
                v-for="order in activeOrders" :key="order.id"
                class="border border-gray-100 rounded-xl p-4"
              >
                <div class="flex items-center justify-between">
                  <span class="font-mono font-bold text-lg text-red-600">{{ order.number }}</span>
                  <button
                    v-if="order.status === 'servie' || order.status === 'annulee'"
                    type="button" @click="dismissOrder(order.id)"
                    class="text-gray-300 hover:text-gray-500 text-sm"
                    aria-label="Retirer cette commande de la liste"
                  >✕</button>
                </div>

                <div v-if="order.status === 'annulee'" class="text-sm text-red-600 font-medium py-3">
                  Cette commande a été annulée.
                </div>

                <template v-else>
                  <!-- Jauge de progression, compacte -->
                  <div class="flex items-center justify-between mt-4 mb-1 px-1">
                    <template v-for="(s, i) in STATUS_STEPS" :key="s.key">
                      <div class="flex flex-col items-center flex-1">
                        <div
                          :class="[
                            'h-6 w-6 rounded-full flex items-center justify-center text-[10px] font-bold border-2',
                            i < stepIndexFor(order.status) ? 'bg-red-600 border-red-600 text-white' :
                            i === stepIndexFor(order.status) ? 'bg-red-600 border-red-600 text-white animate-pulse' :
                            'bg-white border-gray-200 text-gray-300'
                          ]"
                        >
                          <span v-if="i < stepIndexFor(order.status)">✓</span>
                          <span v-else>{{ i + 1 }}</span>
                        </div>
                        <span :class="['text-[9px] mt-1 text-center leading-tight', i <= stepIndexFor(order.status) ? 'text-gray-700 font-medium' : 'text-gray-300']">
                          {{ s.label }}
                        </span>
                      </div>
                      <div
                        v-if="i < STATUS_STEPS.length - 1"
                        :class="['h-0.5 flex-1 -mt-4', i < stepIndexFor(order.status) ? 'bg-red-600' : 'bg-gray-200']"
                      ></div>
                    </template>
                  </div>

                  <div v-if="order.status === 'servie'" class="bg-gray-50 rounded-lg py-2.5 px-3 mt-3 flex items-center justify-between">
                    <span class="text-xs text-gray-500">Bon appétit! Merci d'avoir commander !</span>
                    <span class="font-bold text-red-600">{{ fmt(order.total) }}</span>
                  </div>
                  <p v-else class="text-xs text-gray-400 mt-3">
                    {{ labelFor(order.status) }} — mise à jour automatique
                  </p>
                </template>
              </div>
            </div>

            <button
              v-if="cartCount > 0"
              type="button" @click="step = 'review'"
              class="w-full mt-5 bg-red-600 text-white font-bold py-3.5 rounded-lg"
            >
              Voir mon panier ({{ cartCount }})
            </button>
            <button
              type="button" @click="newOrder"
              :class="[
                'w-full mt-2 font-semibold py-3 rounded-lg text-sm',
                cartCount > 0 ? 'border border-gray-200 text-gray-600' : 'bg-red-600 text-white font-bold py-3.5'
              ]"
            >
              {{ cartCount > 0 ? 'Fermer' : 'Commander autre chose' }}
            </button>
          </div>
        </template>

      </div>
    </div>
  </div>
</template>

<style scoped>
.font-logo {
  font-family: 'Pacifico', cursive;
}
</style>