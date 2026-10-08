import { ModelMeta, PlanI18n, PricingPlan } from '../types';
import { api } from './client';

/**
 * 站点及套餐配置形状
 */
export interface AppConfig {
  site_name: string;
  description: string;
  default_models: string[];
  default_provider: string;
  currency?: string;
  currency_symbol?: string;
  web_search_enabled?: boolean;
  plans: Record<string, {
    name?: string;
    price_monthly?: number;
    price_yearly?: number;
    daily_asks?: number;
    tokens_quota?: number;
    enabled?: boolean;
    features?: string[];
    type?: string;
    price?: number;
    credits?: number;
    bonus_percent?: number;
    validity_months?: number;
    gift_pro_months?: number;
    gift_monthly_credits?: number;
    name_i18n?: PlanI18n;
    features_i18n?: Record<string, string[] | undefined>;
  }>;
}

/** 前台 locale → 套餐多语言键 */
export type PlanLocale = 'zh' | 'zh-TW' | 'en';

/**
 * 按语言取套餐名称:后台多语言配置优先,回落默认名称
 */
export function planNameOf(plan: PricingPlan, locale: PlanLocale): string {
  return plan.nameI18n?.[locale]?.trim() || plan.name;
}

/**
 * 按语言取套餐权益行:后台多语言配置优先,回落默认权益
 */
export function planFeaturesOf(plan: PricingPlan, locale: PlanLocale): string[] {
  const lines = plan.featuresI18n?.[locale];
  if (Array.isArray(lines) && lines.length > 0) return lines;
  return plan.features;
}

/** 常用货币符号映射(与后端 SettingService 保持一致) */
const CURRENCY_SYMBOLS: Record<string, string> = {
  USD: '$', EUR: '€', GBP: '£', CNY: '¥', JPY: '¥',
  HKD: 'HK$', TWD: 'NT$', SGD: 'S$', AUD: 'A$', CAD: 'C$',
  NZD: 'NZ$', CHF: 'CHF ', KRW: '₩', INR: '₹', THB: '฿',
};

/**
 * 用后台套餐配置覆盖本地默认套餐定义(价格/权益/上下架/一次性包参数/多语言)
 * 后台未配置的套餐保留本地默认
 */
export function applyRemotePlans(base: PricingPlan[], remote: AppConfig['plans']): PricingPlan[] {
  return base.map((plan) => {
    const r = remote[plan.id];
    if (!r) return plan;
    const merged: PricingPlan = {
      ...plan,
      name: r.name || plan.name,
      priceMonthly: typeof r.price_monthly === 'number' ? r.price_monthly : plan.priceMonthly,
      priceYearly: typeof r.price_yearly === 'number' ? r.price_yearly : plan.priceYearly,
      enabled: plan.id === 'free' ? true : r.enabled !== false,
      features: Array.isArray(r.features) && r.features.length > 0 ? r.features : plan.features,
    };
    // 多语言名称/权益(后台保存了才覆盖,缺失语言由 planNameOf/planFeaturesOf 回落)
    if (r.name_i18n) merged.nameI18n = r.name_i18n;
    if (r.features_i18n) merged.featuresI18n = r.features_i18n;
    // 一次性包参数
    if (r.type === 'one_time') {
      merged.type = 'one_time';
      merged.oneTime = {
        price: typeof r.price === 'number' ? r.price : (plan.oneTime?.price ?? 0),
        credits: typeof r.credits === 'number' ? r.credits : (plan.oneTime?.credits ?? 0),
        bonusPercent: typeof r.bonus_percent === 'number' ? r.bonus_percent : (plan.oneTime?.bonusPercent ?? 0),
        validityMonths: typeof r.validity_months === 'number' ? r.validity_months : (plan.oneTime?.validityMonths ?? 0),
        giftProMonths: typeof r.gift_pro_months === 'number' ? r.gift_pro_months : (plan.oneTime?.giftProMonths ?? 0),
        giftMonthlyCredits: typeof r.gift_monthly_credits === 'number' ? r.gift_monthly_credits : (plan.oneTime?.giftMonthlyCredits ?? 0),
      };
      if (typeof r.price === 'number') {
        merged.priceMonthly = r.price;
        merged.priceYearly = r.price;
      }
    }
    return merged;
  });
}

/**
 * 按币种码取展示符号;未收录币种回退为 "CODE ",空值回退为 $
 */
export function currencySymbolOf(code?: string): string {
  const c = (code || '').toUpperCase();
  if (c && CURRENCY_SYMBOLS[c]) return CURRENCY_SYMBOLS[c];
  return c ? `${c} ` : '$';
}

/**
 * 服务端模型条目(后端 /tiwen/models 返回形状)
 */
export interface ServerModel {
  id: string;
  name: string;
  iconColor?: string;
  badge?: string;
  desc?: string;
  cat?: string;
  provider?: string;
}

/** 厂商色板:按模型 id 前缀匹配纯色头像底色 */
const VENDOR_PALETTE: { prefix: string; color: string; bgColor: string; abbr: string }[] = [
  { prefix: 'deepseek', color: '#1d4ed8', bgColor: '#eff4ff', abbr: 'DS' },
  { prefix: 'gpt', color: '#0f766e', bgColor: '#ecfdff', abbr: 'G' },
  { prefix: 'gemini', color: '#0369a1', bgColor: '#eff6ff', abbr: 'Ge' },
  { prefix: 'claude', color: '#b45309', bgColor: '#fdf6ec', abbr: 'CL' },
  { prefix: 'grok', color: '#3f3f46', bgColor: '#f4f4f5', abbr: 'GK' },
  { prefix: 'glm', color: '#047857', bgColor: '#edfbf3', abbr: 'GL' },
  { prefix: 'kimi', color: '#be123c', bgColor: '#fdf0f3', abbr: 'KI' },
  { prefix: 'MiniMax', color: '#a21caf', bgColor: '#faf0fc', abbr: 'MM' },
];

const FALLBACK_META = { color: '#475569', bgColor: '#f1f5f9', abbr: 'AI' };

function paletteOf(modelId: string) {
  const lower = modelId.toLowerCase();
  const hit = VENDOR_PALETTE.find(v => lower.startsWith(v.prefix.toLowerCase()));
  return hit || FALLBACK_META;
}

/** 服务端模型条目 → 前端展示元数据(头像缩写/纯色/浅底) */
export function toModelMeta(m: ServerModel): ModelMeta {
  const p = paletteOf(m.id);
  return {
    id: m.id,
    name: m.name,
    cat: m.cat,
    badge: m.badge,
    desc: m.desc,
    avatarText: p.abbr,
    color: p.color,
    bgColor: p.bgColor,
    provider: m.provider,
  };
}

/* ============ 请求单例去重与内存缓存 ============ */
let cachedConfig: AppConfig | null = null;
let inFlightConfigPromise: Promise<AppConfig | null> | null = null;

let cachedModels: ModelMeta[] | null = null;
let inFlightModelsPromise: Promise<ModelMeta[]> | null = null;

/**
 * 获取站点与套餐配置（多组件并发调用自动去重 + 内存缓存）
 */
export async function fetchAppConfig(forceRefresh = false): Promise<AppConfig | null> {
  if (cachedConfig && !forceRefresh) {
    return cachedConfig;
  }
  if (inFlightConfigPromise) {
    return inFlightConfigPromise;
  }

  inFlightConfigPromise = (async () => {
    try {
      const res = await api<AppConfig>('/tiwen/config');
      if (res.ok && res.data) {
        cachedConfig = res.data;
        return res.data;
      }
      return null;
    } finally {
      inFlightConfigPromise = null;
    }
  })();

  return inFlightConfigPromise;
}

/**
 * 获取模型列表（多组件并发调用自动去重 + 内存缓存）
 */
export async function fetchModels(forceRefresh = false): Promise<ModelMeta[]> {
  if (cachedModels && !forceRefresh) {
    return cachedModels;
  }
  if (inFlightModelsPromise) {
    return inFlightModelsPromise;
  }

  inFlightModelsPromise = (async () => {
    try {
      const res = await api<ServerModel[]>('/tiwen/models');
      if (res.ok && Array.isArray(res.data)) {
        cachedModels = res.data.map(toModelMeta);
        return cachedModels;
      }
      return [];
    } finally {
      inFlightModelsPromise = null;
    }
  })();

  return inFlightModelsPromise;
}

/**
 * 清除配置和模型缓存（管理员修改配置后调用）
 */
export function invalidateAppConfig() {
  cachedConfig = null;
  cachedModels = null;
}

/** 拉取模型清单与站点配置 */
export async function fetchModelSetup(forceRefresh = false): Promise<{ models: ModelMeta[]; defaultModels: string[] }> {
  const [models, config] = await Promise.all([
    fetchModels(forceRefresh),
    fetchAppConfig(forceRefresh),
  ]);

  const defaults: string[] = (config?.default_models || []).filter((id: string) =>
    models.some(m => m.id === id)
  );

  return {
    models,
    defaultModels: defaults.length ? defaults.slice(0, 3) : models.slice(0, 3).map(m => m.id),
  };
}

/** 本地兜底配色(模型清单请求失败时仍可渲染卡片) */
export function localMetaOf(modelId: string, modelName?: string): ModelMeta {
  const p = paletteOf(modelId);
  return {
    id: modelId,
    name: modelName || modelId,
    avatarText: p.abbr,
    color: p.color,
    bgColor: p.bgColor,
  };
}
