export type PlanId = 'free' | 'pro' | 'team' | 'flagship';

/** 套餐类型:subscription 订阅(月/年付) | one_time 一次性包(积分制) */
export type PlanType = 'subscription' | 'one_time';

/** 套餐名称/权益多语言(缺失语言由消费方回落默认文案) */
export interface PlanI18n {
  zh?: string;
  'zh-TW'?: string;
  en?: string;
}

/** 一次性包售卖参数 */
export interface OneTimePlanMeta {
  /** 一次性售价 */
  price: number;
  /** 到账基础积分 */
  credits: number;
  /** 加赠比例(%) */
  bonusPercent: number;
  /** 积分有效期(月) */
  validityMonths: number;
  /** 赠送 Pro 会员(月) */
  giftProMonths: number;
  /** 会员期内每月加赠积分 */
  giftMonthlyCredits: number;
}

export interface ModelMeta {
  id: string;
  name: string;
  cat?: string;
  badge?: string;
  desc?: string;
  avatarText: string;
  color: string;
  bgColor: string;
  provider?: string;
}

export interface ModelCardResponse {
  id: string;
  cardId?: number;
  modelKey: string;
  modelName?: string;
  meta: string; // e.g. "1,860 tok · 8.2s"
  tokenCount: number;
  durationSec: number;
  body: string;
  checked: boolean;
  isStreaming?: boolean;
  failed?: boolean;
  failMessage?: string;
}

export interface ConversationRound {
  id: string;
  roundId?: number;
  roundIndex: number;
  question: string;
  tag: '主问题' | '追问';
  pending?: boolean;
  models: ModelCardResponse[];
  createdAt: number;
}

export interface SessionData {
  id: string;
  sessionId?: number;
  title: string;
  summary: string;
  totalTokens: number;
  rounds: ConversationRound[];
  activeRoundIndex: number;
  editorDraft: string;
  updatedAt: number;
}

export interface UserProfile {
  id: string;
  uid?: number;
  email: string;
  name: string;
  avatar?: string;
  plan: PlanId;
  planInterval?: string;
  subscriptionStatus?: string;
  currentPeriodEnd?: number;
  stripeCustomerId?: string;
  /** 积分余额(一次性包到账,含加赠),未购买过为 0 */
  credits?: {
    balance: number;
    expiresAt: string;
  };
  tokenQuota: {
    total: number;
    used: number;
    resetDate: string;
  };
  dailyAsks?: {
    used: number;
    limit: number;
    date: string;
  };
  createdAt: string;
}

export interface PricingPlan {
  id: PlanId;
  name: string;
  tagline: string;
  priceMonthly: number;
  priceYearly: number;
  popular?: boolean;
  badge?: string;
  features: string[];
  /** 后台可下架 pro/team/flagship;free 恒为 true */
  enabled?: boolean;
  /** 套餐类型,默认订阅 */
  type?: PlanType;
  /** 一次性包售卖参数(type=one_time 时有效) */
  oneTime?: OneTimePlanMeta;
  /** 多语言名称(zh/zh-TW/en),缺失语言回落 name */
  nameI18n?: PlanI18n;
  /** 多语言权益行,缺失语言回落 features */
  featuresI18n?: Record<keyof PlanI18n, string[] | undefined>;
}

export interface UsageStats {
  totalTokens: number;
  sessionCount: number;
  roundCount: number;
  cardCount: number;
  models: {
    modelId: string;
    modelName: string;
    tokens: number;
    asks: number;
    durationSec: number;
    percent: number;
  }[];
  daily: { date: string[]; tokens: number[] };
  invoices: {
    id: string;
    title: string;
    status: string;
    failed: boolean;
    amount: number;
    currency: string;
    date: string;
  }[];
}
