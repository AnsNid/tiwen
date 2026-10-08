import React, { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useSession } from '../context/SessionContext';
import { useAuth } from '../context/AuthContext';
import { useLanguage } from '../context/LanguageContext';
import { exportMarkdown } from '../utils/exportDoc';
import { 
  History, 
  Search, 
  Plus, 
  MessageSquare, 
  Trash2, 
  Download, 
  ArrowRight, 
  Edit3, 
  Calendar
} from 'lucide-react';

export const ProfileHistoryPage: React.FC = () => {
  const navigate = useNavigate();
  const { isAuthenticated, openAuthModal } = useAuth();
  const { sessions, deleteSession, renameSession, createSession, showToast, getModelMeta } = useSession();
  const { t, locale } = useLanguage();
  const [searchKeyword, setSearchKeyword] = useState('');
  const [editingId, setEditingId] = useState<string | null>(null);
  const [editTitle, setEditTitle] = useState('');

  if (!isAuthenticated) {
    return (
      <div className="max-w-md mx-auto my-16 p-8 bg-white rounded-3xl border border-[#e2e8f0] text-center shadow-lg space-y-4">
        <div className="w-12 h-12 rounded-2xl bg-brand-subtle text-brand flex items-center justify-center mx-auto">
          <History className="w-6 h-6" />
        </div>
        <h2 className="text-xl font-bold text-[#0f172a]">{t.profile.loginRequired}</h2>
        <p className="text-xs text-[#64748b]">{t.history.emptySub}</p>
        <button
          onClick={() => openAuthModal(t.profile.loginRequiredSub)}
          className="w-full py-2.5 rounded-xl bg-brand text-white font-bold text-xs hover:bg-brand-hover transition-colors shadow-xs"
        >
          {t.profile.loginBtn}
        </button>
      </div>
    );
  }

  const filteredSessions = sessions.filter(
    s =>
      s.title.toLowerCase().includes(searchKeyword.toLowerCase()) ||
      s.summary.toLowerCase().includes(searchKeyword.toLowerCase())
  );

  const handleStartRename = (id: string, currentTitle: string) => {
    setEditingId(id);
    setEditTitle(currentTitle);
  };

  const handleSaveRename = (id: string) => {
    if (editTitle.trim()) {
      renameSession(id, editTitle.trim());
    }
    setEditingId(null);
  };

  const handleExportFullSession = (s: typeof sessions[0]) => {
    let md = `# ${s.title}\n\n**${
      locale === 'zh' ? '主题概要' : locale === 'zh-TW' ? '主題概要' : 'Topic Summary'
    }**：${s.summary}\n**${
      locale === 'zh' ? '总轮数' : locale === 'zh-TW' ? '總輪數' : 'Total Rounds'
    }**：${s.rounds.length}\n**Total Tokens**：${s.totalTokens} tok\n\n`;

    s.rounds.forEach((r, idx) => {
      md += `## Round ${idx + 1} · ${r.tag}：${r.question}\n\n`;
      r.models.forEach(m => {
        const meta = getModelMeta(m.modelKey, m.modelName);
        md += `### Model: ${m.modelName || meta.name} (${m.meta})\n${m.body}\n\n`;
      });
      md += `---\n\n`;
    });

    if (s.editorDraft) {
      md += `## ${
        locale === 'zh' ? '研报草稿区沉淀成果' : locale === 'zh-TW' ? '研報草稿區沉澱成果' : 'Draft & Synthesized Report'
      }\n\n${s.editorDraft}\n`;
    }

    exportMarkdown(md, `${s.title}-archive.md`);
    showToast('Exported full session archive (.md)');
  };

  const handleCreateNew = async () => {
    const newId = await createSession(
      locale === 'zh' ? '新多模型研报主题' : locale === 'zh-TW' ? '新多模型研報主題' : 'New Research Topic'
    );
    if (newId) {
      navigate(`/chat/${newId}`);
    }
  };

  return (
    <div className="max-w-[1400px] mx-auto px-4 sm:px-6 py-8 space-y-8">
      {/* Subnav Tabs */}
      <div className="flex items-center gap-3 border-b border-[#e2e8f0] pb-3">
        <Link
          to="/profile"
          className="px-3.5 py-1.5 rounded-lg text-xs font-semibold text-[#64748b] hover:text-[#0f172a] hover:bg-[#f1f5f9] transition-colors"
        >
          {t.profile.tabOverview}
        </Link>
        <Link
          to="/profile/history"
          className="px-3.5 py-1.5 rounded-lg text-xs font-bold bg-brand text-white shadow-xs"
        >
          {t.profile.tabHistory}
        </Link>
      </div>

      {/* Action Header & Search */}
      <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-4">
        <div className="relative flex-1 max-w-md">
          <Search className="w-4 h-4 text-[#8c97af] absolute left-3.5 top-1/2 -translate-y-1/2" />
          <input
            type="text"
            value={searchKeyword}
            onChange={e => setSearchKeyword(e.target.value)}
            placeholder={t.history.searchPlaceholder}
            className="w-full pl-9 pr-3.5 py-2.5 rounded-xl border border-[#cbd5e1] text-xs sm:text-sm bg-white focus:outline-none focus:border-brand focus:ring-3 focus:ring-brand-subtle transition-all"
          />
        </div>

        <button
          onClick={handleCreateNew}
          className="py-2.5 px-4 rounded-xl bg-brand text-white font-bold text-xs hover:bg-brand-hover flex items-center justify-center gap-1.5 shadow-xs transition-colors"
        >
          <Plus className="w-3.5 h-3.5" />
          <span>{t.history.newSession}</span>
        </button>
      </div>

      {/* Session Cards List */}
      {filteredSessions.length === 0 ? (
        <div className="bg-white rounded-3xl p-12 border border-[#e2e8f0] text-center space-y-3">
          <MessageSquare className="w-10 h-10 text-[#cbd5e1] mx-auto" />
          <h3 className="font-bold text-sm text-[#0f172a]">{t.history.emptyTitle}</h3>
          <p className="text-xs text-[#64748b]">{t.history.emptySub}</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {filteredSessions.map(s => {
            const isEditing = editingId === s.id;
            const updatedDate = new Date(s.updatedAt).toLocaleDateString();

            return (
              <div
                key={s.id}
                className="bg-white rounded-2xl p-5 border border-[#e2e8f0] hover:border-[#cbd5e1] shadow-subtle flex flex-col justify-between gap-4 transition-all"
              >
                <div>
                  {/* Card Title & Rename */}
                  <div className="flex items-start justify-between gap-2 mb-2">
                    {isEditing ? (
                      <div className="flex items-center gap-2 flex-1">
                        <input
                          type="text"
                          value={editTitle}
                          onChange={e => setEditTitle(e.target.value)}
                          className="flex-1 px-2.5 py-1 text-xs font-bold border border-brand rounded-lg focus:outline-none"
                          autoFocus
                        />
                        <button
                          onClick={() => handleSaveRename(s.id)}
                          className="px-2.5 py-1 bg-brand text-white text-xs rounded-lg font-bold"
                        >
                          {t.history.save}
                        </button>
                      </div>
                    ) : (
                      <div className="flex items-center gap-2">
                        <h3 className="font-bold text-sm text-[#0f172a] hover:text-brand transition-colors">
                          {s.title}
                        </h3>
                        <button
                          onClick={() => handleStartRename(s.id, s.title)}
                          className="text-[#94a3b8] hover:text-[#475569] p-0.5"
                          title="Rename Session"
                        >
                          <Edit3 className="w-3 h-3" />
                        </button>
                      </div>
                    )}

                    <span className="px-2 py-0.5 rounded-full text-[10px] font-bold bg-brand-subtle text-brand shrink-0">
                      {t.history.roundsBadge.replace('{n}', s.rounds.length.toString())}
                    </span>
                  </div>

                  {/* Summary */}
                  <p className="text-xs text-[#64748b] line-clamp-2 leading-relaxed mb-4">
                    {s.summary}
                  </p>

                  {/* Model Badges & Metadata */}
                  <div className="flex flex-wrap items-center justify-between gap-2 text-[11px] text-[#8c97af] pt-3 border-t border-[#f1f5f9]">
                    <div className="flex items-center gap-1.5">
                      <span className="text-[10px] font-medium text-[#64748b]">{t.history.modelsInvolved}</span>
                      {Array.from(new Set(s.rounds.flatMap(r => r.models.map(m => m.modelKey)))).slice(0, 6).map(mid => {
                        const meta = getModelMeta(mid);
                        return (
                          <span
                            key={mid}
                            className="w-4 h-4 rounded-md text-[9px] font-extrabold text-white flex items-center justify-center"
                            style={{ backgroundColor: meta.color }}
                            title={meta.name}
                          >
                            {meta.avatarText}
                          </span>
                        );
                      })}
                    </div>

                    <div className="flex items-center gap-3">
                      <span className="font-mono">{s.totalTokens.toLocaleString()} tok</span>
                      <span className="flex items-center gap-1">
                        <Calendar className="w-3 h-3" />
                        <span>{updatedDate}</span>
                      </span>
                    </div>
                  </div>
                </div>

                {/* Actions Footer */}
                <div className="flex items-center justify-between gap-2 pt-2 border-t border-[#f8fafc]">
                  <div className="flex items-center gap-1.5">
                    <button
                      onClick={() => handleExportFullSession(s)}
                      className="p-1.5 px-2.5 rounded-lg text-xs font-medium text-[#64748b] hover:text-brand hover:bg-brand-subtle flex items-center gap-1 transition-colors"
                      title={t.history.exportFull}
                    >
                      <Download className="w-3 h-3" />
                      <span>{t.history.exportFull}</span>
                    </button>

                    <button
                      onClick={() => {
                        if (window.confirm(t.history.confirmDelete.replace('{title}', s.title))) {
                          deleteSession(s.id);
                        }
                      }}
                      className="p-1.5 px-2.5 rounded-lg text-xs font-medium text-[#94a3b8] hover:text-red-600 hover:bg-red-50 flex items-center gap-1 transition-colors"
                      title={t.history.delete}
                    >
                      <Trash2 className="w-3.5 h-3.5" />
                      <span>{t.history.delete}</span>
                    </button>
                  </div>

                  <Link
                    to={`/chat/${s.id}`}
                    className="py-1.5 px-3 rounded-lg bg-brand text-white font-bold text-xs hover:bg-brand-hover flex items-center gap-1 shadow-xs transition-colors"
                  >
                    <span>{t.history.continueChat}</span>
                    <ArrowRight className="w-3.5 h-3.5" />
                  </Link>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
};
