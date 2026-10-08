<script setup>
import { lang, setLang } from '../i18n'
defineProps({ onDark: Boolean })
</script>

<template>
  <div class="lang-switch" :class="{ 'on-dark': onDark }">
    <a href="#" :class="{ active: lang === 'vi' }" @click.prevent="setLang('vi')">VI</a>
    <a href="#" :class="{ active: lang === 'en' }" @click.prevent="setLang('en')">EN</a>
  </div>
</template>
