import React, { useRef, useState } from 'react';
import { api } from '../../api/client';
import { useLanguage } from '../../context/LanguageContext';
import { useSession } from '../../context/SessionContext';
import { FileText, Loader2, Paperclip, X } from 'lucide-react';

export interface AttachmentData {
  name: string;
  content: string;
  truncated: boolean;
}

/** 与后端 AttachmentService::ACCEPT 对应的文件类型约束 */
const ACCEPT = '.txt,.md,.markdown,.csv,.tsv,.json,.log,.html,.htm,.xml,.yml,.yaml,.ini,.sql,.py,.js,.ts,.jsx,.tsx,.php,.java,.c,.h,.cpp,.go,.rs,.rb,.sh,.css,.docx';
const MAX_BYTES = 2 * 1024 * 1024;

/**
 * 附件独立预览卡片（单独成行，绝不挤压输入框宽度）
 */
export const AttachmentPreview: React.FC<{
  value: AttachmentData | null;
  onRemove: () => void;
  disabled?: boolean;
}> = ({ value, onRemove, disabled }) => {
  const { t, locale } = useLanguage();
  if (!value) return null;

  return (
    <div className="inline-flex items-center gap-2.5 px-3 py-1.5 rounded-xl bg-white border border-[#dbeafe] shadow-2xs text-xs font-semibold text-slate-800 animate-in fade-in zoom-in-95 duration-150 max-w-full">
      <div className="w-6 h-6 rounded-lg bg-brand-subtle text-brand flex items-center justify-center shrink-0">
        <FileText className="w-3.5 h-3.5" />
      </div>
      <div className="flex flex-col min-w-0 pr-1">
        <span className="text-xs font-bold text-[#1b2230] truncate max-w-[260px] sm:max-w-[420px]" title={value.name}>
          {value.name}
        </span>
        <span className="text-[10px] text-[#8c97af] font-mono leading-tight mt-0.5">
          {value.content ? `${value.content.length.toLocaleString()} ${locale === 'en' ? 'chars' : '字符'}` : ''}
          {value.truncated && (
            <span className="text-amber-500 font-sans ml-1 font-semibold">
              · {locale === 'zh' ? '已智能截取前段' : locale === 'zh-TW' ? '已智慧截取前段' : 'Trimmed'}
            </span>
          )}
        </span>
      </div>
      <button
        type="button"
        disabled={disabled}
        onClick={onRemove}
        className="p-1 rounded-lg text-[#94a3b8] hover:text-red-500 hover:bg-red-50 transition-colors shrink-0 disabled:opacity-40"
        aria-label={t.workbench.clear || '移除附件'}
        title={locale === 'zh' ? '移除附件' : locale === 'zh-TW' ? '移除附件' : 'Remove attachment'}
      >
        <X className="w-3.5 h-3.5" />
      </button>
    </div>
  );
};

/**
 * 附件上传触发按钮
 */
export const AttachmentButton: React.FC<{
  hasAttachment?: boolean;
  onUploaded: (attachment: AttachmentData) => void;
  disabled?: boolean;
}> = ({ hasAttachment, onUploaded, disabled }) => {
  const { locale } = useLanguage();
  const { showToast } = useSession();
  const inputRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);

  const handleFile = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file || disabled) return;
    if (file.size > MAX_BYTES) {
      showToast(
        locale === 'zh'
          ? '附件不能超过 2MB'
          : locale === 'zh-TW'
          ? '附件不能超過 2MB'
          : 'Attachment exceeds 2MB'
      );
      return;
    }
    setUploading(true);
    const fd = new FormData();
    fd.append('file', file);
    const res = await api<{ name: string; content: string; truncated: boolean }>('/tiwen/upload', {
      method: 'POST',
      body: fd,
    });
    setUploading(false);
    if (res.ok && res.data) {
      onUploaded(res.data);
      showToast(
        locale === 'zh'
          ? `附件「${res.data.name}」已解析并附加`
          : locale === 'zh-TW'
          ? `附件「${res.data.name}」已解析並附加`
          : `Attached "${res.data.name}"`
      );
    } else {
      showToast(
        res.message ||
          (locale === 'zh' ? '附件上传失败' : locale === 'zh-TW' ? '附件上傳失敗' : 'Upload failed')
      );
    }
  };

  return (
    <>
      <input
        ref={inputRef}
        type="file"
        accept={ACCEPT}
        className="hidden"
        onChange={handleFile}
        aria-label={
          locale === 'zh' ? '上传附件文档' : locale === 'zh-TW' ? '上傳附件文件' : 'Upload attachment'
        }
      />
      <button
        type="button"
        onClick={() => inputRef.current?.click()}
        disabled={disabled || uploading}
        title={
          locale === 'zh'
            ? '上传附件文档 (txt/md/docx/代码等, ≤2MB)'
            : locale === 'zh-TW'
            ? '上傳附件文件 (txt/md/docx/程式碼等, ≤2MB)'
            : 'Attach a document (txt/md/docx, ≤2MB)'
        }
        className={`p-2 rounded-xl border transition-colors ${
          hasAttachment
            ? 'border-brand bg-brand-subtle text-brand shadow-2xs'
            : 'border-[#e2e8f0] bg-white text-[#64748b] hover:text-brand hover:border-brand'
        } disabled:opacity-50 disabled:cursor-not-allowed`}
      >
        {uploading ? <Loader2 className="w-3.5 h-3.5 animate-spin text-brand" /> : <Paperclip className="w-3.5 h-3.5" />}
      </button>
    </>
  );
};

/**
 * 完整附件组件（向后兼容）
 */
export const AttachmentField: React.FC<{
  value: AttachmentData | null;
  onChange: (attachment: AttachmentData | null) => void;
  disabled?: boolean;
}> = ({ value, onChange, disabled }) => {
  return (
    <div className="flex flex-col gap-2">
      {value && <AttachmentPreview value={value} onRemove={() => onChange(null)} disabled={disabled} />}
      <AttachmentButton hasAttachment={!!value} onUploaded={onChange} disabled={disabled} />
    </div>
  );
};
