import React from 'react';
import { useSession } from '../../context/SessionContext';
import { Loader2 } from 'lucide-react';

export const Toast: React.FC = () => {
  const { toastMessage, isSynthesizing } = useSession();

  if (!toastMessage) return null;

  return (
    <div className="fixed left-1/2 bottom-8 -translate-x-1/2 z-[300] pointer-events-none transition-all animate-in fade-in slide-in-from-bottom-2 duration-200">
      <div className="bg-[#1b2230] text-white text-xs font-medium px-4 py-2.5 rounded-full shadow-lg border border-white/10 max-w-[85vw] text-center flex items-center gap-2">
        {isSynthesizing ? (
          <Loader2 className="w-3.5 h-3.5 animate-spin text-amber-300 shrink-0" />
        ) : (
          <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 shrink-0"></span>
        )}
        <span>{toastMessage}</span>
      </div>
    </div>
  );
};
