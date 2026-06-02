<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    ImageOff,
    Package,
    Pencil,
    PlusCircle,
    ShoppingBag,
    Trash2,
    XCircle,
} from 'lucide-vue-next';
import { onMounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { useAdminApi } from '@/composables/useAdminApi';

type VariantDraft = {
    title: string;
    sku: string;
    price: number;
    inventory: number;
    is_default: boolean;
};

type ProductItem = {
    id: number;
    title: string;
    slug: string;
    description?: string | null;
    image_url?: string | null;
    price: string;
    sale_price?: string | null;
    sku?: string | null;
    currency: string;
    inventory?: number | null;
    source: string;
    is_active: boolean;
    variants?: VariantDraft[];
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/dashboard' },
            { title: 'Products', href: '/products' },
        ],
    },
});

const { getList, postJson, patchJson, deleteResource, ensureTeam } = useAdminApi();

const loading = ref(false);
const saving = ref(false);
const createModalOpen = ref(false);
const editingProductId = ref<number | null>(null);
const errorText = ref('');
const products = ref<ProductItem[]>([]);

const form = ref({
    title: '',
    slug: '',
    description: '',
    image_url: '',
    currency: 'USD',
    price: 0,
    sale_price: '' as number | '',
    sku: '',
    inventory: 0,
    variants: [{ title: 'Default', sku: '', price: 0, inventory: 0, is_default: true }] as VariantDraft[],
});

function slugify(value: string): string {
    return value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
}

function formatPrice(currency: string, price: string | null | undefined): string {
    if (!price) return '—';
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(Number(price));
}


async function loadProducts() {
    loading.value = true;
    errorText.value = '';
    try {
        await ensureTeam();
        const payload = await getList<ProductItem>('/api/v1/admin/products');
        products.value = payload.data ?? [];
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not load products.';
    } finally {
        loading.value = false;
    }
}

async function createProduct() {
    saving.value = true;
    errorText.value = '';
    try {
        await postJson('/api/v1/admin/products', {
            title: form.value.title,
            slug: form.value.slug || slugify(form.value.title),
            description: form.value.description || null,
            image_url: form.value.image_url || null,
            currency: form.value.currency,
            price: form.value.price,
            sale_price: form.value.sale_price === '' ? null : form.value.sale_price,
            sku: form.value.sku || null,
            inventory: form.value.inventory,
            source: 'native',
            is_active: true,
            variants: form.value.variants.map((v, i) => ({
                ...v,
                price: v.price || form.value.price,
                is_default: i === 0,
            })),
        });

        form.value = {
            title: '',
            slug: '',
            description: '',
            image_url: '',
            currency: 'USD',
            price: 0,
            sale_price: '',
            sku: '',
            inventory: 0,
            variants: [{ title: 'Default', sku: '', price: 0, inventory: 0, is_default: true }],
        };
        createModalOpen.value = false;
        await loadProducts();
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not create product.';
    } finally {
        saving.value = false;
    }
}

async function updateProduct() {
    if (!editingProductId.value) return;
    saving.value = true;
    errorText.value = '';
    try {
        await patchJson(`/api/v1/admin/products/${editingProductId.value}`, {
            title: form.value.title,
            slug: form.value.slug || slugify(form.value.title),
            description: form.value.description || null,
            image_url: form.value.image_url || null,
            currency: form.value.currency,
            price: form.value.price,
            sale_price: form.value.sale_price === '' ? null : form.value.sale_price,
            sku: form.value.sku || null,
            inventory: form.value.inventory,
            variants: form.value.variants.map((v, i) => ({
                ...v,
                price: v.price || form.value.price,
                is_default: i === 0,
            })),
        });

        createModalOpen.value = false;
        editingProductId.value = null;
        await loadProducts();
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not update product.';
    } finally {
        saving.value = false;
    }
}

async function toggleActive(product: ProductItem) {
    try {
        await patchJson(`/api/v1/admin/products/${product.id}`, { is_active: !product.is_active });
        await loadProducts();
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not update product.';
    }
}

async function removeProduct(product: ProductItem) {
    if (!window.confirm(`Delete "${product.title}"?`)) return;
    try {
        await deleteResource(`/api/v1/admin/products/${product.id}`);
        await loadProducts();
    } catch (error) {
        errorText.value = error instanceof Error ? error.message : 'Could not delete product.';
    }
}


function addVariant() {
    form.value.variants.push({
        title: `Variant ${form.value.variants.length + 1}`,
        sku: '',
        price: form.value.price,
        inventory: form.value.inventory,
        is_default: false,
    });
}

function removeVariant(index: number) {
    if (form.value.variants.length > 1) {
        form.value.variants.splice(index, 1);
    }
}

function openCreateModal() {
    editingProductId.value = null;
    form.value = {
        title: '',
        slug: '',
        description: '',
        image_url: '',
        currency: 'USD',
        price: 0,
        sale_price: '',
        sku: '',
        inventory: 0,
        variants: [{ title: 'Default', sku: '', price: 0, inventory: 0, is_default: true }],
    };
    createModalOpen.value = true;
}

function openEditModal(product: ProductItem) {
    editingProductId.value = product.id;
    form.value = {
        title: product.title ?? '',
        slug: product.slug ?? '',
        description: product.description ?? '',
        image_url: product.image_url ?? '',
        currency: product.currency ?? 'USD',
        price: Number(product.price ?? 0),
        sale_price: product.sale_price ? Number(product.sale_price) : '',
        sku: product.sku ?? '',
        inventory: Number(product.inventory ?? 0),
        variants: (product.variants?.length
            ? product.variants
            : [{ title: 'Default', sku: product.sku ?? '', price: Number(product.price ?? 0), inventory: Number(product.inventory ?? 0), is_default: true }]
        ).map((v, i) => ({
            title: v.title ?? `Variant ${i + 1}`,
            sku: v.sku ?? '',
            price: Number(v.price ?? product.price ?? 0),
            inventory: Number(v.inventory ?? product.inventory ?? 0),
            is_default: i === 0,
        })),
    };
    createModalOpen.value = true;
}

onMounted(loadProducts);
</script>

<template>
    <Head title="Products" />

    <div class="page-root flex min-h-screen flex-1 flex-col gap-6 p-4 md:p-6">

        <!-- Header -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="flex items-center gap-2.5">
                    <div class="page-icon flex size-9 items-center justify-center rounded-xl">
                        <Package class="size-5 text-white" />
                    </div>
                    <h1 class="text-2xl font-extrabold tracking-tight">Products</h1>
                </div>
                <p class="mt-1 text-sm text-muted-foreground">
                    Products are shown below videos during playback for viewers to purchase.
                </p>
            </div>
            <button type="button" class="cta-btn flex items-center gap-2 rounded-full px-5 py-2.5 text-sm font-bold text-white" @click="openCreateModal">
                <PlusCircle class="size-4" />
                Add product
            </button>
        </div>

        <!-- Error -->
        <div
            v-if="errorText"
            class="flex items-center gap-2 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"
        >
            <XCircle class="size-4 shrink-0" />
            {{ errorText }}
        </div>

        <!-- Skeleton -->
        <div v-if="loading" class="space-y-3">
            <Skeleton v-for="n in 4" :key="n" class="h-20 rounded-2xl" />
        </div>

        <!-- Empty state -->
        <div
            v-else-if="products.length === 0"
            class="flex flex-col items-center justify-center gap-5 rounded-2xl border border-dashed bg-white py-16 text-center shadow-card"
        >
            <div class="page-icon flex size-14 items-center justify-center rounded-2xl">
                <Package class="size-7 text-white" />
            </div>
            <div>
                <p class="font-bold">No products yet</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Add products here first, then attach them to your shoppable videos.
                </p>
            </div>
            <button type="button" class="cta-btn flex items-center gap-2 rounded-full px-5 py-2.5 text-sm font-bold text-white" @click="openCreateModal">
                <PlusCircle class="size-4" />
                Add your first product
            </button>
        </div>

        <!-- Product list -->
        <div v-else-if="products.length > 0" class="space-y-3">
            <h2 class="text-xs font-bold uppercase tracking-widest text-gray-400">
                Catalogue · {{ products.length }}
            </h2>
            <div
                v-for="product in products"
                :key="product.id"
                class="flex flex-wrap items-start gap-4 rounded-2xl bg-white p-4 shadow-card transition-shadow hover:shadow-md"
            >
                <!-- Image -->
                <div class="shrink-0">
                    <img
                        v-if="product.image_url"
                        :src="product.image_url"
                        :alt="product.title"
                        class="h-16 w-16 rounded-xl object-cover"
                    >
                    <div
                        v-else
                        class="flex h-16 w-16 items-center justify-center rounded-xl bg-gray-100"
                    >
                        <ImageOff class="size-5 text-gray-400" />
                    </div>
                </div>

                <!-- Info -->
                <div class="min-w-0 flex-1 space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-semibold">{{ product.title }}</p>
                        <Badge :variant="product.is_active ? 'default' : 'secondary'">
                            {{ product.is_active ? 'Active' : 'Inactive' }}
                        </Badge>
                        <Badge variant="outline" class="text-xs">{{ product.source }}</Badge>
                    </div>
                    <p v-if="product.description" class="line-clamp-1 text-xs text-muted-foreground">
                        {{ product.description }}
                    </p>
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <span class="font-semibold text-foreground">
                            {{ formatPrice(product.currency, product.price) }}
                        </span>
                        <span
                            v-if="product.sale_price"
                            class="font-semibold text-emerald-600 dark:text-emerald-400"
                        >
                            Sale: {{ formatPrice(product.currency, product.sale_price) }}
                        </span>
                        <span v-if="product.sku" class="text-xs text-muted-foreground">
                            SKU: {{ product.sku }}
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{ product.inventory ?? 0 }} in stock
                        </span>
                        <span v-if="product.variants?.length" class="text-xs text-muted-foreground">
                            {{ product.variants.length }} variant{{ product.variants.length !== 1 ? 's' : '' }}
                        </span>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex shrink-0 gap-2">
                    <button type="button" class="action-btn" @click="openEditModal(product)">
                        <Pencil class="size-3.5" />
                        Edit
                    </button>
                    <button type="button" class="action-btn" @click="toggleActive(product)">
                        {{ product.is_active ? 'Deactivate' : 'Activate' }}
                    </button>
                    <button type="button" class="delete-btn" @click="removeProduct(product)">
                        <Trash2 class="size-4" />
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════ Create product modal ═══════ -->
    <Dialog v-model:open="createModalOpen">
        <DialogContent class="flex max-h-[90vh] flex-col gap-0 p-0 sm:max-w-[600px]">
            <DialogHeader class="shrink-0 border-b px-6 py-4">
                <DialogTitle class="flex items-center gap-2">
                    <ShoppingBag class="size-4 text-orange-500" />
                    {{ editingProductId ? 'Edit product' : 'Add product' }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        editingProductId
                            ? 'Update product details shown in your embed player.'
                            : 'Products appear below videos in the embed player for viewers to purchase.'
                    }}
                </DialogDescription>
            </DialogHeader>

            <div class="flex-1 space-y-5 overflow-y-auto px-6 py-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="p-title">Product name <span class="text-destructive">*</span></Label>
                        <Input id="p-title" v-model="form.title" placeholder="Premium Hoodie" />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="p-slug">Slug</Label>
                        <Input
                            id="p-slug"
                            v-model="form.slug"
                            :placeholder="slugify(form.title || 'premium-hoodie')"
                        />
                    </div>
                    <div class="space-y-1.5 sm:col-span-2">
                        <Label for="p-desc">Description</Label>
                        <textarea
                            id="p-desc"
                            v-model="form.description"
                            rows="2"
                            class="w-full rounded-md border bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            placeholder="Short product description shown in the player…"
                        />
                    </div>
                    <div class="space-y-1.5 sm:col-span-2">
                        <Label for="p-image">Product image URL</Label>
                        <Input
                            id="p-image"
                            v-model="form.image_url"
                            type="url"
                            placeholder="https://example.com/product.jpg"
                        />
                        <div v-if="form.image_url" class="mt-2 flex items-center gap-3">
                            <img
                                :src="form.image_url"
                                alt="Preview"
                                class="h-16 w-16 rounded-lg border object-cover"
                                @error="(e) => (e.target as HTMLImageElement).style.display = 'none'"
                            >
                            <p class="text-xs text-muted-foreground">Image preview</p>
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="p-currency">Currency</Label>
                        <select
                            id="p-currency"
                            v-model="form.currency"
                            class="w-full rounded-md border bg-background px-3 py-2 text-sm"
                        >
                            <option value="USD">USD</option>
                            <option value="EUR">EUR</option>
                            <option value="GBP">GBP</option>
                            <option value="CAD">CAD</option>
                            <option value="AUD">AUD</option>
                            <option value="NGN">NGN</option>
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="p-price">Price <span class="text-destructive">*</span></Label>
                        <Input id="p-price" v-model.number="form.price" type="number" min="0" step="0.01" placeholder="0.00" />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="p-sale">Sale price <span class="text-xs text-muted-foreground">(optional)</span></Label>
                        <Input id="p-sale" v-model.number="form.sale_price" type="number" min="0" step="0.01" placeholder="0.00" />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="p-sku">SKU</Label>
                        <Input id="p-sku" v-model="form.sku" placeholder="HOODIE-BLK-M" />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="p-inv">Inventory</Label>
                        <Input id="p-inv" v-model.number="form.inventory" type="number" min="0" />
                    </div>
                </div>

                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-semibold">Variants</p>
                        <Button variant="outline" size="sm" @click="addVariant">+ Add variant</Button>
                    </div>
                    <div
                        v-for="(variant, index) in form.variants"
                        :key="index"
                        class="grid gap-2 rounded-lg border bg-muted/30 p-3 sm:grid-cols-5"
                    >
                        <Input v-model="variant.title" placeholder="Title (e.g. Black / M)" class="sm:col-span-2" />
                        <Input v-model="variant.sku" placeholder="SKU" />
                        <Input v-model.number="variant.price" type="number" min="0" step="0.01" placeholder="Price" />
                        <div class="flex items-center gap-2">
                            <Input v-model.number="variant.inventory" type="number" min="0" placeholder="Stock" />
                            <Button
                                v-if="form.variants.length > 1"
                                variant="ghost"
                                size="sm"
                                class="shrink-0 text-destructive hover:bg-destructive/10"
                                @click="removeVariant(index)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <DialogFooter class="shrink-0 border-t px-6 py-4">
                <Button variant="ghost" @click="createModalOpen = false; editingProductId = null">Cancel</Button>
                <Button :disabled="saving || !form.title || form.price === 0" @click="editingProductId ? updateProduct() : createProduct()">
                    {{
                        saving
                            ? (editingProductId ? 'Saving…' : 'Creating…')
                            : (editingProductId ? 'Save changes' : 'Create product')
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
.page-root { background-color: #F2EFEA; }
.page-icon { background: linear-gradient(135deg, #f97316, #E8563A); box-shadow: 0 4px 12px rgba(249,115,22,0.35); }
.cta-btn { background: #E8563A; box-shadow: 0 4px 20px rgba(232,86,58,0.35); transition: all 0.2s; }
.cta-btn:hover { background: #D44A2F; transform: translateY(-1px); }
.shadow-card { box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 4px 16px rgba(0,0,0,0.06); }
.action-btn { display: inline-flex; align-items: center; gap: 4px; padding: 5px 12px; border-radius: 9999px; border: 1px solid #e5e7eb; background: #fff; font-size: 12px; font-weight: 600; color: #374151; cursor: pointer; transition: all 0.15s; }
.action-btn:hover { border-color: #E8563A; color: #E8563A; background: rgba(232,86,58,0.04); }
.delete-btn { display: inline-flex; align-items: center; padding: 5px 8px; border-radius: 9999px; background: transparent; color: #9ca3af; border: 1px solid transparent; cursor: pointer; transition: all 0.15s; }
.delete-btn:hover { border-color: #fecaca; background: #fef2f2; color: #ef4444; }
</style>
