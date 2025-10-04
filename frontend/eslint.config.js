import js from '@eslint/js';
import pluginVue from 'eslint-plugin-vue';
import globals from 'globals';

const vueEssential = pluginVue.configs['flat/essential'] ?? [];

const vueConfigs = (Array.isArray(vueEssential) ? vueEssential : [vueEssential]).map((config) => ({
  ...config,
  files: config.files ?? ['**/*.vue'],
  languageOptions: {
    ...(config.languageOptions ?? {}),
    globals: {
      ...globals.browser,
      ...(config.languageOptions?.globals ?? {})
    }
  }
}));

export default [
  {
    files: ['**/*.{js,vue}'],
    ignores: ['node_modules/**', 'dist/**']
  },
  {
    files: ['**/*.js'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: {
        ...globals.browser,
        ...globals.node
      }
    },
    rules: {
      ...js.configs.recommended.rules
    }
  },
  ...vueConfigs
];
