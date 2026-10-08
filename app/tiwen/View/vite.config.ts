import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5175,
    proxy: {
      // 开发模式代理到 XPHP 主服务(后端实际端口以 config/autoload/server.php 为准)
      '/tiwen': {
        target: 'http://127.0.0.1:9521',
        changeOrigin: true,
      },
      '/stripe': {
        target: 'http://127.0.0.1:9521',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist',
    chunkSizeWarningLimit: 1500,
    rollupOptions: {
      output: {
        // vendor 分包:框架与图标库独立成 chunk,长期缓存不随业务代码失效
        manualChunks: {
          'react-vendor': ['react', 'react-dom', 'react-router-dom'],
          'icons': ['lucide-react'],
        },
      },
    },
  },
});
