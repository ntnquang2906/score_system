<script setup>
import { RouterLink, RouterView } from 'vue-router'
import AppDialog from './components/AppDialog.vue'
import LangSwitch from './components/LangSwitch.vue'
import { logout } from './auth'
import { session, canSeeEvaluations, canSubmit } from './session'
import { t } from './i18n'
</script>

<template>
  <header class="header">
    <div class="bar">
      <RouterLink to="/" class="brand">{{ t('app.title') }}</RouterLink>
      <nav>
        <RouterLink v-if="canSubmit()" to="/form">{{ t('nav.form') }}</RouterLink>
        <RouterLink v-if="canSeeEvaluations()" to="/evaluations">{{ t('nav.results') }}</RouterLink>
        <RouterLink to="/profile">{{ t('nav.profile') }}</RouterLink>
      </nav>
      <div class="user-info">
        <LangSwitch on-dark />
        <span>{{ session.user.full_name || session.user.username }}</span>
        <span v-for="r in session.user.roles" :key="r" class="role-badge">{{ t(`role.${r}`) }}</span>
        <button type="button" class="logout-btn" @click="logout">{{ t('nav.logout') }}</button>
      </div>
    </div>
  </header>
  <main class="page">
    <RouterView />
  </main>
  <AppDialog />
</template>
