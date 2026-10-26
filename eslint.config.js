import js from '@eslint/js';
import vue from 'eslint-plugin-vue';
import ts from 'typescript-eslint';

export default ts.config(
    { ignores: ['node_modules/**', 'vendor/**', 'public/**'] },
    js.configs.recommended,
    ...ts.configs.recommended,
    ...vue.configs['flat/recommended'],
    {
        files: ['resources/js/**/*.{ts,vue}'],
        languageOptions: {
            parserOptions: { parser: ts.parser },
            globals: { document: 'readonly' },
        },
    },
);
