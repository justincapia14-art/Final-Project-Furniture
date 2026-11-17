<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AnalyticsReport;
use App\Models\Product;
use App\Models\StoreService;
use Library\Database;

/**
 * PHP Controller for Storefront, Catalog, and Dashboard Views.
 *
 * Handles server-side HTML rendering for product cards, room category cards,
 * color filter counts, and dashboard state hydration.
 */
final class StorefrontController
{
    private Database $db;
    private Product $productModel;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? new Database();
        $this->productModel = new Product($this->db);
    }

    /**
     * Returns the full store state enriched with PHP-computed inventory metrics,
     * customer directory, and current month analytics summary.
     *
     * @return array<string, mixed>
     */
    public function getHydratedStoreState(): array
    {
        $store = $this->db->readStore();
        $products = isset($store['products']) && is_array($store['products'])
            ? array_values(array_map(static fn(array $p): array => StoreService::buildProductRecord($p, $p), $store['products']))
            : [];
        $store['products'] = $products;
        $store['catalog'] = $products;
        $store['vouchers'] = $this->productModel->getAllVouchersWithResolvedStatus();
        $store['customerDirectory'] = StoreService::computeCustomerDirectory(
            is_array($store['orders'] ?? null) ? $store['orders'] : [],
            is_array($store['customers'] ?? null) ? $store['customers'] : [],
            is_array($store['customerProfile'] ?? null) ? $store['customerProfile'] : null
        );
        return $store;
    }

    /**
     * Renders a simplified storefront/catalog product card in PHP.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $options
     */
    public static function renderProductCardHtml(array $item, array $options = []): string
    {
        $esc = static fn(mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $usd = static fn(int|float $v): string => '$' . number_format((int) round((float) $v));

        $id = trim((string) ($item['id'] ?? ''));
        $selectedFinish = (string) ($options['selectedFinish'] ?? $item['colorVariants'][0]['name'] ?? $item['defaultFinish'] ?? 'Natural Finish');
        $pricing = StoreService::computeVariantPricing($item, $selectedFinish);
        $stock = max(0, (int) floor((float) ($item['inStockCount'] ?? $item['stockQuantity'] ?? 0)));
        $minStock = max(0, (int) floor((float) ($item['minStockLevel'] ?? 2)));
        $isOut = $stock === 0;
        $isLow = !$isOut && $stock <= $minStock;
        $leadTimeWeeks = max(1, (int) ($item['leadTimeWeeks'] ?? 3));

        $availLabel = $isOut ? 'Out of Stock' : ($isLow ? ('Low Stock · ' . $stock . ' left') : 'In Stock');
        $stockBadgeClass = $isOut
            ? 'bg-rose-600 text-white'
            : ($isLow ? 'bg-amber-500 text-slate-950' : 'bg-white/95 text-[#0D1015] border border-slate-200/80');

        $discountPercent = max(0, (int) ($item['discountPercent'] ?? 0));
        $hasDiscount = $discountPercent > 0;
        $cardTestId = (string) ($options['cardTestId'] ?? ('product-card-' . $id));
        $isSpotlighted = !empty($options['isSpotlighted']);
        $borderClass = $isSpotlighted ? 'border-[#0095FF] ring-2 ring-[#0095FF]/20' : 'border-slate-200 hover:border-[#0095FF]';
        $shippingLabel = 'Ships in ' . $leadTimeWeeks . ' ' . ($leadTimeWeeks === 1 ? 'week' : 'weeks');
        $desc = trim((string) (($item['customizationNotes'] ?? '') ?: ($item['description'] ?? '')));

        $discountBadge = $hasDiscount
            ? '<span class="px-2.5 py-1 rounded-md bg-[#0D1015] text-white font-mono-tech text-[11px] font-bold">Save ' . $discountPercent . '%</span>'
            : '';
        $origPriceHtml = $hasDiscount
            ? '<p class="font-mono-tech text-xs text-slate-400 line-through leading-none mb-0.5">' . $usd($pricing['variantBasePrice']) . '</p>'
            : '';

        return sprintf(
            '<article data-testid="%s" class="group h-full rounded-2xl bg-white border %s overflow-hidden shadow-2xs hover:shadow-xl transition-all duration-300 flex flex-col justify-between">'
            . '<div class="flex flex-col flex-1">'
            . '<div data-view-product="%s" data-testid="home-product-card-link-%s" role="button" tabindex="0" aria-label="View %s" class="block aspect-[4/3] relative overflow-hidden bg-slate-100 cursor-pointer shrink-0">'
            . '<img src="%s" alt="%s" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" />'
            . '<div class="absolute top-3 left-3 right-3 flex flex-wrap items-center justify-between gap-1.5">'
            . '<span data-testid="shop-stock-badge-%s" class="px-2.5 py-1 rounded-md text-[11px] font-semibold shadow-2xs %s">%s</span>%s'
            . '</div></div>'
            . '<div class="p-5 flex flex-col flex-1 justify-between gap-3.5">'
            . '<div class="space-y-2">'
            . '<p class="text-[11px] font-semibold uppercase tracking-wider text-[#0095FF]">%s</p>'
            . '<div class="flex items-start justify-between gap-3">'
            . '<h3 data-view-product="%s" class="font-serif-display text-lg font-bold text-[#0D1015] group-hover:text-[#0095FF] leading-snug transition-colors cursor-pointer line-clamp-2 min-h-[2.75rem]" title="%s">%s</h3>'
            . '<div class="text-right shrink-0">%s<p class="font-mono-tech text-lg sm:text-xl font-bold text-[#0095FF] leading-none">%s</p></div>'
            . '</div>'
            . '<p class="text-xs text-[#475569] leading-relaxed line-clamp-2 min-h-[2.5rem]">%s</p>'
            . '</div>'
            . '<div class="pt-3 border-t border-slate-100 flex items-center justify-between gap-2 text-[11px] text-[#64748B] font-mono-tech">'
            . '<span class="truncate" title="%s">%s</span><span class="shrink-0">%s</span>'
            . '</div></div></div>'
            . '<div class="px-5 pb-5 pt-0 mt-auto">'
            . '<button type="button" data-view-product="%s" data-testid="view-product-btn-%s" class="w-full py-2.5 px-4 rounded-xl bg-[#0D1015] hover:bg-[#0095FF] text-white text-xs font-semibold transition-colors cursor-pointer text-center shadow-2xs">View Product</button>'
            . '</div></article>',
            $esc($cardTestId),
            $borderClass,
            $esc($id),
            $esc($id),
            $esc($item['name'] ?? ''),
            $esc($item['image'] ?? 'assets/images/category_living_lounge.jpg'),
            $esc($item['name'] ?? ''),
            $esc($id),
            $stockBadgeClass,
            $esc($availLabel),
            $discountBadge,
            $esc($item['categoryName'] ?? 'Showroom Collection'),
            $esc($id),
            $esc($item['name'] ?? ''),
            $esc($item['name'] ?? ''),
            $origPriceHtml,
            $usd($pricing['effectivePrice']),
            $esc($desc),
            $esc($item['dimensions'] ?? ''),
            $esc($item['dimensions'] ?? ''),
            $esc($shippingLabel),
            $esc($id),
            $esc($id)
        );
    }

    /**
     * Renders a Room Category card in PHP.
     *
     * @param array<string, mixed> $cat
     * @param int $pieceCount
     */
    public static function renderCategoryCardHtml(array $cat, int $pieceCount, bool $isSelected = false): string
    {
        $esc = static fn(mixed $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $usd = static fn(int|float $v): string => '$' . number_format((int) round((float) $v));
        $id = (string) ($cat['id'] ?? 'living');
        $href = 'catalog.html?category=' . rawurlencode($id);
        $borderClass = $isSelected ? 'border-[#0095FF] ring-2' : 'border-slate-200 hover:border-[#0095FF]';
        $btnClass = $isSelected ? 'bg-[#0095FF] text-white' : 'bg-[#EBF6FF] group-hover:bg-[#0095FF] text-[#0D1015] group-hover:text-white';

        return sprintf(
            '<article data-category-card-href="%s" class="group rounded-2xl bg-white border %s overflow-hidden shadow-2xs hover:shadow-lg transition-all duration-300 flex flex-col justify-between cursor-pointer">'
            . '<a href="%s" data-filter-target="%s" data-testid="category-image-link-%s" aria-label="Explore %s" class="block flex-1 cursor-pointer focus:outline-none">'
            . '<div class="aspect-[4/3] relative overflow-hidden bg-slate-100">'
            . '<img src="%s" alt="%s" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500" />'
            . '<div class="absolute inset-0 bg-gradient-to-t from-[#0D1015]/85 via-[#0D1015]/20 to-transparent"></div>'
            . '<div class="absolute bottom-3.5 left-4 right-4 text-white">'
            . '<p class="font-mono-tech text-[11px] text-[#BCE3FF]">From %s · %d Pieces</p>'
            . '<h3 class="font-serif-display text-lg font-bold leading-snug mt-0.5">%s</h3>'
            . '</div></div>'
            . '<div class="p-4 pt-3.5"><p class="text-xs text-[#475569] leading-relaxed line-clamp-2">%s</p></div>'
            . '</a>'
            . '<div class="px-4 pb-4 pt-1">'
            . '<a href="%s" data-filter-target="%s" data-testid="category-card-link-%s" class="w-full py-2.5 px-3.5 rounded-xl %s text-xs font-semibold transition-colors flex items-center justify-between">'
            . '<span>Explore %s</span><span>→</span>'
            . '</a></div></article>',
            $esc($href),
            $borderClass,
            $esc($href),
            $esc($id),
            $esc($id),
            $esc($cat['name'] ?? ''),
            $esc($cat['image'] ?? ''),
            $esc($cat['name'] ?? ''),
            $usd((float) ($cat['startingPrice'] ?? 1180)),
            $pieceCount,
            $esc($cat['name'] ?? ''),
            $esc($cat['tagline'] ?? ''),
            $esc($href),
            $esc($id),
            $esc($id),
            $btnClass,
            $esc($cat['name'] ?? '')
        );
    }
}
