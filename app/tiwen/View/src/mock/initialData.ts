import { PricingPlan } from '../types';

export const PRICING_PLANS: PricingPlan[] = [
  {
    id: 'free',
    name: 'Free 基础版',
    tagline: '适合个人快速体验多模型对比',
    priceMonthly: 0,
    priceYearly: 0,
    badge: '开箱即用',
    features: [
      '每日 4 次提问额度 · 按并发模型数计(4 模型一次 = 4 次)',
      '任选 2 个基础模型并发对比',
      '基础单轮提问与对比',
      '基础 Markdown 格式导出',
      '保留最近会话历史'
    ]
  },
  {
    id: 'pro',
    name: 'Pro 专业版',
    tagline: '深度研究员、分析师与创作者的利器',
    priceMonthly: 19.9,
    priceYearly: 199,
    popular: true,
    badge: '最受欢迎',
    features: [
      '每月 1,000,000 Token 高速并发配额',
      '18 个顶尖模型全解锁(GPT / Claude / Gemini / DeepSeek / Kimi...)',
      '最多 6 模型同时并发深度对比',
      '无限制多轮深度追问与上下文携带',
      '⚡ 智能交叉分析综合与一键提炼',
      '四合一导出 (MD / PDF / HTML / Word)',
      '会话历史永久保存与搜索归档'
    ]
  },
  {
    id: 'team',
    name: 'Team 团队版',
    tagline: '适合投研机构、智库与企业协同',
    priceMonthly: 49,
    priceYearly: 490,
    badge: '团队协同',
    features: [
      '包含 5 个独立团队席位 (可扩容)',
      '每月 5,000,000 共享 Token 专属算力池',
      '支持接入企业私有 API Key 与专有模型',
      '团队共享研报工作台与协作草稿库',
      '统一 Stripe 团队合并开票与发票管理',
      '专属客户成功专家与 SLA 响应支持'
    ]
  },
  {
    id: 'flagship',
    name: '旗舰包',
    tagline: '大额积分一次充，算力无忧用一年',
    priceMonthly: 799,
    priceYearly: 799,
    badge: '一次性包',
    type: 'one_time',
    oneTime: {
      price: 799,
      credits: 999,
      bonusPercent: 25,
      validityMonths: 24,
      giftProMonths: 12,
      giftMonthlyCredits: 1000
    },
    nameI18n: { zh: '旗舰包', 'zh-TW': '旗艦包', en: 'Flagship' },
    features: [
      '到账 $999 积分 (+25% 赠送)',
      '送 12 个月 Pro 会员: 每月再领 1,000 积分池',
      '无限存档 · 导出 · 标注 · 评论 (会员期内)',
      '全部旗舰模型按量可用 (≈ 国产旗舰 15 亿+ tokens)',
      '优先队列 + 专属客服',
      '— 积分不退，可与订阅叠加使用'
    ],
    featuresI18n: {
      zh: [
        '到账 $999 积分 (+25% 赠送)',
        '送 12 个月 Pro 会员: 每月再领 1,000 积分池',
        '无限存档 · 导出 · 标注 · 评论 (会员期内)',
        '全部旗舰模型按量可用 (≈ 国产旗舰 15 亿+ tokens)',
        '优先队列 + 专属客服',
        '— 积分不退，可与订阅叠加使用'
      ],
      'zh-TW': [
        '到帳 $999 點數 (+25% 贈送)',
        '送 12 個月 Pro 會員: 每月再領 1,000 點數池',
        '無限存檔 · 導出 · 標註 · 評論 (會員期內)',
        '全部旗艦模型按量可用 (≈ 國產旗艦 15 億+ tokens)',
        '優先隊列 + 專屬客服',
        '— 點數不退，可與訂閱疊加使用'
      ],
      en: [
        '$999 credits on arrival (+25% bonus)',
        '12 months of Pro included: claim 1,000 credits pool monthly',
        'Unlimited archive · export · annotate · comments (during membership)',
        'All flagship models available by usage (≈ 1.5B+ tokens)',
        'Priority queue + dedicated support',
        '— Non-refundable, stacks with subscriptions'
      ]
    }
  }
];
