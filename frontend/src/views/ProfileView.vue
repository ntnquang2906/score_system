<script setup>
import { session } from '../session'
import { t } from '../i18n'
</script>

<template>
  <h1>{{ t('profile.h1') }}</h1>
  <div class="section">
    <div class="stats">
      <div class="stat-box">
        <div class="label">{{ t('profile.name') }}</div>
        <div class="value" style="font-size:18px">{{ session.user.full_name || session.user.username }}</div>
      </div>
      <div class="stat-box">
        <div class="label">{{ t('profile.email') }}</div>
        <div class="value" style="font-size:16px">{{ session.user.email || '–' }}</div>
      </div>
      <div class="stat-box">
        <div class="label">{{ t('profile.staff_code') }}</div>
        <div class="value" style="font-size:18px">{{ session.user.staff_code || '–' }}</div>
      </div>
      <div class="stat-box">
        <div class="label">{{ t('profile.unit') }}</div>
        <div class="value" style="font-size:16px">{{ session.user.unit_name || session.user.tenant.name }}</div>
      </div>
    </div>
    <p>{{ t('profile.roles') }}: {{ session.user.roles.map((r) => t(`role.${r}`)).join(', ') || '–' }}</p>
  </div>
  <div class="info-box">{{ t('profile.coming_soon') }}</div>
</template>
