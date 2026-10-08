import React, { useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useSession } from '../../context/SessionContext';
import { useLanguage } from '../../context/LanguageContext';
import { History, MessageSquareText, ArrowRight } from 'lucide-react';

interface RecentSessionsProps {
  /** 显示条数,默认 5 */
  limit?: number;
  /** 排除的会话 id(如当前正在查看的会话) */
  excludeId?: string;
  /** 紧凑模式(工作台侧栏) */
  dense?: boolean;
}

/** 相对时间:刚刚 / n 分钟前 / n 小时前 / n 天前 / 日期 */
function formatRelative(ts: number, locale: string): string {
  if (!ts) return '-';
  const diff = Date.now() - ts;
  if (diff < 60 * 1000) return locale === 'zh' ? '刚刚' : locale === 'zh-TW' ? '剛剛' : 'just now';
  if (diff < 60 * 60 * 1000)
    return Math.floor(diff / 60000) + (locale === 'zh' ? ' 分钟前' : locale === 'zh-TW' ? ' 分鐘前' : 'm ago');
  if (diff < 24 * 60 * 60 * 1000)
    return Math.floor(diff / 3600000) + (locale === 'zh' ? ' 小时前' : locale === 'zh-TW' ? ' 小時前' : 'h ago');
  if (diff < 7 * 24 * 60 * 60 * 1000)
    return Math.floor(diff / 86400000) + (locale === 'zh' ? ' 天前' : locale === 'zh-TW' ? ' 天前' : 'd ago');
  const d = new Date(ts);
  return isNaN(d.getTime()) ? '-' : d.toLocaleDateString();
}

/**
 * 近期对话列表(按更新时间倒序,取前 N 条)
 * 数据来自全局 SessionContext,登录态自动加载,无需额外请求
 */
export const RecentSessions: React.FC<RecentSessionsProps> = ({ limit = 5, excludeId, dense = false }) => {
  const navigate = useNavigate();
  const { sessions } = useSession();
  const { t, locale } = useLanguage();

  const recent = useMemo(
    () =>
      sessions
        .filter(s => s.id !== excludeId)
        .slice()
        .sort((a, b) => (b.updatedAt || 0) - (a.updatedAt || 0))
        .slice(0, limit),
    [sessions, excludeId, limit]
  );

  if (recent.length === 0) return null;

  return (
    <section className="space-y-2.5" aria-label={t.workbench.recentTitle}>
      <div className="flex items-center justify-between px-1">
        <h2 className={`font-extrabold text-[#1b2230] flex items-center gap-1.5 ${dense ? 'text-xs' : 'text-sm sm:text-base'}`}>
          <History className="w-4 h-4 text-brand" />
          <span>{t.workbench.recentTitle}</span>
          <span className="text-[10px] font-mono text-[#94a3b8]">{recent.length}</span>
        </h2>
        <button
          onClick={() => navigate('/profile/history')}
          className="text-xs text-[#65718a] hover:text-brand font-medium inline-flex items-center gap-0.5 transition-colors"
        >
          <span>{t.workbench.recentViewAll}</span>
          <ArrowRight className="w-3 h-3" />
        </button>
      </div>

      <div className="bg-white rounded-2xl border border-[#e7ebf3] shadow-subtle divide-y divide-[#f1f5f9] overflow-hidden">
        {recent.map(s => (
          <button
            key={s.id}
            onClick={() => navigate(`/chat/${s.id}`)}
            className={`w-full text-left hover:bg-[#f8faff] transition-colors flex items-center gap-2.5 group min-w-0 ${
              dense ? 'px-3.5 py-2.5' : 'px-3.5 sm:px-5 py-3 sm:py-3.5'
            }`}
          >
            <span className="w-7 h-7 rounded-lg bg-brand-subtle text-brand flex items-center justify-center shrink-0">
              <MessageSquareText className="w-3.5 h-3.5" />
            </span>
            <span className="min-w-0 flex-1">
              <span className={`block font-semibold text-[#1b2230] group-hover:text-brand transition-colors truncate ${dense ? 'text-xs' : 'text-xs sm:text-sm'}`}>
                {s.title}
              </span>
              <span className="block text-[10px] sm:text-[11px] text-[#8c97af] mt-px">
                {t.workbench.recentRounds.replace('{n}', String(s.rounds?.length || 0))}
              </span>
            </span>
            <span className="text-[10px] sm:text-[11px] font-mono text-[#8c97af] shrink-0">
              {formatRelative(s.updatedAt, locale)}
            </span>
          </button>
        ))}
      </div>
    </section>
  );
};
