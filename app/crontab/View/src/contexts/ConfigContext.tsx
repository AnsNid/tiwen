import React, { createContext, useContext, useEffect, useRef, useState, ReactNode } from "react";
import { apiGet } from "../lib/api";
import type { SiteConfig } from "../types";

interface ConfigContextType {
  config: SiteConfig | null;
  isLoading: boolean;
}

const ConfigContext = createContext<ConfigContextType | undefined>(undefined);

// 版本化缓存键:结构变化时改键即可让所有客户端失效重拉
const CACHE_KEY = "crontab_site_config_v1";

function readCache(): SiteConfig | null {
  try {
    const cached = localStorage.getItem(CACHE_KEY);
    return cached ? (JSON.parse(cached) as SiteConfig) : null;
  } catch {
    return null;
  }
}

function applyConfig(siteConfig: SiteConfig) {
  if (siteConfig.site_name) {
    document.title = siteConfig.site_name;
  }
  if (siteConfig.site_favicon) {
    const link: HTMLLinkElement =
      document.querySelector("link[rel*='icon']") || document.createElement("link");
    link.type = "image/x-icon";
    link.rel = "shortcut icon";
    link.href = siteConfig.site_favicon;
    document.getElementsByTagName("head")[0].appendChild(link);
  }
}

export const ConfigProvider: React.FC<{ children: ReactNode }> = ({ children }) => {
  const [config, setConfig] = useState<SiteConfig | null>(readCache);
  const [isLoading, setIsLoading] = useState(!config);
  const isFetching = useRef(false);

  useEffect(() => {
    if (config) {
      applyConfig(config);
      return;
    }
    if (isFetching.current) return;
    isFetching.current = true;

    apiGet<SiteConfig>("/getConfig")
      .then((newConfig) => {
        setConfig(newConfig);
        localStorage.setItem(CACHE_KEY, JSON.stringify(newConfig));
        applyConfig(newConfig);
      })
      .catch((error) => {
        console.error("Failed to fetch site config", error);
      })
      .finally(() => {
        setIsLoading(false);
        isFetching.current = false;
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <ConfigContext.Provider value={{ config, isLoading }}>
      {children}
    </ConfigContext.Provider>
  );
};

export const useConfig = () => {
  const context = useContext(ConfigContext);
  if (context === undefined) {
    throw new Error("useConfig must be used within a ConfigProvider");
  }
  return context;
};
