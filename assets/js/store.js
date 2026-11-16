/**
 * Pure JavaScript Store & Business Logic Module
 * Maps directly to the MySQL 8.0.16+ InnoDB 12-table schema in `database/furniture.sql`:
 * Admin, Category, Customer, Product, Material, Voucher, Customer_Order,
 * Order_Item, Order_Item_Material, Customization, Payment, Production_Task
 */

export const ADMIN_MESSENGER_PLACEHOLDER_URL = 'https://www.messenger.com/t/100000505712325';

export const PRODUCT_TYPES = {
  NORMAL: 'standard',
  CUSTOMIZED: 'customized',
};

const CATEGORY_LABELS = {
  living: 'Living Room',
  dining: 'Dining Room',
  bedroom: 'Bedroom',
  office: 'Study/Work Desks',
  kitchen: 'Kitchen',
};

const DEFAULT_CATEGORY_IMAGES = {
  living: 'assets/images/category_living_lounge.jpg',
  dining: 'assets/images/category_dining_tables.jpg',
  bedroom: 'assets/images/category_bedroom_sanctuary.jpg',
  office: 'assets/images/category_office_storage.jpg',
  kitchen: 'assets/images/category_dining_tables.jpg',
};

const CATEGORY_CODE_PREFIXES = {
  living: 'LV',
  dining: 'DN',
  bedroom: 'BD',
  office: 'OF',
  kitchen: 'KT',
};

export function generateOwnEntityId(entityType = 'entity', seed = '', categoryId = '') {
  const cleanSeed = String(seed || '').trim();
  const cleanCategory = String(categoryId || '').trim().toLowerCase();
  let hash = 2166136261;
  const source = `${entityType}:${cleanSeed}:${cleanCategory}:${Date.now()}:${Math.random()}`;
  for (let i = 0; i < source.length; i += 1) {
    hash ^= source.charCodeAt(i);
    hash = Math.imul(hash, 16777619);
  }
  const unsigned = Math.abs(hash >>> 0);
  const ranges = {
    product: [100, 999],
    order: [8100, 9899],
    material: [10, 99],
    customer: [300, 999],
    voucher: [700, 999],
    orderItem: [5100, 5999],
    customization: [6100, 6999],
    task: [2100, 2999],
    payment: [9100, 9999],
  };
  const [minVal, maxVal] = ranges[entityType] || [100, 999];
  const numericId = minVal + (unsigned % (maxVal - minVal + 1));

  let codeId = `${entityType.toUpperCase()}-${numericId}`;
  if (entityType === 'product') {
    const matchedPrefix =
      CATEGORY_CODE_PREFIXES[cleanCategory] ||
      (cleanSeed.match(/^LR-([A-Z]{2,3})-/i)?.[1] || 'LV').toUpperCase();
    codeId = `LR-${matchedPrefix}-${String(numericId).padStart(3, '0')}`;
  } else if (entityType === 'order') {
    codeId = `ORD-2026-${numericId}`;
  } else if (entityType === 'material') {
    const letters =
      cleanSeed
        .replace(/^MAT-/i, '')
        .replace(/[^a-zA-Z]/g, '')
        .toUpperCase()
        .slice(0, 3) || 'TIM';
    codeId = `MAT-${letters.padEnd(3, 'X')}-${String(numericId).padStart(2, '0')}`;
  } else if (entityType === 'customer') {
    codeId = `LR-CUS-${numericId}`;
  } else if (entityType === 'voucher') {
    codeId = `VCH-LR-${numericId}`;
  } else if (entityType === 'orderItem') {
    codeId = `ITM-2026-${numericId}`;
  } else if (entityType === 'customization') {
    codeId = `CSM-2026-${numericId}`;
  } else if (entityType === 'task') {
    codeId = `TSK-2026-${numericId}`;
  } else if (entityType === 'payment') {
    codeId = `PAY-2026-${numericId}`;
  }

  return {
    numericId,
    codeId,
  };
}

export function generateProductCode(categoryId = 'living', seed = '') {
  return generateOwnEntityId('product', seed, categoryId).codeId;
}

export function buildProductRecord(input, existingProduct = null) {
  const rawType = String(
    input?.productType ??
      existingProduct?.productType ??
      (input?.isCustomClientDesign || existingProduct?.isCustomClientDesign
        ? PRODUCT_TYPES.CUSTOMIZED
        : PRODUCT_TYPES.NORMAL)
  )
    .trim()
    .toLowerCase();

  const isCustomized = rawType === PRODUCT_TYPES.CUSTOMIZED || rawType === 'messenger';
  const productType = isCustomized ? PRODUCT_TYPES.CUSTOMIZED : PRODUCT_TYPES.NORMAL;
  const origin = isCustomized ? 'Messenger customization' : 'Admin';

  const categoryId = String(input?.categoryId || existingProduct?.categoryId || 'dining').trim();
  const categoryName =
    input?.categoryName ||
    CATEGORY_LABELS[categoryId] ||
    existingProduct?.categoryName ||
    'Dining Room';

  const price = Math.max(50, Math.round(Number(input?.price ?? existingProduct?.price ?? 2500)));
  const discountPercent = Math.min(
    80,
    Math.max(0, Math.round(Number(input?.discountPercent ?? existingProduct?.discountPercent ?? 0)))
  );
  const effectivePrice = Math.round(price * (1 - discountPercent / 100));

  let colorVariants = Array.isArray(input?.colorVariants)
    ? input.colorVariants
    : Array.isArray(existingProduct?.colorVariants)
    ? existingProduct.colorVariants
    : null;

  if (typeof input?.colorsRaw === 'string' && input.colorsRaw.trim().length > 0) {
    const parsed = input.colorsRaw
      .split(',')
      .map((part) => {
        const [cName, cHex, cDelta] = part.split('|').map((s) => (s || '').trim());
        if (!cName) return null;
        return {
          name: cName,
          hex: cHex && cHex.startsWith('#') ? cHex : '#5C3A21',
          priceDelta: Number(cDelta) || 0,
        };
      })
      .filter(Boolean);
    if (parsed.length > 0) colorVariants = parsed;
  }

  if (!Array.isArray(colorVariants) || colorVariants.length === 0) {
    colorVariants = [{ name: 'Hand-Rubbed Natural Timber', hex: '#5C3A21', priceDelta: 0 }];
  }

  const messengerConversationId = isCustomized
    ? String(input?.messengerConversationId ?? existingProduct?.messengerConversationId ?? '').trim() || null
    : null;
  const messengerCustomerName = isCustomized
    ? String(input?.messengerCustomerName ?? existingProduct?.messengerCustomerName ?? '').trim() || null
    : null;
  const messengerTopic = isCustomized
    ? String(input?.messengerTopic ?? existingProduct?.messengerTopic ?? '').trim() || null
    : null;
  const customizationNotes = isCustomized
    ? String(
        input?.customizationNotes ?? existingProduct?.customizationNotes ?? input?.description ?? ''
      ).trim() ||
      'Custom furniture piece created from a customer customization request. Available in the public catalog for all clients.'
    : null;

  const resolvedImage =
    String(input?.image ?? existingProduct?.image ?? '').trim() ||
    DEFAULT_CATEGORY_IMAGES[categoryId] ||
    'assets/images/category_dining_tables.jpg';

  const rawStock = input?.inStockCount ?? existingProduct?.inStockCount ?? 4;
  const inStockCount = Math.max(0, Math.floor(Number.isFinite(Number(rawStock)) ? Number(rawStock) : 4));

  const rawMinStock = input?.minStockLevel ?? existingProduct?.minStockLevel ?? 2;
  const minStockLevel = Math.max(0, Math.floor(Number.isFinite(Number(rawMinStock)) ? Number(rawMinStock) : 2));

  const rawSkuInput = String(
    input?.sku ??
      input?.productId ??
      existingProduct?.productId ??
      existingProduct?.sku ??
      ''
  )
    .trim()
    .toUpperCase();
  const hasExplicitProductCode =
    rawSkuInput.length >= 4 &&
    rawSkuInput !== 'LR-NEW-001' &&
    !/^\d+$/.test(rawSkuInput);
  const resolvedProductId = hasExplicitProductCode
    ? rawSkuInput
    : generateOwnEntityId('product', input?.name || '', categoryId).codeId;

  return {
    ...(existingProduct || {}),
    id:
      existingProduct?.id ||
      input?.id ||
      `furn-${isCustomized ? 'cust' : 'std'}-${resolvedProductId.toLowerCase()}`,
    productId: resolvedProductId,
    sku: resolvedProductId,
    name: String(input?.name ?? existingProduct?.name ?? 'Untitled Piece').trim(),
    categoryId,
    categoryName,
    productType,
    origin,
    isCustomClientDesign: isCustomized,
    publiclyVisible: true,
    canBePurchased: true,
    adminEditable: true,
    customClientEmail: null,
    messengerConversationId,
    messengerCustomerName,
    messengerTopic,
    customizationNotes,
    price,
    discountPercent,
    effectivePrice,
    dimensions: String(input?.dimensions ?? existingProduct?.dimensions ?? 'Standard Dimensions').trim(),
    leadTimeWeeks: Number(input?.leadTimeWeeks ?? existingProduct?.leadTimeWeeks ?? (isCustomized ? 5 : 4)),
    inStockCount,
    minStockLevel,
    primaryMaterialId: input?.primaryMaterialId ?? existingProduct?.primaryMaterialId ?? 'mat-101',
    defaultFinish: colorVariants[0].name,
    finishes: colorVariants.map((v) => v.name),
    colorVariants,
    description: String(
      input?.description ??
        existingProduct?.description ??
        (isCustomized
          ? customizationNotes
          : 'Handcrafted solid timber and architectural upholstery piece built in our workshop.')
    ).trim(),
    craftsmanshipNote: String(
      input?.craftsmanshipNote ??
        existingProduct?.craftsmanshipNote ??
        (isCustomized
          ? 'Custom-engineered dimensions and finishes added to the public collection.'
          : 'Custom-milled joinery with color-graded finish pricing.')
    ).trim(),
    image: resolvedImage,
  };
}

export function computeVariantPricing(product, selectedColorName) {
  const basePrice = Number(product?.price || 0);
  const discountPercent = Math.min(80, Math.max(0, Number(product?.discountPercent || 0)));
  const variants = Array.isArray(product?.colorVariants) ? product.colorVariants : [];

  const matchedVariant =
    variants.find((v) => v.name === selectedColorName) ||
    variants[0] || { name: product?.defaultFinish || 'Standard Finish', hex: '#78716C', priceDelta: 0 };

  const priceDelta = Number(matchedVariant.priceDelta || 0);
  const variantBasePrice = Math.max(0, basePrice + priceDelta);
  const discountDollar = Math.round(variantBasePrice * (discountPercent / 100));
  const effectivePrice = Math.max(0, variantBasePrice - discountDollar);

  return {
    colorName: matchedVariant.name,
    colorHex: matchedVariant.hex || '#78716C',
    priceDelta,
    variantBasePrice,
    discountPercent,
    discountDollar,
    effectivePrice,
  };
}

export function buildAdminMessengerUrl(payload = {}) {
  const mode = payload.mode || 'direct-offer';
  const skuPart = payload.sku ? `_${String(payload.sku).toLowerCase()}` : '';
  const colorPart = payload.colorName
    ? `_${String(payload.colorName).toLowerCase().replace(/[^a-z0-9]+/g, '-')}`
    : '';
  const offerPart = payload.proposedPrice ? `_offer-${Math.round(Number(payload.proposedPrice))}` : '';
  const clientPart = payload.clientEmail ? `&client=${encodeURIComponent(payload.clientEmail)}` : '';

  return `${ADMIN_MESSENGER_PLACEHOLDER_URL}?ref=${encodeURIComponent(
    `${mode}${skuPart}${colorPart}${offerPart}`
  )}${clientPart}`;
}

export function filterFurnitureItems(items, searchQuery = '', categoryId = 'all') {
  if (!Array.isArray(items)) return [];
  const normalizedQuery = String(searchQuery || '').trim().toLowerCase();
  const normalizedCategory = String(categoryId || 'all').trim().toLowerCase();

  return items.filter((item) => {
    const matchesCategory =
      normalizedCategory === 'all' ||
      String(item.categoryId || '').toLowerCase() === normalizedCategory;

    if (!matchesCategory) return false;
    if (!normalizedQuery) return true;

    const finishesText = Array.isArray(item.finishes) ? item.finishes.join(' ') : '';
    const colorsText = Array.isArray(item.colorVariants)
      ? item.colorVariants.map((c) => c.name).join(' ')
      : '';
    const searchable = [
      item.name,
      item.sku,
      item.categoryName,
      item.description,
      item.craftsmanshipNote,
      item.defaultFinish,
      item.origin,
      item.productType,
      item.customizationNotes,
      item.messengerCustomerName,
      finishesText,
      colorsText,
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase();

    return searchable.includes(normalizedQuery);
  });
}

export function resolveAuthoritativeLinePrice(item, catalog = null) {
  const productId = item?.product?.id || item?.productId || item?.furnitureId || item?.id || '';
  const finishName = item?.finish || item?.selectedFinish || '';

  if (Array.isArray(catalog) && catalog.length > 0 && productId) {
    const authoritativeProduct = catalog.find((p) => p.id === productId);
    if (authoritativeProduct) {
      return computeVariantPricing(authoritativeProduct, finishName).effectivePrice;
    }
  }

  if (item?.product && typeof item.product === 'object') {
    return computeVariantPricing(item.product, finishName).effectivePrice;
  }

  return Math.max(0, Number(item?.unitPrice ?? item?.effectivePrice ?? item?.price ?? 0));
}

export const VOUCHER_QUICK_DURATIONS = [
  { label: '30 minutes', shortLabel: '30m', value: 30, unit: 'minutes' },
  { label: '1 hour', shortLabel: '1h', value: 1, unit: 'hours' },
  { label: '6 hours', shortLabel: '6h', value: 6, unit: 'hours' },
  { label: '12 hours', shortLabel: '12h', value: 12, unit: 'hours' },
  { label: '24 hours', shortLabel: '24h', value: 24, unit: 'hours' },
  { label: '3 days', shortLabel: '3d', value: 3, unit: 'days' },
  { label: '7 days', shortLabel: '7d', value: 7, unit: 'days' },
];

export function isDateOnlyString(val) {
  return typeof val === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(val.trim());
}

export function formatManilaDateOnly(dateStr) {
  if (!dateStr) return '—';
  const clean = String(dateStr).trim();
  const iso = isDateOnlyString(clean) ? `${clean}T12:00:00+08:00` : clean;
  const parsed = new Date(iso);
  if (Number.isNaN(parsed.getTime())) return clean;
  return new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Manila',
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(parsed);
}

export function formatManilaDateTimeParts(isoStr) {
  if (!isoStr) return { datePart: '—', timePart: '', full: '—' };
  const clean = String(isoStr).trim();
  if (isDateOnlyString(clean)) {
    const datePart = formatManilaDateOnly(clean);
    return { datePart, timePart: '', full: datePart };
  }
  const parsed = new Date(clean);
  if (Number.isNaN(parsed.getTime())) {
    return { datePart: clean, timePart: '', full: clean };
  }
  const datePart = new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Manila',
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(parsed);
  const timePart =
    new Intl.DateTimeFormat('en-US', {
      timeZone: 'Asia/Manila',
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
    }).format(parsed) + ' PHT';
  return {
    datePart,
    timePart,
    full: `${datePart}, ${timePart}`,
  };
}

export function formatVoucherIssuedDisplay(voucher) {
  if (!voucher || typeof voucher !== 'object') {
    return {
      primary: '—',
      secondary: 'Not recorded',
      full: '—',
      hasExactTime: false,
      hasFullTimestamp: false,
    };
  }
  if (voucher.issuedAt && !isDateOnlyString(voucher.issuedAt)) {
    const parts = formatManilaDateTimeParts(voucher.issuedAt);
    return {
      primary: parts.datePart,
      secondary: parts.timePart,
      full: parts.full,
      hasExactTime: true,
      hasFullTimestamp: true,
    };
  }
  if (voucher.createdAt) {
    const datePart = formatManilaDateOnly(voucher.createdAt);
    return {
      primary: datePart,
      secondary: 'Time not recorded',
      full: `${datePart} (Time not recorded)`,
      hasExactTime: false,
      hasFullTimestamp: false,
    };
  }
  return {
    primary: '—',
    secondary: 'Time not recorded',
    full: '—',
    hasExactTime: false,
    hasFullTimestamp: false,
  };
}

export function resolveVoucherExpirationTimestamp(voucher) {
  if (!voucher || typeof voucher !== 'object') return null;
  if (voucher.expiresAt && !isDateOnlyString(voucher.expiresAt)) {
    const ms = new Date(voucher.expiresAt).getTime();
    if (!Number.isNaN(ms)) return ms;
  }
  const rawExpiry = voucher.expiresAt || voucher.expiryDate;
  if (rawExpiry) {
    const clean = String(rawExpiry).trim();
    if (isDateOnlyString(clean)) {
      const ms = new Date(`${clean}T23:59:59+08:00`).getTime();
      if (!Number.isNaN(ms)) return ms;
    }
    const ms = new Date(clean).getTime();
    if (!Number.isNaN(ms)) return ms;
  }
  return null;
}

export function formatVoucherExpiryDisplay(voucher) {
  if (!voucher || typeof voucher !== 'object') {
    return { primary: '—', secondary: '', full: '—', expiresAtMs: null };
  }
  const expiresAtMs = resolveVoucherExpirationTimestamp(voucher);
  if (voucher.expiresAt && !isDateOnlyString(voucher.expiresAt)) {
    const parts = formatManilaDateTimeParts(voucher.expiresAt);
    return {
      primary: parts.datePart,
      secondary: parts.timePart,
      full: parts.full,
      expiresAtMs,
    };
  }
  if (voucher.expiryDate) {
    const datePart = formatManilaDateOnly(voucher.expiryDate);
    return {
      primary: datePart,
      secondary: '11:59 PM PHT',
      full: `${datePart}, 11:59 PM PHT`,
      expiresAtMs,
    };
  }
  return { primary: '—', secondary: '', full: '—', expiresAtMs: null };
}

export function formatRemainingTime(remainingMs, options = {}) {
  const { includeSeconds = true } = options;
  if (!Number.isFinite(remainingMs) || remainingMs <= 0) {
    return 'Expired';
  }
  const totalSeconds = Math.max(1, Math.floor(remainingMs / 1000));
  const days = Math.floor(totalSeconds / 86400);
  const hours = Math.floor((totalSeconds % 86400) / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;

  if (days > 0) {
    return `${days}d ${hours}h ${minutes}m left`;
  }
  if (hours > 0) {
    return includeSeconds
      ? `${hours}h ${minutes}m ${String(seconds).padStart(2, '0')}s left`
      : `${hours}h ${minutes}m left`;
  }
  if (minutes > 0) {
    return includeSeconds
      ? `${minutes}m ${String(seconds).padStart(2, '0')}s left`
      : `${minutes}m left`;
  }
  return `${seconds}s left`;
}

export function computeVoucherStatusInfo(voucher, nowMs = Date.now()) {
  if (!voucher || typeof voucher !== 'object') {
    return {
      status: 'Expired',
      badgeClass: 'admin-status-voucher-expired',
      isUsable: false,
      isExpiringSoon: false,
      remainingMs: 0,
      remainingLabel: '—',
      expiresAtMs: null,
    };
  }

  const rawStatus = String(voucher.status || 'Active').trim();
  const expiresAtMs = resolveVoucherExpirationTimestamp(voucher);

  if (rawStatus === 'Used') {
    return {
      status: 'Used',
      badgeClass: 'admin-status-voucher-used',
      isUsable: false,
      isExpiringSoon: false,
      remainingMs: 0,
      remainingLabel: 'Redeemed',
      expiresAtMs,
    };
  }

  if (rawStatus === 'Revoked' || rawStatus === 'Cancelled') {
    return {
      status: 'Revoked',
      badgeClass: 'admin-status-voucher-revoked',
      isUsable: false,
      isExpiringSoon: false,
      remainingMs: 0,
      remainingLabel: 'Revoked',
      expiresAtMs,
    };
  }

  if (rawStatus === 'Expired') {
    return {
      status: 'Expired',
      badgeClass: 'admin-status-voucher-expired',
      isUsable: false,
      isExpiringSoon: false,
      remainingMs: 0,
      remainingLabel: 'Expired',
      expiresAtMs,
    };
  }

  if (expiresAtMs !== null) {
    const remainingMs = expiresAtMs - nowMs;
    if (remainingMs <= 0) {
      return {
        status: 'Expired',
        badgeClass: 'admin-status-voucher-expired',
        isUsable: false,
        isExpiringSoon: false,
        remainingMs: 0,
        remainingLabel: 'Expired',
        expiresAtMs,
      };
    }

    if (remainingMs <= 3600 * 1000) {
      return {
        status: 'Expiring Soon',
        badgeClass: 'admin-status-voucher-expiring',
        isUsable: true,
        isExpiringSoon: true,
        remainingMs,
        remainingLabel: formatRemainingTime(remainingMs),
        expiresAtMs,
      };
    }

    return {
      status: 'Active',
      badgeClass: 'admin-status-voucher-active',
      isUsable: true,
      isExpiringSoon: false,
      remainingMs,
      remainingLabel: formatRemainingTime(remainingMs),
      expiresAtMs,
    };
  }

  return {
    status: 'Active',
    badgeClass: 'admin-status-voucher-active',
    isUsable: true,
    isExpiringSoon: false,
    remainingMs: null,
    remainingLabel: 'No expiry set',
    expiresAtMs: null,
  };
}

export function formatVoucherBenefitLabel(voucher) {
  if (!voucher || typeof voucher !== 'object') return '';
  if (voucher.discountType === 'percentage' || Number(voucher.discountPercent || 0) > 0) {
    const pct = Number(voucher.discountPercent ?? voucher.discountValue ?? voucher.discountAmount ?? 0);
    return `${pct}% OFF`;
  }
  const amt = Number(voucher.discountAmount ?? voucher.discountValue ?? 0);
  return `$${Math.round(amt).toLocaleString('en-US')} OFF`;
}

export function validateVoucherForOrder(
  vouchers,
  voucherCode,
  customerEmail,
  cartProductIds = [],
  catalog = null,
  nowMs = Date.now()
) {
  const cleanCode = String(voucherCode || '').trim().toUpperCase();
  const cleanEmail = String(customerEmail || '').trim().toLowerCase();

  if (!cleanCode) {
    return { valid: false, discountAmount: 0, voucher: null, errorMessage: 'Enter a voucher code.' };
  }

  const matched = Array.isArray(vouchers)
    ? vouchers.find((v) => String(v.code || '').toUpperCase() === cleanCode)
    : null;

  if (!matched) {
    return {
      valid: false,
      discountAmount: 0,
      voucher: null,
      errorMessage: `Voucher "${cleanCode}" was not found.`,
    };
  }

  const statusInfo = computeVoucherStatusInfo(matched, nowMs);
  if (!statusInfo.isUsable) {
    if (statusInfo.status === 'Expired') {
      const expDisplay = formatVoucherExpiryDisplay(matched);
      return {
        valid: false,
        discountAmount: 0,
        voucher: matched,
        errorMessage: `Voucher "${cleanCode}" expired on ${expDisplay.full || matched.expiryDate || 'its expiration date'}.`,
      };
    }
    if (statusInfo.status === 'Revoked') {
      return {
        valid: false,
        discountAmount: 0,
        voucher: matched,
        errorMessage: `Voucher "${cleanCode}" has been revoked and is no longer valid.`,
      };
    }
    return {
      valid: false,
      discountAmount: 0,
      voucher: matched,
      errorMessage: `Voucher "${cleanCode}" is no longer active (${statusInfo.status}).`,
    };
  }

  const assignedEmail = String(matched.customerEmail || '').trim().toLowerCase();
  if (assignedEmail && assignedEmail !== 'all' && assignedEmail !== '*') {
    if (!cleanEmail || assignedEmail !== cleanEmail) {
      return {
        valid: false,
        discountAmount: 0,
        voucher: matched,
        errorMessage: `Voucher "${cleanCode}" is exclusively assigned to ${matched.customerEmail}.`,
      };
    }
  }

  const isLegacyPercent =
    matched.discountType === 'percentage' || Number(matched.discountPercent || 0) > 0;
  const rawDiscountVal = Number(matched.discountAmount ?? matched.discountValue ?? 0);
  if (!isLegacyPercent && (!Number.isFinite(rawDiscountVal) || rawDiscountVal <= 0)) {
    return {
      valid: false,
      discountAmount: 0,
      voucher: matched,
      errorMessage: `Voucher "${cleanCode}" has an invalid discount amount.`,
    };
  }

  const targetProductId = matched.furnitureId || matched.productId || '';
  const targetProductName = matched.furnitureName || matched.productName || ' the selected product';

  if (!isLegacyPercent && Array.isArray(catalog) && catalog.length > 0 && targetProductId) {
    const eligibleCatalogItem = catalog.find((p) => p.id === targetProductId);
    if (eligibleCatalogItem) {
      const maxPrice = Number(eligibleCatalogItem.effectivePrice ?? eligibleCatalogItem.price ?? 0);
      if (maxPrice > 0 && rawDiscountVal > maxPrice) {
        return {
          valid: false,
          discountAmount: 0,
          voucher: matched,
          errorMessage: `Voucher "${cleanCode}" discount ($${rawDiscountVal}) exceeds the eligible product price ($${maxPrice}).`,
        };
      }
    }
  }

  if (Array.isArray(cartProductIds) && cartProductIds.length > 0) {
    const normalizedIds = cartProductIds.map((entry) =>
      typeof entry === 'string'
        ? entry
        : entry?.product?.id || entry?.productId || entry?.furnitureId || entry?.id || ''
    );

    if (!normalizedIds.includes(targetProductId)) {
      return {
        valid: false,
        discountAmount: 0,
        voucher: matched,
        errorMessage: `This voucher is only valid for the selected product (${targetProductName}).`,
      };
    }

    if (cartProductIds.some((entry) => entry && typeof entry === 'object')) {
      const breakdown = calculateOrderPricing(cartProductIds, matched, catalog);
      return {
        valid: true,
        discountAmount: breakdown.voucherDiscountAmount,
        eligibleSubtotal: breakdown.eligibleSubtotal,
        voucher: matched,
        errorMessage: '',
      };
    }
  }

  return {
    valid: true,
    discountAmount: Number(matched.discountAmount || matched.discountValue || 0),
    voucher: matched,
    errorMessage: '',
  };
}

export function calculateOrderPricing(lineItems, voucherOrDiscount = 0, catalog = null) {
  const safeLines = Array.isArray(lineItems) ? lineItems : [];
  const isVoucherObject =
    voucherOrDiscount !== null &&
    typeof voucherOrDiscount === 'object' &&
    Boolean(voucherOrDiscount.furnitureId || voucherOrDiscount.productId || voucherOrDiscount.code);

  const targetProductId = isVoucherObject
    ? String(voucherOrDiscount.furnitureId || voucherOrDiscount.productId || '')
    : '';

  const isPercentageVoucher =
    isVoucherObject &&
    (voucherOrDiscount.discountType === 'percentage' || Number(voucherOrDiscount.discountPercent || 0) > 0);

  const percentageRate = isPercentageVoucher
    ? Math.min(
        100,
        Math.max(
          0,
          Number(
            voucherOrDiscount.discountPercent ??
              voucherOrDiscount.discountValue ??
              voucherOrDiscount.discountAmount ??
              0
          )
        )
      )
    : 0;

  let remainingFixedVoucherDiscount =
    isVoucherObject && !isPercentageVoucher
      ? Math.max(0, Number(voucherOrDiscount.discountAmount ?? voucherOrDiscount.discountValue ?? 0))
      : 0;

  let rawSubtotal = 0;
  let eligibleSubtotal = 0;
  let nonEligibleSubtotal = 0;
  let calculatedVoucherDiscount = 0;

  const lineBreakdowns = safeLines.map((item) => {
    const productId = item?.product?.id || item?.productId || item?.furnitureId || item?.id || '';
    const productName =
      item?.product?.name || item?.productName || item?.furnitureName || item?.name || 'Item';
    const quantity = Math.max(1, Number(item?.quantity || 1));
    const unitPrice = resolveAuthoritativeLinePrice(item, catalog);
    const rawLineTotal = Math.round(unitPrice * quantity);

    rawSubtotal += rawLineTotal;

    const isVoucherEligible = Boolean(isVoucherObject && targetProductId && productId === targetProductId);
    let lineDiscount = 0;

    if (isVoucherEligible) {
      eligibleSubtotal += rawLineTotal;
      if (isPercentageVoucher) {
        lineDiscount = Math.min(rawLineTotal, Math.round(rawLineTotal * (percentageRate / 100)));
      } else {
        lineDiscount = Math.min(rawLineTotal, Math.round(remainingFixedVoucherDiscount));
        remainingFixedVoucherDiscount = Math.max(0, remainingFixedVoucherDiscount - lineDiscount);
      }
      calculatedVoucherDiscount += lineDiscount;
    } else {
      nonEligibleSubtotal += rawLineTotal;
    }

    return {
      productId,
      productName,
      quantity,
      unitPrice,
      rawLineTotal,
      isVoucherEligible,
      lineDiscount,
      finalLineTotal: Math.max(0, rawLineTotal - lineDiscount),
      voucherBadge: isVoucherEligible
        ? `${formatVoucherBenefitLabel(voucherOrDiscount)} applied`
        : isVoucherObject
        ? 'No discount'
        : '',
    };
  });

  const voucherDiscountAmount = isVoucherObject
    ? calculatedVoucherDiscount
    : Math.min(rawSubtotal, Math.max(0, Number(voucherOrDiscount || 0)));

  const subtotal = Math.max(0, rawSubtotal - voucherDiscountAmount);
  const whiteGloveDeliveryFee = subtotal === 0 ? 0 : subtotal >= 5000 ? 0 : 250;
  const totalAmount = subtotal + whiteGloveDeliveryFee;

  return {
    rawSubtotal,
    eligibleSubtotal,
    nonEligibleSubtotal,
    voucherDiscountAmount,
    subtotal,
    whiteGloveDeliveryFee,
    totalAmount,
    appliedVoucherCode: isVoucherObject ? voucherOrDiscount.code : null,
    targetProductId: targetProductId || null,
    lineBreakdowns,
  };
}

export function verifyAndCreateOrder({
  catalog = [],
  vouchers = [],
  customerProfile = {},
  sessionUser = null,
  cartItems = [],
  voucherCode = '',
}) {
  if (!Array.isArray(cartItems) || cartItems.length === 0) {
    return {
      ok: false,
      errorMessage: 'Your shopping cart is empty.',
      order: null,
      pricing: null,
      validatedVoucher: null,
    };
  }

  const activeEmail =
    customerProfile?.email || sessionUser?.email || 'rowena.vance@vancearchitects.com';
  const normalizedLines = [];
  const requestedQtyByProduct = {};

  for (const item of cartItems) {
    const productId = item?.product?.id || item?.productId || item?.furnitureId || item?.id || '';
    const authoritativeProduct = Array.isArray(catalog)
      ? catalog.find((p) => p.id === productId)
      : item?.product;

    if (!authoritativeProduct) {
      return {
        ok: false,
        errorMessage: `Product "${productId || 'Unknown'}" is no longer available in the catalog.`,
        order: null,
        pricing: null,
        validatedVoucher: null,
      };
    }

    const finish =
      item?.finish || item?.selectedFinish || authoritativeProduct.defaultFinish || 'Natural Finish';
    const quantity = Math.max(1, Math.floor(Number(item?.quantity || 1)));
    const availableStock = Math.max(0, Math.floor(Number(authoritativeProduct.inStockCount ?? 0)));

    requestedQtyByProduct[authoritativeProduct.id] =
      (requestedQtyByProduct[authoritativeProduct.id] || 0) + quantity;

    if (availableStock <= 0) {
      return {
        ok: false,
        errorMessage: `"${authoritativeProduct.name}" is currently Out of Stock and cannot be ordered.`,
        order: null,
        pricing: null,
        validatedVoucher: null,
      };
    }

    if (requestedQtyByProduct[authoritativeProduct.id] > availableStock) {
      return {
        ok: false,
        errorMessage: `Requested quantity (${requestedQtyByProduct[authoritativeProduct.id]}) for "${authoritativeProduct.name}" exceeds available stock (${availableStock} available in stock).`,
        order: null,
        pricing: null,
        validatedVoucher: null,
      };
    }

    const pricing = computeVariantPricing(authoritativeProduct, finish);
    normalizedLines.push({
      product: authoritativeProduct,
      productId: authoritativeProduct.id,
      productName: authoritativeProduct.name,
      sku: authoritativeProduct.sku,
      categoryName: authoritativeProduct.categoryName,
      dimensions: authoritativeProduct.dimensions,
      finish: pricing.colorName,
      unitPrice: pricing.effectivePrice,
      quantity,
    });
  }

  let validatedVoucher = null;
  const cleanCode = String(voucherCode || '').trim().toUpperCase();

  if (cleanCode) {
    const vCheck = validateVoucherForOrder(vouchers, cleanCode, activeEmail, normalizedLines, catalog);
    if (!vCheck.valid) {
      return {
        ok: false,
        errorMessage: vCheck.errorMessage,
        order: null,
        pricing: null,
        validatedVoucher: null,
      };
    }
    validatedVoucher = vCheck.voucher;
  }

  const pricing = calculateOrderPricing(normalizedLines, validatedVoucher, catalog);
  const firstEntry = normalizedLines[0];
  const totalQty = normalizedLines.reduce((sum, l) => sum + l.quantity, 0);
  const generatedOrderId = generateOwnEntityId('order', activeEmail).codeId;
  const generatedOrderItemId = generateOwnEntityId('orderItem', `${generatedOrderId}-${firstEntry.productId}`).codeId;
  const generatedTaskId = generateOwnEntityId('task', `${generatedOrderId}`).codeId;
  const generatedPaymentId = generateOwnEntityId('payment', `${generatedOrderId}`).codeId;
  const ordNum = generatedOrderId;

  const orderRecord = {
    id: `ord-${ordNum.toLowerCase()}`,
    orderId: ordNum,
    orderItemId: generatedOrderItemId,
    taskId: generatedTaskId,
    paymentId: generatedPaymentId,
    orderNumber: ordNum,
    clientName: customerProfile?.name || sessionUser?.name || 'Rowena Vance',
    clientEmail: activeEmail,
    clientCompany: 'Private Residence',
    furnitureId: firstEntry.productId,
    furnitureName:
      normalizedLines.length > 1
        ? `${firstEntry.productName} (+${normalizedLines.length - 1} more)`
        : firstEntry.productName,
    sku: firstEntry.sku,
    categoryName: firstEntry.categoryName,
    itemType: 'Standard',
    selectedFinish: firstEntry.finish,
    customDimensions: firstEntry.dimensions || 'Standard Dimensions',
    materialId: firstEntry.product?.primaryMaterialId || 'mat-101',
    quantity: totalQty,
    unitPrice: firstEntry.unitPrice,
    extraCost: 0,
    rawSubtotal: pricing.rawSubtotal,
    eligibleSubtotal: pricing.eligibleSubtotal,
    voucherDiscountAmount: pricing.voucherDiscountAmount,
    appliedVoucherCode: validatedVoucher ? validatedVoucher.code : null,
    appliedVoucherProductId: validatedVoucher
      ? validatedVoucher.furnitureId || validatedVoucher.productId
      : null,
    whiteGloveDeliveryFee: pricing.whiteGloveDeliveryFee,
    totalAmount: pricing.totalAmount,
    depositPaid: pricing.totalAmount,
    status: 'Order Confirmed · In Production Queue',
    sqlStatus: 'Pending',
    taskStatus: 'Todo',
    deliveryStatus: 'Scheduled',
    driverName: 'Devon Vance',
    paymentStatus: 'Paid in Full',
    orderDate: new Date().toISOString().slice(0, 10),
    estimatedDelivery: '4–6 Weeks',
    deliveryAddress: `${customerProfile?.address || 'Suite 400, 118 Mercer St'}, ${
      customerProfile?.city || 'New York, NY'
    }`,
    assignedCraftsperson: 'Mateo Cruz',
    notes: customerProfile?.notes || 'Standard white-glove delivery.',
    lineBreakdowns: pricing.lineBreakdowns,
  };

  const updatedCatalog = Array.isArray(catalog)
    ? catalog.map((prod) => {
        const deducted = requestedQtyByProduct[prod.id] || 0;
        return deducted
          ? { ...prod, inStockCount: Math.max(0, Math.floor(Number(prod.inStockCount ?? 0)) - deducted) }
          : prod;
      })
    : [];

  return {
    ok: true,
    errorMessage: '',
    order: orderRecord,
    pricing,
    validatedVoucher,
    updatedCatalog,
    requestedQtyByProduct,
  };
}

export function createCustomOrderRequest({
  catalog = [],
  materials = [],
  customerProfile = {},
  sessionUser = null,
  customInput = {},
}) {
  const productId = String(customInput?.productId || '').trim();
  const materialId = String(customInput?.materialId || '').trim();
  const lengthCm = Number(customInput?.lengthCm || 0);
  const widthCm = Number(customInput?.widthCm || 0);
  const heightCm = Number(customInput?.heightCm || 0);
  const colorFinish = String(customInput?.colorFinish || '').trim() || 'Hand-Rubbed Natural Finish';
  const notes = String(customInput?.notes || '').trim();
  const quantity = Math.max(1, Math.floor(Number(customInput?.quantity || 1)));

  const product = Array.isArray(catalog) ? catalog.find((p) => p.id === productId) : null;
  if (!product) {
    return { ok: false, errorMessage: 'Please select a base catalog piece to customize.' };
  }

  const material = Array.isArray(materials) ? materials.find((m) => m.id === materialId) : null;
  if (!material) {
    return { ok: false, errorMessage: 'Please select a workshop raw material for your custom piece.' };
  }

  if (
    !Number.isFinite(lengthCm) ||
    lengthCm < 30 ||
    !Number.isFinite(widthCm) ||
    widthCm < 30 ||
    !Number.isFinite(heightCm) ||
    heightCm < 25
  ) {
    return {
      ok: false,
      errorMessage: 'Please enter valid custom dimensions (Length ≥ 30cm, Width ≥ 30cm, Height ≥ 25cm).',
    };
  }

  const materialQtyNeeded = Math.max(4, Math.round(((lengthCm * widthCm) / 1500) * quantity));
  const availableMatStock = Math.max(0, Number(material.stockQuantity ?? 0));
  if (availableMatStock < materialQtyNeeded) {
    return {
      ok: false,
      errorMessage: `Insufficient workshop material stock for "${material.name}": requires ${materialQtyNeeded} ${material.unit}, but only ${availableMatStock} ${material.unit} in stock.`,
    };
  }

  const variantPricing = computeVariantPricing(product, colorFinish);
  const dimensionFactor = Math.max(0, lengthCm + widthCm - 300);
  const extraCost = Math.round(dimensionFactor * 3.5 + Number(material.unitCost || 25) * 4);
  const unitPrice = variantPricing.effectivePrice + extraCost;
  const rawSubtotal = unitPrice * quantity;
  const deliveryFee = rawSubtotal >= 5000 ? 0 : 250;
  const totalAmount = rawSubtotal + deliveryFee;
  const depositPaid = Math.round(totalAmount * 0.5);

  const activeEmail =
    customerProfile?.email || sessionUser?.email || 'rowena.vance@vancearchitects.com';
  const generatedOrderId = generateOwnEntityId('order', `${activeEmail}-${product.id}`).codeId;
  const generatedOrderItemId = generateOwnEntityId('orderItem', `${generatedOrderId}-${product.id}`).codeId;
  const generatedCustomizationId = generateOwnEntityId('customization', `${generatedOrderId}`).codeId;
  const generatedTaskId = generateOwnEntityId('task', `${generatedOrderId}`).codeId;
  const generatedPaymentId = generateOwnEntityId('payment', `${generatedOrderId}`).codeId;
  const ordNum = generatedOrderId;
  const customDimensions = `${Math.round(lengthCm)}cm L × ${Math.round(widthCm)}cm W × ${Math.round(heightCm)}cm H`;

  const orderRecord = {
    id: `ord-cust-${ordNum.toLowerCase()}`,
    orderId: ordNum,
    orderItemId: generatedOrderItemId,
    customizationId: generatedCustomizationId,
    taskId: generatedTaskId,
    paymentId: generatedPaymentId,
    orderNumber: ordNum,
    clientName: customerProfile?.name || sessionUser?.name || 'Rowena Vance',
    clientEmail: activeEmail,
    clientCompany: 'Custom Workshop Commission',
    furnitureId: product.id,
    furnitureName: `${product.name} (Custom Spec)`,
    sku: product.sku,
    categoryName: product.categoryName,
    itemType: 'Custom',
    selectedFinish: colorFinish,
    customDimensions,
    lengthCm: Math.round(lengthCm),
    widthCm: Math.round(widthCm),
    heightCm: Math.round(heightCm),
    materialId: material.id,
    materialName: material.name,
    materialQtyUsed: materialQtyNeeded,
    materialUnit: material.unit,
    quantity,
    unitPrice,
    extraCost,
    rawSubtotal,
    whiteGloveDeliveryFee: deliveryFee,
    totalAmount,
    depositPaid,
    status: 'Timber Selection & Kiln Verification',
    sqlStatus: 'InProduction',
    taskStatus: 'InProgress',
    deliveryStatus: 'Scheduled',
    driverName: 'Devon Vance',
    paymentStatus: '50% Deposit Paid',
    orderDate: new Date().toISOString().slice(0, 10),
    estimatedDelivery: `${(product.leadTimeWeeks || 4) + 1} Weeks`,
    deliveryAddress: `${customerProfile?.address || 'Suite 400, 118 Mercer St'}, ${
      customerProfile?.city || 'New York, NY'
    }`,
    assignedCraftsperson: 'Mateo Cruz',
    notes: notes || `Custom commission using ${material.name} (${customDimensions}).`,
    appliedVoucherCode: null,
    voucherDiscountAmount: 0,
  };

  const prevMatStock = availableMatStock;
  const newMatStock = Math.max(0, prevMatStock - materialQtyNeeded);
  const updatedMaterial = { ...material, stockQuantity: newMatStock };
  const updatedMaterials = materials.map((m) => (m.id === material.id ? updatedMaterial : m));

  const materialMovement = {
    id: `inv-tx-${Date.now()}-${Math.floor(100 + Math.random() * 899)}`,
    materialId: material.id,
    materialName: material.name,
    materialCode: material.code || 'MAT-01',
    materialType: material.materialType || 'Solid Timber',
    unit: material.unit || 'units',
    transactionType: 'Stock Out',
    quantity: materialQtyNeeded,
    previousStock: prevMatStock,
    newStock: newMatStock,
    purchaseCost: null,
    unitCost: material.unitCost,
    reason: `Custom Order #${ordNum} (${orderRecord.clientName})`,
    dateTime: `${new Date().toISOString().slice(0, 10)} ${new Date().toTimeString().slice(0, 5)}`,
    adminName: `${orderRecord.clientName} (Custom Order Allocation)`,
  };

  return {
    ok: true,
    errorMessage: '',
    order: orderRecord,
    updatedMaterials,
    updatedMaterial,
    materialMovement,
  };
}

export function isOrderCompleted(order) {
  if (!order || typeof order !== 'object') return false;
  const statusStr = String(order.status || '');
  if (/Cancelled/i.test(statusStr) || order.sqlStatus === 'Cancelled') return false;
  if (order.isCompleted === true) return true;
  if (/Completed|Delivered/i.test(statusStr)) return true;
  if (order.sqlStatus === 'Delivered' && !/Dispatched|Transit/i.test(statusStr)) return true;
  return false;
}

export function partitionOrdersByCompletion(orders = []) {
  const safeOrders = Array.isArray(orders) ? orders : [];
  const activeOrders = [];
  const completedOrders = [];

  safeOrders.forEach((ord) => {
    if (!ord || typeof ord !== 'object') return;
    if (isOrderCompleted(ord)) {
      completedOrders.push(ord);
    } else {
      activeOrders.push(ord);
    }
  });

  return {
    activeOrders,
    completedOrders,
  };
}

export function getCompletedTransactionsForCustomer(orders = [], customerEmail = '') {
  const safeOrders = Array.isArray(orders) ? orders : [];
  const cleanEmail = String(customerEmail || '').trim().toLowerCase();
  const matchingCompleted = safeOrders.filter((ord) => {
    if (!ord || typeof ord !== 'object') return false;
    if (cleanEmail && String(ord.clientEmail || '').trim().toLowerCase() !== cleanEmail) {
      return false;
    }
    return isOrderCompleted(ord);
  });

  return matchingCompleted.map((ord, idx) => {
    const totalAmt = Math.max(0, Number(ord.totalAmount || 0));
    const paidAmt = Math.max(totalAmt, Number(ord.depositPaid ?? totalAmt));
    const ordSuffix = String(ord.orderNumber || '').split('-').pop() || String(9941 - idx * 12);
    return {
      txId: ord.transactionReference || `TXN-2026-${ordSuffix}`,
      date: ord.completedAt || ord.lastPaymentAt || ord.orderDate || '2026-10-28',
      orderNumber: ord.orderNumber,
      piece: ord.furnitureName,
      finish: ord.selectedFinish,
      dimensions: ord.customDimensions || 'Standard Dimensions',
      itemType: ord.itemType || 'Standard',
      quantity: Math.max(1, Number(ord.quantity || 1)),
      amount: paidAmt,
      status: 'Completed',
    };
  });
}

export function applyAdminOrderUpdate(orderOrParams, updatesArg = {}) {
  if (!orderOrParams || typeof orderOrParams !== 'object') return null;

  if (Array.isArray(orderOrParams.orders) && orderOrParams.orderNumber) {
    const { orders, orderNumber, updates = {} } = orderOrParams;
    const target = orders.find((o) => o.orderNumber === orderNumber);
    if (!target) return { ok: false, updatedOrders: orders, updatedOrder: null };
    const updatedOrder = applyAdminOrderUpdate(target, updates);
    const updatedOrders = orders.map((o) => (o.orderNumber === orderNumber ? updatedOrder : o));
    return { ok: true, updatedOrders, updatedOrder };
  }

  const next = { ...orderOrParams, ...(updatesArg || {}) };
  const ordSuffix = String(next.orderNumber || '').split('-').pop() || '9001';

  if (updatesArg?.status && !updatesArg?.sqlStatus) {
    if (/Confirmed|Pending|Timber/i.test(updatesArg.status)) {
      next.sqlStatus = 'Pending';
      next.taskStatus = next.taskStatus === 'Done' ? 'InProgress' : next.taskStatus || 'Todo';
      next.isCompleted = false;
    } else if (/Joinery|Finishing|Millwork|Production/i.test(updatesArg.status)) {
      next.sqlStatus = 'InProduction';
      next.taskStatus = 'InProgress';
      next.isCompleted = false;
    } else if (/Quality|Ready/i.test(updatesArg.status)) {
      next.sqlStatus = 'Ready';
      next.taskStatus = 'Done';
      next.isCompleted = false;
    } else if (/Completed|Delivered/i.test(updatesArg.status)) {
      next.sqlStatus = 'Delivered';
      next.taskStatus = 'Done';
      next.deliveryStatus = 'Delivered';
      next.isCompleted = true;
      next.completedAt = next.completedAt || new Date().toISOString().slice(0, 10);
      next.transactionReference = next.transactionReference || `TXN-2026-${ordSuffix}`;
      next.depositPaid = Math.max(Number(next.depositPaid || 0), Number(next.totalAmount || 0));
      next.paymentStatus = 'Paid in Full';
      next.cancellationRequested = false;
    } else if (/Dispatched/i.test(updatesArg.status)) {
      next.sqlStatus = 'Ready';
      next.taskStatus = 'Done';
      if (next.deliveryStatus === 'Scheduled') next.deliveryStatus = 'InTransit';
      next.isCompleted = false;
    } else if (/Cancelled/i.test(updatesArg.status)) {
      next.sqlStatus = 'Cancelled';
      next.isCompleted = false;
    }
  }

  if (updatesArg?.deliveryStatus === 'Delivered' || updatesArg?.isCompleted === true) {
    next.sqlStatus = 'Delivered';
    next.taskStatus = 'Done';
    next.deliveryStatus = 'Delivered';
    if (!/Completed|Delivered/i.test(String(next.status || ''))) {
      next.status = 'Delivered · Completed';
    }
    next.isCompleted = true;
    next.completedAt = next.completedAt || new Date().toISOString().slice(0, 10);
    next.transactionReference = next.transactionReference || `TXN-2026-${ordSuffix}`;
    next.depositPaid = Math.max(Number(next.depositPaid || 0), Number(next.totalAmount || 0));
    next.paymentStatus = 'Paid in Full';
    next.cancellationRequested = false;
  } else if (updatesArg?.deliveryStatus === 'InTransit' && !/Dispatched|Delivered|Completed/i.test(next.status)) {
    next.status = 'Dispatched · White-Glove Transit';
    next.sqlStatus = 'Ready';
    next.isCompleted = false;
  }

  return next;
}

export function requestOrderCancellation({
  orders = [],
  orderNumber = '',
  reason = 'Customer requested order cancellation',
}) {
  const safeOrders = Array.isArray(orders) ? orders : [];
  const cleanOrderNum = String(orderNumber || '').trim();
  const target = safeOrders.find((o) => o.orderNumber === cleanOrderNum);
  if (!target) {
    return {
      ok: false,
      errorMessage: 'Order not found.',
      updatedOrders: safeOrders,
      updatedOrder: null,
    };
  }
  if (/Cancelled/i.test(String(target.status || '')) || target.sqlStatus === 'Cancelled') {
    return {
      ok: false,
      errorMessage: 'Order is already cancelled.',
      updatedOrders: safeOrders,
      updatedOrder: target,
    };
  }
  if (target.cancellationRequested) {
    return {
      ok: false,
      errorMessage: 'A cancellation request for this order is already pending Admin review.',
      updatedOrders: safeOrders,
      updatedOrder: target,
    };
  }

  const updatedOrder = {
    ...target,
    cancellationRequested: true,
    cancellationStatus: 'Pending Admin Approval',
    cancellationRequestedAt: new Date().toISOString().slice(0, 10),
    cancellationReason: String(reason || 'Customer requested order cancellation').trim(),
  };
  const updatedOrders = safeOrders.map((o) =>
    o.orderNumber === cleanOrderNum ? updatedOrder : o
  );

  return {
    ok: true,
    errorMessage: '',
    updatedOrders,
    updatedOrder,
  };
}

export function resolveOrderCancellationRequest({
  orders = [],
  orderNumber = '',
  decision = 'approve',
}) {
  const safeOrders = Array.isArray(orders) ? orders : [];
  const cleanOrderNum = String(orderNumber || '').trim();
  const target = safeOrders.find((o) => o.orderNumber === cleanOrderNum);
  if (!target) {
    return {
      ok: false,
      errorMessage: 'Order not found.',
      decision: '',
      updatedOrders: safeOrders,
      updatedOrder: null,
    };
  }

  const normalizedDecision = String(decision || 'approve').trim().toLowerCase();
  const isApproved = normalizedDecision === 'approve' || normalizedDecision === 'approved';

  const updatedOrder = isApproved
    ? applyAdminOrderUpdate(target, {
        status: 'Cancelled · Approved by Admin',
        sqlStatus: 'Cancelled',
        cancellationRequested: false,
        cancellationStatus: 'Approved',
      })
    : {
        ...target,
        cancellationRequested: false,
        cancellationStatus: 'Declined',
      };

  const updatedOrders = safeOrders.map((o) =>
    o.orderNumber === cleanOrderNum ? updatedOrder : o
  );

  return {
    ok: true,
    errorMessage: '',
    decision: isApproved ? 'approved' : 'declined',
    updatedOrders,
    updatedOrder,
  };
}

export function recordCustomerOrderPayment(orderOrParams, amountToPay = null) {
  if (!orderOrParams || typeof orderOrParams !== 'object') return null;

  if (Array.isArray(orderOrParams.orders) && orderOrParams.orderNumber) {
    const { orders, orderNumber, paymentMethod = 'Card' } = orderOrParams;
    const target = orders.find((o) => o.orderNumber === orderNumber);
    if (!target) return { ok: false, errorMessage: 'Order not found.' };
    const total = Math.max(0, Number(target.totalAmount || 0));
    const currentPaid = Math.max(0, Number(target.depositPaid || 0));
    const amountPaid = Math.max(0, total - currentPaid);
    if (amountPaid <= 0) {
      return { ok: false, errorMessage: 'Order is already paid in full.' };
    }
    const updatedOrder = {
      ...recordCustomerOrderPayment(target, amountPaid),
      paymentMethod,
      lastPaymentAt: new Date().toISOString().slice(0, 10),
    };
    const updatedOrders = orders.map((o) => (o.orderNumber === orderNumber ? updatedOrder : o));
    return { ok: true, updatedOrders, updatedOrder, amountPaid };
  }

  const total = Math.max(0, Number(orderOrParams.totalAmount || 0));
  const currentPaid = Math.max(0, Number(orderOrParams.depositPaid || 0));
  const remaining = Math.max(0, total - currentPaid);
  const payment = amountToPay !== null ? Math.min(remaining, Math.max(0, Number(amountToPay))) : remaining;
  const nextPaid = currentPaid + payment;

  return {
    ...orderOrParams,
    depositPaid: nextPaid,
    paymentStatus:
      nextPaid >= total
        ? 'Paid in Full'
        : `Partial (${Math.round((nextPaid / Math.max(1, total)) * 100)}% Paid)`,
    lastPaymentDate: new Date().toISOString().slice(0, 10),
  };
}

export function validateLoginCredentials(email, password, role) {
  const cleanEmail = String(email || '').trim();
  const cleanPass = String(password || '').trim();
  const cleanRole = role === 'admin' ? 'admin' : 'client';

  if (!cleanEmail || !cleanEmail.includes('@')) {
    return { valid: false, errorMessage: 'Please enter a valid email address.' };
  }
  if (cleanPass.length < 4) {
    return { valid: false, errorMessage: 'Password or access code must be at least 4 characters.' };
  }
  return { valid: true, role: cleanRole, email: cleanEmail, errorMessage: '' };
}

export function validateNewFurnitureInput(input) {
  const name = String(input?.name || '').trim();
  const sku = String(input?.sku || '').trim();
  const price = Number(input?.price);
  const inStockCount = Number(input?.inStockCount ?? 1);
  const productType = String(input?.productType || PRODUCT_TYPES.NORMAL).trim().toLowerCase();
  const image = String(input?.image || '').trim();

  if (name.length < 3) {
    return { valid: false, errorMessage: 'Product Name must be at least 3 characters.' };
  }
  if (sku.length < 4) {
    return { valid: false, errorMessage: 'SKU Code must be at least 4 characters (e.g., LR-DN-210).' };
  }
  if (!Number.isFinite(price) || price < 50) {
    return { valid: false, errorMessage: 'Base Price must be at least $50.' };
  }
  if (!Number.isFinite(inStockCount) || inStockCount < 0) {
    return { valid: false, errorMessage: 'Stock quantity cannot be negative.' };
  }
  if (productType !== PRODUCT_TYPES.NORMAL && productType !== PRODUCT_TYPES.CUSTOMIZED) {
    return {
      valid: false,
      errorMessage: 'Product Type must be either Normal Product or Customized Product (Messenger).',
    };
  }
  if (!image) {
    return {
      valid: false,
      errorMessage: 'Product image is required. Please upload an image file or provide an image URL/preset.',
    };
  }
  return { valid: true, errorMessage: '' };
}

export function validateMaterialInput(input) {
  const name = String(input?.name ?? input?.materialName ?? '').trim();
  const unit = String(input?.unit || '').trim();
  const unitCost = Number(input?.unitCost);
  const stockQuantity = Number(input?.stockQuantity ?? 0);

  if (name.length < 2) {
    return { valid: false, errorMessage: 'Material Name is required (at least 2 characters).' };
  }
  if (!unit) {
    return { valid: false, errorMessage: 'Unit of measurement is required (e.g., bd ft, sq m, slabs, sets).' };
  }
  if (!Number.isFinite(unitCost) || unitCost < 0) {
    return { valid: false, errorMessage: 'Unit Cost must be 0 or greater.' };
  }
  if (!Number.isFinite(stockQuantity) || stockQuantity < 0) {
    return { valid: false, errorMessage: 'Stock Quantity must be 0 or greater.' };
  }
  return { valid: true, errorMessage: '' };
}

export function buildMaterialRecord(input, existingMaterial = null) {
  const stockQuantity = Math.max(0, Number(input?.stockQuantity ?? existingMaterial?.stockQuantity ?? 0));
  const minStockLevel = Math.max(0, Number(input?.minStockLevel ?? existingMaterial?.minStockLevel ?? 10));
  const unitCost = Math.max(0, Number(input?.unitCost ?? existingMaterial?.unitCost ?? 25));

  const rawMatCode = String(
    input?.code ??
      input?.sku ??
      input?.materialId ??
      existingMaterial?.materialId ??
      existingMaterial?.code ??
      existingMaterial?.sku ??
      ''
  )
    .trim()
    .toUpperCase();

  const hasExplicitMatCode = rawMatCode.length >= 4 && !/^\d+$/.test(rawMatCode);
  const resolvedMaterialId = hasExplicitMatCode
    ? rawMatCode
    : generateOwnEntityId('material', input?.name ?? input?.materialName ?? 'TIM').codeId;

  return {
    ...(existingMaterial || {}),
    id: existingMaterial?.id || input?.id || `mat-${resolvedMaterialId.toLowerCase()}`,
    materialId: resolvedMaterialId,
    code: resolvedMaterialId,
    sku: resolvedMaterialId,
    name: String(input?.name ?? input?.materialName ?? existingMaterial?.name ?? 'Raw Material').trim(),
    materialType: String(input?.materialType ?? existingMaterial?.materialType ?? 'Solid Timber').trim(),
    unit: String(input?.unit ?? existingMaterial?.unit ?? 'bd ft').trim(),
    unitCost,
    stockQuantity,
    minStockLevel,
    usedIn: String(
      input?.usedIn ?? existingMaterial?.usedIn ?? 'Workshop joinery, upholstery & finishing'
    ).trim(),
  };
}

function classifyStockLevel(availableStock, minStockLevel) {
  if (availableStock === 0) {
    return {
      status: 'out_of_stock',
      statusKey: 'out_of_stock',
      label: 'Out of Stock',
      statusLabel: 'Out of Stock',
      availableStock,
      minStockLevel,
    };
  }
  if (availableStock <= minStockLevel) {
    return {
      status: 'low_stock',
      statusKey: 'low_stock',
      label: 'Low Stock',
      statusLabel: 'Low Stock',
      availableStock,
      minStockLevel,
    };
  }
  return {
    status: 'in_stock',
    statusKey: 'in_stock',
    label: 'In Stock',
    statusLabel: 'In Stock',
    availableStock,
    minStockLevel,
  };
}

export function getMaterialStockStatus(material) {
  const availableStock = Math.max(0, Number(material?.stockQuantity ?? material?.inStockCount ?? 0));
  const minStockLevel = Math.max(0, Number(material?.minStockLevel ?? 10));
  return classifyStockLevel(availableStock, minStockLevel);
}

export function getProductStockStatus(product) {
  const availableStock = Math.max(
    0,
    Math.floor(Number(product?.inStockCount ?? product?.stockQuantity ?? 0))
  );
  const minStockLevel = Math.max(0, Math.floor(Number(product?.minStockLevel ?? 2)));
  return classifyStockLevel(availableStock, minStockLevel);
}

export function computeMaterialInventoryMetrics(materials = []) {
  const safeMaterials = Array.isArray(materials) ? materials : [];
  let totalStockUnits = 0;
  let totalInventoryValue = 0;
  let inStockCount = 0;
  let lowStockCount = 0;
  let outOfStockCount = 0;
  const inStockItems = [];
  const lowStockItems = [];
  const outOfStockItems = [];

  safeMaterials.forEach((mat) => {
    const info = getMaterialStockStatus(mat);
    totalStockUnits += info.availableStock;
    totalInventoryValue += Math.round(info.availableStock * Number(mat?.unitCost || 0));
    if (info.statusKey === 'out_of_stock') {
      outOfStockCount += 1;
      outOfStockItems.push(mat);
    } else if (info.statusKey === 'low_stock') {
      lowStockCount += 1;
      lowStockItems.push(mat);
    } else {
      inStockCount += 1;
      inStockItems.push(mat);
    }
  });

  return {
    totalMaterials: safeMaterials.length,
    totalProducts: safeMaterials.length,
    totalStockAvailable: totalStockUnits,
    totalStockQuantity: totalStockUnits,
    totalInventoryValue,
    inStockCount,
    lowStockCount,
    outOfStockCount,
    inStockItems,
    lowStockItems,
    outOfStockItems,
  };
}

export function getMaterialCategories(materials = []) {
  const defaultCategories = [
    'Solid Timber',
    'Upholstery Textile',
    'Architectural Stone',
    'Hardware & Joinery',
    'Cord & Finishes',
  ];
  const seen = new Set(defaultCategories);
  const result = [...defaultCategories];
  (Array.isArray(materials) ? materials : []).forEach((mat) => {
    const cat = String(mat?.materialType || '').trim();
    if (cat && !seen.has(cat)) {
      seen.add(cat);
      result.push(cat);
    }
  });
  return result;
}

export function filterInventoryMaterials(materials = [], filters = {}) {
  const safeMaterials = Array.isArray(materials) ? materials : [];
  const query = String(filters?.searchQuery || '').trim().toLowerCase();
  const category = String(filters?.category || 'all').trim();
  const status = String(filters?.status || 'all').trim().toLowerCase();

  return safeMaterials.filter((mat) => {
    const matCategory = String(mat?.materialType || 'Solid Timber').trim();
    if (category && category !== 'all' && matCategory.toLowerCase() !== category.toLowerCase()) {
      return false;
    }

    const st = getMaterialStockStatus(mat);
    if (status && status !== 'all') {
      if (status === 'needs_restock') {
        if (st.statusKey !== 'low_stock' && st.statusKey !== 'out_of_stock') return false;
      } else if (st.statusKey !== status) {
        return false;
      }
    }

    if (query) {
      const hay = [
        mat?.name,
        mat?.code,
        mat?.sku,
        matCategory,
        mat?.unit,
        mat?.usedIn,
      ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();
      if (!hay.includes(query)) return false;
    }

    return true;
  });
}

export function paginateItems(items = [], currentPage = 1, pageSize = 5) {
  const safeItems = Array.isArray(items) ? items : [];
  const safePageSize = Math.max(1, Math.floor(Number(pageSize) || 5));
  const totalItems = safeItems.length;
  const totalPages = Math.max(1, Math.ceil(totalItems / safePageSize));
  const safePage = Math.min(totalPages, Math.max(1, Math.floor(Number(currentPage) || 1)));
  const offset = (safePage - 1) * safePageSize;
  const pagedItems = safeItems.slice(offset, offset + safePageSize);
  const startIndex = totalItems === 0 ? 0 : offset + 1;
  const endIndex = Math.min(totalItems, offset + pagedItems.length);

  return {
    items: pagedItems,
    currentPage: safePage,
    pageSize: safePageSize,
    totalPages,
    totalItems,
    startIndex,
    endIndex,
    hasPrev: safePage > 1,
    hasNext: safePage < totalPages,
  };
}

export function computeCustomerDirectory(orders = [], customers = [], customerProfile = null) {
  const byEmail = new Map();

  const deriveCustomerOwnId = (emailStr, explicitId = null) => {
    const cleanExplicit = String(explicitId ?? '').trim().toUpperCase();
    if (cleanExplicit && cleanExplicit !== 'CUST-PROFILE' && !/^\d+$/.test(cleanExplicit)) {
      return cleanExplicit;
    }
    let hash = 0;
    const clean = String(emailStr || '').trim().toLowerCase();
    for (let i = 0; i < clean.length; i += 1) {
      hash = (hash * 31 + clean.charCodeAt(i)) % 699;
    }
    return `LR-CUS-${300 + Math.abs(hash)}`;
  };

  const upsert = (entry) => {
    const email = String(entry?.email || '').trim().toLowerCase();
    if (!email || email === 'admin@lilliarowena.com' || entry?.role === 'admin') return;
    const resolvedCustId = deriveCustomerOwnId(email, entry?.customerId ?? entry?.id);
    const existing = byEmail.get(email) || {
      id: entry.id && String(entry.id) !== 'cust-profile' ? entry.id : `cust-${resolvedCustId}`,
      customerId: resolvedCustId,
      name: entry.name || entry.fullName || 'Client',
      email: entry.email.trim(),
      phone: entry.phone || entry.phoneNumber || '+1 (212) 555-0140',
      address: entry.address || 'Showroom Registered Client',
      company: entry.company || 'Private Residence',
      avatar: String(entry.avatar || '').trim(),
      orderCount: 0,
      totalSpent: 0,
      lastOrderDate: '—',
    };
    byEmail.set(email, {
      ...existing,
      customerId: existing.customerId || resolvedCustId,
      name: entry.name || entry.fullName || existing.name,
      phone: entry.phone || entry.phoneNumber || existing.phone,
      address: entry.address || existing.address,
      company: entry.company || existing.company,
      avatar: String(entry.avatar ?? existing.avatar ?? '').trim(),
    });
  };

  if (
    customerProfile &&
    customerProfile.email &&
    String(customerProfile.email).trim().toLowerCase() !== 'admin@lilliarowena.com' &&
    customerProfile.role !== 'admin'
  ) {
    upsert({
      id: customerProfile.customerId || customerProfile.id || null,
      name: customerProfile.name,
      email: customerProfile.email,
      phone: customerProfile.phone,
      address: [customerProfile.address, customerProfile.city].filter(Boolean).join(', '),
      company: 'Vance & Associates Spatial Design',
      avatar: customerProfile.avatar || '',
    });
  }

  (Array.isArray(customers) ? customers : []).forEach((c) => upsert(c));

  (Array.isArray(orders) ? orders : []).forEach((ord) => {
    const email = String(ord?.clientEmail || '').trim().toLowerCase();
    if (!email || email === 'admin@lilliarowena.com') return;
    if (!byEmail.has(email)) {
      const custOwnId = deriveCustomerOwnId(email);
      upsert({
        id: `cust-${custOwnId}`,
        customerId: custOwnId,
        name: ord.clientName || 'Showroom Client',
        email: ord.clientEmail,
        phone: '+1 (212) 555-0192',
        address: ord.deliveryAddress || 'Private Residence',
        company: ord.clientCompany || 'Private Residence',
      });
    }
    const rec = byEmail.get(email);
    if (!rec) return;
    rec.orderCount += 1;
    rec.totalSpent += Math.max(0, Number(ord.totalAmount || 0));
    if (ord.orderDate && (rec.lastOrderDate === '—' || String(ord.orderDate) > String(rec.lastOrderDate))) {
      rec.lastOrderDate = ord.orderDate;
    }
    if (ord.deliveryAddress && (!rec.address || rec.address === 'Showroom Registered Client')) {
      rec.address = ord.deliveryAddress;
    }
  });

  return Array.from(byEmail.values());
}

export function computeInventoryMetrics(items = []) {
  const safeItems = Array.isArray(items) ? items : [];
  if (safeItems.some((i) => i && Object.prototype.hasOwnProperty.call(i, 'stockQuantity'))) {
    return computeMaterialInventoryMetrics(safeItems);
  }

  let totalStockAvailable = 0;
  let inStockCount = 0;
  let lowStockCount = 0;
  let outOfStockCount = 0;
  const lowStockItems = [];
  const outOfStockItems = [];

  safeItems.forEach((p) => {
    const statusInfo = getProductStockStatus(p);
    totalStockAvailable += statusInfo.availableStock;
    if (statusInfo.statusKey === 'out_of_stock') {
      outOfStockCount += 1;
      outOfStockItems.push(p);
    } else if (statusInfo.statusKey === 'low_stock') {
      lowStockCount += 1;
      lowStockItems.push(p);
    } else {
      inStockCount += 1;
    }
  });

  return {
    totalProducts: safeItems.length,
    totalMaterials: safeItems.length,
    totalStockAvailable,
    inStockCount,
    lowStockCount,
    outOfStockCount,
    lowStockItems,
    outOfStockItems,
  };
}

export function applyMaterialStockMovement({
  materials = [],
  movementInput = null,
  adminName = 'Lillia Mercier (Business Owner)',
  materialId: directMaterialId,
  transactionType: directTransactionType,
  quantity: directQuantity,
  purchaseCost: directPurchaseCost,
  reason: directReason,
  date: directDate,
}) {
  const payload =
    movementInput && typeof movementInput === 'object'
      ? movementInput
      : {
          materialId: directMaterialId,
          transactionType: directTransactionType,
          quantity: directQuantity,
          purchaseCost: directPurchaseCost,
          reason: directReason,
          date: directDate,
        };

  const materialId = String(payload?.materialId || payload?.productId || '').trim();
  const transactionType =
    String(payload?.transactionType || 'Stock In').trim() === 'Stock Out' ? 'Stock Out' : 'Stock In';
  const rawQty = Number(payload?.quantity);
  const quantity = Number.isFinite(rawQty) ? Math.floor(rawQty) : 0;

  if (!materialId) {
    return { ok: false, errorMessage: 'Please select a workshop material.' };
  }
  if (quantity <= 0) {
    return { ok: false, errorMessage: 'Quantity must be a positive number greater than 0.' };
  }

  const targetMaterial = Array.isArray(materials) ? materials.find((m) => m.id === materialId) : null;
  if (!targetMaterial) {
    return { ok: false, errorMessage: 'Selected material was not found in workshop inventory.' };
  }

  const previousStock = Math.max(0, Number(targetMaterial.stockQuantity ?? 0));
  if (transactionType === 'Stock Out' && quantity > previousStock) {
    return {
      ok: false,
      errorMessage: `Cannot deduct ${quantity} ${targetMaterial.unit || 'units'}: "${targetMaterial.name}" only has ${previousStock} ${targetMaterial.unit || 'units'} available in stock.`,
    };
  }

  let purchaseCost = null;
  let reason = String(payload?.reason || '').trim();

  if (transactionType === 'Stock In') {
    if (payload?.purchaseCost !== undefined && payload?.purchaseCost !== '' && payload?.purchaseCost !== null) {
      const parsedCost = Number(payload.purchaseCost);
      if (!Number.isFinite(parsedCost) || parsedCost < 0) {
        return { ok: false, errorMessage: 'Purchase cost must be 0 or a positive amount.' };
      }
      purchaseCost = Math.round(parsedCost * 100) / 100;
    } else {
      purchaseCost = Math.round(quantity * Number(targetMaterial.unitCost || 0) * 100) / 100;
    }
    if (!reason) reason = 'Workshop Raw Material Restock';
  } else if (!reason) {
    return {
      ok: false,
      errorMessage: 'Please specify a reason for Material Stock Out (e.g., Workshop Production, Damaged, Adjustment).',
    };
  }

  const newStock = transactionType === 'Stock In' ? previousStock + quantity : previousStock - quantity;
  const updatedMaterial = { ...targetMaterial, stockQuantity: newStock };
  const updatedMaterials = materials.map((m) => (m.id === materialId ? updatedMaterial : m));

  const nowIso = new Date();
  const datePart = String(payload?.date || nowIso.toISOString().slice(0, 10)).trim();
  const timePart = nowIso.toTimeString().slice(0, 5);

  const transaction = {
    id: `inv-tx-${Date.now()}-${Math.floor(100 + Math.random() * 899)}`,
    materialId: targetMaterial.id,
    materialName: targetMaterial.name,
    materialCode: targetMaterial.code || 'MAT-01',
    materialType: targetMaterial.materialType || 'Solid Timber',
    unit: targetMaterial.unit || 'units',
    transactionType,
    quantity,
    previousStock,
    newStock,
    purchaseCost,
    unitCost: targetMaterial.unitCost,
    reason,
    dateTime: `${datePart} ${timePart}`,
    adminName: String(adminName || 'Lillia Mercier (Business Owner)').trim(),
  };

  return {
    ok: true,
    errorMessage: '',
    updatedMaterial,
    updatedMaterials,
    updatedProduct: updatedMaterial,
    updatedCatalog: updatedMaterials,
    transaction,
  };
}

export function applyStockMovement(params = {}) {
  if (Array.isArray(params.materials) && params.materials.length > 0) {
    return applyMaterialStockMovement(params);
  }

  const {
    catalog = [],
    movementInput = null,
    adminName = 'Lillia Mercier (Business Owner)',
    productId: directProductId,
    transactionType: directTransactionType,
    quantity: directQuantity,
    purchaseCost: directPurchaseCost,
    reason: directReason,
    date: directDate,
  } = params;

  const payload =
    movementInput && typeof movementInput === 'object'
      ? movementInput
      : {
          productId: directProductId,
          transactionType: directTransactionType,
          quantity: directQuantity,
          purchaseCost: directPurchaseCost,
          reason: directReason,
          date: directDate,
        };

  const productId = String(payload?.productId || payload?.materialId || '').trim();
  const transactionType =
    String(payload?.transactionType || 'Stock In').trim() === 'Stock Out' ? 'Stock Out' : 'Stock In';
  const rawQty = Number(payload?.quantity);
  const quantity = Number.isFinite(rawQty) ? Math.floor(rawQty) : 0;

  if (!productId) {
    return { ok: false, errorMessage: 'Please select a valid item.' };
  }
  if (quantity <= 0) {
    return { ok: false, errorMessage: 'Quantity must be a positive whole number greater than 0.' };
  }

  const targetProduct = Array.isArray(catalog) ? catalog.find((p) => p.id === productId) : null;
  if (!targetProduct) {
    return { ok: false, errorMessage: 'Selected item was not found in inventory.' };
  }

  if (Object.prototype.hasOwnProperty.call(targetProduct, 'stockQuantity')) {
    return applyMaterialStockMovement({ ...params, materials: catalog });
  }

  const previousStock = Math.max(0, Math.floor(Number(targetProduct.inStockCount ?? 0)));
  if (transactionType === 'Stock Out' && quantity > previousStock) {
    return {
      ok: false,
      errorMessage: `Cannot deduct ${quantity} units: "${targetProduct.name}" only has ${previousStock} available in stock.`,
    };
  }

  let purchaseCost = null;
  let reason = String(payload?.reason || '').trim();

  if (transactionType === 'Stock In') {
    if (payload?.purchaseCost !== undefined && payload?.purchaseCost !== '' && payload?.purchaseCost !== null) {
      const parsedCost = Number(payload.purchaseCost);
      if (!Number.isFinite(parsedCost) || parsedCost < 0) {
        return { ok: false, errorMessage: 'Purchase cost must be 0 or a positive amount.' };
      }
      purchaseCost = Math.round(parsedCost * 100) / 100;
    }
    if (!reason) reason = 'Inventory Restock';
  } else if (!reason) {
    return {
      ok: false,
      errorMessage: 'Please select or enter a reason for Stock Out (e.g., Damaged, Adjustment).',
    };
  }

  const newStock = transactionType === 'Stock In' ? previousStock + quantity : previousStock - quantity;
  const updatedProduct = { ...targetProduct, inStockCount: newStock };
  const updatedCatalog = catalog.map((p) => (p.id === productId ? updatedProduct : p));

  const nowIso = new Date();
  const datePart = String(payload?.date || nowIso.toISOString().slice(0, 10)).trim();
  const timePart = nowIso.toTimeString().slice(0, 5);

  const transaction = {
    id: `inv-tx-${Date.now()}-${Math.floor(100 + Math.random() * 899)}`,
    productId: targetProduct.id,
    productName: targetProduct.name,
    productSku: targetProduct.sku,
    transactionType,
    quantity,
    previousStock,
    newStock,
    purchaseCost,
    reason,
    dateTime: `${datePart} ${timePart}`,
    adminName: String(adminName || 'Lillia Mercier (Business Owner)').trim(),
  };

  return {
    ok: true,
    errorMessage: '',
    updatedProduct,
    updatedCatalog,
    transaction,
  };
}

export function removeProductFromCatalog({
  catalog = [],
  cartItems = [],
  vouchers = [],
  productId = '',
}) {
  const cleanId = String(productId || '').trim();
  const safeCatalog = Array.isArray(catalog) ? catalog : [];
  const safeCart = Array.isArray(cartItems) ? cartItems : [];
  const safeVouchers = Array.isArray(vouchers) ? vouchers : [];

  if (!cleanId) {
    return {
      ok: false,
      errorMessage: 'Please select a valid product to remove.',
      removedProduct: null,
      updatedCatalog: safeCatalog,
      updatedCartItems: safeCart,
      updatedVouchers: safeVouchers,
    };
  }

  const removedProduct = safeCatalog.find((p) => p.id === cleanId) || null;
  if (!removedProduct) {
    return {
      ok: false,
      errorMessage: 'Product not found in catalog.',
      removedProduct: null,
      updatedCatalog: safeCatalog,
      updatedCartItems: safeCart,
      updatedVouchers: safeVouchers,
    };
  }

  const updatedCatalog = safeCatalog.filter((p) => p.id !== cleanId);
  const updatedCartItems = safeCart.filter((entry) => {
    const entryPid = entry?.product?.id || entry?.productId || entry?.furnitureId || entry?.id || '';
    return entryPid !== cleanId;
  });
  const updatedVouchers = safeVouchers.map((v) => {
    const vPid = v?.furnitureId || v?.productId || '';
    return vPid === cleanId && v.status === 'Active' ? { ...v, status: 'Cancelled' } : v;
  });

  return {
    ok: true,
    errorMessage: '',
    removedProduct,
    updatedCatalog,
    updatedCartItems,
    updatedVouchers,
  };
}

export function removeMaterialFromInventory({ materials = [], materialId = '' }) {
  const cleanId = String(materialId || '').trim();
  const safeMaterials = Array.isArray(materials) ? materials : [];
  const removedMaterial = safeMaterials.find((m) => m.id === cleanId) || null;
  if (!cleanId || !removedMaterial) {
    return {
      ok: false,
      errorMessage: 'Material not found in inventory.',
      removedMaterial: null,
      updatedMaterials: safeMaterials,
    };
  }

  return {
    ok: true,
    errorMessage: '',
    removedMaterial,
    updatedMaterials: safeMaterials.filter((m) => m.id !== cleanId),
  };
}

export function describeMaterialStockPreview(material, mode = 'Stock In', rawQty = 0) {
  if (!material) {
    return {
      currentStock: 0,
      qty: 0,
      projectedStock: 0,
      insufficientStock: false,
      unit: 'units',
      minStockLevel: 10,
      currentStatusKey: 'out_of_stock',
      currentFriendlyLabel: 'Out of stock',
      projectedStatusKey: 'out_of_stock',
      projectedFriendlyLabel: 'Out of stock',
      fillPercent: 0,
      totalValue: 0,
    };
  }

  const currentStock = Math.max(
    0,
    Math.round(Number(material.stockQuantity ?? material.inStockCount ?? 0) * 100) / 100
  );
  const minStockLevel = Math.max(0, Math.floor(Number(material.minStockLevel ?? 10)));
  const unitCost = Math.max(0, Number(material.unitCost ?? 0));
  const unit = String(material.unit || 'units').trim() || 'units';
  const qty = Math.max(0, Math.round(Number(rawQty || 0) * 100) / 100);
  const isStockOut = mode === 'Stock Out';

  const insufficientStock = isStockOut && qty > currentStock;
  const projectedStock = isStockOut
    ? Math.max(0, Math.round((currentStock - qty) * 100) / 100)
    : Math.round((currentStock + qty) * 100) / 100;

  const toFriendlyStatus = (stock) => {
    if (stock <= 0) return { key: 'out_of_stock', label: 'Out of stock — needs restock' };
    if (stock <= minStockLevel) return { key: 'low_stock', label: 'Running low — restock soon' };
    return { key: 'in_stock', label: 'Plenty in stock' };
  };

  const currSt = toFriendlyStatus(currentStock);
  const projSt = toFriendlyStatus(projectedStock);
  const healthyTarget = Math.max(minStockLevel * 3, 20);
  const fillPercent =
    currentStock <= 0
      ? 0
      : Math.min(100, Math.max(8, Math.round((currentStock / healthyTarget) * 100)));

  return {
    currentStock,
    qty,
    projectedStock,
    insufficientStock,
    unit,
    minStockLevel,
    currentStatusKey: currSt.key,
    currentFriendlyLabel: currSt.label,
    projectedStatusKey: projSt.key,
    projectedFriendlyLabel: projSt.label,
    fillPercent,
    totalValue: Math.round(currentStock * unitCost * 100) / 100,
  };
}

export function getRecommendedBestSellers(items, maxItems = 4) {
  const list = Array.isArray(items) ? items : [];
  const cap = Math.max(1, Math.min(4, Number(maxItems || 4)));
  const bestSellers = list.filter((item) => Boolean(item.isBestSeller));
  return (bestSellers.length >= 3 ? bestSellers : list).slice(0, cap);
}

export const CATALOG_COLOR_FILTERS = [
  { key: 'walnut', label: 'dark walnut', hex: '#5C3A21', keywords: ['walnut'] },
  { key: 'white-oak', label: 'white oak', hex: '#CBB28D', keywords: ['white oak', 'natural oak'] },
  { key: 'smoked-oak', label: 'smoked oak', hex: '#9C8265', keywords: ['smoked', 'fumed', 'chestnut'] },
  { key: 'ebonized', label: 'ebonized black', hex: '#222428', keywords: ['ebonized', 'black'] },
  { key: 'boucle', label: 'warm oat bouclé', hex: '#E6DEC8', keywords: ['bouclé', 'boucle'] },
  { key: 'linen', label: 'beige linen', hex: '#DDD3C2', keywords: ['linen'] },
  { key: 'ash', label: 'natural ash', hex: '#D9C7AE', keywords: ['ash'] },
  { key: 'travertine', label: 'travertine stone', hex: '#D8CABB', keywords: ['travertine', 'limestone'] },
  { key: 'cognac', label: 'cognac leather', hex: '#8A4B29', keywords: ['cognac', 'leather'] },
  { key: 'indigo', label: 'midnight indigo', hex: '#243B55', keywords: ['indigo', 'mohair'] },
];

export function findMatchingColorVariant(item, colorKey) {
  if (!colorKey || colorKey === 'all') return null;
  const filterDef = CATALOG_COLOR_FILTERS.find((c) => c.key === colorKey);
  const keywords = filterDef ? filterDef.keywords : [String(colorKey).toLowerCase()];
  const variants = Array.isArray(item?.colorVariants) ? item.colorVariants : [];

  for (const variant of variants) {
    const nameLower = String(variant.name || '').toLowerCase();
    if (keywords.some((kw) => nameLower.includes(kw))) {
      return variant;
    }
  }
  return null;
}

export function filterCatalogPageItems(items, options = {}) {
  const { categoryId = 'all', colorKey = 'all', maxPrice = Infinity, searchQuery = '' } = options;
  const normalizedQuery = String(searchQuery || '').trim().toLowerCase();
  const numericMaxPrice = Number.isFinite(Number(maxPrice)) ? Number(maxPrice) : Infinity;

  return (items || []).filter((item) => {
    if (categoryId && categoryId !== 'all' && item.categoryId !== categoryId) return false;

    let effectiveCheckPrice = Number(item.effectivePrice ?? item.price ?? 0);
    if (colorKey && colorKey !== 'all') {
      const matchedVariant = findMatchingColorVariant(item, colorKey);
      if (!matchedVariant) return false;
      effectiveCheckPrice = computeVariantPricing(item, matchedVariant.name).effectivePrice;
    }

    if (effectiveCheckPrice > numericMaxPrice) return false;

    if (normalizedQuery) {
      const searchableText = [
        item.name,
        item.sku,
        item.categoryName,
        item.description,
        item.defaultFinish,
        ...(item.finishes || []),
      ]
        .join(' ')
        .toLowerCase();
      if (!searchableText.includes(normalizedQuery)) return false;
    }

    return true;
  });
}

export function getColorFilterCounts(items, categoryId = 'all', maxPrice = Infinity) {
  return CATALOG_COLOR_FILTERS.map((colorDef) => ({
    ...colorDef,
    count: filterCatalogPageItems(items, {
      categoryId,
      colorKey: colorDef.key,
      maxPrice,
    }).length,
  }));
}

export function formatCustomerProductDisplay(item, selectedFinishName) {
  const selectedFinish =
    selectedFinishName || item?.colorVariants?.[0]?.name || item?.defaultFinish || 'Natural Finish';
  const pricing = computeVariantPricing(item, selectedFinish);
  const stockInfo = getProductStockStatus(item);
  const isOutOfStock = stockInfo.statusKey === 'out_of_stock';
  const isLowStock = stockInfo.statusKey === 'low_stock';
  const leadTimeWeeks = Math.max(1, Number(item?.leadTimeWeeks || 3));

  const availabilityLabel = isOutOfStock
    ? 'Out of Stock'
    : isLowStock
    ? `Low Stock · ${stockInfo.availableStock} left`
    : 'In Stock';

  const availabilityDetail = isOutOfStock
    ? 'Currently unavailable'
    : `${stockInfo.availableStock} available · Ships in ${leadTimeWeeks} ${leadTimeWeeks === 1 ? 'week' : 'weeks'}`;

  return {
    name: String(item?.name || 'Handcrafted Furniture Piece').trim(),
    categoryName: String(item?.categoryName || 'Showroom Collection').trim(),
    image: String(item?.image || 'assets/images/category_living_lounge.jpg').trim(),
    effectivePrice: pricing.effectivePrice,
    variantBasePrice: pricing.variantBasePrice,
    discountPercent: Math.max(0, Number(item?.discountPercent || 0)),
    hasDiscount: Number(item?.discountPercent || 0) > 0,
    selectedFinish: pricing.colorName,
    selectedColorHex: pricing.colorHex,
    priceDelta: pricing.priceDelta,
    colorVariants: (item?.colorVariants || []).map((v) => ({
      name: v.name,
      shortLabel: String(v.name || '').split('/')[0].trim(),
      hex: v.hex || '#8B5A2B',
      priceDelta: Number(v.priceDelta || 0),
    })),
    description: String(item?.customizationNotes || item?.description || '').trim(),
    craftsmanshipNote: String(item?.craftsmanshipNote || '').trim(),
    dimensions: String(item?.dimensions || '').trim(),
    availabilityStatus: stockInfo.statusKey,
    availabilityLabel,
    availabilityDetail,
    availableStock: stockInfo.availableStock,
    isOutOfStock,
    isLowStock,
    leadTimeWeeks,
  };
}

export function formatSimplifiedProductCardData(item, selectedFinishName) {
  const display = formatCustomerProductDisplay(item, selectedFinishName);
  return {
    id: String(item?.id || '').trim(),
    image: display.image,
    name: display.name,
    categoryName: display.categoryName,
    originalPrice: display.hasDiscount ? display.variantBasePrice : null,
    sellingPrice: display.effectivePrice,
    discountPercent: display.discountPercent,
    hasDiscount: display.hasDiscount,
    description: display.description,
    availabilityLabel: display.availabilityLabel,
    availableStock: display.availableStock,
    isOutOfStock: display.isOutOfStock,
    isLowStock: display.isLowStock,
    dimensions: display.dimensions,
    leadTimeWeeks: display.leadTimeWeeks,
    shippingTimeLabel: `Ships in ${display.leadTimeWeeks} ${display.leadTimeWeeks === 1 ? 'week' : 'weeks'}`,
    ctaLabel: 'View Product',
  };
}

export function resolveAuthNavigationState(rawSessionUser) {
  let sessionObj = rawSessionUser;
  if (typeof rawSessionUser === 'string') {
    try {
      sessionObj = JSON.parse(rawSessionUser);
    } catch (_e) {
      sessionObj = null;
    }
  }

  const email = String(sessionObj?.email || '').trim();
  const rawRole = String(sessionObj?.role || '').trim().toLowerCase();
  const isValidSession = Boolean(email && (rawRole === 'client' || rawRole === 'customer' || rawRole === 'admin'));

  if (!isValidSession) {
    return {
      isAuthenticated: false,
      isCustomer: false,
      isAdmin: false,
      role: 'guest',
      showSignIn: true,
      showRegister: true,
      showCustomerAccountNav: false,
      showAdminNav: false,
      showLoginPrompts: true,
      userDisplayName: '',
      userInitials: '',
      userEmail: '',
      userAvatar: '',
      accountLinks: {
        signIn: { href: 'login.html', label: 'Sign In' },
        register: { href: 'login.html?mode=register', label: 'Register' },
        myAccount: null,
        myOrders: null,
        myCart: null,
        myVouchers: null,
        adminDashboard: null,
      },
      heroOrderLink: { href: 'login.html', label: 'Track My Order →' },
      cartCheckoutCta: { href: 'login.html', label: 'Sign In to Checkout →' },
      cartVoucherHint: 'Sign in to verify your email',
    };
  }

  const isAdmin = rawRole === 'admin';
  const fallbackName = isAdmin
    ? 'Lillia Mercier'
    : email.toLowerCase() === 'rowena.vance@vancearchitects.com'
    ? 'Rowena Vance'
    : email.split('@')[0] || 'Customer';
  const userDisplayName = String(sessionObj?.name || fallbackName).trim();
  const userInitials =
    userDisplayName
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => (part[0] || '').toUpperCase())
      .join('') || (isAdmin ? 'AD' : 'CU');
  const userAvatar = String(sessionObj?.avatar || '').trim();

  if (isAdmin) {
    return {
      isAuthenticated: true,
      isCustomer: false,
      isAdmin: true,
      role: 'admin',
      showSignIn: false,
      showRegister: false,
      showCustomerAccountNav: false,
      showAdminNav: true,
      showLoginPrompts: false,
      userDisplayName,
      userInitials,
      userEmail: email,
      userAvatar,
      accountLinks: {
        signIn: null,
        register: null,
        myAccount: null,
        myOrders: null,
        myCart: null,
        myVouchers: null,
        adminDashboard: { href: 'dashboard.html?role=admin', label: 'Admin Dashboard' },
      },
      heroOrderLink: { href: 'dashboard.html?role=admin', label: 'Admin Dashboard →' },
      cartCheckoutCta: { href: 'dashboard.html?role=admin', label: 'Open Admin Dashboard →' },
      cartVoucherHint: email,
    };
  }

  return {
    isAuthenticated: true,
    isCustomer: true,
    isAdmin: false,
    role: 'client',
    showSignIn: false,
    showRegister: false,
    showCustomerAccountNav: true,
    showAdminNav: false,
    showLoginPrompts: false,
    userDisplayName,
    userInitials,
    userEmail: email,
    userAvatar,
    accountLinks: {
      signIn: null,
      register: null,
      myAccount: { href: 'dashboard.html?role=client&tab=home', label: 'My Account' },
      myOrders: { href: 'dashboard.html?role=client&tab=orders', label: 'My Orders' },
      myCart: { href: 'dashboard.html?role=client&tab=cart', label: 'My Cart' },
      myVouchers: { href: 'dashboard.html?role=client&tab=vouchers', label: 'My Vouchers' },
      adminDashboard: null,
    },
    heroOrderLink: { href: 'dashboard.html?role=client&tab=orders', label: 'My Orders →' },
    cartCheckoutCta: { href: 'dashboard.html?role=client&tab=cart', label: 'Proceed to Checkout →' },
    cartVoucherHint: email,
  };
}

export function resolveCustomerAvatarState(profile = {}) {
  const displayName = String(profile?.name || profile?.fullName || 'Client').trim() || 'Client';
  const initials =
    displayName
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => (part[0] || '').toUpperCase())
      .join('') || 'CU';
  const rawAvatar = String(profile?.avatar || '').trim();
  if (!rawAvatar) {
    return {
      hasAvatar: false,
      avatarSrc: '',
      initials,
      displayName,
    };
  }
  const normalizedSrc =
    rawAvatar.startsWith('data:') ||
    rawAvatar.startsWith('http://') ||
    rawAvatar.startsWith('https://')
      ? rawAvatar
      : rawAvatar.replace(/^\//, '');
  return {
    hasAvatar: Boolean(normalizedSrc),
    avatarSrc: normalizedSrc,
    initials,
    displayName,
  };
}

export function validateAndBuildOfferProposal(offerInput = {}, product = null, sessionUser = null) {
  if (!product || typeof product !== 'object' || !product.id || !product.name) {
    return {
      ok: false,
      errorMessage: 'Please select a valid product before submitting an offer.',
      proposal: null,
    };
  }

  const rawProposed = offerInput?.proposedPrice;
  const numericProposed = Number(rawProposed);
  if (
    rawProposed === undefined ||
    rawProposed === null ||
    String(rawProposed).trim() === '' ||
    !Number.isFinite(numericProposed) ||
    numericProposed <= 0
  ) {
    return {
      ok: false,
      errorMessage: 'Please enter a valid proposed price greater than $0.',
      proposal: null,
    };
  }

  if (numericProposed < 25) {
    return {
      ok: false,
      errorMessage: 'Proposed price must be at least $25.',
      proposal: null,
    };
  }

  const selectedFinish = String(
    offerInput?.selectedFinish ||
      product.colorVariants?.[0]?.name ||
      product.defaultFinish ||
      'Natural Finish'
  ).trim();
  const pricing = computeVariantPricing(product, selectedFinish);
  const originalPrice =
    Number(offerInput?.originalPrice) > 0
      ? Math.round(Number(offerInput.originalPrice))
      : pricing.effectivePrice;
  const proposedPrice = Math.round(numericProposed);
  const customizations =
    String(offerInput?.customizations || '').trim() || 'Standard showroom dimensions & finish';
  const additionalNotes = String(offerInput?.additionalNotes || '').trim();
  const referenceImageDataUrl = offerInput?.referenceImageDataUrl
    ? String(offerInput.referenceImageDataUrl).trim()
    : null;
  const referenceImageName = offerInput?.referenceImageName
    ? String(offerInput.referenceImageName).trim()
    : null;

  const customerName = String(
    sessionUser?.name || offerInput?.customerName || 'Showroom Customer'
  ).trim();
  const customerEmail = String(sessionUser?.email || offerInput?.customerEmail || '').trim();
  const createdDate = String(offerInput?.createdDate || new Date().toISOString().slice(0, 10));
  const slug = String(product.name)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');

  return {
    ok: true,
    errorMessage: null,
    proposal: {
      id: `offer-${Date.now()}`,
      proposalCode: `OFFER-${Math.floor(1000 + Math.random() * 8999)}`,
      productId: String(product.id),
      productName: String(product.name).trim(),
      categoryName: String(product.categoryName || 'Showroom Collection').trim(),
      productImage: String(product.image || 'assets/images/category_living_lounge.jpg').trim(),
      dimensions: String(product.dimensions || 'Standard Showroom Dimensions').trim(),
      selectedFinish,
      originalPrice,
      proposedPrice,
      requestedSavings: Math.max(0, originalPrice - proposedPrice),
      customizations,
      additionalNotes,
      referenceImageDataUrl,
      referenceImageName,
      customerName,
      customerEmail,
      createdDate,
      downloadFilename: `offer-proposal-${slug || 'furniture'}.png`,
      status: 'Pending Owner Review on Messenger',
    },
  };
}

export function buildRequestDesignMessengerUrl(
  rawSessionUser,
  baseMessengerUrl = ADMIN_MESSENGER_PLACEHOLDER_URL
) {
  const authState = resolveAuthNavigationState(rawSessionUser);
  if (!authState.isAuthenticated) {
    return {
      requiresLogin: true,
      redirectUrl: 'login.html?redirect=request-design',
      messengerUrl: baseMessengerUrl,
      messageText: '',
    };
  }

  const customerLabel = authState.userDisplayName
    ? `${authState.userDisplayName} (${authState.userEmail})`
    : authState.userEmail;
  const messageText = `Hi Lillia and Rowena Furniture! I am ${customerLabel} and I would like to request a custom furniture design.`;
  const separator = baseMessengerUrl.includes('?') ? '&' : '?';
  const messengerUrl = `${baseMessengerUrl}${separator}ref=custom-design-request&text=${encodeURIComponent(
    messageText
  )}`;

  return {
    requiresLogin: false,
    redirectUrl: messengerUrl,
    messengerUrl,
    messageText,
  };
}

export function getManilaDateInputDefaults(nowMs = Date.now()) {
  const dateObj = new Date(nowMs);
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(dateObj);

  const map = {};
  for (const p of parts) {
    map[p.type] = p.value;
  }
  const currentDate = `${map.year}-${map.month}-${map.day}`;
  const currentHour = String(map.hour === '24' ? '00' : map.hour).padStart(2, '0');
  const currentMinute = String(map.minute).padStart(2, '0');
  const currentTime = `${currentHour}:${currentMinute}`;

  // Default custom date/time: 24 hours from now in Asia/Manila
  const tomorrowObj = new Date(nowMs + 24 * 3600 * 1000);
  const tParts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(tomorrowObj);
  const tMap = {};
  for (const p of tParts) {
    tMap[p.type] = p.value;
  }
  const defaultFutureDate = `${tMap.year}-${tMap.month}-${tMap.day}`;
  const defaultFutureHour = String(tMap.hour === '24' ? '00' : tMap.hour).padStart(2, '0');
  const defaultFutureTime = `${defaultFutureHour}:${String(tMap.minute).padStart(2, '0')}`;

  return {
    minDate: currentDate,
    minTime: currentTime,
    defaultDate: defaultFutureDate,
    defaultTime: defaultFutureTime,
  };
}

export function calculateExpirationFromInput(input = {}, issuedAtMs = Date.now()) {
  const method = String(input?.expirationMethod || 'duration').trim().toLowerCase();

  if (method !== 'custom' && method !== 'duration') {
    return {
      ok: false,
      errorMessage: 'Select a valid expiration method (Custom Date & Time or Set Duration).',
      expiresAtIso: null,
      expiryDateStr: null,
      expiresAtMs: null,
      formattedManila: '—',
      remainingLabel: '—',
    };
  }

  let expiresAtMs = null;

  if (method === 'custom') {
    const expiryDate = String(input?.expiryDate || '').trim();
    const expiryTime = String(input?.expiryTime || '').trim();

    if (!expiryDate) {
      return {
        ok: false,
        errorMessage: 'Select an expiration date.',
        expiresAtIso: null,
        expiryDateStr: null,
        expiresAtMs: null,
        formattedManila: '—',
        remainingLabel: '—',
      };
    }

    if (!expiryTime) {
      return {
        ok: false,
        errorMessage: 'Select an expiration time.',
        expiresAtIso: null,
        expiryDateStr: null,
        expiresAtMs: null,
        formattedManila: '—',
        remainingLabel: '—',
      };
    }

    if (!/^\d{4}-\d{2}-\d{2}$/.test(expiryDate) || !/^\d{2}:\d{2}(:\d{2})?$/.test(expiryTime)) {
      return {
        ok: false,
        errorMessage: 'Enter a valid expiration date and time.',
        expiresAtIso: null,
        expiryDateStr: null,
        expiresAtMs: null,
        formattedManila: '—',
        remainingLabel: '—',
      };
    }

    const timeWithSec = expiryTime.length === 5 ? `${expiryTime}:00` : expiryTime;
    const manilaIso = `${expiryDate}T${timeWithSec}+08:00`;
    const parsedMs = new Date(manilaIso).getTime();

    if (Number.isNaN(parsedMs)) {
      return {
        ok: false,
        errorMessage: 'Invalid expiration date or time.',
        expiresAtIso: null,
        expiryDateStr: null,
        expiresAtMs: null,
        formattedManila: '—',
        remainingLabel: '—',
      };
    }

    if (parsedMs <= issuedAtMs) {
      return {
        ok: false,
        errorMessage: 'Expiration date and time cannot be in the past. Please choose a future time (Asia/Manila).',
        expiresAtIso: null,
        expiryDateStr: null,
        expiresAtMs: null,
        formattedManila: '—',
        remainingLabel: '—',
      };
    }

    expiresAtMs = parsedMs;
  } else {
    const durationValue = Number(input?.durationValue);
    const durationUnit = String(input?.durationUnit || 'hours').trim().toLowerCase();

    if (!Number.isFinite(durationValue) || durationValue <= 0) {
      return {
        ok: false,
        errorMessage: 'Duration must be greater than 0.',
        expiresAtIso: null,
        expiryDateStr: null,
        expiresAtMs: null,
        formattedManila: '—',
        remainingLabel: '—',
      };
    }

    const unitMultipliers = {
      minutes: 60 * 1000,
      minute: 60 * 1000,
      hours: 3600 * 1000,
      hour: 3600 * 1000,
      days: 86400 * 1000,
      day: 86400 * 1000,
    };

    const multiplier = unitMultipliers[durationUnit];
    if (!multiplier) {
      return {
        ok: false,
        errorMessage: 'Select a valid duration unit (Minutes, Hours, or Days).',
        expiresAtIso: null,
        expiryDateStr: null,
        expiresAtMs: null,
        formattedManila: '—',
        remainingLabel: '—',
      };
    }

    expiresAtMs = issuedAtMs + Math.round(durationValue * multiplier);
  }

  const expiresDateObj = new Date(expiresAtMs);
  const expiresAtIso = expiresDateObj.toISOString();
  const manilaParts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(expiresDateObj);
  const mMap = {};
  for (const p of manilaParts) {
    mMap[p.type] = p.value;
  }
  const expiryDateStr = `${mMap.year}-${mMap.month}-${mMap.day}`;
  const formattedParts = formatManilaDateTimeParts(expiresAtIso);
  const remainingMs = Math.max(0, expiresAtMs - issuedAtMs);

  return {
    ok: true,
    errorMessage: null,
    expirationMethod: method,
    expiresAtIso,
    expiryDateStr,
    expiresAtMs,
    formattedManila: formattedParts.full,
    datePart: formattedParts.datePart,
    timePart: formattedParts.timePart,
    remainingMs,
    remainingLabel: formatRemainingTime(remainingMs, { includeSeconds: false }),
  };
}

export function validateAndCreateVoucher(
  input = {},
  catalog = [],
  existingVouchers = [],
  nowMs = Date.now()
) {
  const customerEmail = String(input?.customerEmail || '').trim().toLowerCase();
  if (!customerEmail || !customerEmail.includes('@') || !customerEmail.includes('.')) {
    return {
      ok: false,
      errorMessage: 'Enter a valid customer email address.',
      voucher: null,
    };
  }

  const furnitureId = String(input?.furnitureId || input?.productId || '').trim();
  const matchedProduct = Array.isArray(catalog)
    ? catalog.find((p) => String(p.id) === furnitureId)
    : null;

  if (!furnitureId || !matchedProduct) {
    return {
      ok: false,
      errorMessage: 'Select a valid eligible product from the catalog.',
      voucher: null,
    };
  }

  const code = String(input?.code || '')
    .trim()
    .toUpperCase()
    .replace(/\s+/g, '-');
  if (!code || code.length < 3) {
    return {
      ok: false,
      errorMessage: 'Enter or generate a valid voucher code (at least 3 characters).',
      voucher: null,
    };
  }

  const duplicate = Array.isArray(existingVouchers)
    ? existingVouchers.find((v) => String(v.code || '').trim().toUpperCase() === code)
    : null;
  if (duplicate) {
    return {
      ok: false,
      errorMessage: `Voucher code "${code}" already exists. Please generate or enter a unique code.`,
      voucher: null,
    };
  }

  const rawDiscount = Number(input?.discountAmount ?? input?.discountValue);
  if (!Number.isFinite(rawDiscount) || rawDiscount <= 0) {
    return {
      ok: false,
      errorMessage: 'Discount Amount ($) must be greater than $0.',
      voucher: null,
    };
  }

  const discountAmount = Math.round(rawDiscount * 100) / 100;
  const maxProductPrice = Number(matchedProduct.effectivePrice ?? matchedProduct.price ?? 0);

  if (maxProductPrice > 0 && discountAmount > maxProductPrice) {
    return {
      ok: false,
      errorMessage: `Discount Amount ($${discountAmount.toLocaleString('en-US')}) cannot exceed the product price ($${maxProductPrice.toLocaleString('en-US')} for ${matchedProduct.name}).`,
      voucher: null,
    };
  }

  const expResult = calculateExpirationFromInput(input, nowMs);
  if (!expResult.ok) {
    return {
      ok: false,
      errorMessage: expResult.errorMessage,
      voucher: null,
    };
  }

  const issuedDateObj = new Date(nowMs);
  const issuedAtIso = issuedDateObj.toISOString();
  const issuedManilaParts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(issuedDateObj);
  const iMap = {};
  for (const p of issuedManilaParts) {
    iMap[p.type] = p.value;
  }
  const createdAtDateStr = `${iMap.year}-${iMap.month}-${iMap.day}`;

  const defaultName = customerEmail
    .split('@')[0]
    .replace(/[._-]+/g, ' ')
    .replace(/\b\w/g, (l) => l.toUpperCase());

  const customerName = String(input?.customerName || defaultName || 'Showroom Client').trim();
  const issuedByAdmin = String(input?.issuedByAdmin || 'Lillia Mercier').trim();

  const statusInfo = computeVoucherStatusInfo(
    {
      status: 'Active',
      expiresAt: expResult.expiresAtIso,
      expiryDate: expResult.expiryDateStr,
    },
    nowMs
  );

  const generatedVoucherCodeId =
    input?.voucherId && !/^\d+$/.test(String(input.voucherId).trim())
      ? String(input.voucherId).trim().toUpperCase()
      : generateOwnEntityId('voucher', code).codeId;

  const voucher = {
    id: input?.id || `vch-${nowMs}`,
    voucherId: generatedVoucherCodeId,
    code,
    customerEmail,
    customerName,
    furnitureId: matchedProduct.id,
    furnitureSku: matchedProduct.sku || 'LR-ITEM',
    furnitureName: matchedProduct.name,
    productPrice: maxProductPrice,
    discountType: 'fixed',
    discountPercent: null,
    discountAmount,
    discountValue: discountAmount,
    status: statusInfo.status,
    issuedByAdmin,
    createdAt: createdAtDateStr,
    issuedAt: issuedAtIso,
    expiryDate: expResult.expiryDateStr,
    expiresAt: expResult.expiresAtIso,
    expirationMethod: expResult.expirationMethod,
  };

  return {
    ok: true,
    errorMessage: null,
    voucher,
  };
}

export function revokeVoucherInList(vouchers = [], voucherId = '', nowMs = Date.now()) {
  const cleanId = String(voucherId || '').trim();
  if (!cleanId) {
    return {
      ok: false,
      errorMessage: 'Missing voucher ID.',
      voucher: null,
      revokedVoucher: null,
      vouchers,
    };
  }

  let updatedVoucher = null;
  const revokedAt = new Date(nowMs).toISOString();

  const nextVouchers = (Array.isArray(vouchers) ? vouchers : []).map((v) => {
    if (String(v.id) !== cleanId && String(v.code).toUpperCase() !== cleanId.toUpperCase()) {
      return v;
    }
    if (v.status === 'Used') {
      updatedVoucher = { ...v, _cannotRevokeUsed: true };
      return v;
    }
    updatedVoucher = {
      ...v,
      status: 'Revoked',
      revokedAt,
    };
    return updatedVoucher;
  });

  if (!updatedVoucher) {
    return {
      ok: false,
      errorMessage: 'Voucher not found.',
      voucher: null,
      revokedVoucher: null,
      vouchers,
    };
  }

  if (updatedVoucher._cannotRevokeUsed) {
    return {
      ok: false,
      errorMessage: 'Cannot revoke a voucher that has already been redeemed.',
      voucher: null,
      revokedVoucher: null,
      vouchers,
    };
  }

  return {
    ok: true,
    errorMessage: null,
    voucher: updatedVoucher,
    revokedVoucher: updatedVoucher,
    vouchers: nextVouchers,
  };
}

export function filterCustomerVouchers(vouchers = [], customerEmail = '') {
  const cleanEmail = String(customerEmail || '').trim().toLowerCase();
  if (!cleanEmail) return [];
  return (Array.isArray(vouchers) ? vouchers : []).filter(
    (v) => String(v?.customerEmail || '').trim().toLowerCase() === cleanEmail
  );
}

/* ==========================================================================
   MONTHLY ANALYTICS & BUSINESS REPORTS ENGINE (ADMIN DASHBOARD)
   ========================================================================== */

const MONTH_NAMES_FULL = [
  'January',
  'February',
  'March',
  'April',
  'May',
  'June',
  'July',
  'August',
  'September',
  'October',
  'November',
  'December',
];

const MONTH_NAMES_SHORT = [
  'Jan',
  'Feb',
  'Mar',
  'Apr',
  'May',
  'Jun',
  'Jul',
  'Aug',
  'Sep',
  'Oct',
  'Nov',
  'Dec',
];

function getDaysInYearMonth(year, month1To12) {
  return new Date(Date.UTC(year, month1To12, 0)).getUTCDate();
}

function pad2(num) {
  return String(Math.max(0, Math.floor(Number(num) || 0))).padStart(2, '0');
}

function formatYmd(year, month1To12, day) {
  return `${year}-${pad2(month1To12)}-${pad2(day)}`;
}

function parseManilaDateOnly(rawDateStr) {
  if (!rawDateStr) return null;
  const clean = String(rawDateStr).trim();
  const ymdMatch = clean.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (ymdMatch) {
    return `${ymdMatch[1]}-${ymdMatch[2]}-${ymdMatch[3]}`;
  }
  const ms = Date.parse(clean);
  if (Number.isNaN(ms)) return null;
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date(ms));
  const map = {};
  for (const p of parts) map[p.type] = p.value;
  return `${map.year}-${map.month}-${map.day}`;
}

export function computePercentageChange(currentValue, previousValue) {
  const curr = Number(currentValue) || 0;
  const prev = Number(previousValue) || 0;

  if (Math.abs(prev) < 0.0001) {
    if (Math.abs(curr) < 0.0001) {
      return {
        percentChange: 0,
        direction: 'flat',
        formatted: '0.0%',
        formattedChange: '0.0%',
        badgeLabel: '0.0% vs prior period',
        previousZero: true,
        isNewFromZero: false,
      };
    }
    const formatted = curr > 0 ? '+100.0%' : '-100.0%';
    return {
      percentChange: curr > 0 ? 100 : -100,
      direction: curr > 0 ? 'up' : 'down',
      formatted,
      formattedChange: curr > 0 ? '+100.0% (New)' : '-100.0%',
      badgeLabel: curr > 0 ? '+100.0% (up from 0)' : '-100.0%',
      previousZero: true,
      isNewFromZero: curr > 0,
    };
  }

  const rawPct = ((curr - prev) / Math.abs(prev)) * 100;
  const pct = Math.round(rawPct * 10) / 10;
  const direction = pct > 0 ? 'up' : pct < 0 ? 'down' : 'flat';
  const sign = pct > 0 ? '+' : '';
  const formatted = `${sign}${pct.toFixed(1)}%`;

  return {
    percentChange: pct,
    direction,
    formatted,
    formattedChange: formatted,
    badgeLabel: `${formatted} vs prior period`,
    previousZero: false,
    isNewFromZero: false,
  };
}

export function resolveAnalyticsDateRange(filterOptions = {}, nowMs = Date.now()) {
  const nowParts = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date(nowMs));
  const nMap = {};
  for (const p of nowParts) nMap[p.type] = p.value;
  const currentYear = Number(nMap.year);
  const currentMonth = Number(nMap.month);

  const preset = String(filterOptions?.preset || 'this_month')
    .trim()
    .toLowerCase();

  let startDate = '';
  let endDate = '';
  let previousStartDate = '';
  let previousEndDate = '';
  let periodLabel = '';
  let previousPeriodLabel = '';
  let selectedYear = Number(filterOptions?.year) || currentYear;
  let selectedMonth = Math.min(12, Math.max(1, Number(filterOptions?.month) || currentMonth));

  if (preset === 'last_month') {
    const lmYear = currentMonth === 1 ? currentYear - 1 : currentYear;
    const lmMonth = currentMonth === 1 ? 12 : currentMonth - 1;
    const lmDays = getDaysInYearMonth(lmYear, lmMonth);
    startDate = formatYmd(lmYear, lmMonth, 1);
    endDate = formatYmd(lmYear, lmMonth, lmDays);

    const prevYear = lmMonth === 1 ? lmYear - 1 : lmYear;
    const prevMonth = lmMonth === 1 ? 12 : lmMonth - 1;
    const prevDays = getDaysInYearMonth(prevYear, prevMonth);
    previousStartDate = formatYmd(prevYear, prevMonth, 1);
    previousEndDate = formatYmd(prevYear, prevMonth, prevDays);

    selectedYear = lmYear;
    selectedMonth = lmMonth;
    periodLabel = `${MONTH_NAMES_FULL[lmMonth - 1]} ${lmYear}`;
    previousPeriodLabel = `${MONTH_NAMES_FULL[prevMonth - 1]} ${prevYear}`;
  } else if (preset === 'select_month') {
    const smDays = getDaysInYearMonth(selectedYear, selectedMonth);
    startDate = formatYmd(selectedYear, selectedMonth, 1);
    endDate = formatYmd(selectedYear, selectedMonth, smDays);

    const prevYear = selectedMonth === 1 ? selectedYear - 1 : selectedYear;
    const prevMonth = selectedMonth === 1 ? 12 : selectedMonth - 1;
    const prevDays = getDaysInYearMonth(prevYear, prevMonth);
    previousStartDate = formatYmd(prevYear, prevMonth, 1);
    previousEndDate = formatYmd(prevYear, prevMonth, prevDays);

    periodLabel = `${MONTH_NAMES_FULL[selectedMonth - 1]} ${selectedYear}`;
    previousPeriodLabel = `${MONTH_NAMES_FULL[prevMonth - 1]} ${prevYear}`;
  } else if (preset === 'this_year') {
    const targetYear = Number(filterOptions?.year) || currentYear;
    selectedYear = targetYear;
    startDate = `${targetYear}-01-01`;
    endDate = `${targetYear}-12-31`;
    previousStartDate = `${targetYear - 1}-01-01`;
    previousEndDate = `${targetYear - 1}-12-31`;
    periodLabel = `Full Year ${targetYear}`;
    previousPeriodLabel = `Full Year ${targetYear - 1}`;
  } else if (preset === 'custom_range') {
    const fallbackStart = formatYmd(currentYear, currentMonth, 1);
    const fallbackEnd = formatYmd(
      currentYear,
      currentMonth,
      getDaysInYearMonth(currentYear, currentMonth)
    );
    let rawStart = parseManilaDateOnly(filterOptions?.startDate) || fallbackStart;
    let rawEnd = parseManilaDateOnly(filterOptions?.endDate) || fallbackEnd;
    if (rawEnd < rawStart) {
      const tmp = rawStart;
      rawStart = rawEnd;
      rawEnd = tmp;
    }
    startDate = rawStart;
    endDate = rawEnd;

    const startUtc = Date.parse(`${startDate}T00:00:00Z`);
    const endUtc = Date.parse(`${endDate}T00:00:00Z`);
    const spanDays = Math.max(1, Math.round((endUtc - startUtc) / 86400000) + 1);
    const prevEndUtc = startUtc - 86400000;
    const prevStartUtc = prevEndUtc - (spanDays - 1) * 86400000;

    previousStartDate = new Date(prevStartUtc).toISOString().slice(0, 10);
    previousEndDate = new Date(prevEndUtc).toISOString().slice(0, 10);
    selectedYear = Number(startDate.slice(0, 4)) || currentYear;
    selectedMonth = Number(startDate.slice(5, 7)) || currentMonth;
    periodLabel = `${startDate} to ${endDate}`;
    previousPeriodLabel = `${previousStartDate} to ${previousEndDate}`;
  } else {
    // Default: 'this_month'
    const tmDays = getDaysInYearMonth(currentYear, currentMonth);
    startDate = formatYmd(currentYear, currentMonth, 1);
    endDate = formatYmd(currentYear, currentMonth, tmDays);

    const prevYear = currentMonth === 1 ? currentYear - 1 : currentYear;
    const prevMonth = currentMonth === 1 ? 12 : currentMonth - 1;
    const prevDays = getDaysInYearMonth(prevYear, prevMonth);
    previousStartDate = formatYmd(prevYear, prevMonth, 1);
    previousEndDate = formatYmd(prevYear, prevMonth, prevDays);

    selectedYear = currentYear;
    selectedMonth = currentMonth;
    periodLabel = `${MONTH_NAMES_FULL[currentMonth - 1]} ${currentYear}`;
    previousPeriodLabel = `${MONTH_NAMES_FULL[prevMonth - 1]} ${prevYear}`;
  }

  const countDaysInclusive = (sStr, eStr) => {
    const sUtc = Date.parse(`${sStr}T00:00:00Z`);
    const eUtc = Date.parse(`${eStr}T00:00:00Z`);
    if (Number.isNaN(sUtc) || Number.isNaN(eUtc)) return 30;
    return Math.max(1, Math.round((eUtc - sUtc) / 86400000) + 1);
  };

  const currentDayCount = countDaysInclusive(startDate, endDate);
  const previousDayCount = countDaysInclusive(previousStartDate, previousEndDate);
  const resolvedPreset = ['this_month', 'last_month', 'select_month', 'this_year', 'custom_range'].includes(preset)
    ? preset
    : 'this_month';

  return {
    preset: resolvedPreset,
    startDate,
    endDate,
    previousStartDate,
    previousEndDate,
    periodLabel,
    previousPeriodLabel,
    selectedYear,
    selectedMonth,
    timezone: 'Asia/Manila',
    current: {
      startDate,
      endDate,
      label: periodLabel,
      year: selectedYear,
      month: selectedMonth,
      dayCount: currentDayCount,
    },
    previous: {
      startDate: previousStartDate,
      endDate: previousEndDate,
      label: previousPeriodLabel,
      dayCount: previousDayCount,
    },
  };
}

function classifyAnalyticsOrderStatus(order) {
  const sqlSt = String(order?.sqlStatus || '').trim();
  const statusStr = String(order?.status || '').toLowerCase();

  if (sqlSt === 'Cancelled' || statusStr.includes('cancel')) {
    return 'Cancelled';
  }
  if (isOrderCompleted(order)) {
    return 'Completed';
  }
  if (
    sqlSt === 'Ready' ||
    statusStr.includes('dispatch') ||
    statusStr.includes('transit') ||
    statusStr.includes('shipped')
  ) {
    return 'Shipped';
  }
  if (
    sqlSt === 'InProduction' ||
    statusStr.includes('joinery') ||
    statusStr.includes('finishing') ||
    statusStr.includes('upholstery') ||
    statusStr.includes('production') ||
    statusStr.includes('processing')
  ) {
    return 'Processing';
  }
  return 'Pending';
}

function isOrderPaidOrDepositConfirmed(order) {
  if (!order || classifyAnalyticsOrderStatus(order) === 'Cancelled') return false;
  if (isOrderCompleted(order)) return true;
  const deposit = Number(order?.depositPaid || 0);
  if (deposit > 0) return true;
  const paySt = String(order?.paymentStatus || '').toLowerCase();
  if (paySt.includes('paid') && !paySt.includes('awaiting') && !paySt.includes('unpaid')) {
    return true;
  }
  return false;
}

function getHistoricalOrderAmount(order) {
  const explicitTotal = Number(order?.totalAmount);
  if (Number.isFinite(explicitTotal) && explicitTotal > 0) {
    return Math.round(explicitTotal);
  }
  const qty = Math.max(1, Number(order?.quantity || 1));
  const unitPrice = Math.max(0, Number(order?.unitPrice || 0));
  const extraCost = Math.max(0, Number(order?.extraCost || 0));
  const discount = Math.max(0, Number(order?.voucherDiscountAmount || 0));
  return Math.max(0, Math.round(unitPrice * qty + extraCost - discount));
}

function deduplicateOrdersList(orders = []) {
  const seen = new Set();
  const unique = [];
  for (const ord of Array.isArray(orders) ? orders : []) {
    if (!ord || typeof ord !== 'object') continue;
    const key = String(ord.orderId || ord.orderNumber || ord.id || '').trim().toUpperCase();
    if (key && seen.has(key)) continue;
    if (key) seen.add(key);
    unique.push(ord);
  }
  return unique;
}

export function computeMonthlyAnalyticsReport(
  storeData = {},
  filterOptionsArg = {},
  nowMsArg = Date.now()
) {
  const effectiveFilter =
    storeData && typeof storeData === 'object' && storeData.filterInput
      ? storeData.filterInput
      : storeData && typeof storeData === 'object' && storeData.filterOptions
      ? storeData.filterOptions
      : filterOptionsArg || {};
  const effectiveNowMs =
    storeData && typeof storeData === 'object' && Number.isFinite(Number(storeData.nowMs))
      ? Number(storeData.nowMs)
      : Number.isFinite(Number(nowMsArg))
      ? Number(nowMsArg)
      : Date.now();

  const range = resolveAnalyticsDateRange(effectiveFilter, effectiveNowMs);
  const products = Array.isArray(storeData?.catalog)
    ? storeData.catalog
    : Array.isArray(storeData?.products)
    ? storeData.products
    : [];
  const categories = Array.isArray(storeData?.categories) ? storeData.categories : [];
  const materials = Array.isArray(storeData?.materials) ? storeData.materials : [];
  const rawOrders = Array.isArray(storeData?.orders) ? storeData.orders : [];
  const orders = deduplicateOrdersList(rawOrders);
  const vouchers = Array.isArray(storeData?.vouchers) ? storeData.vouchers : [];
  const customers = Array.isArray(storeData?.customers) ? storeData.customers : [];
  const inventoryTransactions = Array.isArray(storeData?.inventoryTransactions)
    ? storeData.inventoryTransactions
    : [];

  const productById = new Map();
  const productBySku = new Map();
  products.forEach((p) => {
    if (p?.id) productById.set(String(p.id), p);
    if (p?.sku) productBySku.set(String(p.sku).toUpperCase(), p);
    if (p?.productId) productBySku.set(String(p.productId).toUpperCase(), p);
  });

  const isWithinRange = (dateStr, startStr, endStr) => {
    const d = parseManilaDateOnly(dateStr);
    if (!d) return false;
    return d >= startStr && d <= endStr;
  };

  // Helper to compute period KPI metrics for any [start, end] window
  const computeWindowMetrics = (startStr, endStr) => {
    const periodOrders = orders.filter((ord) => isWithinRange(ord.orderDate, startStr, endStr));
    let totalRevenue = 0;
    let grossBookedRevenue = 0;
    let completedOrders = 0;
    let pendingOrders = 0;
    let processingOrders = 0;
    let shippedOrders = 0;
    let cancelledOrders = 0;
    let totalProductsSold = 0;
    let totalDiscountsGiven = 0;
    let revenueEligibleOrderCount = 0;

    const seenOrderItems = new Set();

    periodOrders.forEach((ord) => {
      const statusBucket = classifyAnalyticsOrderStatus(ord);
      const historicalAmount = getHistoricalOrderAmount(ord);
      const itemKey = String(ord.orderItemId || ord.orderId || ord.orderNumber || ord.id || '');

      if (statusBucket === 'Completed') completedOrders += 1;
      else if (statusBucket === 'Pending') pendingOrders += 1;
      else if (statusBucket === 'Processing') processingOrders += 1;
      else if (statusBucket === 'Shipped') shippedOrders += 1;
      else if (statusBucket === 'Cancelled') cancelledOrders += 1;

      if (statusBucket !== 'Cancelled') {
        grossBookedRevenue += historicalAmount;
        if (isOrderPaidOrDepositConfirmed(ord)) {
          totalRevenue += historicalAmount;
          revenueEligibleOrderCount += 1;
        }
        if (!itemKey || !seenOrderItems.has(itemKey)) {
          if (itemKey) seenOrderItems.add(itemKey);
          totalProductsSold += Math.max(1, Number(ord.quantity || 1));
        }
        totalDiscountsGiven += Math.max(0, Number(ord.voucherDiscountAmount || 0));
      }
    });

    // New customers registered in [startStr, endStr]
    const newCustomersInWindow = customers.filter((c) => {
      const regDate = parseManilaDateOnly(c?.registeredAt || c?.createdAt);
      return regDate && regDate >= startStr && regDate <= endStr;
    });

    const totalOrders = periodOrders.length;
    const averageOrderValue =
      revenueEligibleOrderCount > 0 ? Math.round(totalRevenue / revenueEligibleOrderCount) : 0;

    return {
      periodOrders,
      totalRevenue: Math.round(totalRevenue),
      grossBookedRevenue: Math.round(grossBookedRevenue),
      totalOrders,
      completedOrders,
      pendingOrders,
      processingOrders,
      shippedOrders,
      cancelledOrders,
      totalProductsSold,
      newCustomers: newCustomersInWindow.length,
      newCustomersList: newCustomersInWindow,
      totalDiscountsGiven: Math.round(totalDiscountsGiven),
      averageOrderValue,
      revenueEligibleOrderCount,
    };
  };

  const currentMetrics = computeWindowMetrics(range.startDate, range.endDate);
  const previousMetrics = computeWindowMetrics(range.previousStartDate, range.previousEndDate);

  const formatUsd = (val) => `$${Math.round(Number(val) || 0).toLocaleString('en-US')}`;

  const buildKpiObj = (key, label, currVal, prevVal, formattedCurr, formattedPrev, subtitle) => {
    const comp = computePercentageChange(currVal, prevVal);
    return {
      key,
      label,
      value: currVal,
      previousValue: prevVal,
      formattedValue: formattedCurr,
      formattedPreviousValue: formattedPrev,
      subtitle,
      percentChange: comp.percentChange,
      direction: comp.direction,
      formatted: comp.formatted,
      formattedChange: comp.formattedChange,
      isNewFromZero: comp.isNewFromZero,
      previousZero: comp.previousZero,
      comparison: comp,
    };
  };

  const summary = {
    totalRevenue: buildKpiObj(
      'totalRevenue',
      'Total Revenue',
      currentMetrics.totalRevenue,
      previousMetrics.totalRevenue,
      formatUsd(currentMetrics.totalRevenue),
      formatUsd(previousMetrics.totalRevenue),
      `${currentMetrics.revenueEligibleOrderCount} paid/confirmed order(s)`
    ),
    totalOrders: buildKpiObj(
      'totalOrders',
      'Total Orders',
      currentMetrics.totalOrders,
      previousMetrics.totalOrders,
      String(currentMetrics.totalOrders),
      String(previousMetrics.totalOrders),
      `Gross booked: ${formatUsd(currentMetrics.grossBookedRevenue)}`
    ),
    completedOrders: buildKpiObj(
      'completedOrders',
      'Completed Orders',
      currentMetrics.completedOrders,
      previousMetrics.completedOrders,
      String(currentMetrics.completedOrders),
      String(previousMetrics.completedOrders),
      'Delivered & settled in full'
    ),
    pendingOrders: buildKpiObj(
      'pendingOrders',
      'Pending Orders',
      currentMetrics.pendingOrders,
      previousMetrics.pendingOrders,
      String(currentMetrics.pendingOrders),
      String(previousMetrics.pendingOrders),
      `${currentMetrics.processingOrders} in workshop · ${currentMetrics.shippedOrders} ready/shipped`
    ),
    cancelledOrders: buildKpiObj(
      'cancelledOrders',
      'Cancelled Orders',
      currentMetrics.cancelledOrders,
      previousMetrics.cancelledOrders,
      String(currentMetrics.cancelledOrders),
      String(previousMetrics.cancelledOrders),
      'Excluded from realized revenue'
    ),
    totalProductsSold: buildKpiObj(
      'totalProductsSold',
      'Total Products Sold',
      currentMetrics.totalProductsSold,
      previousMetrics.totalProductsSold,
      `${currentMetrics.totalProductsSold} pcs`,
      `${previousMetrics.totalProductsSold} pcs`,
      'Handcrafted furniture units ordered'
    ),
    newCustomers: buildKpiObj(
      'newCustomers',
      'New Customers',
      currentMetrics.newCustomers,
      previousMetrics.newCustomers,
      String(currentMetrics.newCustomers),
      String(previousMetrics.newCustomers),
      `${customers.length} total registered clients`
    ),
    totalDiscountsGiven: buildKpiObj(
      'totalDiscountsGiven',
      'Total Discounts Given',
      currentMetrics.totalDiscountsGiven,
      previousMetrics.totalDiscountsGiven,
      formatUsd(currentMetrics.totalDiscountsGiven),
      formatUsd(previousMetrics.totalDiscountsGiven),
      'Fixed-amount vouchers redeemed'
    ),
    averageOrderValue: buildKpiObj(
      'averageOrderValue',
      'Average Order Value',
      currentMetrics.averageOrderValue,
      previousMetrics.averageOrderValue,
      formatUsd(currentMetrics.averageOrderValue),
      formatUsd(previousMetrics.averageOrderValue),
      'Realized revenue per paid order'
    ),
    grossOrderValue: currentMetrics.grossBookedRevenue,
    processingOrdersCount: currentMetrics.processingOrders,
    shippedOrdersCount: currentMetrics.shippedOrders,
  };

  const summaryCards = [
    summary.totalRevenue,
    summary.totalOrders,
    summary.completedOrders,
    summary.pendingOrders,
    summary.cancelledOrders,
    summary.totalProductsSold,
    summary.newCustomers,
    summary.totalDiscountsGiven,
    summary.averageOrderValue,
  ];

  // 4. Sales and Revenue Analytics
  // 4a. Daily revenue & orders per day throughout the selected month
  const daysInTargetMonth = getDaysInYearMonth(range.selectedYear, range.selectedMonth);
  const dailySeries = [];
  for (let d = 1; d <= daysInTargetMonth; d += 1) {
    const dateKey = formatYmd(range.selectedYear, range.selectedMonth, d);
    const dayOrders = orders.filter((ord) => parseManilaDateOnly(ord.orderDate) === dateKey);
    let dayRev = 0;
    let dayUnits = 0;
    let nonCancelledCount = 0;
    let completedDayCount = 0;
    dayOrders.forEach((ord) => {
      const st = classifyAnalyticsOrderStatus(ord);
      if (st === 'Completed') completedDayCount += 1;
      if (st !== 'Cancelled') {
        nonCancelledCount += 1;
        dayUnits += Math.max(1, Number(ord.quantity || 1));
        if (isOrderPaidOrDepositConfirmed(ord)) {
          dayRev += getHistoricalOrderAmount(ord);
        }
      }
    });
    const shortLbl = `${MONTH_NAMES_SHORT[range.selectedMonth - 1]} ${d}`;
    dailySeries.push({
      date: dateKey,
      dayOfMonth: d,
      dayLabel: String(d),
      label: shortLbl,
      shortLabel: shortLbl,
      revenue: Math.round(dayRev),
      orders: dayOrders.length,
      orderCount: dayOrders.length,
      completedOrders: completedDayCount,
      nonCancelledOrderCount: nonCancelledCount,
      unitsSold: dayUnits,
    });
  }

  // 4b. Monthly revenue throughout the selected year + sales comparison between months
  const monthlySeries = [];
  for (let m = 1; m <= 12; m += 1) {
    const mStart = formatYmd(range.selectedYear, m, 1);
    const mEnd = formatYmd(range.selectedYear, m, getDaysInYearMonth(range.selectedYear, m));
    const mWindow = computeWindowMetrics(mStart, mEnd);

    const prevMonthRev = m > 1 ? monthlySeries[m - 2].revenue : 0;
    const momChange = computePercentageChange(mWindow.totalRevenue, prevMonthRev);

    monthlySeries.push({
      month: m,
      monthIndex: m,
      monthKey: `${range.selectedYear}-${pad2(m)}`,
      monthName: MONTH_NAMES_FULL[m - 1],
      fullLabel: `${MONTH_NAMES_FULL[m - 1]} ${range.selectedYear}`,
      label: MONTH_NAMES_SHORT[m - 1],
      shortName: MONTH_NAMES_SHORT[m - 1],
      revenue: mWindow.totalRevenue,
      grossBookedRevenue: mWindow.grossBookedRevenue,
      orders: mWindow.totalOrders,
      orderCount: mWindow.totalOrders,
      completedOrders: mWindow.completedOrders,
      productsSold: mWindow.totalProductsSold,
      vsPreviousMonth: momChange,
      monthOverMonthChange: momChange,
      isSelectedMonth: m === range.selectedMonth,
    });
  }

  // 4c. Revenue by Product Category
  const categoryMap = new Map();
  categories.forEach((cat) => {
    categoryMap.set(String(cat.id).toLowerCase(), {
      categoryId: String(cat.id).toLowerCase(),
      categoryCode: cat.categoryId || cat.code || 'CAT-LR',
      categoryName: cat.name,
      revenue: 0,
      unitsSold: 0,
      orders: 0,
      orderCount: 0,
    });
  });

  const normalizeCategorySlug = (order, matchedProduct) => {
    if (matchedProduct?.categoryId) return String(matchedProduct.categoryId).toLowerCase();
    const rawCat = String(order?.categoryName || '').toLowerCase();
    if (rawCat.includes('living') || rawCat.includes('lounge')) return 'living';
    if (rawCat.includes('dining')) return 'dining';
    if (rawCat.includes('bed')) return 'bedroom';
    if (
      rawCat.includes('study') ||
      rawCat.includes('office') ||
      rawCat.includes('credenza') ||
      rawCat.includes('desk')
    ) {
      return 'office';
    }
    if (rawCat.includes('kitchen')) return 'kitchen';
    return 'living';
  };

  currentMetrics.periodOrders.forEach((ord) => {
    if (classifyAnalyticsOrderStatus(ord) === 'Cancelled') return;
    const matchedProd =
      productById.get(String(ord.furnitureId || '')) ||
      productBySku.get(String(ord.sku || '').toUpperCase()) ||
      null;
    const slug = normalizeCategorySlug(ord, matchedProd);
    if (!categoryMap.has(slug)) {
      categoryMap.set(slug, {
        categoryId: slug,
        categoryCode: 'CAT-LR',
        categoryName: ord.categoryName || 'Collection',
        revenue: 0,
        unitsSold: 0,
        orders: 0,
        orderCount: 0,
      });
    }
    const bucket = categoryMap.get(slug);
    bucket.orders += 1;
    bucket.orderCount += 1;
    bucket.unitsSold += Math.max(1, Number(ord.quantity || 1));
    if (isOrderPaidOrDepositConfirmed(ord)) {
      bucket.revenue += getHistoricalOrderAmount(ord);
    }
  });

  const totalCatRev = Math.max(
    1,
    Array.from(categoryMap.values()).reduce((sum, c) => sum + c.revenue, 0)
  );
  const categoryRevenue = Array.from(categoryMap.values())
    .map((c) => ({
      ...c,
      sharePercent:
        currentMetrics.totalRevenue > 0 ? Math.round((c.revenue / totalCatRev) * 1000) / 10 : 0,
    }))
    .sort((a, b) => b.revenue - a.revenue || b.unitsSold - a.unitsSold);

  const revComparison = computePercentageChange(
    currentMetrics.totalRevenue,
    previousMetrics.totalRevenue
  );
  const performanceStatus =
    revComparison.percentChange > 0
      ? 'Improving'
      : revComparison.percentChange < 0
      ? 'Declining'
      : 'Stable';
  const performanceDirection = performanceStatus.toLowerCase();
  const performanceInsight =
    performanceStatus === 'Improving'
      ? `Realized revenue increased by ${revComparison.formatted} (${formatUsd(currentMetrics.totalRevenue)} vs ${formatUsd(previousMetrics.totalRevenue)} in ${range.previousPeriodLabel}).`
      : performanceStatus === 'Declining'
      ? `Realized revenue shifted by ${revComparison.formatted} (${formatUsd(currentMetrics.totalRevenue)} vs ${formatUsd(previousMetrics.totalRevenue)} in ${range.previousPeriodLabel}) while workshop orders remain in production.`
      : `Realized revenue is steady at ${formatUsd(currentMetrics.totalRevenue)} compared to ${range.previousPeriodLabel}.`;

  const salesAnalytics = {
    year: range.selectedYear,
    dailySeries,
    monthlySeries,
    monthlyComparison: monthlySeries,
    categoryRevenue,
    revenueByCategory: categoryRevenue,
    performanceDirection,
    performanceInsight,
    performanceTrend: {
      status: performanceStatus,
      comparison: revComparison,
      currentRevenue: currentMetrics.totalRevenue,
      previousRevenue: previousMetrics.totalRevenue,
      summaryText: performanceInsight,
    },
  };

  // 5. Best-Selling Products & Low-Sales Products
  const productSalesMap = new Map();
  products.forEach((prod) => {
    const stockInfo = getProductStockStatus(prod);
    productSalesMap.set(String(prod.id), {
      id: String(prod.id),
      productId: prod.productId || prod.sku || 'LR-ITEM',
      sku: prod.sku || prod.productId || 'LR-ITEM',
      name: prod.name,
      categoryId: prod.categoryId,
      categoryName: prod.categoryName || 'Showroom Collection',
      image: prod.image || 'assets/images/category_living_lounge.jpg',
      quantitySold: 0,
      totalRevenue: 0,
      orderCount: 0,
      currentStock: stockInfo.availableStock,
      minStockLevel: stockInfo.minStockLevel,
      stockStatusKey: stockInfo.statusKey,
      stockStatusLabel: stockInfo.label,
    });
  });

  const seenProdOrderItems = new Set();
  currentMetrics.periodOrders.forEach((ord) => {
    if (classifyAnalyticsOrderStatus(ord) === 'Cancelled') return;
    const itemKey = String(ord.orderItemId || ord.orderId || ord.orderNumber || ord.id || '');
    if (itemKey && seenProdOrderItems.has(itemKey)) return;
    if (itemKey) seenProdOrderItems.add(itemKey);

    const matchedProd =
      productById.get(String(ord.furnitureId || '')) ||
      productBySku.get(String(ord.sku || '').toUpperCase()) ||
      null;
    const mapKey = matchedProd ? String(matchedProd.id) : String(ord.furnitureId || ord.sku || ord.id);
    if (!productSalesMap.has(mapKey)) {
      productSalesMap.set(mapKey, {
        id: mapKey,
        productId: ord.sku || 'LR-CUSTOM',
        sku: ord.sku || 'LR-CUSTOM',
        name: ord.furnitureName || 'Custom Piece',
        categoryId: 'living',
        categoryName: ord.categoryName || 'Bespoke Commission',
        image: 'assets/images/category_living_lounge.jpg',
        quantitySold: 0,
        totalRevenue: 0,
        orderCount: 0,
        currentStock: 1,
        minStockLevel: 1,
        stockStatusKey: 'in_stock',
        stockStatusLabel: 'In Stock',
      });
    }
    const entry = productSalesMap.get(mapKey);
    const qty = Math.max(1, Number(ord.quantity || 1));
    entry.quantitySold += qty;
    entry.orderCount += 1;
    entry.totalRevenue += getHistoricalOrderAmount(ord);
  });

  const allProductRankings = Array.from(productSalesMap.values())
    .sort((a, b) => b.totalRevenue - a.totalRevenue || b.quantitySold - a.quantitySold || a.name.localeCompare(b.name))
    .map((item, idx) => ({
      ...item,
      rank: idx + 1,
    }));

  const topFiveProducts = allProductRankings.slice(0, 5);
  const lowPerformingProducts = allProductRankings
    .filter((item) => item.quantitySold <= 1)
    .sort((a, b) => a.quantitySold - b.quantitySold || a.totalRevenue - b.totalRevenue)
    .slice(0, 5);

  const bestSellingProducts = {
    allRankedProducts: allProductRankings,
    topFive: topFiveProducts,
    topFiveProducts,
    lowSellingProducts: lowPerformingProducts,
    lowPerformingProducts,
  };

  // 6. Order Analytics (Status Distribution using actual system statuses)
  const statusDefs = [
    {
      status: 'Pending',
      statusKey: 'Pending',
      label: 'Pending',
      sqlStatusLabel: 'Pending · Timber Selection',
      color: '#D97706',
    },
    {
      status: 'Processing',
      statusKey: 'Processing',
      label: 'Processing',
      sqlStatusLabel: 'InProduction · Workshop Joinery',
      color: '#0095FF',
    },
    {
      status: 'Shipped',
      statusKey: 'Shipped',
      label: 'Shipped',
      sqlStatusLabel: 'Ready · Dispatched / Transit',
      color: '#6366F1',
    },
    {
      status: 'Completed',
      statusKey: 'Completed',
      label: 'Completed',
      sqlStatusLabel: 'Delivered · Completed',
      color: '#059669',
    },
    {
      status: 'Cancelled',
      statusKey: 'Cancelled',
      label: 'Cancelled',
      sqlStatusLabel: 'Cancelled',
      color: '#DC2626',
    },
  ];

  const statusCounts = {
    Pending: { count: 0, totalAmount: 0 },
    Processing: { count: 0, totalAmount: 0 },
    Shipped: { count: 0, totalAmount: 0 },
    Completed: { count: 0, totalAmount: 0 },
    Cancelled: { count: 0, totalAmount: 0 },
  };

  currentMetrics.periodOrders.forEach((ord) => {
    const bucketKey = classifyAnalyticsOrderStatus(ord);
    if (statusCounts[bucketKey]) {
      statusCounts[bucketKey].count += 1;
      statusCounts[bucketKey].totalAmount += getHistoricalOrderAmount(ord);
    }
  });

  const totalPeriodOrdersDenom = Math.max(1, currentMetrics.totalOrders);
  const statusDistribution = statusDefs.map((def) => {
    const data = statusCounts[def.statusKey] || { count: 0, totalAmount: 0 };
    const sharePercent =
      currentMetrics.totalOrders > 0
        ? Math.round((data.count / totalPeriodOrdersDenom) * 1000) / 10
        : 0;
    const totalAmt = Math.round(data.totalAmount);
    return {
      ...def,
      count: data.count,
      totalAmount: totalAmt,
      totalValue: totalAmt,
      sharePercent,
      percentage: sharePercent,
    };
  });

  const orderAnalytics = {
    totalOrders: currentMetrics.totalOrders,
    distribution: statusDistribution,
    statusDistribution,
  };

  // 7. Customer Analytics
  const customerOrderStats = new Map();
  customers.forEach((c) => {
    const email = String(c?.email || '').trim().toLowerCase();
    if (!email) return;
    customerOrderStats.set(email, {
      customerId: c.customerId || c.id || 'LR-CUS-300',
      name: c.fullName || c.name || 'Client',
      email: c.email,
      company: c.company || 'Private Residence',
      registeredAt: parseManilaDateOnly(c.registeredAt || c.createdAt) || '2026-09-01',
      periodOrders: 0,
      periodSpent: 0,
      lifetimeOrders: 0,
      lifetimeSpent: 0,
    });
  });

  orders.forEach((ord) => {
    if (classifyAnalyticsOrderStatus(ord) === 'Cancelled') return;
    const email = String(ord?.clientEmail || '').trim().toLowerCase();
    if (!email) return;
    if (!customerOrderStats.has(email)) {
      customerOrderStats.set(email, {
        customerId: generateOwnEntityId('customer', email).codeId,
        name: ord.clientName || 'Showroom Client',
        email: ord.clientEmail,
        company: ord.clientCompany || 'Private Residence',
        registeredAt: parseManilaDateOnly(ord.orderDate) || '2026-09-01',
        periodOrders: 0,
        periodSpent: 0,
        lifetimeOrders: 0,
        lifetimeSpent: 0,
      });
    }
    const stat = customerOrderStats.get(email);
    const amt = getHistoricalOrderAmount(ord);
    stat.lifetimeOrders += 1;
    stat.lifetimeSpent += amt;

    if (isWithinRange(ord.orderDate, range.startDate, range.endDate)) {
      stat.periodOrders += 1;
      stat.periodSpent += amt;
    }
  });

  const allCustomerStats = Array.from(customerOrderStats.values());
  const orderingCustomers = allCustomerStats.filter((c) => c.periodOrders > 0);
  const returningCustomers = allCustomerStats.filter(
    (c) => c.periodOrders > 0 && c.lifetimeOrders > 1
  );
  const lifetimeReturningCustomers = allCustomerStats.filter((c) => c.lifetimeOrders > 1);
  const topCustomers = [...allCustomerStats]
    .sort((a, b) => b.periodSpent - a.periodSpent || b.lifetimeSpent - a.lifetimeSpent)
    .map((c, idx) => ({
      ...c,
      rank: idx + 1,
      isReturning: c.lifetimeOrders > 1,
    }));

  const monthlyRegistrationTrend = [];
  for (let m = 1; m <= 12; m += 1) {
    const mStart = formatYmd(range.selectedYear, m, 1);
    const mEnd = formatYmd(range.selectedYear, m, getDaysInYearMonth(range.selectedYear, m));
    const regCount = allCustomerStats.filter(
      (c) => c.registeredAt >= mStart && c.registeredAt <= mEnd
    ).length;
    const activeBuyerEmails = new Set(
      orders
        .filter(
          (o) =>
            classifyAnalyticsOrderStatus(o) !== 'Cancelled' &&
            isWithinRange(o.orderDate, mStart, mEnd)
        )
        .map((o) => String(o.clientEmail || '').toLowerCase())
    );
    monthlyRegistrationTrend.push({
      month: m,
      monthIndex: m,
      monthName: MONTH_NAMES_FULL[m - 1],
      label: MONTH_NAMES_SHORT[m - 1],
      shortName: MONTH_NAMES_SHORT[m - 1],
      newRegistrations: regCount,
      activeBuyers: activeBuyerEmails.size,
    });
  }

  const customerAnalytics = {
    newCustomersCount: currentMetrics.newCustomers,
    newCustomersList: currentMetrics.newCustomersList,
    customersWhoPlacedOrdersCount: orderingCustomers.length,
    orderingCustomersCount: orderingCustomers.length,
    returningCustomersCount: returningCustomers.length,
    totalLifetimeReturningCustomers: lifetimeReturningCustomers.length,
    totalRegisteredCustomers: allCustomerStats.length,
    highestPurchaseCustomers: topCustomers,
    topCustomers,
    monthlyRegistrationTrends: monthlyRegistrationTrend,
    monthlyRegistrationTrend,
  };

  // 8. Inventory Analytics (Live Snapshot vs Selected Period Historical Movements)
  const productMetrics = computeInventoryMetrics(products);
  const materialMetrics = computeMaterialInventoryMetrics(materials);
  const currentProductCatalogValue = products.reduce((sum, p) => {
    const stock = Math.max(0, Number(p.inStockCount ?? 0));
    const price = Math.max(0, Number(p.effectivePrice ?? p.price ?? 0));
    return sum + Math.round(stock * price);
  }, 0);

  const materialsRequiringRestock = [
    ...materialMetrics.outOfStockItems,
    ...materialMetrics.lowStockItems,
  ].map((m) => {
    const st = getMaterialStockStatus(m);
    return {
      ...m,
      materialId: m.materialId || m.code || 'MAT-01',
      category: m.materialType || 'Solid Timber',
      quantity: st.availableStock,
      minimumStock: st.minStockLevel,
      unit: m.unit || 'units',
      status: st.label,
    };
  });

  const lowStockProductsNormalized = (productMetrics.lowStockItems || []).map((p) => ({
    ...p,
    productId: p.productId || p.sku || 'LR-ITEM',
    stock: Math.max(0, Number(p.inStockCount ?? 0)),
    minStock: Math.max(0, Number(p.minStockLevel ?? 2)),
  }));

  const outOfStockProductsNormalized = (productMetrics.outOfStockItems || []).map((p) => ({
    ...p,
    productId: p.productId || p.sku || 'LR-ITEM',
    stock: Math.max(0, Number(p.inStockCount ?? 0)),
    minStock: Math.max(0, Number(p.minStockLevel ?? 2)),
  }));

  const periodInvTransactions = inventoryTransactions.filter((tx) =>
    isWithinRange(tx.dateTime || tx.date, range.startDate, range.endDate)
  );

  const consumedByMaterial = new Map();
  periodInvTransactions
    .filter((tx) => tx.transactionType === 'Stock Out')
    .forEach((tx) => {
      const mId = String(tx.materialId || tx.materialCode || tx.materialName || '');
      const existing = consumedByMaterial.get(mId) || {
        materialId: tx.materialId,
        materialCode: tx.materialCode || 'MAT-01',
        code: tx.materialCode || 'MAT-01',
        materialName: tx.materialName || 'Material',
        name: tx.materialName || 'Material',
        materialType: tx.materialType || 'Solid Timber',
        unit: tx.unit || 'units',
        quantityConsumed: 0,
        unitsConsumed: 0,
        unitCost: Number(tx.unitCost || 0),
        estimatedCost: 0,
        totalCost: 0,
        transactionCount: 0,
        transactionsCount: 0,
        lastReason: tx.reason || 'Workshop Production',
      };
      const qty = Math.max(0, Number(tx.quantity || 0));
      existing.quantityConsumed += qty;
      existing.unitsConsumed += qty;
      existing.estimatedCost += Math.round(qty * existing.unitCost);
      existing.totalCost = existing.estimatedCost;
      existing.transactionCount += 1;
      existing.transactionsCount = existing.transactionCount;
      if (tx.reason) existing.lastReason = tx.reason;
      consumedByMaterial.set(mId, existing);
    });

  // Also include order-level material consumption for orders placed in period that aren't already in Stock Out tx
  currentMetrics.periodOrders.forEach((ord) => {
    if (classifyAnalyticsOrderStatus(ord) === 'Cancelled') return;
    const ordNum = String(ord.orderNumber || ord.orderId || '');
    const alreadyLogged = periodInvTransactions.some(
      (tx) => tx.transactionType === 'Stock Out' && String(tx.reason || '').includes(ordNum)
    );
    if (alreadyLogged) return;
    const mId = String(ord.materialId || '');
    if (!mId) return;
    const matObj = materials.find((m) => m.id === mId) || null;
    const qtyUsed = Math.max(0, Number(ord.materialQtyUsed || 0));
    if (qtyUsed <= 0) return;
    const mCode = matObj?.materialId || matObj?.code || 'MAT-01';
    const mName = ord.materialName || matObj?.name || 'Workshop Material';
    const existing = consumedByMaterial.get(mId) || {
      materialId: mId,
      materialCode: mCode,
      code: mCode,
      materialName: mName,
      name: mName,
      materialType: matObj?.materialType || 'Solid Timber',
      unit: ord.materialUnit || matObj?.unit || 'units',
      quantityConsumed: 0,
      unitsConsumed: 0,
      unitCost: Number(matObj?.unitCost || 0),
      estimatedCost: 0,
      totalCost: 0,
      transactionCount: 0,
      transactionsCount: 0,
      lastReason: `Allocated to Order #${ordNum}`,
    };
    existing.quantityConsumed += qtyUsed;
    existing.unitsConsumed += qtyUsed;
    existing.estimatedCost += Math.round(qtyUsed * existing.unitCost);
    existing.totalCost = existing.estimatedCost;
    existing.transactionCount += 1;
    existing.transactionsCount = existing.transactionCount;
    existing.lastReason = `Allocated to Order #${ordNum}`;
    consumedByMaterial.set(mId, existing);
  });

  const materialsConsumedInPeriod = Array.from(consumedByMaterial.values()).sort(
    (a, b) => b.estimatedCost - a.estimatedCost || b.quantityConsumed - a.quantityConsumed
  );

  const totalMaterialUnitsConsumed = materialsConsumedInPeriod.reduce(
    (sum, m) => sum + m.quantityConsumed,
    0
  );
  const totalMaterialCostConsumed = materialsConsumedInPeriod.reduce(
    (sum, m) => sum + m.estimatedCost,
    0
  );

  const stockInTransactionsInPeriod = periodInvTransactions.filter(
    (tx) => tx.transactionType === 'Stock In'
  );
  const totalMaterialUnitsRestocked = stockInTransactionsInPeriod.reduce(
    (sum, tx) => sum + Math.max(0, Number(tx.quantity || 0)),
    0
  );
  const totalRestockSpend = stockInTransactionsInPeriod.reduce(
    (sum, tx) => sum + Math.max(0, Number(tx.purchaseCost || 0)),
    0
  );

  const availableProductsCount = products.filter((p) => Number(p?.inStockCount ?? 0) > 0).length;

  const inventoryAnalytics = {
    currentSnapshot: {
      totalAvailableProducts: availableProductsCount,
      totalCatalogProducts: products.length,
      totalAvailableUnits: productMetrics.totalStockAvailable,
      totalProductUnitsInStock: productMetrics.totalStockAvailable,
      lowStockProductsCount: productMetrics.lowStockCount,
      lowStockProducts: lowStockProductsNormalized,
      outOfStockProductsCount: productMetrics.outOfStockCount,
      outOfStockProducts: outOfStockProductsNormalized,
      totalMaterials: materials.length,
      materialsRequiringRestockingCount: materialsRequiringRestock.length,
      materialsRequiringRestockCount: materialsRequiringRestock.length,
      materialsRequiringRestocking: materialsRequiringRestock,
      materialsRequiringRestock,
      materialInventoryValue: materialMetrics.totalInventoryValue,
      currentMaterialInventoryValue: materialMetrics.totalInventoryValue,
      catalogInventoryValue: currentProductCatalogValue,
      currentProductCatalogValue,
      currentInventoryValue: materialMetrics.totalInventoryValue + currentProductCatalogValue,
    },
    periodMovements: {
      periodLabel: range.periodLabel,
      totalMovementsCount: periodInvTransactions.length,
      materialsConsumedInPeriod,
      totalUnitsConsumed: totalMaterialUnitsConsumed,
      totalMaterialUnitsConsumed,
      totalConsumedValue: totalMaterialCostConsumed,
      totalMaterialCostConsumed,
      stockInCount: stockInTransactionsInPeriod.length,
      totalUnitsRestocked: totalMaterialUnitsRestocked,
      totalMaterialUnitsRestocked,
      totalRestockedCost: Math.round(totalRestockSpend),
      totalRestockSpend: Math.round(totalRestockSpend),
      periodTransactions: periodInvTransactions,
    },
  };

  // 9. Voucher Analytics
  const normalizedVouchers = vouchers.map((v) => {
    const statusInfo = computeVoucherStatusInfo(v, effectiveNowMs);
    const issuedDate = parseManilaDateOnly(v.issuedAt || v.createdAt);
    const expiryDate = parseManilaDateOnly(v.expiresAt || v.expiryDate);
    const issuedDisp = formatVoucherIssuedDisplay(v);
    const expiryDisp = formatVoucherExpiryDisplay(v);
    return {
      ...v,
      voucherId: v.voucherId || generateOwnEntityId('voucher', v.code || v.id).codeId,
      productName: v.furnitureName || v.productName || 'Showroom Piece',
      discountAmount: Math.max(0, Number(v.discountAmount ?? v.discountValue ?? 0)),
      effectiveStatus: statusInfo.status,
      status: statusInfo.status,
      badgeClass: statusInfo.badgeClass,
      remainingLabel: statusInfo.remainingLabel,
      issuedDisplay: issuedDisp,
      expiryDisplay: expiryDisp,
      issuedAt: issuedDisp.full || v.createdAt || '—',
      expiresAt: expiryDisp.full || v.expiryDate || 'No expiry',
      issuedDate,
      expiryDate,
      inSelectedPeriod:
        isWithinRange(issuedDate, range.startDate, range.endDate) ||
        isWithinRange(v.redeemedAt, range.startDate, range.endDate) ||
        isWithinRange(v.revokedAt, range.startDate, range.endDate),
    };
  });

  const vouchersInPeriod = normalizedVouchers.filter((v) =>
    isWithinRange(v.issuedDate, range.startDate, range.endDate)
  );

  let activeVouchers = 0;
  let expiringSoonVouchers = 0;
  let redeemedVouchers = 0;
  let redeemedInPeriodCount = 0;
  let expiredVouchers = 0;
  let revokedVouchers = 0;
  let totalDiscountIssued = 0;
  let totalDiscountRedeemed = 0;
  let periodDiscountRedeemed = 0;

  normalizedVouchers.forEach((v) => {
    const amt = v.discountAmount;
    totalDiscountIssued += amt;
    if (v.effectiveStatus === 'Active') {
      activeVouchers += 1;
    } else if (v.effectiveStatus === 'Expiring Soon') {
      activeVouchers += 1;
      expiringSoonVouchers += 1;
    } else if (v.effectiveStatus === 'Used') {
      redeemedVouchers += 1;
      totalDiscountRedeemed += amt;
      if (isWithinRange(v.redeemedAt || v.issuedDate, range.startDate, range.endDate)) {
        redeemedInPeriodCount += 1;
        periodDiscountRedeemed += amt;
      }
    } else if (v.effectiveStatus === 'Expired') {
      expiredVouchers += 1;
    } else if (v.effectiveStatus === 'Revoked' || v.effectiveStatus === 'Cancelled') {
      revokedVouchers += 1;
    }
  });

  const orderVoucherDiscountsInPeriod = currentMetrics.totalDiscountsGiven;
  const effectiveDiscountRedeemed = Math.max(totalDiscountRedeemed, orderVoucherDiscountsInPeriod);
  const effectivePeriodDiscountRedeemed = Math.max(periodDiscountRedeemed, orderVoucherDiscountsInPeriod);
  const totalVouchersIssued = normalizedVouchers.length;
  const redemptionRate =
    totalVouchersIssued > 0
      ? Math.round((redeemedVouchers / totalVouchersIssued) * 1000) / 10
      : 0;
  const periodRedemptionRate =
    vouchersInPeriod.length > 0
      ? Math.round((redeemedInPeriodCount / vouchersInPeriod.length) * 1000) / 10
      : 0;

  const voucherAnalytics = {
    totalVouchersIssued,
    totalIssuedInPeriod: vouchersInPeriod.length,
    vouchersIssuedInSelectedPeriod: vouchersInPeriod.length,
    activeVouchers,
    expiringSoonVouchers,
    redeemedVouchers,
    redeemedInPeriodCount,
    expiredVouchers,
    revokedVouchers,
    totalDiscountIssued: Math.round(totalDiscountIssued),
    totalDiscountRedeemed: Math.round(effectiveDiscountRedeemed),
    periodDiscountRedeemed: Math.round(effectivePeriodDiscountRedeemed),
    redemptionRate,
    periodRedemptionRate,
    voucherBreakdown: normalizedVouchers,
    vouchers: normalizedVouchers,
  };

  const transactions = currentMetrics.periodOrders.map((ord) => {
    const statusBucket = classifyAnalyticsOrderStatus(ord);
    const historicalAmt = getHistoricalOrderAmount(ord);
    const paid = isOrderPaidOrDepositConfirmed(ord);
    const disc = Math.max(0, Number(ord.voucherDiscountAmount || 0));
    return {
      orderId: ord.orderId || ord.orderNumber,
      orderNumber: ord.orderNumber || ord.orderId,
      orderDate: ord.orderDate,
      clientName: ord.clientName,
      clientEmail: ord.clientEmail,
      furnitureName: ord.furnitureName,
      productId: ord.sku || ord.furnitureId,
      sku: ord.sku || ord.furnitureId,
      quantity: Math.max(1, Number(ord.quantity || 1)),
      totalAmount: historicalAmt,
      depositPaid: Math.max(0, Number(ord.depositPaid || 0)),
      discountAmount: disc,
      voucherDiscountAmount: disc,
      appliedVoucherCode: ord.appliedVoucherCode || null,
      normalizedStatus: statusBucket,
      statusBucket,
      stageLabel: ord.status,
      paymentStatus: ord.paymentStatus || 'Pending',
      realizedRevenue: paid ? historicalAmt : 0,
    };
  });

  return {
    businessName: 'Lillia and Rowena Furniture',
    generatedAtIso: new Date(effectiveNowMs).toISOString(),
    generatedAtManila: formatManilaDateTimeParts(new Date(effectiveNowMs).toISOString()).full,
    dateRange: range,
    period: range.current,
    previousPeriod: range.previous,
    summary,
    summaryCards,
    kpis: {
      totalRevenue: currentMetrics.totalRevenue,
      totalOrders: currentMetrics.totalOrders,
      completedOrders: currentMetrics.completedOrders,
      pendingOrders: currentMetrics.pendingOrders,
      cancelledOrders: currentMetrics.cancelledOrders,
      totalProductsSold: currentMetrics.totalProductsSold,
      newCustomers: currentMetrics.newCustomers,
      totalDiscountsGiven: currentMetrics.totalDiscountsGiven,
      averageOrderValue: currentMetrics.averageOrderValue,
    },
    salesAnalytics,
    bestSellingProducts,
    productAnalytics: bestSellingProducts,
    orderAnalytics,
    customerAnalytics,
    inventoryAnalytics,
    voucherAnalytics,
    transactions,
    periodOrders: transactions,
  };
}

export function buildAnalyticsCsvContent(report) {
  if (!report || typeof report !== 'object') return '';
  const esc = (val) => {
    const str = String(val ?? '').replace(/\r?\n/g, ' ');
    if (str.includes(',') || str.includes('"')) {
      return `"${str.replace(/"/g, '""')}"`;
    }
    return str;
  };

  const lines = [];
  lines.push(['Business Name', esc(report.businessName || 'Lillia and Rowena Furniture')].join(','));
  lines.push(['Report Period', esc(report.dateRange?.periodLabel || report.period?.label || '')].join(','));
  lines.push([
    'Date Range',
    esc(`${report.dateRange?.startDate || report.period?.startDate || ''} to ${report.dateRange?.endDate || report.period?.endDate || ''}`),
  ].join(','));
  lines.push(['Generated Date (PHT)', esc(report.generatedAtManila || '')].join(','));
  lines.push('');

  lines.push('MONTHLY BUSINESS SUMMARY KPIs');
  lines.push(['Metric', 'Selected Period Value', 'Previous Period Value', 'Change (%)'].join(','));
  (report.summaryCards || []).forEach((card) => {
    lines.push(
      [
        esc(card.label),
        esc(card.formattedValue),
        esc(card.formattedPreviousValue),
        esc(card.comparison?.formatted || card.formattedChange || '0.0%'),
      ].join(',')
    );
  });
  lines.push('');

  lines.push('BEST-SELLING PRODUCTS');
  lines.push(
    ['Rank', 'Product ID', 'Product Name', 'Category', 'Quantity Sold', 'Total Revenue ($)', 'Stock Availability'].join(',')
  );
  (report.bestSellingProducts?.allRankedProducts || report.productAnalytics?.allRankedProducts || []).forEach((p) => {
    lines.push(
      [
        esc(p.rank),
        esc(p.productId),
        esc(p.name),
        esc(p.categoryName),
        esc(p.quantitySold),
        esc(`$${p.totalRevenue}`),
        esc(`${p.currentStock} (${p.stockStatusLabel})`),
      ].join(',')
    );
  });
  lines.push('');

  lines.push('VOUCHER ANALYTICS SUMMARY');
  lines.push(
    [
      'Total Issued',
      'Active',
      'Redeemed',
      'Expired',
      'Revoked',
      'Total Discount Redeemed ($)',
      'Redemption Rate (%)',
    ].join(',')
  );
  const va = report.voucherAnalytics || {};
  lines.push(
    [
      esc(va.totalVouchersIssued ?? 0),
      esc(va.activeVouchers ?? 0),
      esc(va.redeemedVouchers ?? 0),
      esc(va.expiredVouchers ?? 0),
      esc(va.revokedVouchers ?? 0),
      esc(`$${va.totalDiscountRedeemed ?? 0}`),
      esc(`${va.redemptionRate ?? 0}%`),
    ].join(',')
  );
  lines.push('');

  lines.push('TRANSACTION DETAILS');
  lines.push(
    [
      'Order ID',
      'Order Date',
      'Customer',
      'Customer Email',
      'Product',
      'Product ID',
      'Qty',
      'Order Total ($)',
      'Discount ($)',
      'Status',
    ].join(',')
  );
  (report.transactions || report.periodOrders || []).forEach((ord) => {
    lines.push(
      [
        esc(ord.orderId),
        esc(ord.orderDate),
        esc(ord.clientName),
        esc(ord.clientEmail),
        esc(ord.furnitureName),
        esc(ord.sku),
        esc(ord.quantity),
        esc(`$${ord.totalAmount}`),
        esc(`$${ord.voucherDiscountAmount}`),
        esc(ord.statusBucket),
      ].join(',')
    );
  });

  return lines.join('\r\n');
}

export function buildAnalyticsPdfBytes(report) {
  const safeAscii = (str) =>
    String(str ?? '')
      .replace(/₱/g, '$')
      .replace(/[—–]/g, '-')
      .replace(/×/g, 'x')
      .replace(/[^\x20-\x7E]/g, '')
      .replace(/([()\\])/g, '\\$1');

  const lines = [];
  lines.push(`LILLIA AND ROWENA FURNITURE - MONTHLY ANALYTICS & BUSINESS REPORT`);
  lines.push(`Reporting Period: ${report?.dateRange?.periodLabel || report?.period?.label || 'Current Period'} (${report?.dateRange?.startDate || report?.period?.startDate || ''} to ${report?.dateRange?.endDate || report?.period?.endDate || ''})`);
  lines.push(`Generated On: ${report?.generatedAtManila || ''} (Asia/Manila)`);
  lines.push(`--------------------------------------------------------------------------------`);
  lines.push(`1. EXECUTIVE BUSINESS SUMMARY`);
  (report?.summaryCards || []).forEach((card) => {
    lines.push(
      `   - ${card.label}: ${card.formattedValue} (Prior: ${card.formattedPreviousValue} | ${card.comparison?.formatted || card.formattedChange || '0.0%'})`
    );
  });
  lines.push(`--------------------------------------------------------------------------------`);
  lines.push(`2. TOP 5 BEST-SELLING PRODUCTS`);
  (report?.bestSellingProducts?.topFive || report?.productAnalytics?.topFiveProducts || []).forEach((p) => {
    lines.push(
      `   #${p.rank} [${p.productId}] ${p.name} (${p.categoryName}) - Sold: ${p.quantitySold} pcs | Revenue: $${p.totalRevenue.toLocaleString('en-US')} | Stock: ${p.currentStock}`
    );
  });
  lines.push(`--------------------------------------------------------------------------------`);
  lines.push(`3. ORDER STATUS DISTRIBUTION`);
  (report?.orderAnalytics?.distribution || report?.orderAnalytics?.statusDistribution || []).forEach((s) => {
    lines.push(
      `   - ${s.label}: ${s.count} order(s) (${s.sharePercent}%) - Total: $${s.totalAmount.toLocaleString('en-US')}`
    );
  });
  lines.push(`--------------------------------------------------------------------------------`);
  const va = report?.voucherAnalytics || {};
  lines.push(
    `4. VOUCHER ANALYTICS: Issued: ${va.totalVouchersIssued || 0} | Active: ${va.activeVouchers || 0} | Redeemed: ${va.redeemedVouchers || 0} | Expired: ${va.expiredVouchers || 0} | Revoked: ${va.revokedVouchers || 0}`
  );
  lines.push(
    `   Total Discount Redeemed: $${(va.totalDiscountRedeemed || 0).toLocaleString('en-US')} | Redemption Rate: ${va.redemptionRate || 0}%`
  );
  lines.push(`--------------------------------------------------------------------------------`);
  const inv = report?.inventoryAnalytics?.currentSnapshot || {};
  const mov = report?.inventoryAnalytics?.periodMovements || {};
  lines.push(
    `5. INVENTORY ANALYTICS: Available Products: ${inv.totalAvailableProducts || 0} | Low Stock: ${inv.lowStockProductsCount || 0} | Needs Restock: ${inv.materialsRequiringRestockingCount || 0}`
  );
  lines.push(
    `   Current Combined Inventory Value: $${(inv.currentInventoryValue || 0).toLocaleString('en-US')} | Period Material Consumed Cost: $${(mov.totalMaterialCostConsumed || 0).toLocaleString('en-US')}`
  );
  lines.push(`--------------------------------------------------------------------------------`);
  lines.push(`6. PERIOD TRANSACTIONS (${(report?.transactions || report?.periodOrders || []).length} record(s))`);
  (report?.transactions || report?.periodOrders || []).slice(0, 12).forEach((ord) => {
    lines.push(
      `   ${ord.orderId} | ${ord.orderDate} | ${ord.clientName} | ${ord.sku} (x${ord.quantity}) | $${ord.totalAmount.toLocaleString('en-US')} | ${ord.statusBucket}`
    );
  });

  const textOps = ['BT', '/F1 9 Tf', '40 755 Td', '13 TL'];
  lines.forEach((line, idx) => {
    if (idx === 0) {
      textOps.push(`(${safeAscii(line)}) Tj`);
    } else {
      textOps.push(`T* (${safeAscii(line)}) Tj`);
    }
  });
  textOps.push('ET');
  const streamBody = textOps.join('\n');

  const objects = [
    '1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n',
    '2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n',
    '3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n',
    '4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n',
    `5 0 obj\n<< /Length ${streamBody.length} >>\nstream\n${streamBody}\nendstream\nendobj\n`,
  ];

  let pdf = '%PDF-1.4\n';
  const offsets = [0];
  objects.forEach((obj) => {
    offsets.push(pdf.length);
    pdf += obj;
  });
  const xrefStart = pdf.length;
  pdf += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`;
  for (let i = 1; i < offsets.length; i += 1) {
    pdf += `${String(offsets[i]).padStart(10, '0')} 00000 n \n`;
  }
  pdf += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xrefStart}\n%%EOF`;

  return new TextEncoder().encode(pdf);
}




